<?php
/**
 * Creates the plug-in's own objects in the tenant database (config.local.php 'adminDb'):
 *
 *   php install/install_schema.php
 *
 *   ai_role_assignment  chatbot tier (hr / dept_head / ceo) per employee, where the org chart
 *                       cannot tell; managers and employees are derived automatically
 *   ai_knowledge_base   policy text for information questions (filled by HR; one starter row)
 *   ai_chat_audit_log   one row per chat turn
 *   ai_* and v_* views  from install/views.php (SQL SECURITY DEFINER, so the AI account needs
 *                       no grant on the base tables behind them)
 *
 * Every object is prefixed ai_ / v_ so it can never collide with an ERP table; no ERP table is
 * altered. Idempotent. Then run install/build_catalog.php and install/apply_grants.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // command line only

require_once __DIR__ . '/../bootstrap.php';

use AiChatbot\Db;

Db::useAdminAccount();
$db = Db::app();
$options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$db->exec("CREATE TABLE IF NOT EXISTS ai_role_assignment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pbi_id BIGINT NOT NULL UNIQUE,
    role ENUM('hr','dept_head','ceo') NOT NULL,
    dept_id INT NULL COMMENT 'department a dept_head is head of (NULL = their own)',
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) $options");

$db->exec("CREATE TABLE IF NOT EXISTS ai_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) $options");
if ((int) $db->query('SELECT COUNT(*) FROM ai_knowledge_base')->fetchColumn() === 0) {
    $db->prepare('INSERT INTO ai_knowledge_base (section, title, body) VALUES (?, ?, ?)')->execute(['assistant', 'About this assistant',
        'This assistant answers policy questions from this knowledge base, and ERP data questions only within the '
        . 'modules and records your ERP login is allowed to see. It cannot change any data.']);
}

$db->exec("CREATE TABLE IF NOT EXISTS ai_chat_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT NULL COMMENT 'pbi_id of the asker (NULL for logins without an employee)',
    erp_user_id INT NULL COMMENT 'user_activity_management.user_id',
    role VARCHAR(20) NULL,
    question TEXT NOT NULL,
    path ENUM('info','data','denied','error') NOT NULL,
    generated_sql TEXT NULL,
    denial_reason VARCHAR(500) NULL,
    row_count INT NULL,
    latency_ms INT NULL,
    provider VARCHAR(30) NULL,
    model VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_audit_employee (employee_id, created_at),
    KEY idx_ai_audit_path (path, created_at)
) $options");
// tables created by an older install (the Demo_chatbot migrations) lack erp_user_id
$has = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'ai_chat_audit_log' AND COLUMN_NAME = 'erp_user_id'")->fetchColumn();
if (!(int) $has) {
    $db->exec("ALTER TABLE ai_chat_audit_log ADD COLUMN erp_user_id INT NULL COMMENT 'user_activity_management.user_id' AFTER employee_id,
               ADD KEY idx_ai_audit_user (erp_user_id, created_at)");
}

// Views keep the collation of the connection that created them: Db sets the server default
// (SET NAMES utf8mb4), the same as every query that later reads them.
$views = require __DIR__ . '/views.php';
foreach ($views as $name => $select) {
    $db->exec("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `$name` AS\n$select");
}

printf("%s: ai_role_assignment, ai_knowledge_base, ai_chat_audit_log ready; %d views created or refreshed\n",
    Db::tenant(), count($views));
