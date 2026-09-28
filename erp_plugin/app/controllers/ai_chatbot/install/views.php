<?php
/**
 * The chatbot's role-scoped views over the ERP tables (SQL SECURITY DEFINER), used by
 * install/install_schema.php. Ported verbatim from the Demo_chatbot training migration
 * m260928_000003_create_role_views.php - keep the two in step.
 *
 *   ai_*  internal helpers (cleaning, joins). NEVER granted to the AI account.
 *   v_*   what the chatbot may query; which tier may use which is in src/Catalog.php (VIEWS),
 *         and only those are granted by install/apply_grants.php.
 *
 * No v_* view exposes passwords, tokens, NID/TIN, DOB, religion, personal phone or bank
 * details. Salary appears only in v_hr_* and v_exec_payroll_summary (min group 5).
 *
 * @return array<string,string> view => SELECT, in dependency order
 */
return (static function (): array {
        $min = 5; // aggregates over fewer people would disclose individual pay
        $validDate = fn(string $col) => "CASE WHEN $col > '1971-01-01' THEN $col END";

        // ---- column lists reused by several views
        $emp = "e.employee_id, e.employee_code, e.full_name, e.designation, e.department_id, e.department,
                e.email, e.office_phone, e.join_date, e.employment_status";
        $leave = "l.request_id, l.employee_id, e.full_name AS employee_name, e.department_id, e.department,
                  l.leave_type, l.start_date, l.end_date, l.days, l.half_or_full, l.reason, l.status,
                  l.line_manager_approval, l.applied_on";
        $daily = "a.employee_id, e.full_name AS employee_name, e.department_id, e.department, a.work_date,
                  a.weekday, a.check_in, a.check_out, a.status, a.late_minutes, a.early_leave_minutes, a.overtime_hours";
        $monthly = "m.employee_id, e.full_name AS employee_name, e.department_id, e.department, m.year, m.month,
                    m.working_days, m.present_days, m.absent_days, m.late_days, m.leave_days, m.leave_without_pay_days,
                    m.holidays, m.days_off, m.overtime_hours, m.total_late_minutes";

        return [
            // =============================================================== internal helpers
            'ai_employee' => "SELECT p.pbi_id AS employee_id, p.pbi_code AS employee_code, TRIM(p.pbi_name) AS full_name,
                    TRIM(g.DESG_DESC) AS designation, p.dept_id AS department_id, TRIM(d.DEPT_DESC) AS department,
                    NULLIF(TRIM(p.pbi_email), '') AS email, NULLIF(TRIM(p.pbi_mobile), '') AS office_phone,
                    {$validDate('p.pbi_doj')} AS join_date, {$validDate('p.pbi_doc2')} AS confirmation_date,
                    {$validDate('p.resign_date')} AS resign_date,
                    CASE WHEN p.pbi_job_status = 'In Service' THEN 'Active' ELSE 'Former' END AS employment_status,
                    NULLIF(p.incharge_id, 0) AS manager_id, NULLIF(p.incharge_id_2, 0) AS second_manager_id,
                    p.leave_rule_id
                FROM personnel_basic_info p
                LEFT JOIN department d ON d.DEPT_ID = p.dept_id
                LEFT JOIN designation g ON g.DESG_ID = p.desg_id",

            // Every (employee, supervisor) pair up the chain, via incharge_id and incharge_id_2.
            // Cycle-safe (path check) and capped at 6 levels; depth 1 = direct report.
            'ai_reporting_line' => "WITH RECURSIVE chain (employee_id, supervisor_id, depth, path) AS (
                    SELECT p.pbi_id, s.sup, 1, CAST(CONCAT(',', p.pbi_id, ',', s.sup, ',') AS CHAR(400))
                    FROM personnel_basic_info p
                    JOIN (SELECT pbi_id, incharge_id AS sup FROM personnel_basic_info
                          UNION SELECT pbi_id, incharge_id_2 FROM personnel_basic_info) s ON s.pbi_id = p.pbi_id
                    WHERE s.sup > 0 AND s.sup <> p.pbi_id
                  UNION ALL
                    SELECT c.employee_id, up.incharge_id, c.depth + 1, CONCAT(c.path, up.incharge_id, ',')
                    FROM chain c
                    JOIN personnel_basic_info up ON up.pbi_id = c.supervisor_id
                    WHERE up.incharge_id > 0 AND c.depth < 6
                      AND LOCATE(CONCAT(',', up.incharge_id, ','), c.path) = 0
                )
                SELECT employee_id, supervisor_id, MIN(depth) AS depth FROM chain GROUP BY employee_id, supervisor_id",

            'ai_leave' => "SELECT l.id AS request_id, l.PBI_ID AS employee_id,
                    CASE WHEN l.type REGEXP '^[0-9]+$' THEN COALESCE(TRIM(lt.leave_type_name), CONCAT('Type ', l.type))
                         WHEN TRIM(l.type) = '' THEN 'Unspecified' ELSE TRIM(l.type) END AS leave_type,
                    l.s_date AS start_date, {$validDate('l.e_date')} AS end_date, l.total_days AS days,
                    l.half_or_full, l.reason,
                    CASE l.leave_status WHEN 'GRANTED' THEN 'Approved' WHEN 'Pending' THEN 'Pending'
                         WHEN 'Cancel' THEN 'Cancelled' ELSE 'Unknown' END AS status,
                    l.incharge_status AS line_manager_approval, {$validDate('l.leave_apply_date')} AS applied_on
                FROM hrm_leave_info l
                JOIN personnel_basic_info p ON p.pbi_id = l.PBI_ID
                LEFT JOIN hrm_leave_type lt ON l.type REGEXP '^[0-9]+$' AND lt.id = CAST(l.type AS UNSIGNED)
                WHERE l.s_date > '1971-01-01'",

            // Current-year entitlement per type: the employee's leave rule if they have one,
            // else the type's yearly quota. Used = approved days starting this year.
            'ai_leave_balance' => "SELECT p.pbi_id AS employee_id, YEAR(CURDATE()) AS year, TRIM(lt.leave_type_name) AS leave_type,
                    COALESCE(r.balance, lt.yearly_leave_days) AS entitled,
                    COALESCE(u.used, 0) AS used,
                    COALESCE(r.balance, lt.yearly_leave_days) - COALESCE(u.used, 0) AS remaining
                FROM personnel_basic_info p
                CROSS JOIN hrm_leave_type lt
                LEFT JOIN hrm_leave_rull_manage r ON p.leave_rule_id > 0 AND r.rule_id = p.leave_rule_id AND r.type REGEXP '^[0-9]+$' AND CAST(r.type AS UNSIGNED) = lt.id
                LEFT JOIN (SELECT PBI_ID, CAST(type AS UNSIGNED) AS type_id, SUM(total_days) AS used
                           FROM hrm_leave_info
                           WHERE leave_status = 'GRANTED' AND type REGEXP '^[0-9]+$' AND YEAR(s_date) = YEAR(CURDATE())
                           GROUP BY PBI_ID, CAST(type AS UNSIGNED)) u ON u.PBI_ID = p.pbi_id AND u.type_id = lt.id
                WHERE lt.status = 'Active' AND lt.leave_type_name NOT LIKE '%test%'
                  AND COALESCE(r.balance, lt.yearly_leave_days) > 0",

            'ai_attendance_daily' => "SELECT a.emp_id AS employee_id, a.att_date AS work_date, a.dayname AS weekday,
                    CASE WHEN a.in_time > '1971-01-01' THEN TIME(a.in_time) END AS check_in,
                    CASE WHEN a.out_time > '1971-01-01' THEN TIME(a.out_time) END AS check_out,
                    CASE WHEN a.leave_id > 0 THEN 'On leave'
                         WHEN a.iom_id > 0 THEN 'Official duty'
                         WHEN a.holyday = 1 AND a.present = 1 THEN 'Worked on holiday'
                         WHEN a.holyday = 1 THEN 'Holiday'
                         WHEN a.final_day_off_status = 1 THEN 'Day off'
                         WHEN a.sch_off_day = 1 AND a.present = 1 THEN 'Worked on day off'
                         WHEN a.absent = 1 OR a.force_absent = 1 THEN 'Absent'
                         WHEN a.present = 1 AND a.final_late_status = 1 THEN 'Late'
                         WHEN a.present = 1 THEN 'Present'
                         ELSE 'No record' END AS status,
                    COALESCE(a.final_late_min, 0) AS late_minutes, COALESCE(a.final_early_min, 0) AS early_leave_minutes,
                    COALESCE(a.ot_final_hour, 0) AS overtime_hours
                FROM hrm_att_summary a
                JOIN personnel_basic_info p ON p.pbi_id = a.emp_id
                WHERE a.att_date > '2000-01-01'",

            'ai_attendance_monthly' => "SELECT f.PBI_ID AS employee_id, f.year, f.mon AS month, f.td AS working_days,
                    f.pre AS present_days, f.ab AS absent_days, f.lt AS late_days, f.lv AS leave_days,
                    f.lwp AS leave_without_pay_days, f.hd AS holidays, f.day_off AS days_off,
                    f.ot AS overtime_hours, f.total_late_min AS total_late_minutes
                FROM hrm_attendence_final f
                JOIN personnel_basic_info p ON p.pbi_id = f.PBI_ID
                WHERE f.year > 2000",

            // =============================================================== everyone
            'v_employee_directory' => "SELECT e.full_name, e.designation, e.department, e.email, e.office_phone
                FROM ai_employee e WHERE e.employment_status = 'Active'",

            // =============================================================== self (:me = employee_id)
            'v_my_profile' => "SELECT $emp, e.confirmation_date, m1.full_name AS manager_name, m2.full_name AS second_manager_name
                FROM ai_employee e
                LEFT JOIN ai_employee m1 ON m1.employee_id = e.manager_id
                LEFT JOIN ai_employee m2 ON m2.employee_id = e.second_manager_id",
            'v_my_leave_requests' => "SELECT l.request_id, l.employee_id, l.leave_type, l.start_date, l.end_date, l.days,
                    l.half_or_full, l.reason, l.status, l.line_manager_approval, l.applied_on
                FROM ai_leave l",
            'v_my_leave_balance' => "SELECT b.employee_id, b.year, b.leave_type, b.entitled, b.used, b.remaining FROM ai_leave_balance b",
            'v_my_attendance_daily' => "SELECT a.employee_id, a.work_date, a.weekday, a.check_in, a.check_out, a.status,
                    a.late_minutes, a.early_leave_minutes, a.overtime_hours FROM ai_attendance_daily a",
            'v_my_attendance_monthly' => "SELECT m.employee_id, m.year, m.month, m.working_days, m.present_days, m.absent_days,
                    m.late_days, m.leave_days, m.leave_without_pay_days, m.holidays, m.days_off, m.overtime_hours,
                    m.total_late_minutes FROM ai_attendance_monthly m",

            // =============================================================== team (:me = supervisor_id)
            // One row per (team member, supervisor) pair; depth 1 = direct report. Active staff only.
            'v_team_members' => "SELECT r.supervisor_id, r.depth, $emp
                FROM ai_reporting_line r JOIN ai_employee e ON e.employee_id = r.employee_id
                WHERE e.employment_status = 'Active'",
            'v_team_leave_requests' => "SELECT r.supervisor_id, r.depth, $leave
                FROM ai_reporting_line r
                JOIN ai_leave l ON l.employee_id = r.employee_id
                JOIN ai_employee e ON e.employee_id = r.employee_id
                WHERE e.employment_status = 'Active'",
            'v_team_attendance_daily' => "SELECT r.supervisor_id, r.depth, $daily
                FROM ai_reporting_line r
                JOIN ai_attendance_daily a ON a.employee_id = r.employee_id
                JOIN ai_employee e ON e.employee_id = r.employee_id
                WHERE e.employment_status = 'Active'",
            'v_team_attendance_monthly' => "SELECT r.supervisor_id, r.depth, $monthly
                FROM ai_reporting_line r
                JOIN ai_attendance_monthly m ON m.employee_id = r.employee_id
                JOIN ai_employee e ON e.employee_id = r.employee_id
                WHERE e.employment_status = 'Active'",

            // =============================================================== department (:dept = department_id)
            'v_dept_employees' => "SELECT $emp, mgr.full_name AS manager_name
                FROM ai_employee e LEFT JOIN ai_employee mgr ON mgr.employee_id = e.manager_id
                WHERE e.employment_status = 'Active'",
            'v_dept_leave_requests' => "SELECT $leave
                FROM ai_leave l JOIN ai_employee e ON e.employee_id = l.employee_id
                WHERE e.employment_status = 'Active'",
            'v_dept_attendance_monthly' => "SELECT $monthly
                FROM ai_attendance_monthly m JOIN ai_employee e ON e.employee_id = m.employee_id
                WHERE e.employment_status = 'Active'",
            'v_dept_leave_summary' => "SELECT e.department_id, e.department, YEAR(l.start_date) AS year, l.leave_type,
                    COUNT(*) AS requests, SUM(l.status = 'Pending') AS pending_requests,
                    SUM(l.status = 'Approved') AS approved_requests,
                    COALESCE(SUM(CASE WHEN l.status = 'Approved' THEN l.days END), 0) AS approved_days,
                    COUNT(DISTINCT l.employee_id) AS employees
                FROM ai_leave l JOIN ai_employee e ON e.employee_id = l.employee_id
                WHERE e.employment_status = 'Active'
                GROUP BY e.department_id, e.department, YEAR(l.start_date), l.leave_type",

            // =============================================================== HR (all rows, salary included)
            'v_hr_employees_full' => "SELECT $emp, e.confirmation_date, e.resign_date, mgr.full_name AS manager_name,
                    s.salary_type, NULLIF(s.basic_salary, 0) AS basic_salary, NULLIF(s.gross_salary, 0) AS gross_salary
                FROM ai_employee e
                LEFT JOIN ai_employee mgr ON mgr.employee_id = e.manager_id
                LEFT JOIN salary_info s ON s.PBI_ID = e.employee_id",
            // Salary detail - bank account number and branch deliberately NOT included.
            'v_hr_payroll' => "SELECT e.employee_id, e.full_name, e.designation, e.department, e.employment_status,
                    s.salary_type, s.basic_salary, s.house_rent, s.medical_allowance, s.transport_allowance,
                    s.mobile_allowance, s.food_allowance, s.convenience AS conveyance_allowance, s.special_allowance,
                    s.other_allowance, NULLIF(s.gross_salary, 0) AS gross_salary, NULLIF(s.total_salary, 0) AS total_salary,
                    s.pf AS provident_fund, s.income_tax, s.cash_bank AS payment_method
                FROM salary_info s JOIN ai_employee e ON e.employee_id = s.PBI_ID",
            'v_hr_leave_all' => "SELECT $leave, e.employment_status
                FROM ai_leave l JOIN ai_employee e ON e.employee_id = l.employee_id",
            'v_hr_leave_balances_all' => "SELECT b.employee_id, e.full_name AS employee_name, e.department, b.year,
                    b.leave_type, b.entitled, b.used, b.remaining
                FROM ai_leave_balance b JOIN ai_employee e ON e.employee_id = b.employee_id
                WHERE e.employment_status = 'Active'",
            'v_hr_attendance_daily_all' => "SELECT $daily FROM ai_attendance_daily a JOIN ai_employee e ON e.employee_id = a.employee_id",
            'v_hr_attendance_monthly_all' => "SELECT $monthly FROM ai_attendance_monthly m JOIN ai_employee e ON e.employee_id = m.employee_id",
            'v_hr_separations' => "SELECT e.employee_id, e.full_name, e.designation, e.department, e.join_date, e.resign_date
                FROM ai_employee e WHERE e.employment_status = 'Former'",

            // =============================================================== executive aggregates
            'v_exec_headcount_summary' => "SELECT e.department, COUNT(*) AS active_headcount,
                    SUM(YEAR(e.join_date) = YEAR(CURDATE())) AS joined_this_year
                FROM ai_employee e WHERE e.employment_status = 'Active' GROUP BY e.department",
            // Small groups are suppressed: pay figures only where >= $min people have a salary.
            'v_exec_payroll_summary' => "SELECT e.department, COUNT(*) AS employees_with_salary,
                    CASE WHEN COUNT(*) >= $min THEN SUM(s.gross_salary) END AS total_gross_salary,
                    CASE WHEN COUNT(*) >= $min THEN ROUND(AVG(s.gross_salary), 2) END AS avg_gross_salary,
                    CASE WHEN COUNT(*) >= $min THEN MIN(s.gross_salary) END AS min_gross_salary,
                    CASE WHEN COUNT(*) >= $min THEN MAX(s.gross_salary) END AS max_gross_salary,
                    CASE WHEN COUNT(*) >= $min THEN 'no' ELSE 'yes (fewer than $min people)' END AS suppressed
                FROM ai_employee e JOIN salary_info s ON s.PBI_ID = e.employee_id AND s.gross_salary > 0
                WHERE e.employment_status = 'Active' GROUP BY e.department",
            'v_exec_leave_summary' => "SELECT e.department, YEAR(l.start_date) AS year, l.leave_type, COUNT(*) AS requests,
                    SUM(l.status = 'Pending') AS pending_requests,
                    COALESCE(SUM(CASE WHEN l.status = 'Approved' THEN l.days END), 0) AS approved_days
                FROM ai_leave l JOIN ai_employee e ON e.employee_id = l.employee_id
                GROUP BY e.department, YEAR(l.start_date), l.leave_type",
            'v_exec_attendance_summary' => "SELECT e.department, m.year, m.month, COUNT(*) AS employees,
                    SUM(m.present_days) AS present_days, SUM(m.absent_days) AS absent_days,
                    SUM(m.late_days) AS late_days, SUM(m.leave_days) AS leave_days
                FROM ai_attendance_monthly m JOIN ai_employee e ON e.employee_id = m.employee_id
                GROUP BY e.department, m.year, m.month",
        ];
})();
