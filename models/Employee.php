<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * An employee, and the logged-in identity.
 *
 * The session stores only the employee id. Role and department are re-read from the
 * employees table on every request, so they can never come from the request body.
 * (No Yii RBAC: the security boundary is the MySQL grant, not a PHP permission check.)
 *
 * @property int $id
 * @property string $emp_code
 * @property string $full_name
 * @property string $email
 * @property string $password_hash
 * @property string $auth_key
 * @property string $role
 * @property int|null $department_id
 * @property int|null $manager_id
 * @property string $designation
 * @property string $join_date
 * @property string $status
 * @property string $created_at
 *
 * @property-read Department|null $department
 */
class Employee extends ActiveRecord implements IdentityInterface
{
    public const ROLE_LABELS = [
        'employee' => 'Employee',
        'manager' => 'Manager',
        'dept_head' => 'Department Head',
        'hr' => 'HR',
        'ceo' => 'CEO',
    ];

    public static function tableName(): string
    {
        return '{{%employees}}';
    }

    public function getDepartment(): ActiveQuery
    {
        return $this->hasOne(Department::class, ['id' => 'department_id']);
    }

    public function getRoleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    public static function findIdentity($id): ?static
    {
        return static::findOne(['id' => $id, 'status' => 'active']);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        return null; // no API tokens in this demo
    }

    public static function findByEmail(string $email): ?static
    {
        return static::findOne(['email' => strtolower(trim($email)), 'status' => 'active']);
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getAuthKey(): string
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->auth_key !== '' && hash_equals($this->auth_key, (string) $authKey);
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }
}
