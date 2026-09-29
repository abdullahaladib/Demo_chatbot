<?php

declare(strict_types=1);

namespace AiChatbot;

use PDO;

/**
 * Creates the plug-in's own objects in a company database, so an uploaded ERP works without
 * anyone running command-line scripts on the server:
 *
 *   ai_role_assignment  chatbot tier (hr / dept_head / ceo) where the org chart cannot tell
 *   ai_knowledge_base   policy text for information questions
 *   ai_chat_audit_log   one row per chat turn
 *   ai_* and v_* views  from install/views.php (SQL SECURITY DEFINER)
 *
 * ensure() runs it automatically, once per company database, the first time someone from an
 * enabled company opens an ERP page; a marker file in data/ then skips it. install() is also used
 * by install/install_schema.php. Every object is prefixed ai_ / v_; no ERP table is altered.
 * Starting content (data/seed.php) is written only into EMPTY tables.
 */
final class Installer
{
    /** Bump when the tables or views change, so every company is upgraded on its next visit. */
    public const VERSION = 1;

    public static function ensure(string $company): void
    {
        $tenant = Db::tenant();
        $marker = AI_CHATBOT_RUNTIME_DIR . '/installed.' . preg_replace('/[^A-Za-z0-9_]/', '_', $tenant) . '.php';
        if (self::markerVersion($marker) === self::VERSION) {
            return;
        }

        // one installer at a time (two people may open the first pages together)
        $lock = fopen(AI_CHATBOT_RUNTIME_DIR . '/install.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('AI chatbot: cannot lock data/install.lock');
        }
        try {
            if (self::markerVersion($marker) !== self::VERSION) {
                $done = self::install(Db::app(), $company);
                file_put_contents($marker, AI_CHATBOT_FILE_GUARD
                    . json_encode(['version' => self::VERSION, 'at' => date('c'), 'company' => $company] + $done) . "\n");
                Log::warning("installed chatbot schema v" . self::VERSION . " in $tenant for company $company: " . json_encode($done));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{views:int, knowledge_rows:int, roles:int} */
    public static function install(PDO $db, string $company): array
    {
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
        $views = require AI_CHATBOT_DIR . '/install/views.php';
        foreach ($views as $name => $select) {
            $db->exec("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `$name` AS\n$select");
        }

        return ['views' => count($views)] + self::seed($db, $company);
    }

    /** Starting knowledge base and roles, only into empty tables. */
    private static function seed(PDO $db, string $company): array
    {
        $all = require AI_CHATBOT_DIR . '/data/seed.php';
        $seed = $all[strtolower($company)] ?? null;
        $kbRows = $roles = 0;

        if ((int) $db->query('SELECT COUNT(*) FROM ai_knowledge_base')->fetchColumn() === 0) {
            $insert = $db->prepare('INSERT INTO ai_knowledge_base (section, title, body) VALUES (?, ?, ?)');
            $rows = $seed['knowledge_base'] ?? [[
                'section' => 'assistant', 'title' => 'About this assistant',
                'body' => 'This assistant answers policy questions from this knowledge base, and ERP data questions only '
                    . 'within the modules and records your ERP login is allowed to see. It cannot change any data.',
            ]];
            foreach ($rows as $r) {
                $insert->execute([$r['section'], $r['title'], $r['body']]);
                $kbRows++;
            }
        }

        if ($seed && (int) $db->query('SELECT COUNT(*) FROM ai_role_assignment')->fetchColumn() === 0) {
            $exists = $db->prepare("SELECT COUNT(*) FROM personnel_basic_info WHERE pbi_id = ? AND pbi_job_status = 'In Service'");
            $insert = $db->prepare('INSERT INTO ai_role_assignment (pbi_id, role, dept_id, note) VALUES (?, ?, ?, ?)');
            foreach ($seed['roles'] as $r) {
                $exists->execute([$r['pbi_id']]);
                if ((int) $exists->fetchColumn() > 0) {
                    $insert->execute([$r['pbi_id'], $r['role'], $r['dept_id'], $r['note']]);
                    $roles++;
                }
            }
        }
        return ['knowledge_rows' => $kbRows, 'roles' => $roles];
    }

    private static function markerVersion(string $marker): ?int
    {
        if (!is_file($marker)) {
            return null;
        }
        $json = json_decode(substr((string) file_get_contents($marker), strlen(AI_CHATBOT_FILE_GUARD)), true);
        return isset($json['version']) ? (int) $json['version'] : null;
    }
}
