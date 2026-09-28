<?php

use yii\db\Migration;

/**
 * App-owned tables inside the imported training ERP database (erp_training).
 *
 * Everything the chatbot owns is prefixed `ai_` so it can never collide with the ERP's own
 * 1288 tables (the ERP already has e.g. `company_info` and `employees` with other meanings).
 * The ERP's tables are never altered by these migrations.
 */
class m260928_000001_create_ai_tables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        // Chatbot authority tier per employee, where it cannot be derived from the org chart.
        // (The ERP's user `level` is a module privilege - 52/76 users are "Supreme Administrator" -
        // and department heads are never recorded, so hr / dept_head / ceo are assigned here.)
        // 'manager' and 'employee' are derived automatically and never stored.
        $this->createTable('ai_role_assignment', [
            'id' => $this->primaryKey(),
            'pbi_id' => $this->bigInteger()->notNull()->unique(),
            'role' => "ENUM('hr','dept_head','ceo') NOT NULL",
            // Department a dept_head is head OF (defaults to their own dept_id when NULL).
            'dept_id' => $this->integer()->null(),
            'note' => $this->string(255)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);

        // Demo sign-in passwords (bcrypt), keyed by the ERP login (user_activity_management.user_id).
        // The ERP's own password column (unsalted MD5, some plaintext) is left untouched.
        $this->createTable('ai_user_credential', [
            'user_id' => $this->integer()->notNull(),
            'password_hash' => $this->string(255)->notNull(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'PRIMARY KEY(user_id)',
        ], self::TABLE_OPTIONS);

        // Knowledge base for information questions (the ERP's `company_info` is company master data).
        $this->createTable('ai_knowledge_base', [
            'id' => $this->primaryKey(),
            'section' => $this->string(50)->notNull(),
            'title' => $this->string(150)->notNull(),
            'body' => $this->text()->notNull(),
            'updated_at' => $this->dateTime()->notNull()
                ->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);

        // One row per chat turn. employee_id = pbi_id, deliberately without a foreign key.
        $this->createTable('ai_chat_audit_log', [
            'id' => $this->bigPrimaryKey(),
            'employee_id' => $this->bigInteger()->null(),
            'role' => $this->string(20)->null(),
            'question' => $this->text()->notNull(),
            'path' => "ENUM('info','data','denied','error') NOT NULL",
            'generated_sql' => $this->text()->null(),
            'denial_reason' => $this->string(500)->null(),
            'row_count' => $this->integer()->null(),
            'latency_ms' => $this->integer()->null(),
            'provider' => $this->string(30)->null(),
            'model' => $this->string(80)->null(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);
        $this->createIndex('idx_ai_audit_employee', 'ai_chat_audit_log', ['employee_id', 'created_at']);
        $this->createIndex('idx_ai_audit_path', 'ai_chat_audit_log', ['path', 'created_at']);
    }

    public function safeDown()
    {
        $this->dropTable('ai_chat_audit_log');
        $this->dropTable('ai_knowledge_base');
        $this->dropTable('ai_user_credential');
        $this->dropTable('ai_role_assignment');
    }
}
