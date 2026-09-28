<?php

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Repeatable acceptance checks, one action per build phase.
 *
 *   php yii verify/phase1     schema + seed data
 *   php yii verify/all        every phase that does not need a live AI key
 */
class VerifyController extends Controller
{
    private int $passed = 0;
    private int $failed = 0;

    public function actionAll(): int
    {
        foreach (['phase1', 'phase2', 'phase3', 'phase4', 'phase5', 'phase6'] as $phase) {
            $this->runAction($phase);
        }
        return $this->summary();
    }

    public function actionPhase1(): int
    {
        $this->section('Phase 1 - schema and seed data');
        $db = Yii::$app->db;

        $expected = [
            'departments' => 4, 'employees' => 10, 'salaries' => 20, 'leave_types' => 4,
            'leave_balances' => 40, 'attendance' => 400, 'company_info' => null,
            'leave_requests' => null, 'chat_audit_log' => null,
        ];
        foreach ($expected as $table => $count) {
            $schema = $db->getTableSchema($table, true);
            $this->check("table `$table` exists", $schema !== null);
            if ($schema !== null && $count !== null) {
                $actual = (int) (new Query())->from($table)->count('*', $db);
                $this->check("`$table` has $count rows (got $actual)", $actual === $count);
            }
        }

        $requests = (int) (new Query())->from('leave_requests')->count('*', $db);
        $this->check("~30 leave requests (got $requests)", $requests >= 28);
        $kb = (int) (new Query())->from('company_info')->count('*', $db);
        $this->check("~15 company_info rows (got $kb)", $kb >= 14);

        $engPending = (int) (new Query())->from('leave_requests lr')
            ->innerJoin('employees e', 'e.id = lr.employee_id')
            ->innerJoin('departments d', 'd.id = e.department_id')
            ->where(['lr.status' => 'pending', 'd.code' => 'ENG'])->count('*', $db);
        $this->check("several pending requests in Engineering (got $engPending)", $engPending >= 3);

        $weekend = (int) (new Query())->from('attendance')
            ->where(['in', new \yii\db\Expression('DAYOFWEEK(work_date)'), [6, 7]])->count('*', $db);
        $this->check('no attendance on Friday/Saturday', $weekend === 0);

        $current = (new Query())->select(['employee_id', 'n' => 'COUNT(*)'])->from('salaries')
            ->where(['is_current' => 1])->groupBy('employee_id')->all($db);
        $this->check('exactly one current salary per employee',
            count($current) === 10 && max(array_column($current, 'n')) == 1);

        $mismatch = (int) $db->createCommand(
            "SELECT COUNT(*) FROM leave_balances b
             LEFT JOIN (SELECT employee_id, leave_type_id, SUM(days) d FROM leave_requests
                        WHERE status = 'approved' AND YEAR(start_date) = YEAR(CURDATE())
                        GROUP BY employee_id, leave_type_id) r
               ON r.employee_id = b.employee_id AND r.leave_type_id = b.leave_type_id
             WHERE b.year = YEAR(CURDATE()) AND b.used <> COALESCE(r.d, 0)"
        )->queryScalar();
        $this->check('leave_balances.used matches approved requests', $mismatch === 0);

        $hashes = (new Query())->select(['email', 'password_hash'])->from('employees')->all($db);
        $ok = array_filter($hashes, fn($r) => Yii::$app->security->validatePassword('Demo@1234', $r['password_hash']));
        $this->check('all 10 users accept password Demo@1234', count($ok) === 10);

        $fk = (int) $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_audit_log' AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->queryScalar();
        $this->check('chat_audit_log has no foreign keys', $fk === 0);

        $this->check('dbAi component is configured', Yii::$app->has('dbAi'));

        return $this->summary();
    }

    public function actionPhase2(): int
    {
        $this->section('Phase 2 - views and the restricted erp_ai_ro account');
        $db = Yii::$app->db;
        $ai = Yii::$app->dbAi;

        $views = $db->createCommand(
            "SELECT TABLE_NAME, SECURITY_TYPE, DEFINER, VIEW_DEFINITION FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
        )->queryAll();
        $this->check('20 v_* views exist (got ' . count($views) . ')', count($views) === 20);
        $this->check('every view is SQL SECURITY DEFINER',
            count(array_filter($views, fn($v) => $v['SECURITY_TYPE'] === 'DEFINER')) === count($views));

        // Salary may only be reachable through v_hr_* and v_exec_payroll_summary.
        $salaryAllowed = ['v_hr_employees_full', 'v_hr_payroll', 'v_exec_payroll_summary'];
        $offenders = array_filter($views, fn($v) =>
            (stripos($v['VIEW_DEFINITION'], '`salaries`') !== false) !== in_array($v['TABLE_NAME'], $salaryAllowed, true));
        $this->check('only v_hr_employees_full / v_hr_payroll / v_exec_payroll_summary read `salaries`',
            $offenders === [], implode(', ', array_column($offenders, 'TABLE_NAME')));

        $user = $ai->createCommand('SELECT CURRENT_USER()')->queryScalar();
        $this->check("dbAi authenticates as erp_ai_ro (got $user)", str_starts_with((string) $user, 'erp_ai_ro@'));

        $grants = $ai->createCommand('SHOW GRANTS')->queryColumn();
        $nonView = array_filter($grants, fn($g) => !str_starts_with($g, 'GRANT USAGE ON *.*')
            && !preg_match('/^GRANT SELECT ON `erp_demo`\.`v_[a-z_]+` TO /', $g));
        $this->check('SHOW GRANTS lists only SELECT-on-view grants (' . count($grants) . ' lines)', $nonView === []);

        $n = (int) $ai->createCommand('SELECT COUNT(*) FROM v_employee_directory')->queryScalar();
        $this->check("erp_ai_ro can SELECT a view (v_employee_directory: $n rows)", $n === 10);

        foreach ([
            'base-table SELECT (salaries)' => 'SELECT * FROM salaries LIMIT 1',
            'base-table SELECT (employees)' => 'SELECT * FROM employees LIMIT 1',
            'INSERT into a view' => "INSERT INTO v_employee_directory (full_name) VALUES ('x')",
            'UPDATE a view' => "UPDATE v_hr_payroll SET basic_salary = 1",
            'CREATE TABLE' => 'CREATE TABLE hack (id INT)',
            'read mysql.user' => 'SELECT user FROM mysql.user LIMIT 1',
        ] as $label => $sql) {
            [$refused, $msg] = $this->mysqlRefuses($ai, $sql);
            $this->check("MySQL refuses $label", $refused, $msg);
        }

        $leak = (int) $ai->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'erp_demo' AND TABLE_TYPE = 'BASE TABLE'"
        )->queryScalar();
        $this->check('base tables are invisible to erp_ai_ro in information_schema', $leak === 0);

        $session = $ai->createCommand('SELECT @@SESSION.max_execution_time, @@SESSION.transaction_read_only')->queryOne(\PDO::FETCH_NUM);
        $this->check("dbAi session: 5s statement timeout, read-only (got {$session[0]}ms, ro={$session[1]})",
            (int) $session[0] === 5000 && (int) $session[1] === 1);

        return $this->summary();
    }

    /** Base URL of a running instance (`php yii serve`) for the HTTP-level checks. */
    public string $baseUrl = 'http://localhost:8080';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['baseUrl']);
    }

    public function actionPhase3(): int
    {
        $this->section("Phase 3 - login and demo role switcher (HTTP against {$this->baseUrl})");
        $http = new \app\components\TestHttpClient($this->baseUrl);

        $r = $http->get('/index.php?r=site/index');
        $this->check('guest is redirected to login', $r['code'] === 302 && str_contains($r['location'], 'site%2Flogin'));

        $r = $http->get('/index.php?r=site/login');
        $this->check('login page renders', $r['code'] === 200 && str_contains($r['body'], 'login-form'));

        $r = $http->post('/index.php?r=site/login', ['LoginForm[email]' => 'dev1@demo.local', 'LoginForm[password]' => 'wrong']);
        $this->check('wrong password is rejected', $r['code'] === 200 && str_contains($r['body'], 'Incorrect email or password'));

        $r = $http->post('/index.php?r=site/login', ['LoginForm[email]' => 'dev1@demo.local', 'LoginForm[password]' => 'Demo@1234']);
        $this->check('dev1 logs in with Demo@1234', $r['code'] === 302);
        $r = $http->get('/index.php?r=site/index');
        $this->check('home shows dev1 as Employee', str_contains($r['body'], 'Arif Hossain') && str_contains($r['body'], 'role-employee'));

        $enghead = (int) Yii::$app->db->createCommand("SELECT id FROM employees WHERE email = 'enghead@demo.local'")->queryScalar();
        $r = $http->get('/index.php?r=site/switch-user&id=' . $enghead);
        $this->check('switch-user refuses GET', $r['code'] === 405);

        $r = $http->post('/index.php?r=site/switch-user', ['id' => $enghead], withCsrf: false);
        $this->check('switch-user refuses POST without CSRF token', $r['code'] === 400);

        $r = $http->post('/index.php?r=site/switch-user', ['id' => $enghead]);
        $this->check('switch-user (POST + CSRF) succeeds', $r['code'] === 302);
        $r = $http->get('/index.php?r=site/index');
        $this->check('now signed in as enghead / Department Head',
            str_contains($r['body'], 'Tanvir Ahmed') && str_contains($r['body'], 'role-dept_head'));
        $this->check('navbar lists all 10 users in the switcher', substr_count($r['body'], 'site%2Fswitch-user') >= 10);

        $r = $http->post('/index.php?r=site/logout', []);
        $r = $http->get('/index.php?r=site/index');
        $this->check('logout returns to guest', $r['code'] === 302);

        return $this->summary();
    }

    public function actionPhase4(): int
    {
        $this->section('Phase 4 - validator, session binding, prompt builder, audit (no AI)');

        $who = fn(string $email) => \app\models\Employee::findByEmail($email);
        $run = fn(string $email, string $sql) => (new \app\components\ai\QueryGateway($who($email)))->run($sql);
        $dev1 = $who('dev1@demo.local');
        $enghead = $who('enghead@demo.local');
        $engmgr = $who('engmgr@demo.local');

        // ---------------------------------------------------------------- allowed
        $r = $run('dev1@demo.local', "SELECT employee_id, leave_type, remaining FROM v_my_leave_balance WHERE employee_id = :me AND year = YEAR(CURDATE())");
        $this->check('ALLOWED: dev1 reads own leave balance', $r['status'] === 'ok' && count($r['rows']) === 4, $r['reason']);
        $this->check('  :me bound from the session to dev1\'s id', ($r['bindings']['me'] ?? null) === $dev1->id
            && array_unique(array_column($r['rows'], 'employee_id')) == [$dev1->id]);
        $this->check('  LIMIT 200 appended when missing', str_ends_with($r['finalSql'], 'LIMIT 200'));
        $this->check('  scoped view wrapped in a derived table', str_contains($r['finalSql'], '(SELECT * FROM `v_my_leave_balance` WHERE `employee_id` = :me)'));

        $r = $run('dev1@demo.local', "SELECT DISTINCT employee_id FROM v_my_attendance WHERE employee_id = :me OR 1=1");
        $this->check('ROW SCOPE: `employee_id = :me OR 1=1` still returns only dev1\'s rows',
            $r['status'] === 'ok' && array_column($r['rows'], 'employee_id') == [$dev1->id], json_encode(array_column($r['rows'], 'employee_id')));

        $r = $run('dev1@demo.local', "SELECT full_name FROM v_employee_directory WHERE designation = 'FROM salaries; DROP TABLE x -- #'");
        $this->check('forbidden text inside a string literal is data, not SQL', $r['status'] === 'ok', $r['reason']);

        $r = $run('dev1@demo.local', "SELECT full_name FROM v_employee_directory LIMIT 10;");
        $this->check('optional trailing semicolon accepted; LIMIT 10 kept', $r['status'] === 'ok' && count($r['rows']) === 10 && $r['notes'] === []);

        $r = $run('dev1@demo.local', "SELECT full_name FROM v_employee_directory LIMIT 5000");
        $this->check('LIMIT 5000 rewritten to 200', $r['status'] === 'ok' && str_ends_with($r['finalSql'], 'LIMIT 200'));
        $r = $run('dev1@demo.local', "SELECT full_name FROM v_employee_directory LIMIT 2, 900");
        $this->check('LIMIT 2, 900 rewritten to LIMIT 2, 200', $r['status'] === 'ok' && str_ends_with($r['finalSql'], 'LIMIT 2, 200'));

        // ---------------------------------------------------------------- the demo pair, at SQL level
        $avgSql = "SELECT ROUND(AVG(gross_salary)) AS avg_salary FROM v_hr_employees_full WHERE department = 'Engineering'";
        $r = $run('dev1@demo.local', $avgSql);
        $this->check('DENIED: dev1 -> v_hr_employees_full (average salary)', $r['status'] === 'denied'
            && $r['userMessage'] === "You're not authorised to access salary information.", $r['userMessage']);
        $r = $run('hr@demo.local', $avgSql);
        $this->check('ALLOWED: hr, same SQL -> 218400', $r['status'] === 'ok' && (int) ($r['rows'][0]['avg_salary'] ?? 0) === 218400,
            json_encode($r['rows']));

        // ---------------------------------------------------------------- role scopes
        $r = $run('enghead@demo.local', "SELECT employee_name, department_id FROM v_dept_leave_requests WHERE department_id = :dept AND status = 'pending'");
        $this->check('ALLOWED: enghead pending leave in own dept (:dept bound)', $r['status'] === 'ok'
            && count($r['rows']) === 5 && array_unique(array_column($r['rows'], 'department_id')) == [$enghead->department_id]
            && ($r['bindings']['dept'] ?? null) === $enghead->department_id, json_encode($r['rows']));

        $r = $run('enghead@demo.local', "SELECT employee_name FROM v_dept_leave_requests WHERE status = 'pending'");
        $this->check('DENIED: dept view without :dept', $r['status'] === 'denied' && $r['code'] === 'missing_scope');

        $r = $run('engmgr@demo.local', "SELECT DISTINCT employee_name, manager_id FROM v_team_leave_requests WHERE manager_id = :me");
        $this->check('ALLOWED: manager sees only direct reports', $r['status'] === 'ok'
            && array_unique(array_column($r['rows'], 'manager_id')) == [$engmgr->id] && count($r['rows']) === 3, json_encode($r['rows']));

        $r = $run('engmgr@demo.local', "SELECT * FROM v_team_leave_requests WHERE employee_id = :me");
        $this->check('DENIED: team view scoped on the wrong column (needs manager_id = :me)', $r['status'] === 'denied' && $r['code'] === 'missing_scope');

        $r = $run('engmgr@demo.local', "SELECT * FROM v_dept_employees WHERE department_id = :dept");
        $this->check('DENIED: manager cannot use department views', $r['status'] === 'denied' && $r['code'] === 'not_authorised');

        $r = $run('ceo@demo.local', "SELECT department, avg_gross_salary FROM v_exec_payroll_summary");
        $this->check('ALLOWED: ceo reads v_exec_payroll_summary', $r['status'] === 'ok' && count($r['rows']) === 4);
        $r = $run('hr@demo.local', "SELECT department, avg_gross_salary FROM v_exec_payroll_summary");
        $this->check('DENIED: hr cannot read CEO aggregates', $r['status'] === 'denied');

        // ---------------------------------------------------------------- rejections
        $denied = [
            'missing :me' => ['dev1', 'SELECT * FROM v_my_leave_balance'],
            ':me on the wrong column' => ['dev1', 'SELECT * FROM v_my_leave_balance WHERE year = :me'],
            'base table salaries' => ['dev1', 'SELECT AVG(basic) FROM salaries'],
            'base table even for ceo' => ['ceo', 'SELECT AVG(basic) FROM salaries'],
            'base table in a subquery' => ['dev1', 'SELECT full_name FROM v_employee_directory WHERE email IN (SELECT email FROM employees)'],
            'base table in a comma join' => ['dev1', 'SELECT * FROM v_employee_directory, salaries'],
            'base table via UNION' => ['dev1', 'SELECT full_name FROM v_employee_directory UNION SELECT basic FROM salaries'],
            'UNION TABLE salaries' => ['dev1', 'SELECT 1 UNION TABLE salaries'],
            'parenthesised table ref' => ['dev1', 'SELECT * FROM (salaries)'],
            'forbidden view in parentheses' => ['dev1', 'SELECT * FROM (v_hr_payroll)'],
            'backtick-quoted forbidden view' => ['dev1', 'SELECT * FROM `V_HR_PAYROLL`'],
            'schema-qualified table' => ['hr', 'SELECT * FROM erp_demo.v_hr_payroll'],
            'INFORMATION_SCHEMA' => ['hr', 'SELECT table_name FROM information_schema.tables'],
            'mysql.user' => ['hr', 'SELECT user FROM mysql.user'],
            'UPDATE' => ['hr', "UPDATE v_hr_payroll SET basic_salary = 1"],
            'DELETE' => ['hr', 'DELETE FROM v_my_profile WHERE employee_id = :me'],
            'INSERT' => ['hr', "INSERT INTO v_employee_directory VALUES ('a','b','c','d')"],
            'DROP' => ['hr', 'DROP VIEW v_employee_directory'],
            'WITH (CTE)' => ['hr', 'WITH x AS (SELECT * FROM v_hr_payroll) SELECT * FROM x'],
            'SET' => ['hr', 'SET @a = 1'],
            'CALL' => ['hr', 'CALL anything()'],
            'stacked statements' => ['hr', 'SELECT 1; DROP TABLE employees'],
            '-- comment' => ['hr', 'SELECT full_name FROM v_employee_directory -- hi'],
            '# comment' => ['hr', 'SELECT full_name FROM v_employee_directory # hi'],
            '/* */ comment' => ['hr', 'SELECT /*!50000 full_name */ FROM v_employee_directory'],
            'INTO OUTFILE' => ['hr', "SELECT * FROM v_hr_payroll INTO OUTFILE 'C:/x.csv'"],
            'INTO DUMPFILE' => ['hr', "SELECT * FROM v_hr_payroll INTO DUMPFILE 'C:/x'"],
            'SLEEP()' => ['hr', 'SELECT SLEEP(10)'],
            '@@system variable' => ['hr', 'SELECT @@version'],
            'unknown placeholder' => ['dev1', 'SELECT * FROM v_my_profile WHERE employee_id = :id'],
            'FOR UPDATE' => ['hr', 'SELECT * FROM v_hr_payroll FOR UPDATE'],
            'LIMIT with a non-literal' => ['hr', 'SELECT * FROM v_hr_payroll LIMIT :me'],
            'JSON_TABLE' => ['hr', "SELECT * FROM JSON_TABLE('[]', '\$[*]' COLUMNS (a INT PATH '\$')) t"],
        ];
        foreach ($denied as $label => [$user, $sql]) {
            $r = $run("$user@demo.local", $sql);
            $this->check("REFUSED: $label", $r['status'] === 'denied' && $r['rows'] === [], $r['code'] . ' / ' . $r['reason']);
        }

        // refusal wording never leaks SQL internals
        $leaky = false;
        foreach ($denied as [$user, $sql]) {
            $msg = $run("$user@demo.local", $sql)['userMessage'];
            $leaky = $leaky || preg_match('/SQLSTATE|MySQL|v_[a-z]+_|select|error \d/i', $msg);
        }
        $this->check('refusal messages are plain English (no SQL / view names / error codes)', !$leaky);

        // ---------------------------------------------------------------- execution-time failures are sanitised
        $r = $run('dev1@demo.local', 'SELECT no_such_column FROM v_employee_directory');
        $this->check('MySQL error (unknown column) returns a safe message', $r['status'] === 'error'
            && $r['code'] === 'bad_query' && !str_contains($r['userMessage'], 'no_such_column'), $r['userMessage']);

        // ---------------------------------------------------------------- prompt builder
        $pb = new \app\components\ai\PromptBuilder();
        $p1 = $pb->build($dev1);
        $pHr = $pb->build($who('hr@demo.local'));
        $this->check("dev1's prompt mentions no salary column or v_hr_/v_exec_ view",
            !preg_match('/salary|house_allowance|v_hr_|v_exec_|v_team_|v_dept_/i', $this->promptWithoutKb($p1)));
        $this->check("dev1's prompt includes the knowledge base and its own views",
            str_contains($p1, 'Annual leave') && str_contains($p1, 'v_my_leave_balance') && str_contains($p1, 'REQUIRES: WHERE employee_id = :me'));
        $this->check("dev1's prompt does not contain dev1's employee id", !preg_match('/employee_id\s*=\s*' . $dev1->id . '\b/', $p1));
        $this->check("hr's prompt includes gross_salary in v_hr_employees_full", str_contains($pHr, 'VIEW v_hr_employees_full') && str_contains($pHr, 'gross_salary'));
        $pDh = $pb->build($enghead);
        $this->check("dept_head's prompt maps 'my team' to v_dept_* / :dept", str_contains($pDh, 'department_id = :dept') && !str_contains($pDh, 'VIEW v_team_'));

        // ---------------------------------------------------------------- audit log
        $before = (int) \app\models\ChatAuditLog::find()->count();
        $row = \app\models\ChatAuditLog::record(['employee_id' => $dev1->id, 'role' => 'employee', 'question' => 'verify', 'path' => 'denied',
            'denial_reason' => 'test', 'provider' => 'verify']);
        $this->check('audit row written', $row !== null && (int) \app\models\ChatAuditLog::find()->count() === $before + 1);
        $row?->delete();

        // ---------------------------------------------------------------- web test bench (HTTP)
        $http = new \app\components\TestHttpClient($this->baseUrl);
        $http->post('/index.php?r=site/login', ['LoginForm[email]' => 'dev1@demo.local', 'LoginForm[password]' => 'Demo@1234']);
        $before = (int) \app\models\ChatAuditLog::find()->where(['provider' => 'test-bench'])->count();
        $r = $http->post('/index.php?r=security-test/index', ['sql' => 'SELECT AVG(basic) FROM salaries']);
        $this->check('bench (as dev1): base-table query shows REFUSED + plain message',
            $r['code'] === 200 && str_contains($r['body'], 'REFUSED') && str_contains($r['body'], 'not authorised to access salary information'));
        $r = $http->post('/index.php?r=security-test/index', ['sql' => 'SELECT leave_type, remaining FROM v_my_leave_balance WHERE employee_id = :me']);
        $this->check('bench (as dev1): own leave balance ALLOWED with :me = ' . $dev1->id,
            str_contains($r['body'], 'ALLOWED') && str_contains($r['body'], ':me = ' . $dev1->id));
        $after = (int) \app\models\ChatAuditLog::find()->where(['provider' => 'test-bench'])->count();
        $this->check('bench wrote one audit row per run', $after === $before + 2);
        $last = \app\models\ChatAuditLog::find()->where(['provider' => 'test-bench'])->orderBy(['id' => SORT_DESC])->limit(2)->all();
        $this->check('audit rows record path data/denied, role and employee',
            $last[0]->path === 'data' && $last[1]->path === 'denied' && $last[1]->role === 'employee' && (int) $last[1]->employee_id === $dev1->id);
        \app\models\ChatAuditLog::deleteAll(['id' => array_column($last, 'id')]);

        return $this->summary();
    }

    public function actionPhase5(): int
    {
        $this->section('Phase 5 - chat flow with a scripted stand-in model (no API key needed)');
        $startId = (int) \app\models\ChatAuditLog::find()->max('id');

        $who = fn(string $email) => \app\models\Employee::findByEmail($email);
        $turn = fn(string $text, array $calls = []) => new \app\components\ai\provider\LlmTurn($text, $calls);
        $call = fn(string $name, array $args = []) => ['id' => 'call_' . bin2hex(random_bytes(3)), 'name' => $name, 'args' => $args];

        // A well-behaved "model": what we expect Gemini to do for the five demo questions.
        // It only ever writes SQL against views it can see in the system prompt it was given.
        $sane = function (string $q, array $results, int $round, string $system) use ($turn, $call) {
            $q = strtolower($q);
            if (str_contains($q, 'policy')) {
                return $turn('Annual leave is 20 days per year; sick leave 14; casual leave 10.');
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
                    return $turn(\app\components\ai\PromptBuilder::DENIAL_MARKER . ' salary information');
                }
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT ROUND(AVG(gross_salary)) AS avg_salary FROM v_hr_employees_full WHERE department = 'Engineering' LIMIT 1"])]);
            }
            if (str_contains($q, 'leave days')) {
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT leave_type, remaining FROM v_my_leave_balance WHERE employee_id = :me AND year = YEAR(CURDATE()) LIMIT 10"])]);
            }
            if (str_contains($q, 'pending leave')) {
                return $turn('', [$call('runReadOnlyQuery', ['sql' => "SELECT employee_name, leave_type, start_date, days FROM v_dept_leave_requests WHERE department_id = :dept AND status = 'pending' LIMIT 50"])]);
            }
            return $turn('I am not sure.');
        };
        $ask = function (string $email, string $question, callable $script) use ($who) {
            $before = (int) \app\models\ChatAuditLog::find()->count();
            $out = (new \app\components\ai\ChatService(new \app\components\ai\provider\ScriptedProvider($script)))->ask($who($email), $question);
            $rows = \app\models\ChatAuditLog::find()->where(['>', 'id', 0])->orderBy(['id' => SORT_DESC])->limit(1)->all();
            $out['_auditDelta'] = (int) \app\models\ChatAuditLog::find()->count() - $before;
            $out['_audit'] = $rows[0] ?? null;
            return $out;
        };

        $dev1 = $who('dev1@demo.local');
        $enghead = $who('enghead@demo.local');

        // ---- the five demo scenarios
        $r = $ask('dev1@demo.local', "what's our leave policy?", $sane);
        $this->check('#1 dev1 leave policy -> info path, no SQL', $r['path'] === 'info' && $r['queries'] === [] && $r['_audit']->path === 'info');

        $r = $ask('dev1@demo.local', 'how many leave days do I have left?', $sane);
        $this->check('#2 dev1 leave days left -> data path, :me bound to dev1',
            $r['path'] === 'data' && ($r['queries'][0]['bindings']['me'] ?? null) === $dev1->id && count($r['table']['rows']) === 4);
        $this->check('#2 getUserRole returned the session role', ($r['trace'][0]['step'] ?? '') === 'getUserRole' && $r['trace'][0]['detail'] === 'employee');

        $r = $ask('dev1@demo.local', "what's the average salary in engineering?", $sane);
        $this->check('#3 dev1 average salary -> DENIED, no SQL generated',
            $r['path'] === 'denied' && $r['queries'] === [] && $r['answer'] === "You're not authorised to access salary information.", $r['answer']);
        $this->check('#3 audit row path=denied with a reason', $r['_audit']->path === 'denied' && $r['_audit']->denial_reason !== null);

        $r = $ask('enghead@demo.local', 'who on my team has pending leave?', $sane);
        $this->check('#4 enghead pending leave -> data, :dept bound to Engineering',
            $r['path'] === 'data' && ($r['queries'][0]['bindings']['dept'] ?? null) === $enghead->department_id && count($r['table']['rows']) === 5);

        $r = $ask('hr@demo.local', "what's the average salary in engineering?", $sane);
        $this->check('#5 hr, same sentence as #3 -> ANSWERED (218400)',
            $r['path'] === 'data' && (int) ($r['table']['rows'][0]['avg_salary'] ?? 0) === 218400);

        // ---- a malicious / confused model cannot get past the server
        $rogue = fn(string $sql) => function (string $q, array $results, int $round) use ($turn, $call, $sql) {
            $last = end($results)['result'] ?? [];
            return $round === 0 ? $turn('', [$call('runReadOnlyQuery', ['sql' => $sql])]) : $turn('Result: ' . json_encode($last));
        };

        $r = $ask('dev1@demo.local', 'average salary?', $rogue('SELECT AVG(basic) FROM salaries LIMIT 1'));
        $this->check('rogue model -> base table salaries: DENIED, answer written by server',
            $r['path'] === 'denied' && $r['answer'] === "You're not authorised to access salary information." && $r['_audit']->generated_sql === 'SELECT AVG(basic) FROM salaries LIMIT 1');

        $r = $ask('dev1@demo.local', 'average salary?', $rogue("SELECT AVG(gross_salary) FROM v_hr_employees_full LIMIT 1"));
        $this->check('rogue model -> guesses an HR view: DENIED', $r['path'] === 'denied');

        $r = $ask('dev1@demo.local', 'everyone attendance', $rogue('SELECT DISTINCT employee_id FROM v_my_attendance WHERE employee_id = :me OR 1=1 LIMIT 50'));
        $this->check("rogue model -> `OR 1=1`: only dev1's rows come back",
            $r['path'] === 'data' && array_column($r['table']['rows'], 'employee_id') == [$dev1->id]);

        $r = $ask('dev1@demo.local', 'x', $rogue('SELECT * FROM v_my_leave_balance LIMIT 5'));
        $this->check('rogue model -> omits :me: DENIED', $r['path'] === 'denied');

        $r = $ask('dev1@demo.local', 'x', $rogue('DELETE FROM v_my_profile WHERE employee_id = :me'));
        $this->check('rogue model -> DELETE: DENIED', $r['path'] === 'denied');

        // ---- self-correction: a failing query gets a sanitised hint, then succeeds
        $fixer = function (string $q, array $results, int $round) use ($turn, $call) {
            return match ($round) {
                0 => $turn('', [$call('runReadOnlyQuery', ['sql' => 'SELECT nope FROM v_employee_directory LIMIT 5'])]),
                1 => $turn('', [$call('runReadOnlyQuery', ['sql' => 'SELECT full_name FROM v_employee_directory LIMIT 5'])]),
                default => $turn('Done.'),
            };
        };
        $hintLeaks = false;
        $spy = function (string $q, array $results, int $round) use ($fixer, &$hintLeaks) {
            foreach ($results as $res) {
                $hintLeaks = $hintLeaks || str_contains(json_encode($res), 'nope') || str_contains(json_encode($res), '1054');
            }
            return $fixer($q, $results, $round);
        };
        $r = $ask('dev1@demo.local', 'directory', $spy);
        $this->check('bad column -> model retries -> data (2 queries logged)', $r['path'] === 'data' && count($r['queries']) === 2
            && substr_count((string) $r['_audit']->generated_sql, 'SELECT') === 2);
        $this->check('the retry hint sent to the model contains no raw MySQL error', !$hintLeaks);

        $r = $ask('dev1@demo.local', 'loop', fn() => $turn('', [$call('getUserRole')]));
        $this->check('model that never stops calling tools -> error after max rounds', $r['path'] === 'error' && $r['_auditDelta'] === 1);

        $r = $ask('dev1@demo.local', str_repeat('x', 1001), $sane);
        $this->check('over-long question -> error path, still audited', $r['path'] === 'error' && $r['_auditDelta'] === 1);

        // ---- the real providers build their requests without a key-less crash
        $this->check('Gemini + Groq providers load and declare 2 tools', count(\app\components\ai\ChatService::tools()) === 2
            && class_exists(\app\components\ai\provider\GeminiProvider::class) && class_exists(\app\components\ai\provider\OpenAiCompatibleProvider::class));

        // ---- HTTP endpoint
        $http = new \app\components\TestHttpClient($this->baseUrl);
        $r = $http->postJson('/index.php?r=chat/ask', ['question' => 'hi']);
        $this->check('POST chat/ask as guest -> 401', $r['code'] === 401);
        $http->post('/index.php?r=site/login', ['LoginForm[email]' => 'dev1@demo.local', 'LoginForm[password]' => 'Demo@1234']);
        $r = $http->get('/index.php?r=chat/ask');
        $this->check('GET chat/ask -> 405', $r['code'] === 405);
        // An over-long question is rejected BEFORE any AI call, so this never uses the
        // owner's free-tier quota, yet still exercises the endpoint, JSON and audit path.
        $before = (int) \app\models\ChatAuditLog::find()->count();
        $r = $http->postJson('/index.php?r=chat/ask', ['question' => str_repeat('x', 1001), 'role' => 'ceo', 'employee_id' => 1]);
        $json = json_decode($r['body'], true) ?? [];
        $this->check('POST chat/ask as dev1 returns JSON; role/employee_id in the body are ignored (no AI call)',
            $r['code'] === 200 && ($json['role'] ?? '') === 'employee' && array_key_exists('provider', $json) && $json['provider'] === null, substr($r['body'], 0, 200));
        $this->check('... and wrote exactly one audit row', (int) \app\models\ChatAuditLog::find()->count() === $before + 1);

        // remove only the audit rows this verification run created, so the demo audit page starts clean
        \app\models\ChatAuditLog::deleteAll(['>', 'id', $startId]);

        return $this->summary();
    }

    public function actionPhase6(): int
    {
        $this->section("Phase 6 - chat UI and audit page (HTTP against {$this->baseUrl})");
        $startId = (int) \app\models\ChatAuditLog::find()->max('id');
        $who = fn(string $email) => \app\models\Employee::findByEmail($email);
        foreach (['dev1@demo.local' => 'verify-q-dev1', 'hr@demo.local' => 'verify-q-hr'] as $email => $q) {
            $e = $who($email);
            \app\models\ChatAuditLog::record(['employee_id' => $e->id, 'role' => $e->role, 'question' => $q, 'path' => 'info', 'provider' => 'verify']);
        }

        $http = new \app\components\TestHttpClient($this->baseUrl);
        $r = $http->get('/index.php');
        $this->check('home (/) for a guest redirects to login', $r['code'] === 302);
        $r = $http->get('/index.php?r=site/login');
        $this->check('no chat widget on the sign-in page', !str_contains($r['body'], 'id="chat-widget"'));

        $http->post('/index.php?r=site/login', ['LoginForm[email]' => 'dev1@demo.local', 'LoginForm[password]' => 'Demo@1234']);
        $r = $http->get('/index.php');
        $this->check('home (/) is the "Demo Chatbot Testing Interface" dashboard', $r['code'] === 200
            && str_contains($r['body'], 'Demo Chatbot Testing Interface') && str_contains($r['body'], 'chat button in the bottom-right corner'));
        $this->check('floating chat button present', str_contains($r['body'], 'id="chat-fab"') && str_contains($r['body'], 'aria-controls="chat-panel"'));
        $this->check('chat popup is closed (hidden) in the initial markup', (bool) preg_match('/<section id="chat-panel"[^>]*\shidden>/', $r['body']));
        $this->check('widget assets loaded', str_contains($r['body'], 'js/chat-widget.js') && str_contains($r['body'], 'css/chat-widget.css'));
        $this->check('chat shows the current role as a badge', (bool) preg_match('/id="role-badge">Employee</', $r['body']));
        $this->check('chat has the "Show generated SQL" toggle', str_contains($r['body'], 'id="toggle-sql"') && str_contains($r['body'], 'Show generated SQL'));
        $this->check('demo questions offered as suggestions', str_contains($r['body'], 'How many leave days do I have left?')
            && str_contains($r['body'], 'average salary in engineering'));
        $this->check('widget config carries the session user id (for per-user history)',
            str_contains($r['body'], htmlspecialchars('"userId":' . $who('dev1@demo.local')->id, ENT_QUOTES)));

        $r = $http->get('/index.php?r=chat/audit');
        $this->check("dev1's audit page shows own questions only", str_contains($r['body'], 'verify-q-dev1') && !str_contains($r['body'], 'verify-q-hr'));
        $this->check('chat widget is also on the audit page', str_contains($r['body'], 'id="chat-fab"'));

        $r = $http->get('/index.php?r=security-test/index');
        $this->check('test bench shows the role\'s system prompt', str_contains($r['body'], 'System prompt the AI receives') && str_contains($r['body'], 'v_my_leave_balance'));
        $this->check('chat widget is also on the test bench', str_contains($r['body'], 'id="chat-fab"'));

        $http->post('/index.php?r=site/switch-user', ['id' => $who('hr@demo.local')->id]);
        $r = $http->get('/index.php?r=chat/audit');
        $this->check("hr's audit page shows everyone's questions", str_contains($r['body'], 'verify-q-dev1') && str_contains($r['body'], 'verify-q-hr'));
        $r = $http->get('/index.php');
        $this->check('after switching, the chat badge says HR', (bool) preg_match('/id="role-badge">HR</', $r['body']));

        \app\models\ChatAuditLog::deleteAll(['>', 'id', $startId]);
        return $this->summary();
    }

    /**
     * LIVE acceptance test of the five demo questions against the configured provider.
     * Needs an API key in config/ai.php. Uses real AI calls (free-tier quota).
     */
    public function actionDemo(): int
    {
        $this->section('Demo script - live against the configured AI provider');
        $cfg = \app\components\ai\provider\ProviderFactory::configOrDefault();
        try {
            $provider = \app\components\ai\provider\ProviderFactory::create();
            $this->stdout("  provider: {$provider->name()} / {$provider->model()}\n");
        } catch (\app\components\ai\provider\ProviderError $e) {
            $this->stdout("  SKIPPED: {$e->getMessage()}\n  Paste a key into config/ai.php and re-run.\n");
            return ExitCode::OK;
        }

        $who = fn(string $email) => \app\models\Employee::findByEmail($email);
        $cases = [
            ['#1', 'dev1@demo.local', "what's our leave policy?", fn($r) => $r['path'] === 'info' && $r['queries'] === []],
            ['#2', 'dev1@demo.local', 'how many leave days do I have left?', fn($r) => $r['path'] === 'data'
                && ($r['queries'][count($r['queries']) - 1]['bindings']['me'] ?? null) === $who('dev1@demo.local')->id
                && preg_match('/\b16\b/', $r['answer'])],
            ['#3', 'dev1@demo.local', "what's the average salary in engineering?", fn($r) => $r['path'] === 'denied'
                && stripos($r['answer'], 'salar') !== false],
            ['#4', 'enghead@demo.local', 'who on my team has pending leave?', fn($r) => $r['path'] === 'data'
                && ($r['queries'][count($r['queries']) - 1]['bindings']['dept'] ?? null) === $who('enghead@demo.local')->department_id
                && str_contains($r['answer'], 'Arif') && str_contains($r['answer'], 'Farhana')],
            ['#5', 'hr@demo.local', "what's the average salary in engineering?", fn($r) => $r['path'] === 'data'
                && preg_match('/218[,.]?400|2,18,400/', $r['answer'])],
        ];
        foreach ($cases as [$id, $email, $q, $ok]) {
            $r = (new \app\components\ai\ChatService())->ask($who($email), $q);
            $this->check("$id $email: \"$q\" -> {$r['path']} ({$r['latencyMs']} ms)", (bool) $ok($r));
            $this->stdout('       answer: ' . str_replace("\n", ' ', mb_substr($r['answer'], 0, 220)) . "\n");
            foreach ($r['queries'] as $query) {
                $this->stdout("       sql [{$query['status']}]: " . preg_replace('/\s+/', ' ', $query['sql']) . "\n");
            }
            sleep((int) ($cfg['demoPauseSeconds'] ?? 4)); // stay under free-tier per-minute limits
        }
        return $this->summary();
    }

    /** Strips the knowledge-base section (which legitimately talks about "salary" as a policy topic). */
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

    // ------------------------------------------------------------------ helpers

    protected function section(string $title): void
    {
        $this->stdout("\n== $title ==\n");
    }

    protected function check(string $label, bool $ok, string $detail = ''): void
    {
        $ok ? $this->passed++ : $this->failed++;
        $this->stdout(sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " -- $detail" : ''));
    }

    protected function summary(): int
    {
        $this->stdout(sprintf("\n%d passed, %d failed\n", $this->passed, $this->failed));
        return $this->failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
