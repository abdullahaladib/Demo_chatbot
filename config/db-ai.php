<?php

/**
 * `dbAi` - the restricted connection used ONLY to execute AI-generated SQL
 * (MySQL account: erp_ai_ro, which holds SELECT on the v_* views and nothing else).
 *
 * Lazy-loaded: Yii does not open this connection until something calls it.
 */
$local = require __DIR__ . '/db-local.php';

return [
    'class' => \yii\db\Connection::class,
    'dsn' => "mysql:host={$local['host']};port={$local['port']};dbname={$local['dbname']}",
    'username' => $local['ai']['username'],
    'password' => $local['ai']['password'],
    'charset' => 'utf8mb4',
    // Real server-side prepared statements, so :me / :dept are bound by MySQL rather
    // than substituted into the SQL string by PDO.
    'attributes' => [
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
    'on afterOpen' => static function (\yii\base\Event $event): void {
        $event->sender->createCommand(
            // 5-second statement timeout; session read-only as belt-and-braces (the grant
            // is the real control); and an explicit sql_mode WITHOUT ANSI_QUOTES and
            // NO_BACKSLASH_ESCAPES, so MySQL tokenises strings exactly as SqlValidator does.
            "SET SESSION MAX_EXECUTION_TIME = 5000, SESSION transaction_read_only = 1, "
            . "SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,"
            . "NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        )->execute();
    },
];
