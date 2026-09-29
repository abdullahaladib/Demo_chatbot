<?php
/**
 * Creates the plug-in's own objects in the tenant database (config.local.php 'adminDb'):
 *
 *   php install/install_schema.php [company-id]      (default: training)
 *
 *   ai_role_assignment  chatbot tier (hr / dept_head / ceo) per employee, where the org chart
 *                       cannot tell; managers and employees are derived automatically
 *   ai_knowledge_base   policy text for information questions (filled by HR; one starter row)
 *   ai_chat_audit_log   one row per chat turn
 *   ai_* and v_* views  from install/views.php (SQL SECURITY DEFINER, so the AI account needs
 *                       no grant on the base tables behind them)
 *
 * Every object is prefixed ai_ / v_ so it can never collide with an ERP table; no ERP table is
 * altered. Idempotent. The same install also runs by itself on a server (AiChatbot\Installer::ensure),
 * the first time someone from an enabled company opens an ERP page.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // command line only

require_once __DIR__ . '/../bootstrap.php';

use AiChatbot\Db;
use AiChatbot\Installer;

Db::useAdminAccount();
$company = $argv[1] ?? 'training';
$done = Installer::install(Db::app(), $company);
printf("%s: ai_role_assignment, ai_knowledge_base, ai_chat_audit_log ready; %d views created or refreshed; "
    . "seeded %d knowledge rows, %d roles (only into empty tables)\n",
    Db::tenant(), $done['views'], $done['knowledge_rows'], $done['roles']);
