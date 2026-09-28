<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * One ai_chat_audit_log row per chat turn - info, data, refusals and errors alike.
 * Written with the tenant's own ERP account (the AI account cannot touch it).
 * employee_id = the asker's pbi_id (NULL for a login without an employee), erp_user_id = the ERP
 * login (user_activity_management.user_id). Audit rows have no FK.
 */
final class Audit
{
    public static function record(array $f): void
    {
        try {
            $stmt = Db::app()->prepare(
                'INSERT INTO ai_chat_audit_log (employee_id, erp_user_id, role, question, path, generated_sql, denial_reason,
                     row_count, latency_ms, provider, model)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $f['employee_id'] ?? null,
                $f['user_id'] ?? null,
                isset($f['role']) ? substr((string) $f['role'], 0, 20) : null,
                mb_substr((string) ($f['question'] ?? ''), 0, 65000),
                $f['path'],
                $f['generated_sql'] ?? null,
                isset($f['denial_reason']) ? mb_substr((string) $f['denial_reason'], 0, 500) : null,
                $f['row_count'] ?? null,
                $f['latency_ms'] ?? null,
                isset($f['provider']) ? substr((string) $f['provider'], 0, 30) : null,
                isset($f['model']) ? substr((string) $f['model'], 0, 80) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit log write failed: ' . $e->getMessage()); // never turn a refusal into a crash
        }
    }
}
