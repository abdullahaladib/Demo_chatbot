<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * The generated catalogue (data/catalog.json.php, built by install/build_catalog.php) plus the
 * curated table notes and the self-service views. Read-only at runtime.
 */
final class Catalog
{
    private static ?array $data = null;
    private static ?array $notes = null;

    /**
     * Self-service / scoped VIEWS (created in the tenant by the chatbot's training migrations).
     * scope: 'me' -> <column> = :me (signed-in employee's pbi_id), 'dept' -> <column> = :dept,
     * null -> no row filter. `tiers` = which chatbot tiers may use the view.
     */
    public const VIEWS = [
        'v_employee_directory' => ['scope' => null, 'tiers' => ['*'], 'subject' => 'the employee directory',
            'description' => 'Company phone-book: name, designation, department, office email and phone of active employees.'],
        'v_my_profile' => ['scope' => 'me', 'column' => 'employee_id', 'tiers' => ['employee', 'manager', 'dept_head', 'hr', 'ceo'],
            'subject' => 'employee profiles', 'description' => "The signed-in user's own employee record."],
        'v_my_leave_requests' => ['scope' => 'me', 'column' => 'employee_id', 'tiers' => ['employee', 'manager', 'dept_head', 'hr', 'ceo'],
            'subject' => 'leave requests', 'description' => "The user's own leave requests (status Approved / Pending / Cancelled)."],
        'v_my_leave_balance' => ['scope' => 'me', 'column' => 'employee_id', 'tiers' => ['employee', 'manager', 'dept_head', 'hr', 'ceo'],
            'subject' => 'leave balances', 'description' => "The user's own leave balance for the current year (entitled, used, remaining) per leave type."],
        'v_my_attendance_daily' => ['scope' => 'me', 'column' => 'employee_id', 'tiers' => ['employee', 'manager', 'dept_head', 'hr', 'ceo'],
            'subject' => 'attendance records', 'description' => "The user's own daily attendance (check-in/out, status, late minutes)."],
        'v_my_attendance_monthly' => ['scope' => 'me', 'column' => 'employee_id', 'tiers' => ['employee', 'manager', 'dept_head', 'hr', 'ceo'],
            'subject' => 'attendance records', 'description' => "The user's own monthly attendance totals."],
        'v_team_members' => ['scope' => 'me', 'column' => 'supervisor_id', 'tiers' => ['manager', 'dept_head', 'ceo'],
            'subject' => "other employees' records", 'description' => "Active people in the user's reporting line (depth 1 = direct report)."],
        'v_team_leave_requests' => ['scope' => 'me', 'column' => 'supervisor_id', 'tiers' => ['manager', 'dept_head', 'ceo'],
            'subject' => "other employees' leave requests", 'description' => "Leave requests of the user's reporting line (depth 1 = direct report)."],
        'v_team_attendance_daily' => ['scope' => 'me', 'column' => 'supervisor_id', 'tiers' => ['manager', 'dept_head', 'ceo'],
            'subject' => "other employees' attendance", 'description' => "Daily attendance of the user's reporting line."],
        'v_team_attendance_monthly' => ['scope' => 'me', 'column' => 'supervisor_id', 'tiers' => ['manager', 'dept_head', 'ceo'],
            'subject' => "other employees' attendance", 'description' => "Monthly attendance totals of the user's reporting line."],
        'v_dept_employees' => ['scope' => 'dept', 'column' => 'department_id', 'tiers' => ['dept_head'],
            'subject' => 'department-wide employee records', 'description' => 'Active employees of the department the user heads.'],
        'v_dept_leave_requests' => ['scope' => 'dept', 'column' => 'department_id', 'tiers' => ['dept_head'],
            'subject' => 'department-wide leave requests', 'description' => "Leave requests in the user's department."],
        'v_dept_attendance_monthly' => ['scope' => 'dept', 'column' => 'department_id', 'tiers' => ['dept_head'],
            'subject' => 'department-wide attendance', 'description' => "Monthly attendance totals in the user's department."],
        'v_dept_leave_summary' => ['scope' => 'dept', 'column' => 'department_id', 'tiers' => ['dept_head'],
            'subject' => 'department leave statistics', 'description' => "Leave statistics of the user's department by year and type."],
        'v_exec_payroll_summary' => ['scope' => null, 'tiers' => ['ceo', 'hr'], 'subject' => 'salary information',
            'description' => 'Payroll per department (total/avg/min/max gross monthly); pay figures suppressed for groups under 5.'],
        'v_exec_headcount_summary' => ['scope' => null, 'tiers' => ['ceo', 'hr'], 'subject' => 'executive summaries',
            'description' => 'Active headcount per department.'],
    ];

    public static function data(): array
    {
        if (self::$data === null) {
            $file = Config::get('catalogFile');
            if (!is_file($file)) {
                throw new \RuntimeException('AI chatbot: catalogue missing - run install/build_catalog.php');
            }
            $json = (string) file_get_contents($file);
            if (str_starts_with($json, AI_CHATBOT_FILE_GUARD)) {
                $json = substr($json, strlen(AI_CHATBOT_FILE_GUARD));
            }
            self::$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$data;
    }

    /** @return array<string,array> table name => catalogue entry */
    public static function tables(): array
    {
        return self::data()['tables'];
    }

    public static function table(string $name): ?array
    {
        $tables = self::tables();
        if (isset($tables[$name])) {
            return $tables[$name] + ['name' => $name];
        }
        foreach ($tables as $t => $info) { // MySQL on Windows: table names are case-insensitive
            if (strcasecmp($t, $name) === 0) {
                return $info + ['name' => $t];
            }
        }
        return null;
    }

    /** @return array<string,array> module id => ['name','file','type','tables'] */
    public static function modules(): array
    {
        return self::data()['modules'];
    }

    public static function note(string $table): ?string
    {
        self::$notes ??= require AI_CHATBOT_DIR . '/data/table_notes.php';
        return self::$notes[strtolower($table)] ?? null;
    }

    /** Names the AI must never see whatever the user: every base table of the tenant + our views. */
    public static function isKnownObject(string $lowerName): bool
    {
        return self::table($lowerName) !== null || isset(self::VIEWS[$lowerName]);
    }
}
