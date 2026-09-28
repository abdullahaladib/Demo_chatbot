<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Knowledge base for INFORMATION questions (answered from the prompt, no SQL), built from
 * the training ERP's own policy tables at migration time so it matches the data:
 *   leave types + yearly days (hrm_leave_type), the office schedule (hrm_schedule_info),
 *   weekly off-days (personnel_basic_info), public holidays (hris_holiday_setup),
 *   late policies (hris_late_policy_config), departments and job titles in use.
 * Procedural items the ERP does not record (how to apply, who to contact) are drafted and
 * say so. The ERP's test rows ("newTest" leave type, "test" holiday) are skipped.
 */
class m260928_000004_knowledge_base extends Migration
{
    public function safeUp()
    {
        $db = $this->db;
        $rows = [];

        // ---- leave
        $types = (new Query())->select(['leave_type_name', 'yearly_leave_days'])->from('hrm_leave_type')
            ->where(['status' => 'Active'])->andWhere(['not like', 'leave_type_name', 'test'])
            ->orderBy('id')->all($db);
        $withQuota = array_filter($types, fn($t) => (int) $t['yearly_leave_days'] > 0);
        $noQuota = array_filter($types, fn($t) => (int) $t['yearly_leave_days'] === 0);
        $rows[] = ['leave', 'Leave entitlement per year',
            'Paid leave per calendar year: ' . implode('; ', array_map(fn($t) => trim($t['leave_type_name']) . ' - '
                . (int) $t['yearly_leave_days'] . ' days', $withQuota)) . '. '
            . 'Other leave types, granted case by case with no fixed quota: '
            . implode(', ', array_map(fn($t) => trim($t['leave_type_name']), $noQuota)) . '. '
            . 'A few employees are on a special leave rule with different quotas. Unused leave does not carry over '
            . 'unless HR approves it. Ask me "how many leave days do I have left" to see your own balance.'];
        $rows[] = ['leave', 'How to apply for leave (drafted for the demo)',
            'Apply in the ERP leave module. Your line manager (in-charge) approves or declines it first, then HR grants it. '
            . 'Apply at least 3 working days ahead for casual leave; sick leave can be applied on return, with a medical '
            . 'certificate for more than 2 days. Short leave (a few hours) is recorded as "Short Leave (SHL)".'];

        // ---- hours
        $shift = (new Query())->from('hrm_schedule_info')->where(['id' => 1])->one($db);
        if ($shift) {
            $grace = (int) substr((string) $shift['in_grace_time'], 3, 2);
            $rows[] = ['hours', 'Office hours',
                sprintf('The standard %s runs from %s to %s, with a break around %s. Check-in is recorded by the office '
                    . 'attendance device. Arriving more than %d minutes after the start time is recorded as late.',
                    trim($shift['schedule_name']), substr($shift['office_start_time'], 0, 5),
                    substr($shift['office_end_time'], 0, 5), substr($shift['office_mid_time'], 0, 5), $grace)];
        }
        $rows[] = ['hours', 'Weekly holiday',
            'Friday is the weekly holiday for everyone. Saturday to Thursday are working days (a few employees also have '
            . 'Saturday off by their roster).'];
        $late = (new Query())->select('late_policy_name')->from('hris_late_policy_config')->where(['status' => 'Active'])
            ->orderBy('sort_order')->column($db);
        if ($late) {
            $rows[] = ['hours', 'Late attendance',
                'Late arrivals are handled under these policies, depending on the employee\'s rule: ' . implode('; ', $late)
                . '. Attendance, late days and overtime are summarised monthly.'];
        }

        // ---- holidays
        $holidays = (new Query())->select(['holiday_name', 'holiday_date', 'no_of_days', 'remarks'])->from('hris_holiday_setup')
            ->where(['not like', 'holiday_name', 'test'])->orderBy('holiday_date')->all($db);
        if ($holidays) {
            $rows[] = ['holidays', 'Public holidays ' . substr($holidays[0]['holiday_date'], 0, 4),
                implode('; ', array_map(fn($h) => trim($h['holiday_name']) . ' ' . date('j M', strtotime($h['holiday_date']))
                    . ((int) $h['no_of_days'] > 1 ? " ({$h['no_of_days']} days)" : '')
                    . (trim((string) $h['remarks']) !== '' ? ' - ' . rtrim(trim($h['remarks']), '.') : ''), $holidays)) . '.'];
        }

        // ---- organisation reference (helps the model map "engineering" -> department "Engineer")
        $depts = (new Query())->select(['d.DEPT_DESC', 'n' => 'COUNT(*)'])->from(['p' => 'personnel_basic_info'])
            ->innerJoin(['d' => 'department'], 'd.DEPT_ID = p.dept_id')->where(['p.pbi_job_status' => 'In Service'])
            ->groupBy('d.DEPT_DESC')->orderBy(['n' => SORT_DESC])->all($db);
        $rows[] = ['organisation', 'Departments',
            'Departments with active staff (exact names as stored): ' . implode('; ', array_map(fn($d) => trim($d['DEPT_DESC'])
                . " ({$d['n']})", $depts)) . '. The software engineering team is the department named "Engineer".'];
        $titles = (new Query())->select('g.DESG_DESC')->distinct()->from(['p' => 'personnel_basic_info'])
            ->innerJoin(['g' => 'designation'], 'g.DESG_ID = p.desg_id')->where(['p.pbi_job_status' => 'In Service'])
            ->column($db);
        $rows[] = ['organisation', 'Job titles in use', implode('; ', array_map('trim', $titles)) . '.'];

        // ---- drafted procedural items
        $rows[] = ['payroll', 'Payroll (drafted for the demo)',
            'Salary is paid monthly by bank transfer or cash, as set per employee. It consists of basic salary plus '
            . 'allowances (house rent, medical, transport, mobile, food and others). Provident fund and income tax are '
            . 'deducted where applicable. Salary details are confidential: only HR and executives can look them up.'];
        $rows[] = ['contacts', 'Who to contact (drafted for the demo)',
            'Leave, payroll, letters and HR policy: HR (Farzana Rahman, hr.demo@demo.local). Attendance device or '
            . 'roster problems: your line manager first, then HR. ERP access and passwords: the ERP administrator.'];
        $rows[] = ['assistant', 'About this assistant',
            'This assistant answers policy questions from this knowledge base, and ERP data questions (leave, attendance, '
            . 'colleagues, salary) only within what your role is allowed to see. It cannot change any data.'];

        $this->batchInsert('ai_knowledge_base', ['section', 'title', 'body'], $rows);
    }

    public function safeDown()
    {
        $this->delete('ai_knowledge_base');
    }
}
