<?php

declare(strict_types=1);

namespace app\components;

use app\models\Employee;
use Yii;

/**
 * Decides an employee's chatbot authority tier from ORG DATA, server-side, at every request.
 *
 * The training ERP's own `user_activity_management.level` cannot be used: it is a module
 * privilege (Report Viewer, Purchase Officer, ...), and 52 of 76 logins are level 5
 * "Supreme Administrator". So, first match wins:
 *
 *   1. an explicit assignment in `ai_role_assignment`   -> ceo | hr | dept_head
 *      (authority tiers are an HR decision, recorded - never guessed from job titles)
 *   2. line manager of at least one IN-SERVICE employee -> manager
 *      (personnel_basic_info.incharge_id or incharge_id_2 points at them)
 *   3. otherwise                                         -> employee
 *
 * Nothing here reads the request: the Employee comes from the authenticated session.
 */
final class RoleResolver
{
    /** @var array<int, array{role:string, dept:?int}> per-request memo */
    private static array $memo = [];

    /** @return array{role:string, dept:?int} role, and the department the role is scoped to */
    public static function resolve(Employee $employee): array
    {
        $id = (int) $employee->pbi_id;
        if (isset(self::$memo[$id])) {
            return self::$memo[$id];
        }

        $db = Yii::$app->db;
        $assigned = $db->createCommand(
            'SELECT role, dept_id FROM ai_role_assignment WHERE pbi_id = :id',
            [':id' => $id]
        )->queryOne();

        if ($assigned) {
            $result = [
                'role' => $assigned['role'],
                'dept' => $assigned['dept_id'] !== null ? (int) $assigned['dept_id'] : self::ownDept($employee),
            ];
        } else {
            $reports = (int) $db->createCommand(
                "SELECT COUNT(*) FROM personnel_basic_info
                 WHERE pbi_job_status = 'In Service' AND pbi_id <> :id
                   AND (incharge_id = :id OR incharge_id_2 = :id)",
                [':id' => $id]
            )->queryScalar();
            $result = ['role' => $reports > 0 ? 'manager' : 'employee', 'dept' => self::ownDept($employee)];
        }

        return self::$memo[$id] = $result;
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    private static function ownDept(Employee $employee): ?int
    {
        return (int) $employee->dept_id > 0 ? (int) $employee->dept_id : null;
    }
}
