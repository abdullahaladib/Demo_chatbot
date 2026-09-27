-- =============================================================================
-- 01-ai-readonly-user.sql  --  run as MySQL root AFTER `php yii migrate`
--
-- Creates `erp_ai_ro`, the account that executes AI-generated SQL.
-- It receives SELECT on the role-scoped v_* views and NOTHING else:
--   * no grant on any base table (employees, salaries, ...)
--   * no INSERT/UPDATE/DELETE/DDL anywhere
--   * no FILE, PROCESS, SUPER or other global privilege
--
-- This works because the views are SQL SECURITY DEFINER: they run with the
-- privileges of their creator (erp_app), not of the caller.
--
-- Replace __ERP_AI_RO_PASSWORD__ with the `ai` password in config/db-local.php.
-- Created for both 'localhost' and '127.0.0.1' (MySQL treats them as different
-- accounts; which one matches depends on how the client connects).
-- Safe to re-run: it drops and recreates the account.
-- =============================================================================

DROP USER IF EXISTS 'erp_ai_ro'@'localhost';
DROP USER IF EXISTS 'erp_ai_ro'@'127.0.0.1';

CREATE USER 'erp_ai_ro'@'localhost' IDENTIFIED BY '__ERP_AI_RO_PASSWORD__'
    WITH MAX_USER_CONNECTIONS 10;
CREATE USER 'erp_ai_ro'@'127.0.0.1' IDENTIFIED BY '__ERP_AI_RO_PASSWORD__'
    WITH MAX_USER_CONNECTIONS 10;

-- ---- 'localhost' ----
GRANT SELECT ON erp_demo.v_dept_attendance          TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_dept_employees           TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_dept_leave_requests      TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_dept_leave_summary       TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_employee_directory       TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_exec_headcount_summary   TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_exec_leave_summary       TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_exec_payroll_summary     TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_hr_attendance_all        TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_hr_employees_full        TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_hr_leave_all             TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_hr_leave_balances_all    TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_hr_payroll               TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_my_attendance            TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_my_leave_balance         TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_my_leave_requests        TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_my_profile               TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_team_attendance          TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_team_employees           TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_demo.v_team_leave_requests      TO 'erp_ai_ro'@'localhost';

-- ---- '127.0.0.1' ----
GRANT SELECT ON erp_demo.v_dept_attendance          TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_dept_employees           TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_dept_leave_requests      TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_dept_leave_summary       TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_employee_directory       TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_exec_headcount_summary   TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_exec_leave_summary       TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_exec_payroll_summary     TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_hr_attendance_all        TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_hr_employees_full        TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_hr_leave_all             TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_hr_leave_balances_all    TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_hr_payroll               TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_my_attendance            TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_my_leave_balance         TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_my_leave_requests        TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_my_profile               TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_team_attendance          TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_team_employees           TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_demo.v_team_leave_requests      TO 'erp_ai_ro'@'127.0.0.1';

FLUSH PRIVILEGES;

-- The demo slide: only view grants should appear.
SHOW GRANTS FOR 'erp_ai_ro'@'127.0.0.1';
