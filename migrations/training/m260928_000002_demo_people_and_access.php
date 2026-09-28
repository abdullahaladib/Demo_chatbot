<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Demo access on top of the imported training ERP (owner: "feel free to create users as
 * needed, and share the passwords with me").
 *
 * 1. Tier assignments (ai_role_assignment). Nobody active in the ERP is HR or an executive
 *    (the CEO and MD are "Not In Service"), and department heads are never recorded, so:
 *      ceo       -> 1001 Bimol Chandra Das, Chief Technical Officer (top active executive)
 *      dept_head -> 1005 Payer Alam Rony, CTO (Operation), head of department 10 "Engineer"
 *      hr        -> a NEW, clearly labelled demo employee in "HR & Admin" (created below)
 *    'manager' / 'employee' are derived automatically by RoleResolver.
 * 2. One demo employee + login for HR (copied from an existing row as a template so every
 *    required ERP column is valid; personal fields are blanked).
 * 3. Demo password `Demo@1234` (bcrypt, in ai_user_credential) for every login linked to an
 *    in-service employee. The ERP's own password column is not changed.
 */
class m260928_000002_demo_people_and_access extends Migration
{
    public const DEMO_PASSWORD = 'Demo@1234';
    private const HR_CODE = 'DEMO-HR';
    private const HR_USERNAME = 'hr.demo';

    public function safeUp()
    {
        $db = $this->db;
        // ERP rows use zero dates ('0000-00-00') for "not set"; write them the ERP's way.
        // (Session-only; restored at the end.)
        $mode = $db->createCommand('SELECT @@SESSION.sql_mode')->queryScalar();
        $this->execute("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        // ---- HR designation (the ERP has none)
        $desgId = (new Query())->select('DESG_ID')->from('designation')->where(['DESG_DESC' => 'HR Manager'])->scalar($db);
        if (!$desgId) {
            $this->insert('designation', ['DESG_DESC' => 'HR Manager', 'DESG_SHORT_NAME' => 'HRM',
                'DESG_DESC_BN' => '', 'DESG_SHORT_NAME_BN' => '']);
            $desgId = (int) $db->getLastInsertID();
        }

        // ---- demo HR employee, templated on an existing in-service row
        $template = (new Query())->from('personnel_basic_info')->where(['pbi_id' => 45710])->one($db);
        unset($template['pbi_id']);
        $blank = ['last_name', 'pbi_email', 'pbi_phone', 'pbi_mobile', 'job_description', 'job_title',
            'pbi_picture_att_path', 'pbi_nid_att_path', 'pbi_tin_att_path', 'pbi_cv_att_path'];
        $hr = array_merge($template, array_fill_keys($blank, ''), [
            'pbi_code' => self::HR_CODE,
            'machine_id' => self::HR_CODE,
            'pbi_name' => 'Farzana Rahman (Demo HR)',
            'last_name' => 'Demo HR',
            'pbi_email' => 'hr.demo@demo.local',
            'pbi_dob' => null,
            'dept_id' => 2,              // HR & Admin
            'desg_id' => (int) $desgId,
            'joining_designation' => 'HR Manager',
            'pbi_job_status' => 'In Service',
            'pbi_doj' => '2024-01-01',
            'incharge_id' => 1001,
            'incharge_id_2' => 0,
            'leave_rule_id' => 0,
            'resign_date' => '0000-00-00',
            'entry_at' => date('Y-m-d H:i:s'),
            'edit_at' => date('Y-m-d H:i:s'),
        ]);
        $this->insert('personnel_basic_info', $hr);
        $hrId = (int) $db->getLastInsertID();

        $userTemplate = (new Query())->from('user_activity_management')->where(['PBI_ID' => 45710])->one($db);
        unset($userTemplate['user_id']);
        $this->insert('user_activity_management', array_merge($userTemplate, [
            'username' => self::HR_USERNAME,
            'password' => md5(self::DEMO_PASSWORD), // ERP-format column; login uses ai_user_credential
            'level' => 3,                            // "HRM Manager" in the ERP's own module levels
            'fname' => 'Farzana Rahman (Demo HR)',
            'email' => 'hr.demo@demo.local',
            'mobile' => '',
            'designation' => 'HR Manager',
            'department' => 'HR & Admin',
            'status' => 'Active',
            'PBI_ID' => $hrId,
            'user_pic' => '',
            'token' => '',
            'otp_verified' => '',
            'sign' => '',
            'dob' => '0000-00-00',
            'entry_at' => date('Y-m-d H:i:s'),
        ]));

        // ---- tier assignments
        $this->batchInsert('ai_role_assignment', ['pbi_id', 'role', 'dept_id', 'note'], [
            [1001, 'ceo', null, 'Top active executive (CTO); the ERP CEO/MD are Not In Service'],
            [1005, 'dept_head', 10, 'CTO (Operation) as head of department 10 Engineer'],
            [$hrId, 'hr', null, 'Demo HR person created for the chatbot demo'],
        ]);

        // ---- demo password for every login of an in-service employee
        $users = (new Query())->select(['u.user_id'])->from(['u' => 'user_activity_management'])
            ->innerJoin(['p' => 'personnel_basic_info'], 'p.pbi_id = u.PBI_ID')
            ->where(['p.pbi_job_status' => 'In Service'])->andWhere(['>', 'u.PBI_ID', 0])
            ->column($db);
        $hash = Yii::$app->security->generatePasswordHash(self::DEMO_PASSWORD, 10);
        $this->batchInsert('ai_user_credential', ['user_id', 'password_hash'],
            array_map(fn($id) => [(int) $id, $hash], array_unique($users)));

        $this->execute('SET SESSION sql_mode = ' . $db->quoteValue($mode));
    }

    public function safeDown()
    {
        $hrId = (new Query())->select('pbi_id')->from('personnel_basic_info')->where(['pbi_code' => self::HR_CODE])->scalar($this->db);
        $this->delete('ai_user_credential');
        $this->delete('ai_role_assignment');
        $this->delete('user_activity_management', ['username' => self::HR_USERNAME]);
        if ($hrId) {
            $this->delete('personnel_basic_info', ['pbi_id' => $hrId]);
        }
        $this->delete('designation', ['DESG_DESC' => 'HR Manager', 'DESG_SHORT_NAME' => 'HRM']);
    }
}
