<?php

use yii\db\Migration;

/**
 * Leave and attendance: leave_types, leave_requests, leave_balances, attendance.
 */
class m260927_000002_create_leave_attendance_tables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function safeUp()
    {
        $this->createTable('{{%leave_types}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(50)->notNull(),
            'code' => $this->string(10)->notNull()->unique(),
            'annual_quota' => $this->integer()->notNull()->defaultValue(0),
            'is_paid' => $this->boolean()->notNull()->defaultValue(true),
        ], self::TABLE_OPTIONS);

        $this->createTable('{{%leave_requests}}', [
            'id' => $this->primaryKey(),
            'employee_id' => $this->integer()->notNull(),
            'leave_type_id' => $this->integer()->notNull(),
            'start_date' => $this->date()->notNull(),
            'end_date' => $this->date()->notNull(),
            'days' => $this->integer()->notNull(),
            'reason' => $this->string(255)->null(),
            'status' => "ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'",
            'approved_by' => $this->integer()->null(),
            'applied_at' => $this->dateTime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], self::TABLE_OPTIONS);

        $this->createIndex('idx_leave_requests_employee', '{{%leave_requests}}', ['employee_id', 'start_date']);
        $this->createIndex('idx_leave_requests_status', '{{%leave_requests}}', 'status');
        $this->addForeignKey('fk_leave_requests_employee', '{{%leave_requests}}', 'employee_id',
            '{{%employees}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_leave_requests_type', '{{%leave_requests}}', 'leave_type_id',
            '{{%leave_types}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_leave_requests_approver', '{{%leave_requests}}', 'approved_by',
            '{{%employees}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%leave_balances}}', [
            'id' => $this->primaryKey(),
            'employee_id' => $this->integer()->notNull(),
            'leave_type_id' => $this->integer()->notNull(),
            'year' => $this->integer()->notNull(),
            'entitled' => $this->integer()->notNull()->defaultValue(0),
            'used' => $this->integer()->notNull()->defaultValue(0),
            'remaining' => $this->integer()->notNull()->defaultValue(0),
        ], self::TABLE_OPTIONS);

        $this->createIndex('uq_leave_balances_emp_type_year', '{{%leave_balances}}',
            ['employee_id', 'leave_type_id', 'year'], true);
        $this->addForeignKey('fk_leave_balances_employee', '{{%leave_balances}}', 'employee_id',
            '{{%employees}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_leave_balances_type', '{{%leave_balances}}', 'leave_type_id',
            '{{%leave_types}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%attendance}}', [
            'id' => $this->primaryKey(),
            'employee_id' => $this->integer()->notNull(),
            'work_date' => $this->date()->notNull(),
            'check_in' => $this->time()->null(),
            'check_out' => $this->time()->null(),
            'status' => "ENUM('present','late','absent','leave','holiday') NOT NULL DEFAULT 'present'",
            'work_hours' => $this->decimal(4, 2)->notNull()->defaultValue(0),
        ], self::TABLE_OPTIONS);

        $this->createIndex('uq_attendance_emp_date', '{{%attendance}}', ['employee_id', 'work_date'], true);
        $this->createIndex('idx_attendance_date', '{{%attendance}}', 'work_date');
        $this->addForeignKey('fk_attendance_employee', '{{%attendance}}', 'employee_id',
            '{{%employees}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%attendance}}');
        $this->dropTable('{{%leave_balances}}');
        $this->dropTable('{{%leave_requests}}');
        $this->dropTable('{{%leave_types}}');
    }
}
