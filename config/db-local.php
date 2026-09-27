<?php

/**
 * Local database settings - copy to config/db-local.php (gitignored) and fill in.
 *
 * Both connections share host/port/dbname; only the account differs.
 *   app -> erp_app   : full privileges on erp_demo (application + migrations)
 *   ai  -> erp_ai_ro : SELECT on the v_* views only (see sql/01-ai-readonly-user.sql)
 *
 * Use whichever host string actually authenticates for you (127.0.0.1 vs localhost):
 * MySQL matches 'user'@'localhost' and 'user'@'127.0.0.1' as different accounts.
 */
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'erp_demo',
    'app' => [
        'username' => 'erp_app',
        'password' => 'ErpApp_c637382ff4aa38a1c403e898',
    ],
    'ai' => [
        'username' => 'erp_ai_ro',
        'password' => 'ErpAiRo_a9a63c1aff46a9ef79595de8',
    ],
    // Yii cookie validation secret - any long random string.
    'cookieValidationKey' => '061a81b3d7871fab2836464efa4c54b98a5f1306b9dc3427',
];
