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
    'turnBudgetSeconds' => 90,            // wall-clock cap for one question (all AI calls + retries)
    'providers' => [
        'gemini' => [
            'type' => 'gemini',
            'model' => 'gemini-3.7-flash',       // default; the chat's settings panel can change it
            // shown in the settings panel if Google's live model list cannot be loaded
            'fallbackModels' => ['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash'],
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

    // ------------------------------------------------------------------ which companies
    // Company ids (the ERP login's "company id", $_SESSION['proj_id']) that get the chatbot. Every other
    // company on the same server sees no widget, and its database is never touched.
    'enabledCompanies' => ['training'],

    // ERP usernames that see the gear icon in the chat and may change the AI key and model.
    'settingsAdmins' => ['bimol'],

    // ------------------------------------------------------------------ database
    // The read-only account that executes AI-generated SQL, per tenant database (the ERP is
    // multi-tenant: $_SESSION['db_name'] says which tenant the user signed into).
    // It holds SELECT on the catalogued tables/columns and the v_* self-service views only.
    'aiAccounts' => [
        // 'erp_training' => ['username' => 'erp_ai_ro', 'password' => '...'],   // config.local.php
    ],
    // No aiAccounts entry for a company? Run the AI's SQL on the company's own ERP connection
    // instead, locked to read-only for that session. The validator, the module allowlist and the
    // column rewrite still apply; only the MySQL-level column grants (layer 1) are missing. A
    // dedicated SELECT-only user (install/apply_grants.php, or cPanel) is the stronger setup.
    'tenantAccountFallback' => true,
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
