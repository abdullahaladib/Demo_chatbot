<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * One row per chat turn - info answers, data answers, refusals and errors alike.
 * Written through the privileged `db` connection; erp_ai_ro cannot read or write it.
 *
 * @property int $id
 * @property int|null $employee_id
 * @property string|null $role
 * @property string $question
 * @property string $path          info | data | denied | error
 * @property string|null $generated_sql
 * @property string|null $denial_reason
 * @property int|null $row_count
 * @property int|null $latency_ms
 * @property string|null $provider
 * @property string|null $model
 * @property string $created_at
 */
class ChatAuditLog extends ActiveRecord
{
    public const PATH_INFO = 'info';
    public const PATH_DATA = 'data';
    public const PATH_DENIED = 'denied';
    public const PATH_ERROR = 'error';

    public static function tableName(): string
    {
        return '{{%chat_audit_log}}';
    }

    /**
     * Writes one audit row. Never throws: a failed audit write is logged loudly but must
     * not turn a refusal into a crash.
     */
    public static function record(array $fields): ?self
    {
        $row = new self();
        $row->setAttributes([
            'employee_id' => $fields['employee_id'] ?? null,
            'role' => $fields['role'] ?? null,
            'question' => mb_substr((string) ($fields['question'] ?? ''), 0, 65000),
            'path' => $fields['path'],
            'generated_sql' => $fields['generated_sql'] ?? null,
            'denial_reason' => isset($fields['denial_reason']) ? mb_substr($fields['denial_reason'], 0, 500) : null,
            'row_count' => $fields['row_count'] ?? null,
            'latency_ms' => $fields['latency_ms'] ?? null,
            'provider' => $fields['provider'] ?? null,
            'model' => $fields['model'] ?? null,
        ], false);

        try {
            if ($row->save(false)) {
                return $row;
            }
        } catch (\Throwable $e) {
            \Yii::error('Audit log write failed: ' . $e->getMessage(), __METHOD__);
        }
        return null;
    }
}
