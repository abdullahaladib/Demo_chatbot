<?php

/**
 * ACCESS MAP - the single source of truth for what each role's AI may query, over the
 * imported training ERP (erp_training).
 *
 * Read by BOTH:
 *   - app\components\ai\PromptBuilder  (the model is only ever told about these views)
 *   - app\components\ai\SqlValidator   (any other identifier - including all 1288 ERP base
 *                                       tables and the internal ai_* helper views - is rejected)
 *
 * `scope` says which session-bound parameter a view REQUIRES:
 *   'me'   -> WHERE <column> = :me    (:me   = signed-in employee's pbi_id, bound from the session)
 *   'dept' -> WHERE <column> = :dept  (:dept = the department the signed-in role is scoped to)
 *   null   -> no row restriction (the view itself is already safe for that tier)
 *
 * Views decide WHICH COLUMNS; the bound parameter decides WHICH ROWS.
 * Tiers are resolved server-side by app\components\RoleResolver (never from the request).
 */

$directory = ['v_employee_directory'];
$self = ['v_my_profile', 'v_my_leave_requests', 'v_my_leave_balance', 'v_my_attendance_daily', 'v_my_attendance_monthly'];
$team = ['v_team_members', 'v_team_leave_requests', 'v_team_attendance_daily', 'v_team_attendance_monthly'];
$dept = ['v_dept_employees', 'v_dept_leave_requests', 'v_dept_attendance_monthly', 'v_dept_leave_summary'];
$hr = ['v_hr_employees_full', 'v_hr_payroll', 'v_hr_leave_all', 'v_hr_leave_balances_all',
    'v_hr_attendance_daily_all', 'v_hr_attendance_monthly_all', 'v_hr_separations'];
$exec = ['v_exec_headcount_summary', 'v_exec_payroll_summary', 'v_exec_leave_summary', 'v_exec_attendance_summary'];

$teamNote = 'One row per (team member, supervisor) pair; depth 1 = direct report, 2+ = further down the reporting line.';

return [
    // Hard cap on rows returned per query. A missing LIMIT gets this appended;
    // a larger LIMIT is rewritten down to it.
    'maxRows' => 200,

    'roles' => [
        'employee' => [...$directory, ...$self],
        'manager' => [...$directory, ...$self, ...$team],
        'dept_head' => [...$directory, ...$self, ...$team, ...$dept],
        'hr' => [...$directory, ...$self, ...$hr],
        'ceo' => [...$directory, ...$self, ...$team, ...$hr, ...$exec],
    ],

    // Role-specific phrasing hints for the prompt ("my team" means different rows per tier).
    'roleGuidance' => [
        'employee' => 'This user can only see their own records plus the company directory.',
        'manager' => '"My team" / "my reports" means the people in this user\'s reporting line: use the v_team_* views with supervisor_id = :me (depth = 1 for direct reports only). For the user\'s own records use the v_my_* views.',
        'dept_head' => '"My department" / "my team" means everyone in the department this user heads: use the v_dept_* views with department_id = :dept. For people reporting to them personally use v_team_* with supervisor_id = :me. For the user\'s own records use the v_my_* views.',
        'hr' => 'This user is in HR and may see every employee, including salary, leave and attendance, via the v_hr_* views.',
        'ceo' => 'This user is an executive: they may see everything HR can, their own reporting line (v_team_*, supervisor_id = :me), and company-wide summaries in the v_exec_* views.',
    ],

    'views' => [
        // -------------------------------------------------------------- shared
        'v_employee_directory' => [
            'scope' => null,
            'subject' => 'the employee directory',
            'description' => 'Company phone-book: name, designation, department, office email and phone of every active employee.',
        ],

        // -------------------------------------------------------------- self
        'v_my_profile' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'employee profiles',
            'description' => "The user's own employee record (code, designation, department, join/confirmation date, line managers).",
        ],
        'v_my_leave_requests' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'leave requests',
            'description' => "The user's own leave requests. status is Approved / Pending / Cancelled; line_manager_approval is the in-charge's decision.",
        ],
        'v_my_leave_balance' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'leave balances',
            'description' => "The user's own leave balance for the CURRENT year per leave type (entitled, used = approved days, remaining).",
        ],
        'v_my_attendance_daily' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'attendance records',
            'description' => "The user's own daily attendance: check-in/out, status (Present, Late, Absent, On leave, Official duty, Holiday, Day off...), late minutes.",
        ],
        'v_my_attendance_monthly' => [
            'scope' => 'me', 'column' => 'employee_id',
            'subject' => 'attendance records',
            'description' => "The user's own monthly attendance totals (working, present, absent, late, leave days, overtime).",
        ],

        // -------------------------------------------------------------- team (reporting line)
        'v_team_members' => [
            'scope' => 'me', 'column' => 'supervisor_id',
            'subject' => "other employees' records",
            'description' => "Active people in the user's reporting line. $teamNote",
        ],
        'v_team_leave_requests' => [
            'scope' => 'me', 'column' => 'supervisor_id',
            'subject' => "other employees' leave requests",
            'description' => "Leave requests of the user's reporting line. $teamNote",
        ],
        'v_team_attendance_daily' => [
            'scope' => 'me', 'column' => 'supervisor_id',
            'subject' => "other employees' attendance",
            'description' => "Daily attendance of the user's reporting line. $teamNote",
        ],
        'v_team_attendance_monthly' => [
            'scope' => 'me', 'column' => 'supervisor_id',
            'subject' => "other employees' attendance",
            'description' => "Monthly attendance totals of the user's reporting line. $teamNote",
        ],

        // -------------------------------------------------------------- department
        'v_dept_employees' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department-wide employee records',
            'description' => "Active employees of the department the user heads.",
        ],
        'v_dept_leave_requests' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department-wide leave requests',
            'description' => "Leave requests of active employees in the user's department (status Approved / Pending / Cancelled).",
        ],
        'v_dept_attendance_monthly' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department-wide attendance',
            'description' => "Monthly attendance totals of active employees in the user's department.",
        ],
        'v_dept_leave_summary' => [
            'scope' => 'dept', 'column' => 'department_id',
            'subject' => 'department leave statistics',
            'description' => "Leave statistics of the user's department by year and leave type.",
        ],

        // -------------------------------------------------------------- HR
        'v_hr_employees_full' => [
            'scope' => null,
            'subject' => 'salary information',
            'description' => 'Every employee (Active and Former) with department, designation, manager and CURRENT basic and gross monthly salary (BDT; NULL = no salary on record).',
        ],
        'v_hr_payroll' => [
            'scope' => null,
            'subject' => 'salary information',
            'description' => 'Salary structure per employee: basic, allowances, gross, total, provident fund, income tax, payment method. Monthly BDT.',
        ],
        'v_hr_leave_all' => [
            'scope' => null,
            'subject' => 'company-wide leave requests',
            'description' => 'All leave requests across the company.',
        ],
        'v_hr_leave_balances_all' => [
            'scope' => null,
            'subject' => 'company-wide leave balances',
            'description' => 'Current-year leave balances of every active employee per leave type.',
        ],
        'v_hr_attendance_daily_all' => [
            'scope' => null,
            'subject' => 'company-wide attendance',
            'description' => 'Daily attendance of every employee.',
        ],
        'v_hr_attendance_monthly_all' => [
            'scope' => null,
            'subject' => 'company-wide attendance',
            'description' => 'Monthly attendance totals of every employee.',
        ],
        'v_hr_separations' => [
            'scope' => null,
            'subject' => 'employee separation records',
            'description' => 'Former employees with join and resignation dates.',
        ],

        // -------------------------------------------------------------- executive aggregates
        'v_exec_headcount_summary' => [
            'scope' => null,
            'subject' => 'executive summaries',
            'description' => 'Active headcount per department, and how many joined this year.',
        ],
        'v_exec_payroll_summary' => [
            'scope' => null,
            'subject' => 'salary information',
            'description' => 'Payroll per department (total/avg/min/max gross monthly BDT). Figures are NULL (suppressed = yes) for groups under 5 people.',
        ],
        'v_exec_leave_summary' => [
            'scope' => null,
            'subject' => 'executive summaries',
            'description' => 'Company-wide leave statistics by department, year and leave type.',
        ],
        'v_exec_attendance_summary' => [
            'scope' => null,
            'subject' => 'executive summaries',
            'description' => 'Company-wide monthly attendance totals by department.',
        ],
    ],

    // Plain-English subject when a query names an ERP BASE TABLE (never allowed for any role).
    // Anything not listed here is refused as "that data".
    'baseTableSubjects' => [
        'salary_info' => 'salary information',
        'salary_advance' => 'salary information',
        'salary_bonus' => 'salary information',
        'increment_detail' => 'salary information',
        'loan_details' => 'loan records',
        'personnel_basic_info' => 'raw employee records',
        'user_activity_management' => 'login accounts',
        'hrm_leave_info' => 'raw leave records',
        'hrm_att_summary' => 'raw attendance records',
        'hrm_attendence_final' => 'raw attendance records',
        'performance_appraisal_details' => 'performance appraisals',
        'ai_chat_audit_log' => 'the audit log',
        'ai_user_credential' => 'login accounts',
        'ai_role_assignment' => 'role assignments',
        'ai_employee' => 'raw employee records',
        'ai_reporting_line' => 'raw employee records',
        'ai_leave' => 'raw leave records',
        'ai_leave_balance' => 'raw leave records',
        'ai_attendance_daily' => 'raw attendance records',
        'ai_attendance_monthly' => 'raw attendance records',
    ],
];
