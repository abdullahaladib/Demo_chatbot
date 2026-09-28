<?php

use yii\db\Migration;

/**
 * Role-scoped read-only views - the ONLY objects the AI's MySQL account can read.
 *
 * Views decide WHICH TABLES AND COLUMNS a tier can reach. They cannot decide which
 * ROWS belong to "the person asking" (MySQL views take no parameters), so every
 * scoped view exposes its scope column - employee_id, manager_id or department_id -
 * and the application binds :me / :dept from the session. See config/access-map.php.
 *
 * All views run with SQL SECURITY DEFINER (made explicit here): they execute with the
 * privileges of their creator (erp_app), so erp_ai_ro can read them while holding no
 * grant at all on the base tables.
 *
 * Salary appears ONLY in v_hr_* and v_exec_payroll_summary. No other view joins to
 * the salaries table.
 */
class m260927_000005_create_role_views extends Migration
{
    public function safeUp()
    {
        foreach ($this->views() as $name => $select) {
            $this->execute("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `$name` AS\n$select");
        }
    }

    public function safeDown()
    {
        foreach (array_reverse(array_keys($this->views())) as $name) {
            $this->execute("DROP VIEW IF EXISTS `$name`");
        }
    }

    /** @return array<string,string> view name => SELECT */
    private function views(): array
    {
        $e = $this->db->quoteTableName('{{%employees}}');
        $d = $this->db->quoteTableName('{{%departments}}');
        $s = $this->db->quoteTableName('{{%salaries}}');
        $lt = $this->db->quoteTableName('{{%leave_types}}');
        $lr = $this->db->quoteTableName('{{%leave_requests}}');
        $lb = $this->db->quoteTableName('{{%leave_balances}}');
        $a = $this->db->quoteTableName('{{%attendance}}');

        // Reusable fragments. None of them touch the salaries table.
        $employeeCols = "e.id AS employee_id, e.emp_code, e.full_name, e.email, e.designation,
            e.department_id, d.name AS department, e.manager_id, m.full_name AS manager_name,
            e.join_date, e.status";
        $leaveRequestCols = "lr.id AS request_id, lr.employee_id, e.full_name AS employee_name,
            e.department_id, d.name AS department, e.manager_id,
            lt.name AS leave_type, lt.code AS leave_type_code, lr.start_date, lr.end_date, lr.days,
            lr.reason, lr.status, ap.full_name AS approved_by_name, lr.applied_at";
        $leaveRequestFrom = "FROM $lr lr
            JOIN $e e ON e.id = lr.employee_id
            LEFT JOIN $d d ON d.id = e.department_id
            JOIN $lt lt ON lt.id = lr.leave_type_id
            LEFT JOIN $e ap ON ap.id = lr.approved_by";
        $attendanceCols = "a.employee_id, e.full_name AS employee_name, e.department_id,
            d.name AS department, e.manager_id, a.work_date, a.check_in, a.check_out,
            a.status, a.work_hours";
        $attendanceFrom = "FROM $a a
            JOIN $e e ON e.id = a.employee_id
            LEFT JOIN $d d ON d.id = e.department_id";
        $gross = 's.basic + s.house_allowance + s.transport_allowance';

        return [
            // ---------------------------------------------------- shared, every tier
            // No ids, no dates, no manager - safe for anyone in the company to see.
            'v_employee_directory' => "SELECT e.full_name, e.designation, d.name AS department, e.email
                FROM $e e LEFT JOIN $d d ON d.id = e.department_id
                WHERE e.status = 'active'",

            // ---------------------------------------------------- self (:me = employee_id)
            'v_my_profile' => "SELECT e.id AS employee_id, e.emp_code, e.full_name, e.email, e.role,
                    e.designation, e.department_id, d.name AS department, m.full_name AS manager_name,
                    e.join_date, e.status
                FROM $e e
                LEFT JOIN $d d ON d.id = e.department_id
                LEFT JOIN $e m ON m.id = e.manager_id",

            'v_my_leave_balance' => "SELECT lb.employee_id, lb.year, lt.name AS leave_type,
                    lt.code AS leave_type_code, lt.is_paid, lb.entitled, lb.used, lb.remaining
                FROM $lb lb JOIN $lt lt ON lt.id = lb.leave_type_id",

            'v_my_leave_requests' => "SELECT lr.id AS request_id, lr.employee_id, lt.name AS leave_type,
                    lt.code AS leave_type_code, lr.start_date, lr.end_date, lr.days, lr.reason,
                    lr.status, ap.full_name AS approved_by_name, lr.applied_at
                FROM $lr lr
                JOIN $lt lt ON lt.id = lr.leave_type_id
                LEFT JOIN $e ap ON ap.id = lr.approved_by",

            'v_my_attendance' => "SELECT a.employee_id, a.work_date, a.check_in, a.check_out,
                    a.status, a.work_hours
                FROM $a a",

            // ---------------------------------------------------- team (:me = manager_id)
            'v_team_employees' => "SELECT $employeeCols
                FROM $e e
                LEFT JOIN $d d ON d.id = e.department_id
                LEFT JOIN $e m ON m.id = e.manager_id",

            'v_team_leave_requests' => "SELECT $leaveRequestCols $leaveRequestFrom",

            'v_team_attendance' => "SELECT $attendanceCols $attendanceFrom",

            // ---------------------------------------------------- department (:dept = department_id)
            'v_dept_employees' => "SELECT $employeeCols
                FROM $e e
                LEFT JOIN $d d ON d.id = e.department_id
                LEFT JOIN $e m ON m.id = e.manager_id",

            'v_dept_leave_requests' => "SELECT $leaveRequestCols $leaveRequestFrom",

            'v_dept_attendance' => "SELECT $attendanceCols $attendanceFrom",

            'v_dept_leave_summary' => "SELECT e.department_id, d.name AS department,
                    YEAR(lr.start_date) AS year, lt.name AS leave_type, lt.code AS leave_type_code,
                    COUNT(*) AS total_requests,
                    SUM(lr.status = 'pending') AS pending_requests,
                    SUM(lr.status = 'approved') AS approved_requests,
                    SUM(lr.status = 'rejected') AS rejected_requests,
                    COALESCE(SUM(CASE WHEN lr.status = 'approved' THEN lr.days END), 0) AS approved_days
                FROM $lr lr
                JOIN $e e ON e.id = lr.employee_id
                JOIN $d d ON d.id = e.department_id
                JOIN $lt lt ON lt.id = lr.leave_type_id
                GROUP BY e.department_id, d.name, YEAR(lr.start_date), lt.name, lt.code",

            // ---------------------------------------------------- HR / CEO (all rows, salary included)
            'v_hr_employees_full' => "SELECT e.id AS employee_id, e.emp_code, e.full_name, e.email, e.role,
                    e.designation, e.department_id, d.name AS department, e.manager_id,
                    m.full_name AS manager_name, e.join_date, e.status,
                    s.basic AS basic_salary, s.house_allowance, s.transport_allowance,
                    ($gross) AS gross_salary, s.effective_from AS salary_effective_from
                FROM $e e
                LEFT JOIN $d d ON d.id = e.department_id
                LEFT JOIN $e m ON m.id = e.manager_id
                LEFT JOIN $s s ON s.employee_id = e.id AND s.is_current = 1",

            'v_hr_payroll' => "SELECT s.id AS salary_id, s.employee_id, e.emp_code, e.full_name,
                    d.name AS department, s.basic AS basic_salary, s.house_allowance,
                    s.transport_allowance, ($gross) AS gross_salary, s.effective_from, s.is_current
                FROM $s s
                JOIN $e e ON e.id = s.employee_id
                LEFT JOIN $d d ON d.id = e.department_id",

            'v_hr_leave_all' => "SELECT $leaveRequestCols $leaveRequestFrom",

            'v_hr_leave_balances_all' => "SELECT lb.employee_id, e.full_name AS employee_name,
                    d.name AS department, lb.year, lt.name AS leave_type, lt.code AS leave_type_code,
                    lb.entitled, lb.used, lb.remaining
                FROM $lb lb
                JOIN $e e ON e.id = lb.employee_id
                LEFT JOIN $d d ON d.id = e.department_id
                JOIN $lt lt ON lt.id = lb.leave_type_id",

            'v_hr_attendance_all' => "SELECT $attendanceCols $attendanceFrom",

            // ---------------------------------------------------- CEO aggregates
            'v_exec_headcount_summary' => "SELECT d.name AS department, d.code AS department_code,
                    COUNT(e.id) AS headcount,
                    SUM(e.role IN ('manager','dept_head')) AS managers,
                    MIN(e.join_date) AS earliest_join, MAX(e.join_date) AS latest_join
                FROM $d d
                LEFT JOIN $e e ON e.department_id = d.id AND e.status = 'active'
                GROUP BY d.id, d.name, d.code",

            'v_exec_payroll_summary' => "SELECT d.name AS department, d.code AS department_code,
                    COUNT(*) AS headcount,
                    SUM($gross) AS total_gross_salary,
                    ROUND(AVG($gross), 2) AS avg_gross_salary,
                    MIN($gross) AS min_gross_salary,
                    MAX($gross) AS max_gross_salary
                FROM $s s
                JOIN $e e ON e.id = s.employee_id AND e.status = 'active'
                JOIN $d d ON d.id = e.department_id
                WHERE s.is_current = 1
                GROUP BY d.id, d.name, d.code",

            'v_exec_leave_summary' => "SELECT d.name AS department, YEAR(lr.start_date) AS year,
                    lt.name AS leave_type,
                    COUNT(*) AS total_requests,
                    SUM(lr.status = 'pending') AS pending_requests,
                    COALESCE(SUM(CASE WHEN lr.status = 'approved' THEN lr.days END), 0) AS approved_days
                FROM $lr lr
                JOIN $e e ON e.id = lr.employee_id
                JOIN $d d ON d.id = e.department_id
                JOIN $lt lt ON lt.id = lr.leave_type_id
                GROUP BY d.name, YEAR(lr.start_date), lt.name",
        ];
    }
}
