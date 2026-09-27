<?php

$db = require __DIR__ . '/db.php';
// test database! Important not to run tests on production or development databases
$db['dsn'] = str_replace('dbname=erp_demo', 'dbname=erp_demo_test', $db['dsn']);

return $db;
