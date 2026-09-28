<?php
/**
 * Builds data/catalog.json.php - what the AI may know about, per ERP module.
 *
 *   php install/build_catalog.php
 *
 * It LEARNS the ERP instead of hard-coding it:
 *   1. modules      <- user_module_manage (active modules, their source folder `module_file`)
 *   2. module→table <- scans each module's PHP source (app/views/<module_file>/) for the
 *                      tables its SQL actually uses: FROM / JOIN / INTO / UPDATE ... and the
 *                      ERP's own helpers find_a_field('t', ...), find_all_field('t', ...),
 *                      foreign_relation('t', ...), db_insert('t', ...), db_update('t', ...)...
 *   3. schema       <- information_schema (columns, primary keys, foreign keys) + exact row counts
 *
 * Then it applies the security rules (see SECURITY RULES below): empty tables, backup copies,
 * logs and credential stores are dropped; sensitive columns are removed; salary/payroll-type
 * tables are marked `confidential` (HR / executive tier only).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // command line only

require_once __DIR__ . '/../bootstrap.php';

use AiChatbot\Config;
use AiChatbot\Db;

// =============================================================================== SECURITY RULES

/** Tables never exposed (credentials, access control, logs, the chatbot's own tables). */
const EXCLUDE_TABLE = '/^(user_activity_management.*|user_(page|feature|module|report|role|roll|query|action).*|'
    . '.*_log(_.*)?|.*_logs|log_.*|query_log|ai_.*|sms_api_info|.*smtp.*|.*mail_config.*|.*api_(key|config|info).*|'
    . 'general_configuration|config_template|company_define|database_info|otp.*|.*token.*|.*session.*)$/i';

/** Backup / archive / scratch copies the ERP keeps next to the real tables. */
const BACKUP_TABLE = '/(_old\b|_old_|_bak|backup|_copy|_del$|_tmp$|_temp$|test|_\d{3,}$|_\d{6,}|mhafuz|kawsar|clouderp|-)/i';

/** Salary / payroll / personal-finance tables: HR and executive tier only. */
const CONFIDENTIAL_TABLE = '/^(salary.*|.*_salary.*|payroll.*|.*payroll.*|increment.*|.*bonus.*|pf_.*|.*provident.*|'
    . '.*gratuity.*|loan_details|salary_advance|.*appraisal.*|performance_.*|self_assessment.*|elms_salary.*|hris_payroll.*)$/i';

/** Column names that are never exposed, in any table. */
const SENSITIVE_COLUMN = '/(pass(word)?|passwd|pwd|token|otp|secret|api_?key|salt|^sign$|signature|'
    . '(^|_)nid($|_)|national_id|passport|(^|_)tin($|_)|(^|_)dob($|_)|birth|religion|'
    . 'ac_no|acc_no|account_no|bank_ac|iban|swift|card_no|(^|_)pin$|cv_att|nid_att|tin_att|picture_att)/i';

/** Columns whose presence makes a table confidential (salary figures). */
const SALARY_COLUMN = '/^(basic_salary|gross_salary|total_salary|consolidated_salary|net_salary|salary_amount)$/i';

/** A table referenced by this many active modules is shared reference data (visible with any module). */
const SHARED_MODULE_COUNT = 6;

// =============================================================================== helpers

Db::useAdminAccount();
$db = Db::app();
$schemaName = Db::tenant();
$viewsRoot = realpath(AI_CHATBOT_DIR . '/../../views');

$started = microtime(true);
$log = fn(string $m) => fwrite(STDOUT, $m . "\n");

// ------------------------------------------------------------------------- 1. schema
$tables = [];
foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $r) {
    $tables[strtolower($r['TABLE_NAME'])] = ['name' => $r['TABLE_NAME'], 'columns' => [], 'pk' => [], 'fk' => []];
}
foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION") as $c) {
    $t = strtolower($c['TABLE_NAME']);
    if (!isset($tables[$t])) {
        continue;
    }
    $tables[$t]['columns'][] = ['name' => $c['COLUMN_NAME'], 'type' => $c['COLUMN_TYPE']];
    if ($c['COLUMN_KEY'] === 'PRI') {
        $tables[$t]['pk'][] = $c['COLUMN_NAME'];
    }
}
foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL") as $f) {
    $t = strtolower($f['TABLE_NAME']);
    if (isset($tables[$t])) {
        $tables[$t]['fk'][] = [$f['COLUMN_NAME'], $f['REFERENCED_TABLE_NAME'], $f['REFERENCED_COLUMN_NAME']];
    }
}
$log(sprintf('schema: %d base tables', count($tables)));

// exact row counts (information_schema estimates are unreliable for small tables)
foreach ($tables as $t => &$info) {
    $info['rows'] = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $info['name']) . '`')->fetchColumn();
}
unset($info);

// ------------------------------------------------------------------------- 2. modules + source scan
$modules = [];
foreach ($db->query("SELECT id, module_name, module_file, module_type FROM user_module_manage WHERE status = 'Yes' ORDER BY id") as $m) {
    $modules[(int) $m['id']] = ['id' => (int) $m['id'], 'name' => trim($m['module_name']),
        'file' => trim($m['module_file']), 'type' => trim((string) $m['module_type']), 'tables' => []];
}

$patterns = [
    '/\b(?:from|join|into|update)\s+`?([a-z0-9_]+)`?/i',
    '/\b(?:find_a_field|find_all_field|foreign_relation2?|db_insert|db_update|db_delete(?:_all)?|reduncancy_check(?:_all)?|db_last_insert_id|db_fetch_object)\s*\(\s*[\'"]([a-z0-9_]+)[\'"]/i',
];
$refs = []; // [module id][table] => count
foreach ($modules as $id => $m) {
    $dir = $viewsRoot . DIRECTORY_SEPARATOR . $m['file'];
    if ($m['file'] === '' || !is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (strtolower($file->getExtension()) !== 'php' || preg_match(BACKUP_TABLE, $file->getFilename())) {
            continue;
        }
        $src = @file_get_contents($file->getPathname());
        if ($src === false) {
            continue;
        }
        foreach ($patterns as $p) {
            if (preg_match_all($p, $src, $mm)) {
                foreach ($mm[1] as $name) {
                    $name = strtolower($name);
                    if (isset($tables[$name])) {
                        $refs[$id][$name] = ($refs[$id][$name] ?? 0) + 1;
                    }
                }
            }
        }
    }
}
$log(sprintf('source scan: %d modules with table references', count($refs)));

// ------------------------------------------------------------------------- 3. security rules + assembly
$tableModules = []; // table => [module ids]
foreach ($refs as $id => $ts) {
    foreach ($ts as $t => $n) {
        $tableModules[$t][] = $id;
    }
}

$pkIndex = []; // distinctive single-column primary key name => table (for join hints)
foreach ($tables as $t => $info) {
    if (count($info['pk']) === 1 && strtolower($info['pk'][0]) !== 'id') {
        $pkIndex[strtolower($info['pk'][0])][] = $info['name'];
    }
}

$catalog = ['builtAt' => date('c'), 'database' => $schemaName, 'modules' => [], 'tables' => []];
$stats = ['kept' => 0, 'empty' => 0, 'excluded' => 0, 'backup' => 0, 'unused' => 0, 'confidential' => 0, 'hiddenColumns' => 0];

foreach ($tables as $t => $info) {
    if (preg_match(EXCLUDE_TABLE, $t)) {
        $stats['excluded']++;
        continue;
    }
    if (preg_match(BACKUP_TABLE, $t)) {
        $stats['backup']++;
        continue;
    }
    if ($info['rows'] === 0) {
        $stats['empty']++;
        continue;
    }
    $mods = $tableModules[$t] ?? [];
    if ($mods === []) {
        $stats['unused']++; // no active module's code touches it
        continue;
    }

    $columns = [];
    $hidden = [];
    $confidential = (bool) preg_match(CONFIDENTIAL_TABLE, $t);
    foreach ($info['columns'] as $c) {
        if (preg_match(SENSITIVE_COLUMN, $c['name'])) {
            $hidden[] = $c['name'];
            continue;
        }
        if (preg_match(SALARY_COLUMN, $c['name'])) {
            $confidential = true;
        }
        $columns[] = $c;
    }
    if ($columns === []) {
        $stats['excluded']++;
        continue;
    }
    $stats['hiddenColumns'] += count($hidden);
    $stats['confidential'] += (int) $confidential;

    // join hints: declared FKs + columns named like another table's distinctive primary key
    $relations = [];
    foreach ($info['fk'] as [$col, $refTable, $refCol]) {
        $relations[] = "$col -> $refTable.$refCol";
    }
    foreach ($columns as $c) {
        $key = strtolower($c['name']);
        if (isset($pkIndex[$key]) && !in_array($key, array_map('strtolower', $info['pk']), true)) {
            foreach (array_slice($pkIndex[$key], 0, 2) as $target) {
                $relations[] = "{$c['name']} -> $target.{$c['name']}";
            }
        }
    }

    $colNames = array_column($columns, 'name');
    $catalog['tables'][$info['name']] = [
        'modules' => array_values(array_unique($mods)),
        'shared' => count(array_unique($mods)) >= SHARED_MODULE_COUNT,
        'confidential' => $confidential,
        'rows' => $info['rows'],
        'pk' => $info['pk'],
        'groupFor' => in_array('group_for', $colNames, true),
        'columns' => $columns,
        'hiddenColumns' => $hidden,
        'relations' => array_values(array_unique($relations)),
    ];
    $stats['kept']++;
}

foreach ($modules as $id => $m) {
    $mine = array_keys(array_filter($catalog['tables'], fn($t) => in_array($id, $t['modules'], true) && !$t['shared']));
    sort($mine);
    $catalog['modules'][(string) $id] = ['name' => $m['name'], 'file' => $m['file'], 'type' => $m['type'], 'tables' => $mine];
}
$shared = array_keys(array_filter($catalog['tables'], fn($t) => $t['shared']));
sort($shared);
$catalog['sharedTables'] = $shared;

$file = Config::get('catalogFile');
file_put_contents($file, AI_CHATBOT_FILE_GUARD . json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$log(sprintf('kept %d tables (%d shared, %d confidential); dropped %d empty, %d backup copies, %d excluded, %d unused; %d sensitive columns hidden',
    $stats['kept'], count($shared), $stats['confidential'], $stats['empty'], $stats['backup'], $stats['excluded'],
    $stats['unused'], $stats['hiddenColumns']));
$log(sprintf('wrote %s in %.1fs', $file, microtime(true) - $started));
