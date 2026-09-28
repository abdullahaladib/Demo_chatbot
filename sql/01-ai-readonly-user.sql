-- =============================================================================
-- 01-ai-readonly-user.sql  --  run as MySQL root AFTER the training import + `php yii migrate`
--                              (or just run: php yii setup/database <root-password>)
--
-- Creates `erp_ai_ro`, the account that executes AI-generated SQL against the training
-- ERP database `erp_training` (1288 base tables). It receives SELECT on the role-scoped
-- v_* views and NOTHING else:
--   * no grant on any of the ERP's base tables (personnel_basic_info, salary_info, ...)
--   * no grant on the internal ai_* helper views
--   * no INSERT/UPDATE/DELETE/DDL anywhere, no FILE/PROCESS/SUPER or other global privilege
--
-- This works because the views are SQL SECURITY DEFINER: they run with the privileges of
-- their creator (erp_app), not of the caller.
--
-- Replace __ERP_AI_RO_PASSWORD__ with the `ai` password in config/db-local.php.
-- Created for both 'localhost' and '127.0.0.1'. Safe to re-run (drops and recreates).
-- =============================================================================

DROP USER IF EXISTS 'erp_ai_ro'@'localhost';
DROP USER IF EXISTS 'erp_ai_ro'@'127.0.0.1';

CREATE USER 'erp_ai_ro'@'localhost' IDENTIFIED BY '__ERP_AI_RO_PASSWORD__'
    WITH MAX_USER_CONNECTIONS 10;
CREATE USER 'erp_ai_ro'@'127.0.0.1' IDENTIFIED BY '__ERP_AI_RO_PASSWORD__'
    WITH MAX_USER_CONNECTIONS 10;

-- ---- 'localhost' ----
GRANT SELECT ON erp_training.v_dept_attendance_monthly    TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_dept_employees             TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_dept_leave_requests        TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_dept_leave_summary         TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_employee_directory         TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_exec_attendance_summary    TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_exec_headcount_summary     TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_exec_leave_summary         TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_exec_payroll_summary       TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_attendance_daily_all    TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_attendance_monthly_all  TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_employees_full          TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_leave_all               TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_leave_balances_all      TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_payroll                 TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_hr_separations             TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_my_attendance_daily        TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_my_attendance_monthly      TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_my_leave_balance           TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_my_leave_requests          TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_my_profile                 TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_team_attendance_daily      TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_team_attendance_monthly    TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_team_leave_requests        TO 'erp_ai_ro'@'localhost';
GRANT SELECT ON erp_training.v_team_members               TO 'erp_ai_ro'@'localhost';

-- ---- '127.0.0.1' ----
GRANT SELECT ON erp_training.v_dept_attendance_monthly    TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_dept_employees             TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_dept_leave_requests        TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_dept_leave_summary         TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_employee_directory         TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_exec_attendance_summary    TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_exec_headcount_summary     TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_exec_leave_summary         TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_exec_payroll_summary       TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_attendance_daily_all    TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_attendance_monthly_all  TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_employees_full          TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_leave_all               TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_leave_balances_all      TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_payroll                 TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_hr_separations             TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_my_attendance_daily        TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_my_attendance_monthly      TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_my_leave_balance           TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_my_leave_requests          TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_my_profile                 TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_team_attendance_daily      TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_team_attendance_monthly    TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_team_leave_requests        TO 'erp_ai_ro'@'127.0.0.1';
GRANT SELECT ON erp_training.v_team_members               TO 'erp_ai_ro'@'127.0.0.1';

FLUSH PRIVILEGES;

-- The demo slide: only view grants should appear.
SHOW GRANTS FOR 'erp_ai_ro'@'127.0.0.1';
