<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * Who is asking - built ONLY from the ERP's authenticated session (set by the ERP's own
 * login, check_login.php), then enriched from the tenant database. Nothing comes from the
 * request body.
 *
 *   userId   $_SESSION['user']['id']     user_activity_management.user_id
 *   group    $_SESSION['user']['group']  company the user is signed into (group_for)
 *   pbiId    user_activity_management.PBI_ID -> personnel_basic_info (null for logins
 *            that are not employees, e.g. system administrators)
 *   tier     chatbot tier: ceo | hr | dept_head | manager | employee | none (no employee record)
 *   modules  ERP module ids enabled for the user (user_module_define, status enable)
 */
final class Identity
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $name,
        public readonly int $group,
        public readonly ?int $pbiId,
        public readonly ?int $departmentId,
        public readonly string $tier,
        public readonly array $modules,
        public readonly string $designation = '',
    ) {
    }

    public const TIER_LABELS = [
        'ceo' => 'Executive', 'hr' => 'HR', 'dept_head' => 'Department Head',
        'manager' => 'Manager', 'employee' => 'Employee', 'none' => 'ERP User',
    ];

    public function tierLabel(): string
    {
        return self::TIER_LABELS[$this->tier] ?? $this->tier;
    }

    /** Signed-in ERP user, or null if the session is not an active ERP login. */
    public static function fromErpSession(array $session): ?self
    {
        if (($session['mhafuz'] ?? '') !== 'Active' || empty($session['user']['id']) || empty($session['db_name'])) {
            return null;
        }
        Db::useErpSession($session);
        return self::load((int) $session['user']['id'], (int) ($session['user']['group'] ?? 0));
    }

    /** Resolve a user from the tenant database (also used by the CLI tests). */
    public static function load(int $userId, ?int $group = null): ?self
    {
        $db = Db::app();
        $u = $db->prepare('SELECT user_id, username, fname, designation, group_for, PBI_ID FROM user_activity_management WHERE user_id = ?');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user) {
            return null;
        }

        $pbi = (int) $user['PBI_ID'] > 0 ? (int) $user['PBI_ID'] : null;
        $employee = null;
        if ($pbi !== null) {
            $e = $db->prepare("SELECT pbi_id, pbi_name, dept_id, pbi_job_status FROM personnel_basic_info WHERE pbi_id = ?");
            $e->execute([$pbi]);
            $employee = $e->fetch() ?: null;
            if (!$employee || $employee['pbi_job_status'] !== 'In Service') {
                $pbi = null; // former employee: no self-service scope
                $employee = null;
            }
        }

        $m = $db->prepare("SELECT d.module_id FROM user_module_define d JOIN user_module_manage m ON m.id = d.module_id
                           WHERE d.user_id = ? AND d.status = 'enable' AND m.status = 'Yes'");
        $m->execute([$userId]);
        $modules = array_map('intval', $m->fetchAll(\PDO::FETCH_COLUMN));

        [$tier, $dept] = self::resolveTier($pbi, $employee);

        return new self(
            (int) $user['user_id'],
            (string) $user['username'],
            trim((string) ($employee['pbi_name'] ?? $user['fname'] ?? '')) ?: (string) $user['username'],
            $group ?? (int) $user['group_for'],
            $pbi,
            $dept,
            $tier,
            $modules,
            trim((string) $user['designation']),
        );
    }

    /**
     * Tier from ORG DATA, never from the ERP's `level` (a module privilege; most users are
     * "Supreme Administrator"): explicit ai_role_assignment > line manager of an in-service
     * employee > employee. Logins without an employee record get 'none'.
     *
     * @return array{0:string, 1:?int} tier, department the tier is scoped to
     */
    private static function resolveTier(?int $pbi, ?array $employee): array
    {
        if ($pbi === null) {
            return ['none', null];
        }
        $db = Db::app();
        $ownDept = (int) ($employee['dept_id'] ?? 0) > 0 ? (int) $employee['dept_id'] : null;

        $a = $db->prepare('SELECT role, dept_id FROM ai_role_assignment WHERE pbi_id = ?');
        $a->execute([$pbi]);
        if ($row = $a->fetch()) {
            return [$row['role'], $row['dept_id'] !== null ? (int) $row['dept_id'] : $ownDept];
        }

        $r = $db->prepare("SELECT COUNT(*) FROM personnel_basic_info
                           WHERE pbi_job_status = 'In Service' AND pbi_id <> ? AND (incharge_id = ? OR incharge_id_2 = ?)");
        $r->execute([$pbi, $pbi, $pbi]);
        return [(int) $r->fetchColumn() > 0 ? 'manager' : 'employee', $ownDept];
    }
}
