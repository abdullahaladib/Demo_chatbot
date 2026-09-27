<?php

use yii\db\Migration;

/**
 * Organisation core: departments, employees, salaries.
 *
 * Salary lives in its own table on purpose: employee-tier views simply never join
 * to it, so there is no sensitive column sitting next to harmless ones waiting to be
 * selected by accident.
 */
class m260927_000001_create_org_tables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        $this->createTable('{{%departments}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(100)->notNull(),
            'code' => $this->string(10)->notNull()->unique(),
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);

        $this->createTable('{{%employees}}', [
            'id' => $this->primaryKey(),
            'emp_code' => $this->string(20)->notNull()->unique(),
            'full_name' => $this->string(150)->notNull(),
            'email' => $this->string(150)->notNull()->unique(),
            'password_hash' => $this->string(255)->notNull(),
            'auth_key' => $this->string(32)->notNull(),
            'role' => "ENUM('employee','manager','dept_head','hr','ceo') NOT NULL DEFAULT 'employee'",
            'department_id' => $this->integer()->null(),
            'manager_id' => $this->integer()->null(),
            'designation' => $this->string(100)->notNull(),
            'join_date' => $this->date()->notNull(),
            'status' => "ENUM('active','inactive') NOT NULL DEFAULT 'active'",
            'created_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);

        $this->createIndex('idx_employees_department', '{{%employees}}', 'department_id');
        $this->createIndex('idx_employees_manager', '{{%employees}}', 'manager_id');
        $this->createIndex('idx_employees_role', '{{%employees}}', 'role');
        $this->addForeignKey('fk_employees_department', '{{%employees}}', 'department_id',
            '{{%departments}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_employees_manager', '{{%employees}}', 'manager_id',
            '{{%employees}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%salaries}}', [
            'id' => $this->primaryKey(),
            'employee_id' => $this->integer()->notNull(),
            'basic' => $this->decimal(12, 2)->notNull(),
            'house_allowance' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'transport_allowance' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'effective_from' => $this->date()->notNull(),
            'is_current' => $this->boolean()->notNull()->defaultValue(false),
        ], self::TABLE_OPTIONS);

        $this->createIndex('idx_salaries_employee_current', '{{%salaries}}', ['employee_id', 'is_current']);
        $this->addForeignKey('fk_salaries_employee', '{{%salaries}}', 'employee_id',
            '{{%employees}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%salaries}}');
        $this->dropForeignKey('fk_employees_manager', '{{%employees}}');
        $this->dropForeignKey('fk_employees_department', '{{%employees}}');
        $this->dropTable('{{%employees}}');
        $this->dropTable('{{%departments}}');
    }
}
