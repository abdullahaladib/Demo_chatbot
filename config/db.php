<?php

/**
 * `db` - the privileged application connection (MySQL account: erp_app).
 * Used by the app itself and by migrations. The AI never touches this connection.
 */
$local = require __DIR__ . '/db-local.php';

return [
    'class' => \yii\db\Connection::class,
    'dsn' => "mysql:host={$local['host']};port={$local['port']};dbname={$local['dbname']}",
    'username' => $local['app']['username'],
    'password' => $local['app']['password'],
    'charset' => 'utf8mb4',
];
