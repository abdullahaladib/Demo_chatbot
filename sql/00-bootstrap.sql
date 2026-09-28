-- =============================================================================
-- 00-bootstrap.sql  --  run ONCE as MySQL root, BEFORE `php yii migrate`
--
-- Creates the database and the privileged application account `erp_app` (setup/import-training
-- then drops and recreates the database itself from the training dump).
-- The restricted AI account (`erp_ai_ro`) is created later, in 01-ai-readonly-user.sql,
-- because it can only be granted on views that exist.
--
-- Replace __ERP_APP_PASSWORD__ with the password you put in config/db-local.php.
-- The account is created for both 'localhost' and '127.0.0.1': depending on
-- skip_name_resolve / how the client connects, MySQL matches one or the other.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS erp_training
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'erp_app'@'localhost' IDENTIFIED BY '__ERP_APP_PASSWORD__';
CREATE USER IF NOT EXISTS 'erp_app'@'127.0.0.1' IDENTIFIED BY '__ERP_APP_PASSWORD__';

-- Full rights on this one schema only (not global). Needed for migrations, which
-- create tables and views. Views created by erp_app run as erp_app (SQL SECURITY
-- DEFINER), which is what lets erp_ai_ro read them without base-table grants.
GRANT ALL PRIVILEGES ON erp_training.* TO 'erp_app'@'localhost';
GRANT ALL PRIVILEGES ON erp_training.* TO 'erp_app'@'127.0.0.1';

FLUSH PRIVILEGES;
