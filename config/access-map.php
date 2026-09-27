<?php

/**
 * ACCESS MAP - the single source of truth for what each role's AI may query.
 *
 * Read by BOTH:
 *   - app\components\ai\PromptBuilder  (the model is only ever told about these views)
 *   - app\components\ai\SqlValidator   (any other identifier is rejected before execution)
 *
 * `scope` says which session-bound parameter a view REQUIRES:
 *   'me'   -> WHERE <column> = :me    (:me   = logged-in employee id, bound by PHP from the session)
 *   'dept' -> WHERE <column> = :dept  (:dept = logged-in employee's department id, from the session)
 *   null   -> no row restriction (the view itself is already safe for that tier)
 *
 * Views decide WHICH COLUMNS; the bound parameter decides WHICH ROWS.
 */

$directory = ['v_employee_directory'];
$self = ['v_my_profile', 'v_my_leave_balance', 'v_my_leave_requests', 'v_my_attendance'];
$team = ['v_team_employees', 'v_team_leave_requests', 'v_team_attendance'];
$dept = ['v_dept_employees', 'v_dept_leave_requests', 'v_dept_attendance', 'v_dept_leave_summary'];
$hr = ['v_hr_employees_full', 'v_hr_payroll', 'v_hr_leave_all', 'v_hr_leave_balances_all', 'v_hr_attendance_all'];
$exec = ['v_exec_headcount_summary', 'v_exec_payroll_summary', 'v_exec_leave_summary'];

return [
    // Hard cap on rows returned per query. A missing LIMIT gets this appended;
    // a larger LIMIT is rewritten down to it.
    'maxRows' => 200,

    'roles' => [
        'employee' => [...$directory, ...$self],
        'manager' => [...$directory, ...$self, ...$team],
        'dept_head' => [...$directory, ...$self, ...$dept],
        'hr' => [...$directory, ...$self, ...$hr],
        'ceo' => [...$directory, ...$self, ...$hr, ...$exec],
    ],

    // Role-specific phrasing hints for the prompt ("my team" means different rows per tier).
    'roleGuidance' => [
        'employee' => 'This user can only see their own records plus the company directory.',
        'manager' => '"My team" / "my reports" means this user\'s direct reports: use the v_team_* views with manager_id = :me. For the user\'s own records use the v_my_* views.',
        'dept_head' => '"My team" / "my department" means everyone in this user\'s department: use the v_dept_* views with department_id = :dept. For the user\'s own records use the v_my_* views.',
        'hr' => 'This user is in HR and may see every employee, including salary, leave and attendance, via the v_hr_* views.',
        'ceo' => 'This user is the CEO and may see everything HR can, plus company-wide summaries in the v_exec_* views.',
    ],

    'views' => [
        // -------------------------------------------------------------- shared
        'v_employee_directory' => [
            'scope' => null,
            'subject' => 'the employee directory',
            'description' => 'Company phone-book: name, designation, department and email of every active employee.',
        ],

        // -------------------------------------------------------------- self
        'v_my_profile' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'employee profiles',
            'description' => "The user's own employee record (designation, department, manager, join date).",
        ],
        'v_my_leave_balance' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'leave balances',
            'description' => "The user's own leave balances per leave type and year (entitled, used, remaining).",
        ],
        'v_my_leave_requests' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'leave requests',
            'description' => "The user's own leave requests and their status.",
        ],
        'v_my_attendance' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'attendance records',
            'description' => "The user's own daily attendance (check-in/out, status, hours).",
        ],

        // -------------------------------------------------------------- team (direct reports)
        'v_team_employees' => [
            'scope' => 'me', 'column' => 'manager_id',
            'subject' => "other employees' records",
            'description' => "Employees who report directly to the user.",
        ],
        'v_team_leave_requests' => [
            'scope' => 'me', 'column' => 'manager_id',
            'subject' => "other employees' leave requests",
            'description' => "Leave requests of the user's direct reports.",
        ],
        'v_team_attendance' => [
            'scope' => 'me', 'column' => 'manager_id',
            'subject' => "other employees' attendance",
            'description' => "Daily attendance of the user's direct reports.",
        ],

        // -------------------------------------------------------------- department
        'v_dept_employees' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department-wide employee records',
            'description' => "Everyone in the user's department.",
        ],
        'v_dept_leave_requests' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department-wide leave requests',
            'description' => "Leave requests of everyone in the user's department.",
        ],
        'v_dept_attendance' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department-wide attendance',
            'description' => "Daily attendance of everyone in the user's department.",
        ],
        'v_dept_leave_summary' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department leave statistics',
            'description' => "Leave statistics for the user's department, aggregated by year and leave type.",
        ],

        // -------------------------------------------------------------- HR / CEO
        'v_hr_employees_full' => [
            'scope' => null,
            'subject' => 'salary information',
            'description' => 'Every employee with role, department, manager and CURRENT salary (basic, allowances, gross, monthly BDT).',
        ],
        'v_hr_payroll' => [
            'scope' => null,
            'subject' => 'salary information',
            'description' => 'Salary history for every employee, current and past rows (is_current = 1 marks the current package). Monthly BDT.',
        ],
        'v_hr_leave_all' => [
            'scope' => null,
            'subject' => 'company-wide leave requests',
            'description' => 'All leave requests across the company.',
        ],
        'v_hr_leave_balances_all' => [
            'scope' => null,
            'subject' => 'company-wide leave balances',
            'description' => 'Leave balances of every employee per leave type and year.',
        ],
        'v_hr_attendance_all' => [
            'scope' => null,
            'subject' => 'company-wide attendance',
            'description' => 'Daily attendance of every employee.',
        ],

        // -------------------------------------------------------------- CEO aggregates
        'v_exec_headcount_summary' => [
            'scope' => null,
            'subject' => 'executive summaries',
            'description' => 'Active headcount per department.',
        ],
        'v_exec_payroll_summary' => [
            'scope' => null,
            'subject' => 'salary information',
            'description' => 'Current payroll per department: headcount, total/average/min/max gross monthly salary (BDT).',
        ],
        'v_exec_leave_summary' => [
            'scope' => null,
            'subject' => 'executive summaries',
            'description' => 'Company-wide leave statistics by department, year and leave type.',
        ],
    ],

    // Plain-English subject used when a query names a BASE TABLE (never allowed for any role).
    'baseTableSubjects' => [
        'salaries' => 'salary information',
        'employees' => 'raw employee records',
        'departments' => 'raw department records',
        'leave_requests' => 'raw leave records',
        'leave_balances' => 'raw leave records',
        'leave_types' => 'raw leave records',
        'attendance' => 'raw attendance records',
        'company_info' => 'the knowledge base tables',
        'chat_audit_log' => 'the audit log',
    ],
];
