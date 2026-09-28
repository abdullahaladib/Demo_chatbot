<?php

declare(strict_types=1);

namespace app\models;

use app\components\RoleResolver;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * An employee of the training ERP (`personnel_basic_info`), and the signed-in identity.
 *
 * The session stores only pbi_id. Everything else - including the chatbot authority tier -
 * is re-derived from the database on every request (RoleResolver), never taken from the
 * request body. No Yii RBAC: the security boundary is the MySQL grant, not a PHP check.
 *
 * The rest of the app talks to this class through a small stable surface, so the security
 * code did not change when the database was replaced:
 *   id            -> pbi_id           (bound as :me)
 *   department_id -> the department the role is scoped to (bound as :dept)
 *   role          -> employee | manager | dept_head | hr | ceo
 *   full_name, email, designation, department (relation), getRoleLabel()
 *
 * @property int $pbi_id
 * @property string|null $pbi_code
 * @property string $pbi_name
 * @property string|null $pbi_email
 * @property int|null $dept_id
 * @property int|null $desg_id
 * @property int|null $incharge_id
 * @property int|null $incharge_id_2
 * @property string|null $pbi_job_status
 *
 * @property-read int $id
 * @property-read string $role
 * @property-read int|null $department_id
 * @property-read string $full_name
 * @property-read string $email
 * @property-read string $designation
 * @property-read Department|null $department
 */
class Employee extends ActiveRecord implements IdentityInterface
{
    public const ACTIVE_STATUS = 'In Service';

    public const ROLE_LABELS = [
        'employee' => 'Employee',
        'manager' => 'Manager',
        'dept_head' => 'Department Head',
        'hr' => 'HR',
        'ceo' => 'Executive',
    ];

    public static function tableName(): string
    {
        return 'personnel_basic_info';
    }

    public static function primaryKey(): array
    {
        return ['pbi_id'];
    }

    // ------------------------------------------------------------------ stable surface

    public function getId(): int
    {
        return (int) $this->pbi_id;
    }

    public function getRole(): string
    {
        return RoleResolver::resolve($this)['role'];
    }

    /** Department the role is scoped to (a dept head's assigned department, else their own). */
    public function getDepartment_id(): ?int
    {
        return RoleResolver::resolve($this)['dept'];
    }

    public function getFull_name(): string
    {
        return trim((string) $this->pbi_name);
    }

    public function getEmail(): string
    {
        return (string) $this->pbi_email;
    }

    public function getDesignation(): string
    {
        return trim((string) ($this->designationRecord->DESG_DESC ?? '')) ?: '-';
    }

    public function getRoleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    public function getDepartment(): ActiveQuery
    {
        return $this->hasOne(Department::class, ['DEPT_ID' => 'dept_id']);
    }

    public function getDesignationRecord(): ActiveQuery
    {
        return $this->hasOne(Designation::class, ['DESG_ID' => 'desg_id']);
    }

    public function getErpUser(): ActiveQuery
    {
        return $this->hasOne(ErpUser::class, ['PBI_ID' => 'pbi_id']);
    }

    public function isActive(): bool
    {
        return $this->pbi_job_status === self::ACTIVE_STATUS;
    }

    /**
     * The curated demo people (params['demoPeople'], ERP usernames), in that order.
     * @return static[]
     */
    public static function demoPeople(): array
    {
        $usernames = \Yii::$app->params['demoPeople'] ?? [];
        if ($usernames === []) {
            return [];
        }
        $ids = ErpUser::find()->select(['PBI_ID', 'username'])->where(['username' => $usernames])
            ->andWhere(['>', 'PBI_ID', 0])->asArray()->all();
        $byUsername = array_column($ids, 'PBI_ID', 'username');
        $people = static::find()->with(['department', 'designationRecord'])
            ->where(['pbi_id' => array_values($byUsername), 'pbi_job_status' => self::ACTIVE_STATUS])
            ->indexBy('pbi_id')->all();
        $ordered = [];
        foreach ($usernames as $u) {
            if (isset($byUsername[$u], $people[$byUsername[$u]])) {
                $ordered[] = $people[$byUsername[$u]];
            }
        }
        return $ordered;
    }

    // ------------------------------------------------------------------ IdentityInterface

    public static function findIdentity($id): ?static
    {
        return static::findOne(['pbi_id' => (int) $id, 'pbi_job_status' => self::ACTIVE_STATUS]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        return null; // no API tokens in this demo
    }

    public function getAuthKey(): ?string
    {
        return null; // cookie auto-login is disabled (the ERP has no per-user auth key)
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }
}
