<?php

declare(strict_types=1);

namespace app\components;

/**
 * Streams the MariaDB 10.11 phpMyAdmin dump (trainingclouderp_training_db.sql) into a
 * MySQL 8 compatible copy. The source file is never modified.
 *
 * Only CREATE TABLE definitions are rewritten; data lines pass through untouched.
 * Rewrites (each one is a real MariaDB-vs-MySQL difference found by a trial import):
 *   1. `date ... DEFAULT current_timestamp()` -> `DEFAULT (curdate())`
 *      (MariaDB allows it on DATE; MySQL needs an expression default)
 *   2. duplicate values inside ENUM(...) are removed, e.g. enum('A','B','','') -> enum('A','B','')
 *      (MariaDB warns; MySQL 8 raises ERROR 1291)
 *   3. `SET SESSION innodb_strict_mode = 0` is prepended (two wide backup tables hit ERROR 1118)
 * Zero dates ('0000-00-00') need no rewrite: the dump's own `SET SQL_MODE =
 * "NO_AUTO_VALUE_ON_ZERO"` turns off strict mode / NO_ZERO_DATE for the import session.
 */
final class TrainingDumpConverter
{
    /** @var array<string,int> how many times each rewrite was applied */
    public array $stats = ['date_default' => 0, 'enum_dedup' => 0, 'lines' => 0];

    public function convert(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException("Cannot open $source or $target");
        }
        // 3. Two wide backup tables (personnel_basic_info_main/_user) exceed InnoDB's row-size
        //    check under MySQL's strict InnoDB mode (ERROR 1118); MariaDB is lenient. Relax it
        //    for this import session only.
        fwrite($out, "SET SESSION innodb_strict_mode = 0;\n");
        $inCreate = false;
        while (($line = fgets($in)) !== false) {
            $this->stats['lines']++;
            if (str_starts_with($line, 'CREATE TABLE ')) {
                $inCreate = true;
            }
            if ($inCreate) {
                $line = $this->fixDefinitionLine($line);
                if (str_starts_with($line, ') ENGINE=')) {
                    $inCreate = false;
                }
            }
            fwrite($out, $line);
        }
        fclose($in);
        fclose($out);
    }

    private function fixDefinitionLine(string $line): string
    {
        $line = preg_replace_callback(
            '/`\s+date(\s+NOT NULL)?\s+DEFAULT\s+current_timestamp\(\)/i',
            function (array $m): string {
                $this->stats['date_default']++;
                return '` date' . ($m[1] ?? '') . ' DEFAULT (curdate())';
            },
            $line,
        );

        return preg_replace_callback(
            "/\\b(enum|set)\\(((?:'(?:[^'\\\\]|\\\\.|'')*'\\s*,?\\s*)+)\\)/i",
            function (array $m): string {
                preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $m[2], $vals);
                $unique = [];
                foreach ($vals[1] as $v) {
                    // MySQL compares ENUM members case-insensitively and ignoring trailing spaces
                    $key = strtolower(rtrim($v));
                    if (!isset($unique[$key])) {
                        $unique[$key] = $v;
                    }
                }
                if (count($unique) === count($vals[1])) {
                    return $m[0];
                }
                $this->stats['enum_dedup']++;
                return $m[1] . '(' . implode(',', array_map(fn($v) => "'$v'", $unique)) . ')';
            },
            $line,
        );
    }
}
