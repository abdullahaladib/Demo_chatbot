<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * LAYER 4 - validates (and minimally rewrites) AI-generated SQL before it may run.
 * Ported from the Demo_chatbot reference implementation; the rules are the same, the
 * allowlist now comes from the ERP-mirroring AccessPolicy.
 *
 * Works on a real token stream, not substring matching: string literals, backtick
 * identifiers, numbers, words and placeholders are separated first, so e.g. the text
 * 'FROM salary_info' inside a string literal is data, not a table reference.
 *
 * A query passes only if ALL of these hold:
 *   - one statement (an optional trailing ';' is dropped; any other ';' is rejected)
 *   - it begins with SELECT; no DML/DDL/admin keywords anywhere (INSERT, UPDATE, SET,
 *     CALL, LOAD, HANDLER, REPLACE, WITH [except WITH ROLLUP], TABLE, INTO ...)
 *   - no comments (--, #, /* *\/), no @variables, no ? placeholders
 *   - no INFORMATION_SCHEMA / mysql / performance_schema / sys, no schema-qualified
 *     tables, no table functions, no parenthesised table lists
 *   - every table / view referenced after FROM / JOIN / ',' is allowed for THIS user
 *   - no `SELECT *` / `t.*` (columns must be named; COUNT(*) is fine) - the AI's MySQL
 *     account only holds grants on the non-sensitive columns
 *   - self-scoped views filtered `<column> = :me`, department views `department_id = :dept`
 *   - LIMIT present (appended if missing) and <= maxRows (rewritten if larger)
 *
 * Row scope is also ENFORCED by rewriting, whatever the model wrote in its WHERE clause:
 *   v_my_attendance_daily -> (SELECT * FROM `v_my_attendance_daily` WHERE `employee_id` = :me) AS ...
 *   journal (has group_for) -> (SELECT `id`,`jv_no`,... FROM `journal` WHERE `group_for` = :group) AS `journal`
 * so `... OR 1=1` can never widen the rows, and every company table stays in the user's company.
 */
final class SqlValidator
{
    private const MAX_LENGTH = 6000;

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

    private const PLACEHOLDERS = ['me', 'dept', 'group'];

    private const MSG_GENERIC = "I can only run simple read-only lookups, so I didn't run that query.";
    private const MSG_SCOPE = "I can only look up records you're entitled to see, and that query wasn't limited to them, so I didn't run it.";

    /** @var array<int, array{t:string, v:string, x?:string, p?:string}> */
    private array $tokens = [];
    /** @var int[] indexes into $tokens of the non-whitespace tokens */
    private array $sig = [];

    /**
     * @param callable(string):bool|null $tableExists does a (lower-case) table exist in the tenant
     *        at all? Only used to tell a model typo (retryable) from a real but forbidden table.
     */
    public function __construct(
        private readonly AccessPolicy $policy,
        private readonly int $maxRows = 200,
        private readonly mixed $tableExists = null,
    ) {
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

        // ---- keyword / function / schema / placeholder / star sweep over every token
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
                // A chatbot VIEW name anywhere in the query (not only after FROM) must be allowed.
                if (isset(Catalog::VIEWS[$name]) && !$this->policy->allows($name)) {
                    $this->deny($name, "View $name is not allowed for this user");
                }
            }
            if ($tok['t'] === 'param' && !in_array(substr($tok['v'], 1), self::PLACEHOLDERS, true)) {
                $this->reject('bad_placeholder', "Unknown placeholder {$tok['v']} (use :me, :dept or :group)", self::MSG_GENERIC);
            }
            // SELECT * / t.* - but COUNT(*) is fine
            if ($tok['v'] === '*' && $tok['t'] === 'op') {
                $prev = $this->value($k - 1);
                if ($prev === ',' || $prev === '.' || in_array($this->upper($k - 1), ['SELECT', 'DISTINCT'], true)) {
                    $this->reject('select_star', 'SELECT * is not allowed: name the columns you need', self::MSG_GENERIC);
                }
            }
        }

        // ---- table references: every one must be allowed for this user
        $refs = [];
        foreach ($this->sig as $k => $i) {
            $w = $this->tokens[$i]['t'] === 'word' ? strtoupper($this->tokens[$i]['v']) : '';
            if ($w === 'FROM' || $w === 'JOIN') {
                array_push($refs, ...$this->parseTableRefs($k + 1));
            }
        }
        foreach ($refs as $ref) {
            if ($this->policy->allows($ref['name'])) {
                continue;
            }
            if (Catalog::isKnownObject($ref['name']) || $this->exists($ref['name'])) {
                $this->deny($ref['name'], "Table {$ref['name']} is not allowed for this user");
            }
            $this->reject('unknown_table', "No table named {$ref['name']} is available", self::MSG_GENERIC);
        }

        // ---- scoped views need their session predicate
        foreach ($refs as $ref) {
            $scope = $this->policy->scopeOf($ref['name']);
            if ($scope !== null && $scope['required'] && !$this->hasPredicate($scope['column'], $scope['param'])) {
                $this->reject('missing_scope',
                    "{$ref['name']} requires `{$scope['column']} = :{$scope['param']}`", self::MSG_SCOPE);
            }
        }

        // ---- LIMIT
        $notes = [];
        $limitNote = $this->enforceLimit($this->maxRows);
        if ($limitNote !== null) {
            $notes[] = $limitNote;
        }

        // ---- rewrite every scoped reference into a derived table that applies the scope
        foreach ($refs as $ref) {
            $scope = $this->policy->scopeOf($ref['name']);
            if ($scope === null) {
                continue;
            }
            $select = isset($scope['columns'])
                ? implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $scope['columns']))
                : '*';
            $alias = $ref['hasAlias'] ? '' : " AS `{$ref['name']}`";
            if ($scope['param'] === null) {
                // no row scope, but only the table's non-sensitive columns
                $inner = "(SELECT $select FROM `{$ref['name']}`)";
                $this->tokens[$ref['index']] = ['t' => 'wrap', 'v' => $inner . $alias, 'x' => $inner . $alias, 'p' => null];
                continue;
            }
            $inner = "(SELECT $select FROM `{$ref['name']}` WHERE `{$scope['column']}` = %s)";
            $this->tokens[$ref['index']] = [
                't' => 'wrap',
                'v' => sprintf($inner, ':' . $scope['param']) . $alias,
                'x' => sprintf($inner, '?') . $alias,
                'p' => $scope['param'],
            ];
            $notes[] = "{$ref['name']} row-scoped to {$scope['column']} = :{$scope['param']}";
        }

        // ---- render display SQL (named placeholders) and executable SQL (positional)
        $display = $exec = '';
        $placeholders = [];
        foreach ($this->tokens as $tok) {
            $display .= $tok['t'] === 'wrap' ? $this->shorten($tok['v']) : $tok['v'];
            if ($tok['t'] === 'param') {
                $exec .= '?';
                $placeholders[] = substr($tok['v'], 1);
            } elseif ($tok['t'] === 'wrap') {
                $exec .= $tok['x'];
                if ($tok['p'] !== null) {
                    $placeholders[] = $tok['p'];
                }
            } else {
                $exec .= $tok['v'];
            }
        }

        $tables = array_values(array_unique(array_column($refs, 'name')));
        return ValidationResult::pass($display, $exec, $placeholders, $tables, $notes);
    }

    /** Display form of a wrapped table: column lists can be long, so show `...` instead. */
    private function shorten(string $wrapped): string
    {
        return (string) preg_replace('/^\(SELECT (`[^`]+`(, `[^`]+`){3,}) FROM/', '(SELECT ... FROM', $wrapped);
    }

    private function exists(string $lowerName): bool
    {
        return $this->tableExists !== null && (bool) ($this->tableExists)($lowerName);
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
                $this->push('ws', $m[0]);
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
                $this->push('str', substr($sql, $i, $j - $i + 1));
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
                $this->push('ident', substr($sql, $i, $j - $i + 1));
            } elseif ($c === ':' && preg_match('/\G:[A-Za-z_]\w*/', $sql, $m, 0, $i)) {
                $this->push('param', $m[0]);
            } elseif (preg_match('/\G(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $sql, $m, 0, $i)) {
                $this->push('num', $m[0]);
            } elseif (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $sql, $m, 0, $i)) {
                $this->push('word', $m[0]);
            } elseif (str_contains('(),.;=<>!+-*/%&|^~', $c)) {
                $this->push('op', $c);
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

    private function push(string $type, string $value): void
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
            "You're not authorised to access " . $this->policy->subjectOf($identifier) . '.');
    }

    private function reject(string $code, string $reason, string $userMessage): never
    {
        throw new RejectedQuery($code, $reason, $userMessage);
    }
}
