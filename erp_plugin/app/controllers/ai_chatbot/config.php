<?php
/**
 * AI Chatbot plug-in - settings (no secrets here).
 * Secrets (API keys, the read-only DB account's password) go in config.local.php next to
 * this file, which is merged over these values and never copied into any repository.
 */
return [
    // ------------------------------------------------------------------ AI provider
    'provider' => 'gemini',                 // 'gemini' | 'groq'
    'temperature' => 0,                     // deterministic SQL generation
    'timeoutSeconds' => 45,
    'maxToolRounds' => 8,                   // role check + table lookups + queries + answer
    'providers' => [
        'gemini' => [
            'type' => 'gemini',
            'model' => 'gemini-3.6-flash',
            'baseUrl' => 'https://generativelanguage.googleapis.com/v1beta',
            'apiKey' => '',                 // config.local.php
        ],
        'groq' => [
            'type' => 'openai-compatible',
            'model' => 'openai/gpt-oss-120b',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'apiKey' => '',                 // config.local.php
        ],
    ],
    // PHP on Windows often has no CA bundle: the OS certificate store is used (TLS stays ON).
    'caBundle' => null,

    // ------------------------------------------------------------------ database
    // The read-only account that executes AI-generated SQL, per tenant database (the ERP is
    // multi-tenant: $_SESSION['db_name'] says which tenant the user signed into).
    // It holds SELECT on the catalogued tables/columns and the v_* self-service views only.
    'aiAccounts' => [
        // 'erp_training' => ['username' => 'erp_ai_ro', 'password' => '...'],   // config.local.php
    ],
    'dbHost' => 'localhost',
    'dbPort' => 3306,

    // ------------------------------------------------------------------ query limits
    'maxRows' => 200,                       // LIMIT appended / rewritten down to this
    'statementTimeoutMs' => 5000,           // MAX_EXECUTION_TIME on the AI connection
    'maxQueryRetries' => 2,                 // a failing (not refused) query gets this many fixes
    'describeMaxTables' => 6,               // tables per describeTables call

    // ------------------------------------------------------------------ catalogue
    // Built by `php install/build_catalog.php`; which tables/columns the AI may know about.
    'catalogFile' => __DIR__ . '/data/catalog.json.php',
];
