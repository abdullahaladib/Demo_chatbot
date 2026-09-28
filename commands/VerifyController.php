<?php

declare(strict_types=1);

namespace app\commands;

use app\components\ai\ChatService;
use app\components\ai\PromptBuilder;
use app\components\ai\provider\LlmTurn;
use app\components\ai\provider\ProviderError;
use app\components\ai\provider\ProviderFactory;
use app\components\ai\provider\ScriptedProvider;
use app\components\ai\QueryGateway;
use app\components\RoleResolver;
use app\components\TestHttpClient;
use app\models\ChatAuditLog;
use app\models\Employee;
use app\models\ErpUser;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Repeatable acceptance checks for the chatbot on the imported TRAINING ERP (erp_training).
 * No AI calls anywhere except actionDemo (live, only when the owner asks).
 *
 *   php yii verify/all                                    phases 1-6 (needs `php yii serve`)
 *   php yii verify/all --baseUrl=http://localhost:8081    against a temporary test server
 *   php yii verify/demo                                   the five demo questions, LIVE AI
 *
 * The expected numbers below are facts of the training dump + our demo-access migration
 * (see CLAUDE.md "Seed data facts"). If the dump is replaced, update them.
 */
class VerifyController extends Controller
{
    /** Base URL of a running instance (`php yii serve`) for the HTTP-level checks. */
    public string $baseUrl = 'http://localhost:8080';

    private int $passed = 0;
    private int $failed = 0;

    // Demo people (ERP usernames) and the facts the checks rely on.
    private const EXEC = 'bimol';      // 1001 Bimol Chandra Das, CTO        -> ceo (assigned)
    private const HR = 'hr.demo';      // Farzana Rahman (Demo HR)            -> hr (created)
    private const DEPT_HEAD = '1005';  // 1005 Payer Alam Rony, CTO (Ops)     -> dept_head of dept 10
    private const MANAGER = '1002';    // 1002 Kawsar Mahmud, Sr. PM          -> manager (3 direct reports)
    private const EMPLOYEE = 'tanvir'; // 1960 Tanvir Ahmmed, Jr. SE          -> employee
    private const ENGINEER_DEPT = 10;
    private const ENG_AVG_GROSS = 66429;
    private const TANVIR_CL_REMAINING = 13;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['baseUrl']);
    }

    public function actionAll(): int
    {
        foreach (['phase1', 'phase2', 'phase3', 'phase4', 'phase5', 'phase6'] as $phase) {
            $this->runAction($phase);
        }
        return $this->summary();
    }

    // =================================================================== Phase 1
    public function actionPhase1(): int
    {
        $this->section('Phase 1 - training ERP import, app tables, demo access, tier resolution');
        $db = Yii::$app->db;

        $dbName = $db->createCommand('SELECT DATABASE()')->queryScalar();
        $this->check("app database is erp_training (got $dbName)", $dbName === 'erp_training');
        $tables = (int) $db->createCommand("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->queryScalar();
        $this->check("all 1288 ERP tables imported, plus ai_* tables (got $tables)", $tables >= 1288 + 5);

        foreach ([
            'personnel_basic_info' => 56, 'user_activity_management' => 77, 'hrm_leave_info' => 731,
            'hrm_att_summary' => 18922, 'salary_info' => 51, 'hrm_leave_type' => 12,
        ] as $table => $count) {
            $n = (int) (new Query())->from($table)->count('*', $db);
            $this->check("`$table` has $count rows (got $n)", $n === $count);
        }

        foreach (['ai_role_assignment', 'ai_user_credential', 'ai_knowledge_base', 'ai_chat_audit_log', 'ai_migration'] as $t) {
            $this->check("app table `$t` exists", $db->getTableSchema($t, true) !== null);
        }
        $applied = (int) (new Query())->from('ai_migration')->where(['like', 'version', 'm2609'])->count('*', $db);
        $this->check("4 training migrations applied (got $applied)", $applied === 4);

        $this->check('3 tier assignments (ceo, dept_head, hr)',
            (function ($r) { sort($r); return $r; })((new Query())->select('role')->from('ai_role_assignment')->column($db)) === ['ceo', 'dept_head', 'hr']);
        $creds = (int) (new Query())->from('ai_user_credential')->count('*', $db);
        $this->check("demo password issued to every in-service login ($creds)", $creds === 35);

        $hr = $this->who(self::HR);
        $this->check('demo HR person exists, in service, in "HR & Admin"',
            $hr !== null && $hr->isActive() && (int) $hr->dept_id === 2 && str_contains($hr->full_name, 'Demo HR'));

        foreach ([self::EXEC => 'ceo', self::HR => 'hr', self::DEPT_HEAD => 'dept_head', self::MANAGER => 'manager',
                     self::EMPLOYEE => 'employee', '1954' => 'employee'] as $user => $role) {
            $e = $this->who($user);
            $this->check("tier: {$user} -> $role", $e !== null && $e->role === $role, $e ? $e->full_name . ' = ' . $e->role : 'not found');
        }
        $this->check('dept head is scoped to department 10 (Engineer)', $this->who(self::DEPT_HEAD)?->department_id === self::ENGINEER_DEPT);
        $this->check('manager-ness comes from the org chart, not the ERP `level` (Kawsar is level 5 "Supreme Administrator")',
            (int) ErpUser::findByUsername(self::MANAGER)->level === 5 && $this->who(self::MANAGER)->role === 'manager');

        $former = (new Query())->select('u.username')->from(['u' => 'user_activity_management'])
            ->innerJoin(['p' => 'personnel_basic_info'], 'p.pbi_id = u.PBI_ID')
            ->where(['<>', 'p.pbi_job_status', 'In Service'])->andWhere(['<>', 'u.username', ''])->scalar($db);
        $form = new \app\models\LoginForm(['username' => (string) $former, 'password' => 'Demo@1234']);
        $this->check("a former employee's login cannot sign in ($former)", $former && !$form->validate());

        $kb = (new Query())->from('ai_knowledge_base')->count('*', $db);
        $leave = (string) (new Query())->select('body')->from('ai_knowledge_base')->where(['section' => 'leave'])->scalar($db);
        $this->check("knowledge base built from ERP policy tables ($kb rows)", $kb >= 10 && str_contains($leave, 'Casual Leave (CL) - 15 days'));
        $this->check('dbAi component is configured', Yii::$app->has('dbAi'));

        return $this->summary();
    }

    // =================================================================== Phase 2
    public function actionPhase2(): int
    {
        $this->section('Phase 2 - role views and the restricted erp_ai_ro account');
        $db = Yii::$app->db;
        $ai = Yii::$app->dbAi;

        $views = $db->createCommand(
            "SELECT TABLE_NAME, SECURITY_TYPE, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
        )->queryAll();
        $public = array_filter($views, fn($v) => str_starts_with($v['TABLE_NAME'], 'v_'));
        $helpers = array_filter($views, fn($v) => str_starts_with($v['TABLE_NAME'], 'ai_'));
        $this->check('25 v_* views + 6 internal ai_* helper views', count($public) === 25 && count($helpers) === 6);
        $this->check('every view is SQL SECURITY DEFINER', count(array_filter($views, fn($v) => $v['SECURITY_TYPE'] === 'DEFINER')) === count($views));
        $this->check('the access map lists exactly the 25 v_* views',
            array_keys(\app\components\ai\AccessMap::config()['views']) == array_values(array_intersect(
                array_keys(\app\components\ai\AccessMap::config()['views']), array_column($public, 'TABLE_NAME'))));

        $salaryAllowed = ['v_hr_employees_full', 'v_hr_payroll', 'v_exec_payroll_summary'];
        $offenders = array_filter($public, fn($v) =>
            (stripos($v['VIEW_DEFINITION'], '`salary_info`') !== false) !== in_array($v['TABLE_NAME'], $salaryAllowed, true));
        $this->check('only v_hr_employees_full / v_hr_payroll / v_exec_payroll_summary read salary_info',
            $offenders === [], implode(', ', array_column($offenders, 'TABLE_NAME')));

        $leaky = $db->createCommand(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'v\\_%'
               AND COLUMN_NAME REGEXP 'pass|token|otp|(^|_)dob($|_)|birth|religion|(^|_)nid($|_)|(^|_)tin($|_)|(^|_)cv($|_)|picture|photo|ac_no|account|branch_info|^sign($|_)'"
        )->queryColumn();
        $this->check('no v_* view exposes passwords, tokens, DOB, religion, NID/TIN/CV/photo paths or bank accounts',
            $leaky === [], implode(', ', $leaky));

        $user = $ai->createCommand('SELECT CURRENT_USER()')->queryScalar();
        $this->check("dbAi authenticates as erp_ai_ro (got $user)", str_starts_with((string) $user, 'erp_ai_ro@'));
        $grants = $ai->createCommand('SHOW GRANTS')->queryColumn();
        $nonView = array_filter($grants, fn($g) => !str_starts_with($g, 'GRANT USAGE ON *.*')
            && !preg_match('/^GRANT SELECT ON `erp_training`\.`v_[a-z_]+` TO /', $g));
        $this->check('SHOW GRANTS lists only SELECT on v_* views (' . count($grants) . ' lines)', $nonView === [] && count($grants) === 26);

        $n = (int) $ai->createCommand('SELECT COUNT(*) FROM v_employee_directory')->queryScalar();
        $this->check("erp_ai_ro can SELECT a view (v_employee_directory: $n rows)", $n === 35);

        foreach ([
            'base table salary_info' => 'SELECT AVG(gross_salary) FROM salary_info',
            'base table personnel_basic_info' => 'SELECT * FROM personnel_basic_info LIMIT 1',
            'base table user_activity_management (passwords)' => 'SELECT password FROM user_activity_management LIMIT 1',
            'internal helper view ai_employee' => 'SELECT * FROM ai_employee LIMIT 1',
            'internal helper view ai_reporting_line' => 'SELECT * FROM ai_reporting_line LIMIT 1',
            'INSERT into a view' => "INSERT INTO v_employee_directory (full_name) VALUES ('x')",
            'UPDATE a view' => 'UPDATE v_hr_payroll SET basic_salary = 1',
            'CREATE TABLE' => 'CREATE TABLE hack (id INT)',
            'read mysql.user' => 'SELECT user FROM mysql.user LIMIT 1',
        ] as $label => $sql) {
            [$refused, $msg] = $this->mysqlRefuses($ai, $sql);
            $this->check("MySQL refuses $label", $refused, $msg);
        }

        $leak = (int) $ai->createCommand("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'erp_training' AND TABLE_TYPE = 'BASE TABLE'")->queryScalar();
        $this->check('the 1288 base tables are invisible to erp_ai_ro in information_schema', $leak === 0);
        $session = $ai->createCommand('SELECT @@SESSION.max_execution_time, @@SESSION.transaction_read_only')->queryOne(\PDO::FETCH_NUM);
        $this->check("dbAi session: 5s statement timeout, read-only (got {$session[0]}ms, ro={$session[1]})",
            (int) $session[0] === 5000 && (int) $session[1] === 1);

        $line = $db->createCommand('SELECT COUNT(*) self_pairs, MAX(depth) max_depth, COUNT(*) - COUNT(DISTINCT employee_id, supervisor_id) dupes FROM ai_reporting_line WHERE employee_id = supervisor_id OR 1=1')->queryOne();
        $self = (int) $db->createCommand('SELECT COUNT(*) FROM ai_reporting_line WHERE employee_id = supervisor_id')->queryScalar();
        $this->check("reporting chain is cycle-safe: no self-pairs, depth <= 6, one row per pair (max depth {$line['max_depth']})",
            $self === 0 && (int) $line['max_depth'] <= 6 && (int) $line['dupes'] === 0);

        $small = $db->createCommand("SELECT COUNT(*) FROM v_exec_payroll_summary WHERE employees_with_salary < 5 AND avg_gross_salary IS NOT NULL")->queryScalar();
        $this->check('payroll summary suppresses pay figures for groups under 5 people', (int) $small === 0);

        return $this->summary();
    }

    // =================================================================== Phase 3
    public function actionPhase3(): int
    {
        $this->section("Phase 3 - ERP login and demo role switcher (HTTP against {$this->baseUrl})");
        $http = new TestHttpClient($this->baseUrl);

        $r = $http->get('/index.php?r=site/index');
        $this->check('guest is redirected to login', $r['code'] === 302 && str_contains($r['location'], 'site%2Flogin'));
        $r = $http->get('/index.php?r=site/login');
        $this->check('login page renders with a username field', $r['code'] === 200 && str_contains($r['body'], 'LoginForm[username]'));
        $names = ['Bimol Chandra Das', 'Farzana Rahman (Demo HR)', 'Payer Alam Rony', 'Kawsar Mahmud', 'Tanvir Ahmmed', 'Md Nizam Uddin'];
        $this->check('login page lists the 6 demo people (one per tier)', count(array_filter($names, fn($n) => str_contains($r['body'], $n))) === 6);

        $r = $http->post('/index.php?r=site/login', ['LoginForm[username]' => self::EMPLOYEE, 'LoginForm[password]' => 'wrong']);
        $this->check('wrong password is rejected', $r['code'] === 200 && str_contains($r['body'], 'Incorrect username or password'));
        $r = $http->post('/index.php?r=site/login', ['LoginForm[username]' => self::EMPLOYEE, 'LoginForm[password]' => 'Demo@1234']);
        $this->check('tanvir signs in with the demo password', $r['code'] === 302);
        $r = $http->get('/index.php?r=site/index');
        $this->check('home shows Tanvir Ahmmed as Employee', str_contains($r['body'], 'Tanvir Ahmmed') && str_contains($r['body'], 'role-employee'));

        $deptHead = $this->who(self::DEPT_HEAD);
        $r = $http->get('/index.php?r=site/switch-user&id=' . $deptHead->id);
        $this->check('switch-user refuses GET', $r['code'] === 405);
        $r = $http->post('/index.php?r=site/switch-user', ['id' => $deptHead->id], withCsrf: false);
        $this->check('switch-user refuses POST without CSRF token', $r['code'] === 400);
        $r = $http->post('/index.php?r=site/switch-user', ['id' => $deptHead->id]);
        $this->check('switch-user (POST + CSRF) succeeds', $r['code'] === 302);
        $r = $http->get('/index.php?r=site/index');
        $this->check('now signed in as Payer Alam Rony / Department Head',
            str_contains($r['body'], 'Payer Alam Rony') && str_contains($r['body'], 'role-dept_head'));
        $this->check('navbar switcher lists the 6 demo people', substr_count($r['body'], 'site%2Fswitch-user') >= 6);

        $http->post('/index.php?r=site/logout', []);
        $r = $http->get('/index.php?r=site/index');
        $this->check('logout returns to guest', $r['code'] === 302);

        return $this->summary();
    }

    // =================================================================== Phase 4
    public function actionPhase4(): int
    {
        $this->section('Phase 4 - validator, session binding, prompt builder, audit (no AI)');
        $run = fn(string $user, string $sql) => (new QueryGateway($this->who($user)))->run($sql);
        $tanvir = $this->who(self::EMPLOYEE);
        $kawsar = $this->who(self::MANAGER);

        // ---------------------------------------------------------------- allowed + row scope
        $r = $run(self::EMPLOYEE, 'SELECT employee_id, leave_type, remaining FROM v_my_leave_balance WHERE employee_id = :me');
        $cl = array_values(array_filter($r['rows'], fn($x) => str_starts_with($x['leave_type'], 'Casual')))[0]['remaining'] ?? null;
        $this->check('ALLOWED: tanvir reads own leave balance (Casual remaining ' . self::TANVIR_CL_REMAINING . ')',
            $r['status'] === 'ok' && (int) $cl === self::TANVIR_CL_REMAINING, $r['reason']);
        $this->check('  :me bound from the session to pbi_id 1960', ($r['bindings']['me'] ?? null) === $tanvir->id
            && array_unique(array_column($r['rows'], 'employee_id')) == [$tanvir->id]);
        $this->check('  LIMIT 200 appended; view wrapped in a scoped derived table', str_ends_with($r['finalSql'], 'LIMIT 200')
            && str_contains($r['finalSql'], '(SELECT * FROM `v_my_leave_balance` WHERE `employee_id` = :me)'));

        $r = $run(self::EMPLOYEE, 'SELECT DISTINCT employee_id FROM v_my_attendance_daily WHERE employee_id = :me OR 1=1');
        $this->check("ROW SCOPE: `employee_id = :me OR 1=1` still returns only tanvir's rows",
            $r['status'] === 'ok' && array_column($r['rows'], 'employee_id') == [$tanvir->id], json_encode(array_column($r['rows'], 'employee_id')));

        $r = $run(self::MANAGER, 'SELECT DISTINCT full_name, depth FROM v_team_members WHERE supervisor_id = :me OR 1=1');
        $this->check('TEAM SCOPE: manager sees only his 3 direct reports, even with OR 1=1',
            $r['status'] === 'ok' && count($r['rows']) === 3 && array_unique(array_column($r['rows'], 'depth')) == [1], json_encode($r['rows']));

        $r = $run(self::EXEC, 'SELECT COUNT(*) n, MAX(depth) d FROM v_team_members WHERE supervisor_id = :me');
        $this->check('TEAM SCOPE: executive sees his whole reporting line (21, several levels deep)',
            (int) ($r['rows'][0]['n'] ?? 0) === 21 && (int) ($r['rows'][0]['d'] ?? 0) >= 2, json_encode($r['rows']));

        $r = $run(self::DEPT_HEAD, "SELECT COUNT(*) n, MIN(department_id) d1, MAX(department_id) d2 FROM v_dept_leave_requests WHERE department_id = :dept AND status = 'Pending'");
        $this->check('DEPT SCOPE: dept head reads pending leave of department 10 only',
            $r['status'] === 'ok' && (int) $r['rows'][0]['n'] > 0 && (int) $r['rows'][0]['d1'] === self::ENGINEER_DEPT
            && (int) $r['rows'][0]['d2'] === self::ENGINEER_DEPT && ($r['bindings']['dept'] ?? null) === self::ENGINEER_DEPT);

        $r = $run(self::EMPLOYEE, "SELECT full_name FROM v_employee_directory WHERE designation = 'FROM salary_info; DROP TABLE x -- #'");
        $this->check('forbidden text inside a string literal is data, not SQL', $r['status'] === 'ok', $r['reason']);
        $r = $run(self::EMPLOYEE, 'SELECT full_name FROM v_employee_directory LIMIT 5000');
        $this->check('LIMIT 5000 rewritten to 200', $r['status'] === 'ok' && str_ends_with($r['finalSql'], 'LIMIT 200'));

        // ---------------------------------------------------------------- the demo pair
        $avg = "SELECT ROUND(AVG(gross_salary)) AS avg_salary FROM v_hr_employees_full WHERE department = 'Engineer' AND employment_status = 'Active'";
        $r = $run(self::EMPLOYEE, $avg);
        $this->check('DENIED: tanvir -> average salary', $r['status'] === 'denied'
            && $r['userMessage'] === "You're not authorised to access salary information.", $r['userMessage']);
        $r = $run(self::HR, $avg);
        $this->check('ALLOWED: HR, same SQL -> ' . self::ENG_AVG_GROSS, $r['status'] === 'ok' && (int) ($r['rows'][0]['avg_salary'] ?? 0) === self::ENG_AVG_GROSS);

        // ---------------------------------------------------------------- tier boundaries
        $boundaries = [
            'employee cannot use team views' => [self::EMPLOYEE, 'SELECT * FROM v_team_members WHERE supervisor_id = :me', 'not_authorised'],
            'manager cannot use department views' => [self::MANAGER, 'SELECT * FROM v_dept_employees WHERE department_id = :dept', 'not_authorised'],
            'manager cannot use HR views' => [self::MANAGER, 'SELECT * FROM v_hr_payroll', 'not_authorised'],
            'dept head cannot see salaries' => [self::DEPT_HEAD, 'SELECT * FROM v_hr_employees_full', 'not_authorised'],
            'HR cannot use executive summaries' => [self::HR, 'SELECT * FROM v_exec_payroll_summary', 'not_authorised'],
            'team view scoped on the wrong column' => [self::MANAGER, 'SELECT * FROM v_team_members WHERE employee_id = :me', 'missing_scope'],
            'dept view without :dept' => [self::DEPT_HEAD, "SELECT * FROM v_dept_leave_requests WHERE status = 'Pending'", 'missing_scope'],
            'self view without :me' => [self::EMPLOYEE, 'SELECT * FROM v_my_leave_balance', 'missing_scope'],
        ];
        foreach ($boundaries as $label => [$user, $sql, $code]) {
            $r = $run($user, $sql);
            $this->check("DENIED: $label", $r['status'] === 'denied' && $r['code'] === $code, $r['code'] . ' / ' . $r['reason']);
        }
        $r = $run(self::EXEC, 'SELECT department, avg_gross_salary FROM v_exec_payroll_summary');
        $this->check('ALLOWED: executive reads v_exec_payroll_summary', $r['status'] === 'ok' && count($r['rows']) >= 1);

        // ---------------------------------------------------------------- rejections
        $denied = [
            'ERP base table salary_info' => [self::EMPLOYEE, 'SELECT AVG(gross_salary) FROM salary_info'],
            'ERP base table even for the executive' => [self::EXEC, 'SELECT AVG(gross_salary) FROM salary_info'],
            'ERP login table (passwords)' => [self::HR, 'SELECT username, password FROM user_activity_management'],
            'internal helper view' => [self::HR, 'SELECT * FROM ai_employee'],
            'base table in a subquery' => [self::EMPLOYEE, 'SELECT full_name FROM v_employee_directory WHERE full_name IN (SELECT pbi_name FROM personnel_basic_info)'],
            'base table in a comma join' => [self::EMPLOYEE, 'SELECT * FROM v_employee_directory, salary_info'],
            'base table via UNION' => [self::EMPLOYEE, 'SELECT full_name FROM v_employee_directory UNION SELECT gross_salary FROM salary_info'],
            'UNION TABLE' => [self::EMPLOYEE, 'SELECT 1 UNION TABLE salary_info'],
            'parenthesised table ref' => [self::EMPLOYEE, 'SELECT * FROM (salary_info)'],
            'backtick-quoted forbidden view' => [self::EMPLOYEE, 'SELECT * FROM `V_HR_PAYROLL`'],
            'schema-qualified table' => [self::HR, 'SELECT * FROM erp_training.v_hr_payroll'],
            'INFORMATION_SCHEMA' => [self::HR, 'SELECT table_name FROM information_schema.tables'],
            'mysql.user' => [self::HR, 'SELECT user FROM mysql.user'],
            'UPDATE' => [self::HR, 'UPDATE v_hr_payroll SET basic_salary = 1'],
            'DELETE' => [self::HR, 'DELETE FROM v_my_profile WHERE employee_id = :me'],
            'DROP' => [self::HR, 'DROP VIEW v_employee_directory'],
            'WITH (CTE)' => [self::HR, 'WITH x AS (SELECT * FROM v_hr_payroll) SELECT * FROM x'],
            'SET' => [self::HR, 'SET @a = 1'],
            'stacked statements' => [self::HR, 'SELECT 1; DROP TABLE salary_info'],
            '-- comment' => [self::HR, 'SELECT full_name FROM v_employee_directory -- hi'],
            '/* */ comment' => [self::HR, 'SELECT /*!50000 full_name */ FROM v_employee_directory'],
            'INTO OUTFILE' => [self::HR, "SELECT * FROM v_hr_payroll INTO OUTFILE 'C:/x.csv'"],
            'SLEEP()' => [self::HR, 'SELECT SLEEP(10)'],
            '@@system variable' => [self::HR, 'SELECT @@version'],
            'unknown placeholder' => [self::EMPLOYEE, 'SELECT * FROM v_my_profile WHERE employee_id = :id'],
            'FOR UPDATE' => [self::HR, 'SELECT * FROM v_hr_payroll FOR UPDATE'],
            'LIMIT with a non-literal' => [self::HR, 'SELECT * FROM v_hr_payroll LIMIT :me'],
        ];
        $leakyMessage = false;
        foreach ($denied as $label => [$user, $sql]) {
            $r = $run($user, $sql);
            $this->check("REFUSED: $label", $r['status'] === 'denied' && $r['rows'] === [], $r['code'] . ' / ' . $r['reason']);
            $leakyMessage = $leakyMessage || preg_match('/SQLSTATE|MySQL|v_[a-z]+_|select|error \d/i', $r['userMessage']);
        }
        $this->check('refusal messages are plain English (no SQL / view names / error codes)', !$leakyMessage);

        $r = $run(self::EMPLOYEE, 'SELECT no_such_column FROM v_employee_directory');
        $this->check('MySQL error (unknown column) returns a safe message', $r['status'] === 'error'
            && $r['code'] === 'bad_query' && !str_contains($r['userMessage'], 'no_such_column'), $r['userMessage']);

        // ---------------------------------------------------------------- prompt builder
        $pb = new PromptBuilder();
        $pTanvir = $pb->build($tanvir);
        $this->check("employee's prompt mentions no salary column or v_hr_/v_exec_/v_team_/v_dept_ view",
            !preg_match('/salary|house_rent|v_hr_|v_exec_|v_team_|v_dept_/i', $this->promptWithoutKb($pTanvir)));
        $this->check("employee's prompt has the knowledge base and REQUIRES employee_id = :me",
            str_contains($pTanvir, 'Casual Leave (CL) - 15 days') && str_contains($pTanvir, 'REQUIRES: WHERE employee_id = :me'));
        $this->check("employee's prompt does not contain their pbi_id", !preg_match('/\b1960\b/', $this->promptWithoutKb($pTanvir)));
        $this->check("manager's prompt: v_team_* with supervisor_id = :me", str_contains($pb->build($kawsar), 'REQUIRES: WHERE supervisor_id = :me'));
        $pHr = $pb->build($this->who(self::HR));
        $this->check("HR's prompt includes gross_salary", str_contains($pHr, 'VIEW v_hr_employees_full') && str_contains($pHr, 'gross_salary'));
        $pDh = $pb->build($this->who(self::DEPT_HEAD));
        $this->check("dept head's prompt maps 'my department' to v_dept_* / :dept",
            str_contains($pDh, 'department_id = :dept') && !str_contains($pDh, 'VIEW v_hr_'));

        // ---------------------------------------------------------------- audit + bench
        $before = (int) ChatAuditLog::find()->count();
        $row = ChatAuditLog::record(['employee_id' => $tanvir->id, 'role' => 'employee', 'question' => 'verify', 'path' => 'denied', 'provider' => 'verify']);
        $this->check('audit row written to ai_chat_audit_log', $row !== null && (int) ChatAuditLog::find()->count() === $before + 1);
        $row?->delete();

        $http = new TestHttpClient($this->baseUrl);
        $http->post('/index.php?r=site/login', ['LoginForm[username]' => self::EMPLOYEE, 'LoginForm[password]' => 'Demo@1234']);
        $startId = (int) ChatAuditLog::find()->max('id');
        $r = $http->post('/index.php?r=security-test/index', ['sql' => 'SELECT AVG(gross_salary) FROM salary_info']);
        $this->check('test bench (as tanvir): base-table query REFUSED', str_contains($r['body'], 'REFUSED') && str_contains($r['body'], 'not authorised to access salary information'));
        $r = $http->post('/index.php?r=security-test/index', ['sql' => 'SELECT leave_type, remaining FROM v_my_leave_balance WHERE employee_id = :me']);
        $this->check('test bench (as tanvir): own balance ALLOWED with :me = 1960', str_contains($r['body'], 'ALLOWED') && str_contains($r['body'], ':me = 1960'));
        $this->check('test bench wrote 2 audit rows', (int) ChatAuditLog::find()->where(['>', 'id', $startId])->count() === 2);
        ChatAuditLog::deleteAll(['>', 'id', $startId]);

        return $this->summary();
    }

    // =================================================================== Phase 5
    public function actionPhase5(): int
    {
        $this->section('Phase 5 - chat flow with a scripted stand-in model (no API key, no AI calls)');
        $startId = (int) ChatAuditLog::find()->max('id');
        $turn = fn(string $text, array $calls = []) => new LlmTurn($text, $calls);
        $call = fn(string $name, array $args = []) => ['id' => 'call_' . bin2hex(random_bytes(3)), 'name' => $name, 'args' => $args];

        // A well-behaved "model" for the five demo questions; it only writes SQL against
        // views it can see in the system prompt it was given.
        $sane = function (string $q, array $results, int $round, string $system) use ($turn, $call) {
            $q = strtolower($q);
            if (str_contains($q, 'policy')) {
                return $turn('Casual leave is 15 days a year, sick leave 6.');
            }
            if ($round === 0) {
                return $turn('', [$call('getUserRole')]);
            }
            $last = end($results)['result'] ?? [];
            if (isset($last['rows'])) {
                return $turn('Here is what I found: ' . json_encode($last['rows']));
            }
            if (str_contains($q, 'average salary')) {
                if (!str_contains($system, 'gross_salary')) {
                    return $turn(PromptBuilder::DENIAL_MARKER . ' salary information');
                }
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT ROUND(AVG(gross_salary)) AS avg_salary FROM v_hr_employees_full WHERE department = 'Engineer' AND employment_status = 'Active' LIMIT 1"])]);
            }
            if (str_contains($q, 'casual leave')) {
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT leave_type, remaining FROM v_my_leave_balance WHERE employee_id = :me AND leave_type LIKE 'Casual%' LIMIT 5"])]);
            }
            if (str_contains($q, 'my department')) {
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT employee_name, leave_type, start_date, days FROM v_dept_leave_requests WHERE department_id = :dept AND status = 'Pending' LIMIT 100"])]);
            }
            if (str_contains($q, 'my team')) {
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT employee_name, leave_type, start_date FROM v_team_leave_requests WHERE supervisor_id = :me AND depth = 1 AND status = 'Pending' LIMIT 50"])]);
            }
            return $turn('I am not sure.');
        };
        $ask = function (string $user, string $question, callable $script) {
            $before = (int) ChatAuditLog::find()->count();
            $out = (new ChatService(new ScriptedProvider($script)))->ask($this->who($user), $question);
            $out['_auditDelta'] = (int) ChatAuditLog::find()->count() - $before;
            $out['_audit'] = ChatAuditLog::find()->orderBy(['id' => SORT_DESC])->one();
            return $out;
        };
        $tanvir = $this->who(self::EMPLOYEE);

        $r = $ask(self::EMPLOYEE, "what's our leave policy?", $sane);
        $this->check('#1 tanvir: leave policy -> info path, no SQL', $r['path'] === 'info' && $r['queries'] === [] && $r['_audit']->path === 'info');

        $r = $ask(self::EMPLOYEE, 'how many casual leave days do I have left?', $sane);
        $this->check('#2 tanvir: casual leave left -> data, :me = 1960, remaining ' . self::TANVIR_CL_REMAINING,
            $r['path'] === 'data' && ($r['queries'][0]['bindings']['me'] ?? null) === $tanvir->id
            && (int) ($r['table']['rows'][0]['remaining'] ?? -1) === self::TANVIR_CL_REMAINING);
        $this->check('#2 getUserRole returned the session role', ($r['trace'][0]['step'] ?? '') === 'getUserRole' && $r['trace'][0]['detail'] === 'employee');

        $r = $ask(self::EMPLOYEE, "what's the average salary in engineering?", $sane);
        $this->check('#3 tanvir: average salary -> DENIED, no SQL generated',
            $r['path'] === 'denied' && $r['queries'] === [] && $r['answer'] === "You're not authorised to access salary information.", $r['answer']);

        $r = $ask(self::DEPT_HEAD, 'who in my department has pending leave?', $sane);
        $this->check('#4 dept head: pending leave in my department -> data, :dept = 10',
            $r['path'] === 'data' && ($r['queries'][0]['bindings']['dept'] ?? null) === self::ENGINEER_DEPT && count($r['table']['rows']) > 0);

        $r = $ask(self::HR, "what's the average salary in engineering?", $sane);
        $this->check('#5 HR, same sentence as #3 -> ANSWERED (' . self::ENG_AVG_GROSS . ')',
            $r['path'] === 'data' && (int) ($r['table']['rows'][0]['avg_salary'] ?? 0) === self::ENG_AVG_GROSS);

        $r = $ask(self::MANAGER, 'who on my team has pending leave?', $sane);
        $this->check('manager: pending leave of direct reports -> data, :me = 1002',
            $r['path'] === 'data' && ($r['queries'][0]['bindings']['me'] ?? null) === 1002);

        // ---- a malicious / confused model cannot get past the server
        $rogue = fn(string $sql) => function (string $q, array $results, int $round) use ($turn, $call, $sql) {
            return $round === 0 ? $turn('', [$call('runReadOnlyQuery', ['sql' => $sql])]) : $turn('Result: ' . json_encode(end($results)['result'] ?? []));
        };
        foreach ([
            'reads ERP salary_info directly' => 'SELECT AVG(gross_salary) FROM salary_info LIMIT 1',
            'reads ERP passwords' => 'SELECT username, password FROM user_activity_management LIMIT 5',
            'guesses an HR view' => 'SELECT AVG(gross_salary) FROM v_hr_employees_full LIMIT 1',
            'omits :me' => 'SELECT * FROM v_my_leave_balance LIMIT 5',
            'DELETE' => 'DELETE FROM v_my_profile WHERE employee_id = :me',
        ] as $label => $sql) {
            $r = $ask(self::EMPLOYEE, 'x', $rogue($sql));
            $this->check("rogue model $label -> DENIED", $r['path'] === 'denied' && $r['_auditDelta'] === 1);
        }
        $r = $ask(self::EMPLOYEE, 'everyone', $rogue('SELECT DISTINCT employee_id FROM v_my_attendance_daily WHERE employee_id = :me OR 1=1 LIMIT 50'));
        $this->check("rogue model `OR 1=1` -> only tanvir's rows", $r['path'] === 'data' && array_column($r['table']['rows'], 'employee_id') == [$tanvir->id]);

        // ---- self-correction without leaking MySQL errors
        $hintLeaks = false;
        $fixer = function (string $q, array $results, int $round) use ($turn, $call, &$hintLeaks) {
            foreach ($results as $res) {
                $hintLeaks = $hintLeaks || str_contains(json_encode($res), 'nope') || str_contains(json_encode($res), '1054');
            }
            return match ($round) {
                0 => $turn('', [$call('runReadOnlyQuery', ['sql' => 'SELECT nope FROM v_employee_directory LIMIT 5'])]),
                1 => $turn('', [$call('runReadOnlyQuery', ['sql' => 'SELECT full_name FROM v_employee_directory LIMIT 5'])]),
                default => $turn('Done.'),
            };
        };
        $r = $ask(self::EMPLOYEE, 'directory', $fixer);
        $this->check('bad column -> model retries -> data (2 queries logged)', $r['path'] === 'data' && count($r['queries']) === 2);
        $this->check('the retry hint sent to the model contains no raw MySQL error', !$hintLeaks);

        $r = $ask(self::EMPLOYEE, 'loop', fn() => $turn('', [$call('getUserRole')]));
        $this->check('model that never stops calling tools -> error after max rounds, audited', $r['path'] === 'error' && $r['_auditDelta'] === 1);

        // ---- HTTP endpoint (an over-long question is rejected BEFORE any AI call)
        $http = new TestHttpClient($this->baseUrl);
        $r = $http->postJson('/index.php?r=chat/ask', ['question' => 'hi']);
        $this->check('POST chat/ask as guest -> 401', $r['code'] === 401);
        $http->post('/index.php?r=site/login', ['LoginForm[username]' => self::EMPLOYEE, 'LoginForm[password]' => 'Demo@1234']);
        $r = $http->get('/index.php?r=chat/ask');
        $this->check('GET chat/ask -> 405', $r['code'] === 405);
        $before = (int) ChatAuditLog::find()->count();
        $r = $http->postJson('/index.php?r=chat/ask', ['question' => str_repeat('x', 1001), 'role' => 'ceo', 'employee_id' => 1001]);
        $json = json_decode($r['body'], true) ?? [];
        $this->check('POST chat/ask returns JSON; role/employee_id in the body are ignored (no AI call)',
            $r['code'] === 200 && ($json['role'] ?? '') === 'employee' && array_key_exists('provider', $json) && $json['provider'] === null);
        $this->check('... and wrote exactly one audit row', (int) ChatAuditLog::find()->count() === $before + 1);

        ChatAuditLog::deleteAll(['>', 'id', $startId]);
        return $this->summary();
    }

    // =================================================================== Phase 6
    public function actionPhase6(): int
    {
        $this->section("Phase 6 - dashboard, floating chat widget, audit page (HTTP against {$this->baseUrl})");
        $startId = (int) ChatAuditLog::find()->max('id');
        foreach ([self::EMPLOYEE => 'verify-q-tanvir', self::HR => 'verify-q-hr'] as $user => $q) {
            $e = $this->who($user);
            ChatAuditLog::record(['employee_id' => $e->id, 'role' => $e->role, 'question' => $q, 'path' => 'info', 'provider' => 'verify']);
        }

        $http = new TestHttpClient($this->baseUrl);
        $r = $http->get('/index.php?r=site/login');
        $this->check('no chat widget on the sign-in page', !str_contains($r['body'], 'id="chat-widget"'));

        $http->post('/index.php?r=site/login', ['LoginForm[username]' => self::EMPLOYEE, 'LoginForm[password]' => 'Demo@1234']);
        $r = $http->get('/index.php');
        $this->check('home (/) is the "Demo Chatbot Testing Interface" dashboard', $r['code'] === 200
            && str_contains($r['body'], 'Demo Chatbot Testing Interface') && str_contains($r['body'], 'Tanvir Ahmmed'));
        $this->check('floating chat button + closed popup present', str_contains($r['body'], 'id="chat-fab"')
            && (bool) preg_match('/<section id="chat-panel"[^>]*\shidden>/', $r['body']));
        $this->check('chat shows the role badge (Employee)', (bool) preg_match('/id="role-badge">Employee</', $r['body']));
        $this->check('employee suggestions offered', str_contains($r['body'], 'How many casual leave days do I have left?'));
        $this->check('widget config carries the session user id (1960)', str_contains($r['body'], htmlspecialchars('"userId":1960', ENT_QUOTES)));

        $r = $http->get('/index.php?r=chat/audit');
        $this->check("tanvir's audit page shows own questions only", str_contains($r['body'], 'verify-q-tanvir') && !str_contains($r['body'], 'verify-q-hr'));
        $this->check('chat widget is also on the audit page', str_contains($r['body'], 'id="chat-fab"'));
        $r = $http->get('/index.php?r=security-test/index');
        $this->check("test bench shows the role's system prompt", str_contains($r['body'], 'System prompt the AI receives') && str_contains($r['body'], 'v_my_leave_balance'));

        $http->post('/index.php?r=site/switch-user', ['id' => $this->who(self::HR)->id]);
        $r = $http->get('/index.php?r=chat/audit');
        $this->check("HR's audit page shows everyone's questions", str_contains($r['body'], 'verify-q-tanvir') && str_contains($r['body'], 'verify-q-hr'));
        $r = $http->get('/index.php');
        $this->check('after switching, the badge says HR and HR suggestions appear',
            (bool) preg_match('/id="role-badge">HR</', $r['body']) && str_contains($r['body'], 'List all pending leave requests'));

        ChatAuditLog::deleteAll(['>', 'id', $startId]);
        return $this->summary();
    }

    // =================================================================== live demo
    /**
     * LIVE acceptance test of the five demo questions against the configured AI provider.
     * Uses real API calls - run only when the owner asks.
     */
    public function actionDemo(): int
    {
        $this->section('Demo script - LIVE against the configured AI provider');
        try {
            $provider = ProviderFactory::create();
            $this->stdout("  provider: {$provider->name()} / {$provider->model()}\n");
        } catch (ProviderError $e) {
            $this->stdout("  SKIPPED: {$e->getMessage()}\n");
            return ExitCode::OK;
        }
        $cases = [
            ['#1', self::EMPLOYEE, "what's our leave policy?", fn($r) => $r['path'] === 'info' && $r['queries'] === []],
            ['#2', self::EMPLOYEE, 'how many casual leave days do I have left?', fn($r) => $r['path'] === 'data'
                && str_contains(json_encode(array_column($r['queries'], 'bindings')), '"me":1960') && preg_match('/\b13\b/', $r['answer'])],
            ['#3', self::EMPLOYEE, "what's the average salary in engineering?", fn($r) => $r['path'] === 'denied' && stripos($r['answer'], 'salar') !== false],
            ['#4', self::DEPT_HEAD, 'who in my department has pending leave?', fn($r) => $r['path'] === 'data'
                && str_contains(json_encode(array_column($r['queries'], 'bindings')), '"dept":10')],
            ['#5', self::HR, "what's the average salary in engineering?", fn($r) => $r['path'] === 'data'
                && preg_match('/66[,.]?4(29|28)/', $r['answer'])],
        ];
        foreach ($cases as [$id, $user, $q, $ok]) {
            $r = (new ChatService())->ask($this->who($user), $q);
            $this->check("$id $user: \"$q\" -> {$r['path']} ({$r['latencyMs']} ms)", (bool) $ok($r));
            $this->stdout('       answer: ' . str_replace("\n", ' ', mb_substr($r['answer'], 0, 220)) . "\n");
            foreach ($r['queries'] as $query) {
                $this->stdout("       sql [{$query['status']}]: " . preg_replace('/\s+/', ' ', $query['sql']) . "\n");
            }
        }
        return $this->summary();
    }

    // =================================================================== helpers

    /** The in-service employee behind an ERP username (fresh tier resolution each time). */
    private function who(string|int $username): ?Employee
    {
        RoleResolver::forget();
        $user = ErpUser::findByUsername((string) $username);
        return $user ? Employee::findIdentity((int) $user->PBI_ID) : null;
    }

    /** Strips the knowledge-base section (which legitimately talks about salary as a policy topic). */
    private function promptWithoutKb(string $prompt): string
    {
        return preg_replace('/=== KNOWLEDGE BASE ===.*?=== DATA ACCESS/s', '=== DATA ACCESS', $prompt);
    }

    /** @return array{bool,string} whether MySQL rejected the statement, and its error code */
    private function mysqlRefuses(\yii\db\Connection $conn, string $sql): array
    {
        try {
            $conn->createCommand($sql)->execute();
            return [false, 'statement SUCCEEDED'];
        } catch (\yii\db\Exception $e) {
            return [true, 'MySQL error ' . ($e->errorInfo[1] ?? '?')];
        }
    }

    protected function section(string $title): void
    {
        $this->stdout("\n== $title ==\n");
    }

    protected function check(string $label, bool $ok, string $detail = ''): void
    {
        $ok ? $this->passed++ : $this->failed++;
        $this->stdout(sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' && !$ok ? " -- $detail" : ''));
    }

    protected function summary(): int
    {
        $this->stdout(sprintf("\n%d passed, %d failed\n", $this->passed, $this->failed));
        return $this->failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
