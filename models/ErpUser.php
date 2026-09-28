<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * A login of the training ERP (`user_activity_management`), linked to an employee via PBI_ID.
 *
 * Password check, in order:
 *   1. the demo password in the app-owned `ai_user_credential` (bcrypt), if one was issued;
 *   2. otherwise the ERP's own stored hash: unsalted MD5 (74 of 76 rows). This is kept only so
 *      real training-ERP passwords keep working; it is weak by design of the source system and
 *      is called out in the README. Plaintext rows (2) are never accepted.
 * The ERP's password column is never read by the chatbot's views or shown anywhere.
 *
 * @property int $user_id
 * @property string $username
 * @property string $password
 * @property int|null $level
 * @property int|null $PBI_ID
 * @property string|null $status
 */
class ErpUser extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'user_activity_management';
    }

    public static function findByUsername(string $username): ?self
    {
        $username = trim($username);
        return $username === '' ? null : static::findOne(['username' => $username]);
    }

    public function isActive(): bool
    {
        return in_array(strtolower(trim((string) $this->status)), ['active', 'in service'], true);
    }

    public function validatePassword(string $password): bool
    {
        $demoHash = Yii::$app->db->createCommand(
            'SELECT password_hash FROM ai_user_credential WHERE user_id = :id',
            [':id' => (int) $this->user_id]
        )->queryScalar();
        if (is_string($demoHash) && $demoHash !== '') {
            return Yii::$app->security->validatePassword($password, $demoHash);
        }

        $stored = strtolower((string) $this->password);
        return preg_match('/^[a-f0-9]{32}$/', $stored) === 1 && hash_equals($stored, md5($password));
    }

    public function getEmployee(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Employee::class, ['pbi_id' => 'PBI_ID']);
    }
}
