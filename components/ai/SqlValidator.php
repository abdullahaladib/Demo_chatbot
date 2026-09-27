<?php

declare(strict_types=1);

namespace app\components\ai;

/**
 * LAYER 4 - validates (and minimally rewrites) AI-generated SQL before it may run.
 *
 * Works on a real token stream, not substring matching: string literals, backtick
 * identifiers, numbers, words and placeholders are separated first, so e.g. the text
 * 'FROM salaries' inside a string literal is data, not a table reference.
 *
 * A query passes only if ALL of these hold:
 *   - one statement (an optional trailing ';' is dropped; any other ';' is rejected)
 *   - it begins with SELECT; no DML/DDL/admin keywords anywhere (INSERT, UPDATE, SET,
 *     CALL, LOAD, HANDLER, REPLACE, WITH [except WITH ROLLUP], TABLE, INTO ...)
 *   - no comments (--, #, /* *\/), no @variables, no ? placeholders
 *   - no INFORMATION_SCHEMA / mysql / performance_schema / sys, no schema-qualified
 *     tables, no table functions, no parenthesised table lists
 *   - every table referenced after FROM / JOIN / ',' is in this role's allowlist
 *   - every self-scoped view is filtered `<scope column> = :me`, every department view
 *     `department_id = :dept`
 *   - LIMIT present (appended if missing) and <= maxRows (rewritten if larger)
 *
 * Defence in depth on row scope: `WHERE employee_id = :me OR 1=1` contains :me yet
 * leaks every row. So besides requiring the predicate, each scoped view reference is
 * rewritten into a derived table that applies the scope itself:
 *     v_my_attendance  ->  (SELECT * FROM `v_my_attendance` WHERE `employee_id` = :me) AS `v_my_attendance`
 * Whatever the model writes in its own WHERE clause, it can only ever see its own rows.
 */
final class SqlValidator
{
    private const MAX_LENGTH = 4000;

    /** Rejected wherever they appear as a bare word (outside string literals). */
    private const FORBIDDEN_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE',
        'SET', 'CALL', 'LOAD', 'HANDLER', 'REPLACE', 'RENAME', 'LOCK', 'UNLOCK', 'INTO', 'OUTFILE',
        'DUMPFILE', 'EXECUTE', 'PREPARE', 'DEALLOCATE', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'USE', 'KILL',
        'FLUSH', 'RESET', 'PURGE', 'INSTALL', 'UNINSTALL', 'SHUTDOWN', 'RESTART', 'CLONE', 'IMPORT',
        'ANALYZE', 'OPTIMIZE', 'REPAIR', 'BINLOG', 'DO', 'SIGNAL', 'RESIGNAL', 'PROCEDURE',
        'TABLE', 'VALUES', 'LATERAL', 'STRAIGHT_JOIN', 'WITH', 'CURRENT_USER', 'CURRENT_ROLE',
    ];

    /** Rejected when followed by '(' - side effects, timing attacks or environment probing. */
    private const FORBIDDEN_FUNCTIONS = [
        'SLEEP', 'BENCHMARK', 'LOAD_FILE', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS',
        'IS_FREE_LOCK', 'IS_USED_LOCK', 'USER', 'SESSION_USER', 'SYSTEM_USER', 'DATABASE',
        'SCHEMA', 'VERSION', 'CONNECTION_ID', 'LAST_INSERT_ID', 'ROW_COUNT', 'FOUND_ROWS',
        'SOURCE_POS_WAIT', 'MASTER_POS_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET', 'JSON_TABLE',
    ];

    private const SYSTEM_SCHEMAS = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    /** Words that end a table reference, i.e. can never be read as an alias. */
    private const CLAUSE_WORDS = [
        'AS', 'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'JOIN', 'INNER', 'LEFT', 'RIGHT',
        'CROSS', 'NATURAL', 'FULL', 'OUTER', 'ON', 'USING', 'UNION', 'EXCEPT', 'INTERSECT',
        'WINDOW', 'FOR', 'LOCK', 'INTO', 'STRAIGHT_JOIN', 'FORCE', 'IGNORE', 'USE', 'PARTITION',
    ];

    private const MSG_GENERIC = "I can only run simple read-only lookups, so I didn't run that query.";
    private const MSG_SCOPE = "I can only look up records you're entitled to see, and that query wasn't limited to them, so I didn't run it.";

    /** @var array<int, array{t:string, v:string, x?:string, p?:string}> */
    private array $tokens = [];
    /** @var int[] indexes into $tokens of the non-whitespace tokens */
    private array $sig = [];

    public function __construct(private readonly string $role)
    {
    }

    public function validate(string $sql): ValidationResult
    {
        try {
            return $this->run($sql);
        } catch (RejectedQuery $e) {
            return ValidationResult::reject($e->codeName, $e->getMessage(), $e->userMessage);
        }
    }

    private function run(string $sql): ValidationResult
    {
        if (!AccessMap::isKnownRole($this->role)) {
            $this->reject('unknown_role', "Unknown role '{$this->role}'", self::MSG_GENERIC);
        }

        // ---- one statement, optional trailing semicolon
        $sql = trim($sql);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }
        if ($sql === '') {
            $this->reject('empty', 'Empty query', self::MSG_GENERIC);
        }
        if (strlen($sql) > self::MAX_LENGTH) {
            $this->reject('too_long', 'Query exceeds ' . self::MAX_LENGTH . ' characters', self::MSG_GENERIC);
        }

        $this->tokenize($sql);
        $this->sig = array_keys(array_filter($this->tokens, fn($t) => $t['t'] !== 'ws'));

        foreach ($this->sig as $i) {
            if ($this->tokens[$i]['v'] === ';') {
                $this->reject('multiple_statements', 'Semicolon inside the query (multiple statements)', self::MSG_GENERIC);
            }
        }

        // ---- must begin with SELECT
        if ($this->upper(0) !== 'SELECT' || $this->tokens[$this->sig[0]]['t'] !== 'word') {
            $first = $this->tokens[$this->sig[0]]['v'];
            $this->reject('not_select', "Query must begin with SELECT (begins with '$first')", self::MSG_GENERIC);
        }

        $allowed = AccessMap::viewsFor($this->role);
        $knownViews = array_keys(AccessMap::config()['views']);

        // ---- keyword / function / schema / placeholder sweep over every token
        foreach ($this->sig as $k => $i) {
            $tok = $this->tokens[$i];
            if ($tok['t'] === 'word') {
                $w = strtoupper($tok['v']);
                if (in_array($w, self::FORBIDDEN_WORDS, true)) {
                    $isRollup = $w === 'WITH' && $this->upper($k + 1) === 'ROLLUP';
                    $isReplaceFn = $w === 'REPLACE' && $this->value($k + 1) === '(';
                    if (!$isRollup && !$isReplaceFn) {
                        $this->reject('forbidden_keyword', "Forbidden keyword $w", self::MSG_GENERIC);
                    }
                }
                if (in_array($w, self::FORBIDDEN_FUNCTIONS, true) && $this->value($k + 1) === '(') {
                    $this->reject('forbidden_function', "Forbidden function $w()", self::MSG_GENERIC);
                }
            }
            if ($tok['t'] === 'word' || $tok['t'] === 'ident') {
                $name = $this->identifier($i);
                if (in_array($name, self::SYSTEM_SCHEMAS, true)) {
                    $this->reject('system_schema', "System schema $name referenced", self::MSG_GENERIC);
                }
                // A view name anywhere in the query (not only after FROM) must be allowed.
                if (in_array($name, $knownViews, true) && !in_array($name, $allowed, true)) {
                    $this->deny($name, "View $name is not allowed for role {$this->role}");
                }
            }
            if ($tok['t'] === 'param' && !in_array(substr($tok['v'], 1), ['me', 'dept'], true)) {
                $this->reject('bad_placeholder', "Unknown placeholder {$tok['v']}", self::MSG_GENERIC);
            }
        }

        // ---- table references: every one must be on this role's allowlist
        $refs = [];
        foreach ($this->sig as $k => $i) {
            $w = $this->tokens[$i]['t'] === 'word' ? strtoupper($this->tokens[$i]['v']) : '';
            if ($w === 'FROM' || $w === 'JOIN') {
                array_push($refs, ...$this->parseTableRefs($k + 1));
            }
        }
        foreach ($refs as $ref) {
            if (!in_array($ref['name'], $allowed, true)) {
                $kind = isset(AccessMap::config()['baseTableSubjects'][$ref['name']]) ? 'base table' : 'unknown table';
                $this->deny($ref['name'], "Table {$ref['name']} ($kind) is not in the allowlist for role {$this->role}");
            }
        }

        // ---- scoped views need their session predicate
        foreach ($refs as $ref) {
            $view = AccessMap::view($ref['name']);
            if (!empty($view['scope']) && !$this->hasPredicate($view['column'], $view['scope'])) {
                $this->reject('missing_scope',
                    "{$ref['name']} requires `{$view['column']} = :{$view['scope']}`", self::MSG_SCOPE);
            }
        }

        // ---- LIMIT
        $notes = [];
        $max = AccessMap::maxRows();
        $limitNote = $this->enforceLimit($max);
        if ($limitNote !== null) {
            $notes[] = $limitNote;
        }

        // ---- wrap scoped views so the scope holds regardless of the model's WHERE
        foreach ($refs as $ref) {
            $view = AccessMap::view($ref['name']);
            if (empty($view['scope'])) {
                continue;
            }
            $inner = "(SELECT * FROM `{$ref['name']}` WHERE `{$view['column']}` = %s)";
            $alias = $ref['hasAlias'] ? '' : " AS `{$ref['name']}`";
            $this->tokens[$ref['index']] = [
                't' => 'wrap',
                'v' => sprintf($inner, ':' . $view['scope']) . $alias,
                'x' => sprintf($inner, '?') . $alias,
                'p' => $view['scope'],
            ];
            $notes[] = "{$ref['name']} row-scoped to {$view['column']} = :{$view['scope']}";
        }

        // ---- render display SQL (named placeholders) and executable SQL (positional)
        $display = $exec = '';
        $placeholders = [];
        foreach ($this->tokens as $tok) {
            $display .= $tok['v'];
            if ($tok['t'] === 'param') {
                $exec .= '?';
                $placeholders[] = substr($tok['v'], 1);
            } elseif ($tok['t'] === 'wrap') {
                $exec .= $tok['x'];
                $placeholders[] = $tok['p'];
            } else {
                $exec .= $tok['v'];
            }
        }

        $views = array_values(array_unique(array_column($refs, 'name')));
        return ValidationResult::pass($display, $exec, $placeholders, $views, $notes);
    }

    // ------------------------------------------------------------------ tokenizer

    private function tokenize(string $sql): void
    {
        $this->tokens = [];
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $two = substr($sql, $i, 2);

            if (ctype_space($c)) {
                preg_match('/\G\s+/', $sql, $m, 0, $i);
                $this->push('ws', $m[0], $i);
            } elseif ($c === '#' || $two === '--' || $two === '/*') {
                $this->reject('comment', 'SQL comment found', self::MSG_GENERIC);
            } elseif ($c === "'" || $c === '"') {
                $j = $i + 1;
                while (true) {
                    if ($j >= $len) {
                        $this->reject('unterminated_string', 'Unterminated string literal', self::MSG_GENERIC);
                    }
                    if ($sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $c) {
                        if (($sql[$j + 1] ?? '') === $c) { // doubled quote escape
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $this->push('str', substr($sql, $i, $j - $i + 1), $i);
            } elseif ($c === '`') {
                $j = $i + 1;
                while (true) {
                    if ($j >= $len) {
                        $this->reject('unterminated_identifier', 'Unterminated backtick identifier', self::MSG_GENERIC);
                    }
                    if ($sql[$j] === '`') {
                        if (($sql[$j + 1] ?? '') === '`') {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $this->push('ident', substr($sql, $i, $j - $i + 1), $i);
            } elseif ($c === ':' && preg_match('/\G:[A-Za-z_]\w*/', $sql, $m, 0, $i)) {
                $this->push('param', $m[0], $i);
            } elseif (preg_match('/\G(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $sql, $m, 0, $i)) {
                $this->push('num', $m[0], $i);
            } elseif (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $sql, $m, 0, $i)) {
                $this->push('word', $m[0], $i);
            } elseif (str_contains('(),.;=<>!+-*/%&|^~', $c)) {
                $this->push('op', $c, $i);
            } elseif ($c === '@') {
                $this->reject('variable', 'User/system variables (@) are not allowed', self::MSG_GENERIC);
            } elseif ($c === '?') {
                $this->reject('bad_placeholder', 'Positional ? placeholders are not allowed', self::MSG_GENERIC);
            } else {
                $this->reject('bad_character', sprintf("Unsupported character '%s'", $c), self::MSG_GENERIC);
            }
            $i += strlen(end($this->tokens)['v']);
        }
    }

    private function push(string $type, string $value, int $at): void
    {
        $this->tokens[] = ['t' => $type, 'v' => $value];
    }

    // ------------------------------------------------------------------ parsing helpers

    /**
     * Parses the table reference(s) that follow FROM or JOIN, starting at significant
     * position $k. Handles aliases and comma-separated lists; derived tables
     * `(SELECT ...)` are skipped here (their own FROM clauses are visited separately).
     *
     * @return array<int, array{name:string, index:int, hasAlias:bool}>
     */
    private function parseTableRefs(int $k): array
    {
        $refs = [];
        while (true) {
            if (!isset($this->sig[$k])) {
                $this->reject('parse', 'Expected a table after FROM/JOIN', self::MSG_GENERIC);
            }
            $i = $this->sig[$k];
            $tok = $this->tokens[$i];

            if ($tok['v'] === '(') {
                if ($this->upper($k + 1) !== 'SELECT') {
                    $this->reject('parenthesised_table', 'Parenthesised table references are not supported', self::MSG_GENERIC);
                }
                $k = $this->matchParen($k) + 1;
                $k = $this->skipAlias($k);
            } elseif ($tok['t'] === 'word' || $tok['t'] === 'ident') {
                $name = $this->identifier($i);
                if ($this->value($k + 1) === '.') {
                    $this->reject('qualified_table', "Schema-qualified table $name.* is not allowed", self::MSG_GENERIC);
                }
                if ($this->value($k + 1) === '(') {
                    $this->reject('table_function', "Table function $name() is not allowed", self::MSG_GENERIC);
                }
                $after = $this->skipAlias($k + 1);
                if ($name !== 'dual') {
                    $refs[] = ['name' => $name, 'index' => $i, 'hasAlias' => $after !== $k + 1];
                }
                $k = $after;
            } else {
                $this->reject('parse', "Expected a table name, found '{$tok['v']}'", self::MSG_GENERIC);
            }

            if ($this->value($k) === ',') {
                $k++;
                continue;
            }
            return $refs;
        }
    }

    /** Returns the significant position after an optional `[AS] alias`. */
    private function skipAlias(int $k): int
    {
        if ($this->upper($k) === 'AS') {
            return $k + 2;
        }
        $tok = isset($this->sig[$k]) ? $this->tokens[$this->sig[$k]] : null;
        if ($tok !== null && ($tok['t'] === 'ident'
                || ($tok['t'] === 'word' && !in_array(strtoupper($tok['v']), self::CLAUSE_WORDS, true)))) {
            return $k + 1;
        }
        return $k;
    }

    private function matchParen(int $k): int
    {
        $depth = 0;
        for ($n = count($this->sig); $k < $n; $k++) {
            $v = $this->value($k);
            if ($v === '(') {
                $depth++;
            } elseif ($v === ')' && --$depth === 0) {
                return $k;
            }
        }
        $this->reject('parse', 'Unbalanced parentheses', self::MSG_GENERIC);
    }

    /** True if the query contains `[qualifier.]column = :param` or `:param = [qualifier.]column`. */
    private function hasPredicate(string $column, string $param): bool
    {
        $n = count($this->sig);
        for ($k = 0; $k < $n; $k++) {
            if ($this->value($k) !== '=') {
                continue;
            }
            $left = $k > 0 ? $this->tokens[$this->sig[$k - 1]] : null;
            $right = $k + 1 < $n ? $this->tokens[$this->sig[$k + 1]] : null;
            if ($left === null || $right === null) {
                continue;
            }
            $isCol = fn($t) => ($t['t'] === 'word' || $t['t'] === 'ident')
                && strtolower(trim($t['v'], '`')) === $column;
            $isParam = fn($t) => $t['t'] === 'param' && $t['v'] === ':' . $param;
            if (($isCol($left) && $isParam($right)) || ($isParam($left) && $isCol($right))) {
                return true;
            }
        }
        return false;
    }

    /** Ensures a top-level LIMIT <= $max. Returns a note describing any rewrite. */
    private function enforceLimit(int $max): ?string
    {
        $depth = 0;
        $limitAt = null;
        foreach ($this->sig as $k => $i) {
            $v = $this->tokens[$i]['v'];
            if ($v === '(') {
                $depth++;
            } elseif ($v === ')') {
                $depth--;
            } elseif ($depth === 0 && $this->tokens[$i]['t'] === 'word' && strtoupper($v) === 'LIMIT') {
                $limitAt = $k;
            }
        }

        if ($limitAt === null) {
            $this->tokens[] = ['t' => 'op', 'v' => " LIMIT $max"];
            return "no LIMIT: appended LIMIT $max";
        }

        // LIMIT n | LIMIT offset, n | LIMIT n OFFSET m
        $countAt = $this->value($limitAt + 2) === ',' ? $limitAt + 3 : $limitAt + 1;
        if (!isset($this->sig[$countAt]) || $this->tokens[$this->sig[$countAt]]['t'] !== 'num'
            || !ctype_digit($this->tokens[$this->sig[$countAt]]['v'])) {
            $this->reject('bad_limit', 'LIMIT must be an integer literal', self::MSG_GENERIC);
        }
        if ($countAt === $limitAt + 3 && $this->tokens[$this->sig[$limitAt + 1]]['t'] !== 'num') {
            $this->reject('bad_limit', 'LIMIT offset must be an integer literal', self::MSG_GENERIC);
        }
        $count = (int) $this->tokens[$this->sig[$countAt]]['v'];
        if ($count > $max) {
            $this->tokens[$this->sig[$countAt]]['v'] = (string) $max;
            return "LIMIT $count rewritten to $max";
        }
        return null;
    }

    private function identifier(int $i): string
    {
        $v = $this->tokens[$i]['v'];
        if ($this->tokens[$i]['t'] === 'ident') {
            $v = str_replace('``', '`', substr($v, 1, -1));
        }
        return strtolower($v);
    }

    /** Value of the significant token at position $k ('' if out of range). */
    private function value(int $k): string
    {
        return isset($this->sig[$k]) ? $this->tokens[$this->sig[$k]]['v'] : '';
    }

    private function upper(int $k): string
    {
        return isset($this->sig[$k]) && $this->tokens[$this->sig[$k]]['t'] === 'word'
            ? strtoupper($this->tokens[$this->sig[$k]]['v']) : '';
    }

    private function deny(string $identifier, string $reason): never
    {
        $this->reject('not_authorised', $reason,
            "You're not authorised to access " . AccessMap::subjectOf($identifier) . '.');
    }

    private function reject(string $code, string $reason, string $userMessage): never
    {
        throw new RejectedQuery($code, $reason, $userMessage);
    }
}
