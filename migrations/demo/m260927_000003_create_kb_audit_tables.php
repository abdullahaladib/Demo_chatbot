<?php

use yii\db\Migration;

/**
 * company_info (knowledge base for information questions) and chat_audit_log.
 */
class m260927_000003_create_kb_audit_tables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        // ~15 short rows: the whole table goes into the system prompt. At this size
        // that is cheaper, simpler and more accurate than embeddings / a vector store.
        $this->createTable('{{%company_info}}', [
            'id' => $this->primaryKey(),
            'section' => $this->string(50)->notNull(),
            'title' => $this->string(150)->notNull(),
            'body' => $this->text()->notNull(),
            'updated_at' => $this->dateTime()->notNull()
                ->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);

        $this->createIndex('idx_company_info_section', '{{%company_info}}', 'section');

        // One row per chat turn, including refusals and errors.
        // employee_id deliberately has NO foreign key: audit rows must survive the
        // deletion of the employee they describe.
        $this->createTable('{{%chat_audit_log}}', [
            'id' => $this->bigPrimaryKey(),
            'employee_id' => $this->integer()->null(),
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

        $this->createIndex('idx_chat_audit_employee', '{{%chat_audit_log}}', ['employee_id', 'created_at']);
        $this->createIndex('idx_chat_audit_path', '{{%chat_audit_log}}', ['path', 'created_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%chat_audit_log}}');
        $this->dropTable('{{%company_info}}');
    }
}
