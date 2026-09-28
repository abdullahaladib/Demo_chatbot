<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Demo seed data. Everything here is fabricated.
 *
 * Dates for leave requests and attendance are relative to the day the migration runs,
 * so the "last 40 working days" and "pending requests" are always fresh for the demo.
 * mt_srand() makes the random parts (clock-in times, late/absent days) repeatable.
 *
 * The data is kept internally consistent:
 *   - leave_balances.used = sum of APPROVED leave days this year, per type
 *   - approved leave that falls in the attendance window shows as status 'leave'
 */
class m260927_000004_seed_demo_data extends Migration
{
    private const PASSWORD = 'Demo@1234';
    private const ATTENDANCE_DAYS = 40;

    /** Bangladesh weekend: Friday (5) and Saturday (6), per DateTime::format('w'). */
    private const WEEKEND = [5, 6];

    /** Fixed-date public holidays (month-day). Lunar holidays are omitted for simplicity. */
    private const HOLIDAYS = ['02-21', '03-17', '03-26', '04-14', '05-01', '08-05', '12-16', '12-25'];

    public function safeUp()
    {
        mt_srand(20260927);
        $today = new DateTimeImmutable('today');

        // ---------------------------------------------------------------- departments
        $this->batchInsert('{{%departments}}', ['name', 'code'], [
            ['Executive', 'EXEC'],
            ['Engineering', 'ENG'],
            ['Sales', 'SLS'],
            ['Human Resources', 'HR'],
        ]);
        $dept = (new Query())->select('id')->from('{{%departments}}')->indexBy('code')->column($this->db);

        // ---------------------------------------------------------------- employees
        // key => [email, name, role, dept, manager key, designation, join date,
        //         [current basic, house, transport]]   (monthly BDT)
        $people = [
            'ceo' => ['ceo@demo.local', 'Mahbubur Rahman Chowdhury', 'ceo', 'EXEC', null,
                'Chief Executive Officer', '2015-03-01', [450000, 180000, 30000]],
            'hr' => ['hr@demo.local', 'Nusrat Jahan', 'hr', 'HR', 'ceo',
                'HR Manager', '2018-06-10', [150000, 60000, 12000]],
            'enghead' => ['enghead@demo.local', 'Tanvir Ahmed', 'dept_head', 'ENG', 'ceo',
                'Head of Engineering', '2017-01-15', [280000, 112000, 20000]],
            'engmgr' => ['engmgr@demo.local', 'Farhana Akter', 'manager', 'ENG', 'enghead',
                'Engineering Manager', '2019-04-01', [200000, 80000, 15000]],
            'dev1' => ['dev1@demo.local', 'Arif Hossain', 'employee', 'ENG', 'engmgr',
                'Senior Software Engineer', '2021-08-01', [110000, 44000, 8000]],
            'dev2' => ['dev2@demo.local', 'Sadia Islam', 'employee', 'ENG', 'engmgr',
                'Software Engineer', '2022-02-13', [95000, 38000, 8000]],
            // Joined ~4 months before seeding, so he is always "on probation" in the demo.
            'dev3' => ['dev3@demo.local', 'Rakibul Hasan', 'employee', 'ENG', 'engmgr',
                'Junior Software Engineer', $today->modify('-4 months')->format('Y-m-d'), [55000, 22000, 5000]],
            'saleshead' => ['saleshead@demo.local', 'Kamrul Islam', 'dept_head', 'SLS', 'ceo',
                'Head of Sales', '2018-09-01', [220000, 88000, 18000]],
            'sales1' => ['sales1@demo.local', 'Sharmin Sultana', 'employee', 'SLS', 'saleshead',
                'Senior Sales Executive', '2023-01-10', [70000, 28000, 6000]],
            'sales2' => ['sales2@demo.local', 'Imran Kabir', 'employee', 'SLS', 'saleshead',
                'Sales Executive', '2024-03-03', [65000, 26000, 6000]],
        ];

        $security = Yii::$app->security;
        $ids = [];
        $n = 0;
        foreach ($people as $key => [$email, $name, $role, $deptCode, $mgrKey, $designation, $joined]) {
            $n++;
            $this->insert('{{%employees}}', [
                'emp_code' => sprintf('EMP-%03d', $n),
                'full_name' => $name,
                'email' => $email,
                'password_hash' => $security->generatePasswordHash(self::PASSWORD),
                'auth_key' => $security->generateRandomString(32),
                'role' => $role,
                'department_id' => $dept[$deptCode],
                'manager_id' => $mgrKey === null ? null : $ids[$mgrKey],
                'designation' => $designation,
                'join_date' => $joined,
                'status' => 'active',
            ]);
            $ids[$key] = (int) $this->db->getLastInsertID();
        }

        // ---------------------------------------------------------------- salaries
        // Two rows each: the original package at joining, and the current one.
        $salaryRows = [];
        foreach ($people as $key => $p) {
            [$basic, $house, $transport] = $p[7];
            $joined = new DateTimeImmutable($p[6]);
            // Current package took effect on the most recent 1 January or 1 July
            // after joining (at least 3 months after), else it's a probation confirmation.
            $raiseFrom = $this->lastRaiseDate($joined, $today);
            $salaryRows[] = [$ids[$key], round($basic / 1.12, -3), round($house / 1.12, -3),
                round($transport / 1.12, -2), $joined->format('Y-m-d'), 0];
            $salaryRows[] = [$ids[$key], $basic, $house, $transport, $raiseFrom->format('Y-m-d'), 1];
        }
        $this->batchInsert('{{%salaries}}',
            ['employee_id', 'basic', 'house_allowance', 'transport_allowance', 'effective_from', 'is_current'],
            $salaryRows);

        // ---------------------------------------------------------------- leave types
        $this->batchInsert('{{%leave_types}}', ['name', 'code', 'annual_quota', 'is_paid'], [
            ['Annual Leave', 'AL', 20, 1],
            ['Sick Leave', 'SL', 14, 1],
            ['Casual Leave', 'CL', 10, 1],
            ['Unpaid Leave', 'UL', 0, 0],
        ]);
        $types = (new Query())->select(['id', 'code', 'annual_quota'])->from('{{%leave_types}}')
            ->indexBy('code')->all($this->db);

        // ---------------------------------------------------------------- leave requests
        // [employee, type, start offset in calendar days from today, working days, status, reason]
        $requests = [
            // pending - several in Engineering, for "who on my team has pending leave?"
            ['dev1', 'CL', 2, 1, 'pending', 'Bank and passport office work'],
            ['dev1', 'AL', 10, 3, 'pending', "Family trip to Cox's Bazar"],
            ['dev2', 'CL', 4, 1, 'pending', 'Sister\'s wedding preparations'],
            ['dev3', 'AL', 20, 2, 'pending', 'Visiting parents in Rajshahi'],
            ['engmgr', 'AL', 14, 5, 'pending', 'Visiting family in Sylhet'],
            ['sales1', 'AL', 8, 2, 'pending', 'Personal errands'],
            ['hr', 'CL', 6, 1, 'pending', 'Child\'s school event'],
            // approved
            ['dev1', 'SL', -12, 2, 'approved', 'Fever'],
            ['dev1', 'AL', -120, 4, 'approved', 'Eid holidays with family'],
            ['dev2', 'AL', -30, 3, 'approved', 'Trip to Sreemangal'],
            ['dev2', 'SL', -75, 1, 'approved', 'Dental appointment'],
            ['dev3', 'CL', -9, 1, 'approved', 'House shifting'],
            ['dev3', 'UL', -40, 2, 'approved', 'Family emergency (no paid leave during probation)'],
            ['engmgr', 'AL', -45, 3, 'approved', 'Short vacation'],
            ['engmgr', 'CL', -100, 1, 'approved', 'Personal work'],
            ['enghead', 'AL', -60, 5, 'approved', 'Annual vacation'],
            ['enghead', 'CL', -20, 1, 'approved', 'Personal work'],
            ['sales1', 'SL', -15, 2, 'approved', 'Viral fever'],
            ['sales1', 'AL', -140, 5, 'approved', 'Eid holidays'],
            ['sales2', 'CL', -25, 1, 'approved', 'Personal work'],
            ['sales2', 'AL', -90, 3, 'approved', 'Trip to Bandarban'],
            ['saleshead', 'AL', -50, 4, 'approved', 'Family vacation'],
            ['hr', 'AL', -70, 5, 'approved', 'Annual vacation'],
            ['hr', 'SL', -33, 1, 'approved', 'Migraine'],
            ['ceo', 'AL', -110, 5, 'approved', 'Overseas travel'],
            // rejected
            ['dev1', 'AL', -60, 7, 'rejected', 'Extended vacation during release week'],
            ['dev2', 'CL', -18, 1, 'rejected', 'Personal work (clashed with sprint demo)'],
            ['sales1', 'CL', -100, 1, 'rejected', 'Personal work (quarter-end)'],
            ['sales2', 'AL', -5, 3, 'rejected', 'Trip (clashed with client visit)'],
            // cancelled
            ['dev3', 'AL', -80, 2, 'cancelled', 'Trip - plans changed'],
            ['saleshead', 'CL', -35, 1, 'cancelled', 'Personal work'],
        ];

        $managerOf = [];
        foreach ($people as $key => $p) {
            $managerOf[$key] = $p[4];
        }

        $requestRows = [];
        $usedDays = [];      // [employee key][type code] => days, current year, approved only
        $onLeave = [];       // [employee key][Y-m-d] => true, approved only
        $year = (int) $today->format('Y');
        foreach ($requests as [$who, $type, $offset, $days, $status, $reason]) {
            $start = $this->nextWorkingDay($today->modify(sprintf('%+d days', $offset)));
            $dates = $this->workingDaysFrom($start, $days);
            $end = end($dates);

            $applied = $start->modify('-7 days');
            if ($applied >= $today) {
                $applied = $today->modify('-1 day');
            }
            $applied = $applied->setTime(10 + mt_rand(0, 6), mt_rand(0, 59));

            // Approver is the line manager; the CEO's own leave is signed off by HR.
            $approver = null;
            if ($status === 'approved' || $status === 'rejected') {
                $approver = $ids[$managerOf[$who] ?? 'hr'];
            }

            $requestRows[] = [$ids[$who], (int) $types[$type]['id'], $start->format('Y-m-d'),
                $end->format('Y-m-d'), $days, $reason, $status, $approver, $applied->format('Y-m-d H:i:s')];

            if ($status === 'approved') {
                foreach ($dates as $d) {
                    $onLeave[$who][$d->format('Y-m-d')] = true;
                    if ((int) $d->format('Y') === $year) {
                        $usedDays[$who][$type] = ($usedDays[$who][$type] ?? 0) + 1;
                    }
                }
            }
        }
        $this->batchInsert('{{%leave_requests}}',
            ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'reason', 'status',
                'approved_by', 'applied_at'],
            $requestRows);

        // ---------------------------------------------------------------- leave balances
        $balanceRows = [];
        foreach ($people as $key => $p) {
            foreach ($types as $code => $t) {
                $entitled = (int) $t['annual_quota'];
                $used = $usedDays[$key][$code] ?? 0;
                $balanceRows[] = [$ids[$key], (int) $t['id'], $year, $entitled, $used, max(0, $entitled - $used)];
            }
        }
        $this->batchInsert('{{%leave_balances}}',
            ['employee_id', 'leave_type_id', 'year', 'entitled', 'used', 'remaining'],
            $balanceRows);

        // ---------------------------------------------------------------- attendance
        // Last 40 working days (Sun-Thu), ending yesterday.
        $workDays = [];
        for ($d = $today->modify('-1 day'); count($workDays) < self::ATTENDANCE_DAYS; $d = $d->modify('-1 day')) {
            if (!in_array((int) $d->format('w'), self::WEEKEND, true)) {
                $workDays[] = $d;
            }
        }
        $workDays = array_reverse($workDays);

        $attendanceRows = [];
        foreach ($people as $key => $p) {
            $joined = new DateTimeImmutable($p[6]);
            foreach ($workDays as $d) {
                if ($d < $joined) {
                    continue;
                }
                $date = $d->format('Y-m-d');
                if (in_array($d->format('m-d'), self::HOLIDAYS, true)) {
                    $attendanceRows[] = [$ids[$key], $date, null, null, 'holiday', 0];
                    continue;
                }
                if (isset($onLeave[$key][$date])) {
                    $attendanceRows[] = [$ids[$key], $date, null, null, 'leave', 0];
                    continue;
                }
                $roll = mt_rand(1, 100);
                if ($roll <= 3) {
                    $attendanceRows[] = [$ids[$key], $date, null, null, 'absent', 0];
                    continue;
                }
                // 9:00 start with 15 min grace: in by 09:15 is 'present', later is 'late'.
                $inMinutes = $roll <= 15
                    ? 9 * 60 + mt_rand(16, 75)      // late: 09:16 - 10:15
                    : 8 * 60 + mt_rand(35, 75);     // on time: 08:35 - 09:15
                $outMinutes = 17 * 60 + mt_rand(55, 130); // 17:55 - 19:10
                $attendanceRows[] = [
                    $ids[$key], $date,
                    sprintf('%02d:%02d:00', intdiv($inMinutes, 60), $inMinutes % 60),
                    sprintf('%02d:%02d:00', intdiv($outMinutes, 60), $outMinutes % 60),
                    $roll <= 15 ? 'late' : 'present',
                    round(($outMinutes - $inMinutes) / 60, 2),
                ];
            }
        }
        $this->batchInsert('{{%attendance}}',
            ['employee_id', 'work_date', 'check_in', 'check_out', 'status', 'work_hours'],
            $attendanceRows);

        // ---------------------------------------------------------------- company info
        $this->batchInsert('{{%company_info}}', ['section', 'title', 'body'], $this->companyInfo($year));
    }

    public function safeDown()
    {
        foreach (['company_info', 'attendance', 'leave_balances', 'leave_requests', 'leave_types',
                     'salaries', 'employees', 'departments'] as $table) {
            if ($table === 'employees') {
                // Self-referencing FK: break the manager chain before deleting.
                $this->update('{{%employees}}', ['manager_id' => null]);
            }
            $this->delete("{{%$table}}");
        }
        // Reset the counters so a re-seed (`migrate/redo 2`) gives the same ids again
        // (ceo = 1 ... dev1 = 5 ...), which the docs and demo screenshots rely on.
        foreach (['company_info', 'attendance', 'leave_balances', 'leave_requests', 'leave_types',
                     'salaries', 'employees', 'departments'] as $table) {
            $this->execute("ALTER TABLE {{%$table}} AUTO_INCREMENT = 1");
        }
    }

    private function nextWorkingDay(DateTimeImmutable $d): DateTimeImmutable
    {
        while (in_array((int) $d->format('w'), self::WEEKEND, true)) {
            $d = $d->modify('+1 day');
        }
        return $d;
    }

    /** @return DateTimeImmutable[] $count consecutive working days starting at $start */
    private function workingDaysFrom(DateTimeImmutable $start, int $count): array
    {
        $out = [];
        for ($d = $start; count($out) < $count; $d = $d->modify('+1 day')) {
            if (!in_array((int) $d->format('w'), self::WEEKEND, true)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** Most recent 1 Jan / 1 Jul that is at least 3 months after joining (else joining + 3 months). */
    private function lastRaiseDate(DateTimeImmutable $joined, DateTimeImmutable $today): DateTimeImmutable
    {
        $earliest = $joined->modify('+3 months');
        $year = (int) $today->format('Y');
        $candidate = new DateTimeImmutable($today->format('m') >= 7 ? "$year-07-01" : "$year-01-01");
        return $candidate >= $earliest ? $candidate : $earliest;
    }

    private function companyInfo(int $year): array
    {
        return [
            ['leave', 'Annual leave',
                'Every confirmed employee gets 20 days of paid annual leave per calendar year. Apply at least 7 days in advance through the ERP; your line manager approves. Up to 5 unused days can be carried into the next year; the rest lapse on 31 December. Annual leave cannot be taken during probation.'],
            ['leave', 'Sick leave',
                'You get 14 days of paid sick leave per year. Inform your manager by 10:00 on the day. Absences longer than 2 consecutive days need a doctor\'s certificate uploaded within a week of returning. Unused sick leave does not carry over.'],
            ['leave', 'Casual leave',
                'You get 10 days of paid casual leave per year for personal errands and short notice needs. Maximum 3 consecutive days at a time. Apply at least 1 working day in advance where possible. Does not carry over.'],
            ['leave', 'Unpaid leave and how leave is approved',
                'Unpaid leave has no fixed quota and is granted at management discretion once paid leave is exhausted or during probation. All leave requests go to your line manager; department heads can see all requests in their department, and HR has final oversight. You can check your remaining balance and request status in the ERP or by asking this assistant.'],
            ['hours', 'Working hours and weekend',
                'Office hours are 09:00 to 18:00, Sunday to Thursday, with a one-hour lunch break between 13:00 and 14:00. Friday and Saturday are the weekly holidays. The standard working week is 40 hours.'],
            ['hours', 'Attendance and late arrival',
                'Check in and out using the office access card; this feeds the attendance system automatically. There is a 15-minute grace period: arriving after 09:15 is recorded as late. Three late arrivals in a calendar month count as one day of casual leave. Unexplained absence is treated as unpaid leave.'],
            ['hours', 'Remote work',
                'Engineering and HR staff may work from home up to 2 days per week with their manager\'s agreement. Sales staff work from the office or client sites. You must be reachable on the company chat during core hours (10:00 to 16:00) when working remotely.'],
            ['employment', 'Probation',
                'New joiners serve a 6-month probation. During probation the notice period is 7 days, annual leave cannot be taken (sick and casual leave can), and a review with your manager and HR happens in month 3 and month 6. Confirmation is communicated in writing.'],
            ['employment', 'Resignation and notice period',
                'Confirmed employees must give 60 days\' written notice (managers and above: 90 days). Notice can be bought out with basic salary at management discretion. Final settlement, including unused annual leave encashment, is paid within 30 days of the last working day.'],
            ['holidays', "Public holidays $year",
                'The company follows the Government of Bangladesh public holiday calendar, including Shaheed Day (21 Feb), Independence Day (26 Mar), Pohela Boishakh (14 Apr), May Day (1 May), Victory Day (16 Dec) and Christmas (25 Dec), plus Eid-ul-Fitr and Eid-ul-Adha (3 days each, dates per the moon). HR publishes the full list each January.'],
            ['finance', 'Reimbursement and expense claims',
                'Business expenses (client travel, conveyance, approved training, client meals) are reimbursed against original receipts. Submit claims in the ERP within 30 days of the expense; your manager approves and Finance pays with the next salary. Claims above BDT 20,000 need department head approval.'],
            ['finance', 'Payroll cycle',
                'Salary is paid monthly by bank transfer on the last working day of the month. Payslips are available in the ERP from the 1st of the following month. Salary consists of basic, house rent allowance and transport allowance. Income tax is deducted at source as required by the NBR.'],
            ['finance', 'Festival bonus',
                'Confirmed employees receive two festival bonuses per year, each equal to one month\'s basic salary, paid before Eid-ul-Fitr and Eid-ul-Adha (or an equivalent festival of the employee\'s choice). Employees on probation receive a pro-rated bonus.'],
            ['it', 'IT and acceptable use policy',
                'Company laptops are for work use. Do not install unlicensed software, share passwords, or store company data on personal cloud drives. Multi-factor authentication is mandatory for email and the ERP. Report lost devices or suspicious emails to IT immediately. Do not paste confidential company or employee data into public AI tools.'],
            ['contacts', 'Who to contact for what',
                'Leave, payroll, payslips, policies and employment letters: HR (Nusrat Jahan, hr@demo.local). Laptop, accounts, access cards and software: IT helpdesk (it@demo.local). Expense claims and reimbursements: Finance (finance@demo.local). Day-to-day work questions and leave approval: your line manager.'],
            ['benefits', 'Provident fund and health cover',
                'After confirmation, 10% of basic salary is contributed to the provident fund, matched 10% by the company, vesting after 3 years. All employees and their spouse and up to two children are covered by group health insurance from day one.'],
        ];
    }
}
