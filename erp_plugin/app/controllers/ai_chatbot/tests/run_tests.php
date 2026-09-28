<?php
/**
 * AI Chatbot plug-in - offline test suite. NO AI calls: the "model" is ScriptedProvider.
 *
 *   php app/controllers/ai_chatbot/tests/run_tests.php [baseUrl]      (from the ERP root)
 *
 * Needs config.local.php (adminDb + aiAccounts) and the demo logins from install/local_demo_setup.php.
 * The HTTP section runs only when the ERP is being served at baseUrl (default
 * http://training.localhost:8090); otherwise it is reported as skipped.
 */

declare(strict_types=1);

use AiChatbot\AccessPolicy;
use AiChatbot\ChatService;
use AiChatbot\Db;
use AiChatbot\Identity;
use AiChatbot\PromptBuilder;
use AiChatbot\QueryGateway;
use AiChatbot\SqlValidator;
use AiChatbot\TableDescriber;
use AiChatbot\provider\LlmTurn;
use AiChatbot\provider\ScriptedProvider;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
Db::useAdminAccount();

$pass = 0;
$fail = 0;
$skip = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . ($ok || $detail === '' ? '' : "  -- $detail") . "\n";
}
function section(string $title): void
{
    echo "\n== $title ==\n";
}
function userId(string $username): int
{
    $s = Db::app()->prepare("SELECT user_id FROM user_activity_management WHERE username = ? AND status = 'Active' ORDER BY user_id");
    $s->execute([$username]);
    return (int) $s->fetchColumn();
}
/** A scripted model: $steps[$round] is a list of tool calls, or a final text. */
function model(array $steps, ?array &$seen = null): ScriptedProvider
{
    return new ScriptedProvider(function (string $q, array $results, int $round) use ($steps, &$seen): LlmTurn {
        $seen = $results;
        $step = $steps[$round] ?? 'done';
        if (is_string($step)) {
            return new LlmTurn($step);
        }
        return new LlmTurn('', array_map(fn($c, $i) => ['id' => "c$round$i", 'name' => $c[0], 'args' => $c[1] ?? []], $step, array_keys($step)));
    });
}
function auditCount(): int
{
    return (int) Db::app()->query('SELECT COUNT(*) FROM ai_chat_audit_log')->fetchColumn();
}

// ------------------------------------------------------------------------------------------
section('Identity and tier (from org data, not the ERP level)');
$tanvir = Identity::load(userId('tanvir'));
$hr = Identity::load(userId('hr.demo'));
$noEmp = Identity::load(userId('mhafuz'));
check('tanvir resolves with modules', $tanvir !== null && count($tanvir->modules) > 0);
check('tanvir is linked to an employee', $tanvir?->pbiId !== null);
check('tanvir tier is not hr/ceo', !in_array($tanvir?->tier, ['hr', 'ceo'], true), (string) $tanvir?->tier);
check('hr.demo tier is hr', $hr?->tier === 'hr', (string) $hr?->tier);
check('login without an employee record gets tier none', $noEmp?->tier === 'none', (string) $noEmp?->tier);

// ------------------------------------------------------------------------------------------
section('Access policy (mirrors ERP modules)');
$pT = new AccessPolicy($tanvir);
$pH = new AccessPolicy($hr);
$pN = new AccessPolicy($noEmp);
check('tanvir: journal allowed (Accounts module)', $pT->allows('journal'));
check('tanvir: sale_do_master allowed (Sales module)', $pT->allows('sale_do_master'));
check('tanvir: salary table hris_employee_salary denied', !$pT->allows('hris_employee_salary'));
check('tanvir: personnel_basic_info denied (no HR admin module)', !$pT->allows('personnel_basic_info'));
check('tanvir: own-record view v_my_leave_balance allowed', $pT->allows('v_my_leave_balance'));
check('tanvir: v_exec_payroll_summary denied', !$pT->allows('v_exec_payroll_summary'));
check('tanvir: system table user_activity_management denied', !$pT->allows('user_activity_management'));
check('hr.demo: personnel_basic_info allowed', $pH->allows('personnel_basic_info'));
check('hr.demo: salary table allowed (confidential, hr tier)', $pH->allows('hris_employee_salary'));
check('no-employee login: v_my_profile denied', !$pN->allows('v_my_profile'));
check('scope of journal is company (group_for)', ($pT->scopeOf('journal')['param'] ?? '') === 'group');
check('scope of v_my_profile is :me', ($pT->scopeOf('v_my_profile')['param'] ?? '') === 'me');

// ------------------------------------------------------------------------------------------
section('SQL validator');
$v = new SqlValidator($pT, 200, fn(string $t) => in_array($t, ['journal', 'hris_employee_salary', 'user_activity_management'], true));
$r = $v->validate('SELECT jv_no, dr_amt FROM journal LIMIT 5');
check('plain SELECT on an allowed table passes', $r->ok, $r->reason);
check('company scope is injected (group_for = ?)', str_contains($r->executableSql, '`group_for` = ?') && $r->placeholders === ['group'], $r->executableSql);
check('wrapped table lists explicit allowed columns (no *)', !str_contains($r->executableSql, 'SELECT *'), $r->executableSql);
$r = $v->validate('SELECT jv_no FROM journal LIMIT 5000');
check('LIMIT above 200 is rewritten to 200', $r->ok && str_contains($r->sql, 'LIMIT 200'), $r->sql);
$r = $v->validate('SELECT jv_no FROM journal');
check('missing LIMIT is appended', $r->ok && str_contains($r->sql, 'LIMIT 200'), $r->sql);
$r = $v->validate('SELECT * FROM journal LIMIT 5');
check('SELECT * rejected as a retryable mistake', !$r->ok && $r->code === 'select_star' && $r->retryable(), $r->code);
$r = $v->validate('SELECT a FROM jurnal LIMIT 5');
check('typo table -> unknown_table, retryable', !$r->ok && $r->code === 'unknown_table' && $r->retryable(), $r->code);
$r = $v->validate('SELECT basic_salary FROM hris_employee_salary LIMIT 5');
check('salary table -> security refusal (not retryable)', !$r->ok && !$r->retryable(), $r->code);
$r = $v->validate('SELECT username FROM user_activity_management LIMIT 5');
check('system table -> security refusal', !$r->ok && !$r->retryable(), $r->code);
$r = $v->validate('SELECT leave_type FROM v_my_leave_balance LIMIT 5');
check('scoped view without :me -> missing_scope', !$r->ok && $r->code === 'missing_scope', $r->code);
$r = $v->validate('SELECT employee_id FROM v_my_profile WHERE employee_id = :me OR 1=1');
check('":me OR 1=1" still wraps the view to the asker', $r->ok && str_contains($r->executableSql, 'WHERE employee_id = ?'), $r->executableSql);
foreach ([
    'DELETE FROM journal' => 'DELETE',
    'SELECT jv_no FROM journal; DROP TABLE journal' => 'two statements',
    'SELECT jv_no FROM journal -- hi' => 'comment',
    'SELECT table_name FROM information_schema.tables' => 'system schema',
    'SELECT jv_no FROM journal INTO OUTFILE \'x\'' => 'INTO OUTFILE',
    'SELECT @@version' => 'system variable',
    'SELECT SLEEP(10)' => 'SLEEP()',
    'SELECT jv_no FROM erp_master.company_info' => 'other database',
] as $sql => $what) {
    check("rejected: $what", !$v->validate($sql)->ok);
}

// ------------------------------------------------------------------------------------------
section('Layer 1: the MySQL account itself (erp_ai_plugin)');
// One account serves every tier, so confidential (salary) tables ARE granted - hr/ceo need them;
// the tier split is enforced by AccessPolicy + SqlValidator (tested above), not by MySQL.
Db::useAdminAccount();
$ai = Db::ai();
$n = (int) $ai->query('SELECT COUNT(*) FROM journal')->fetchColumn();
check('AI account can read journal', $n > 0, (string) $n);
foreach ([
    'SELECT password FROM dealer_info LIMIT 1' => 'hidden column dealer_info.password',
    'SELECT username FROM user_activity_management LIMIT 1' => 'user_activity_management',
    'SELECT id FROM erp_master.database_info LIMIT 1' => 'master DB credentials',
    'UPDATE journal SET dr_amt = dr_amt WHERE 1 = 0' => 'UPDATE',
] as $sql => $what) {
    try {
        $ai->query($sql);
        check("MySQL refuses $what", false, 'query ran');
    } catch (PDOException $e) {
        check("MySQL refuses $what", true);
    }
}

// ------------------------------------------------------------------------------------------
section('Gateway: validate + bind + run read-only');
$gw = new QueryGateway($tanvir, $pT);
$r = $gw->run('SELECT COUNT(DISTINCT jv_no) AS vouchers FROM journal');
$s = Db::app()->prepare('SELECT COUNT(DISTINCT jv_no) FROM journal WHERE group_for = ?');
$s->execute([$tanvir->group]);
check('voucher count equals the company-scoped count', $r['status'] === 'ok' && (int) $r['rows'][0]['vouchers'] === (int) $s->fetchColumn(), json_encode($r['rows'] ?? $r['reason']));
check(':group bound from the session', ($r['bindings']['group'] ?? null) === $tanvir->group);
$r = $gw->run('SELECT employee_id FROM v_my_profile WHERE employee_id = :me OR 1=1');
check('v_my_profile returns only the asker', $r['status'] === 'ok' && count($r['rows']) === 1 && (int) $r['rows'][0]['employee_id'] === $tanvir->pbiId, json_encode($r['rows']));
$r = $gw->run('SELECT jv_no, no_such_column FROM journal LIMIT 3');
check('unknown column -> sanitised bad_query, retryable', $r['status'] === 'error' || ($r['code'] === 'bad_query'), $r['code']);
check('no raw MySQL error text leaks', !str_contains($r['userMessage'] . $r['reason'], 'SQLSTATE'), $r['userMessage']);

// ------------------------------------------------------------------------------------------
section('describeTables and prompt');
$d = TableDescriber::describe($pT, ['journal', 'hris_employee_salary', 'user_activity_management', 'dealer_info']);
$avail = array_map(fn($t) => strtolower($t['table']) . '=' . ($t['available'] ? 'yes' : 'no') . (isset($t['columns']) ? '+cols' : ''), $d);
check('describes allowed tables only (denied ones: no columns)', $avail === ['journal=yes+cols', 'hris_employee_salary=no', 'user_activity_management=no', 'dealer_info=yes+cols'], json_encode($avail));
$dealer = array_values(array_filter($d, fn($t) => strtolower($t['table'] ?? '') === 'dealer_info'))[0] ?? null;
$cols = array_map(fn($c) => strtok($c, ' '), $dealer['columns'] ?? []);
check('hidden columns are not described (dealer_info.password)', count($cols) > 5 && in_array('dealer_name_e', $cols, true) && !in_array('password', $cols, true), json_encode(array_slice($cols, 0, 5)));
$prompt = (new PromptBuilder())->build($pT);
check('prompt lists journal', str_contains($prompt, 'journal'));
check('prompt does not list salary tables', !str_contains($prompt, 'hris_employee_salary'));
check('prompt has no user id', !preg_match('/\b' . $tanvir->userId . '\b/', $prompt));
check('prompt carries the denial marker', str_contains($prompt, PromptBuilder::DENIAL_MARKER));

// ------------------------------------------------------------------------------------------
section('ChatService with a scripted model (no AI calls)');
$before = auditCount();
$res = (new ChatService(model(["Annual leave is 20 days."])))->ask($tanvir, "What's our leave policy?");
check('info path: text answer, no SQL', $res['path'] === 'info' && $res['queries'] === [], $res['path']);

$seen = null;
$res = (new ChatService(model([
    [['getUserRole'], ['describeTables', ['tables' => ['journal']]]],
    [['runReadOnlyQuery', ['sql' => 'SELECT tr_from, COUNT(DISTINCT jv_no) AS vouchers FROM journal GROUP BY tr_from ORDER BY vouchers DESC LIMIT 5']]],
    'Here are the vouchers by type.',
], $seen)))->ask($tanvir, 'How many vouchers by type?');
check('data path: query ran, table returned', $res['path'] === 'data' && ($res['table']['rows'] ?? []) !== [], $res['path'] . ' ' . $res['answer']);
check('trace shows getUserRole, describeTables, runReadOnlyQuery', array_column($res['trace'], 'step') === ['getUserRole', 'describeTables', 'runReadOnlyQuery'], json_encode(array_column($res['trace'], 'step')));

$res = (new ChatService(model([
    [['runReadOnlyQuery', ['sql' => 'SELECT * FROM journal LIMIT 5']]],
    [['runReadOnlyQuery', ['sql' => 'SELECT jv_no, jv_date FROM journal ORDER BY jv_date DESC LIMIT 5']]],
    'Latest vouchers listed.',
], $seen)))->ask($tanvir, 'Show the latest vouchers');
check('retry: SELECT * hint, then a corrected query succeeds', $res['path'] === 'data' && count($res['queries']) === 2 && $res['queries'][0]['status'] !== 'ok' && $res['queries'][1]['status'] === 'ok', json_encode(array_column($res['queries'], 'status')));

$res = (new ChatService(model([
    [['runReadOnlyQuery', ['sql' => 'SELECT AVG(basic_salary) AS avg_salary FROM hris_employee_salary']]],
    'The average salary is 99999.',
])))->ask($tanvir, 'What is the average salary?');
check('malicious model on salary: server refusal ends the turn', $res['path'] === 'denied' && !str_contains($res['answer'], '99999'), $res['answer']);

$res = (new ChatService(model(['ACCESS_DENIED: salary information'])))->ask($tanvir, 'What is the average salary?');
check('model self-refusal -> "not authorised" wording', $res['path'] === 'denied' && $res['answer'] === "You're not authorised to access salary information.", $res['answer']);

$res = (new ChatService(model(['ok'])))->ask($tanvir, str_repeat('x', 1001));
check('over-long question rejected before the model', $res['path'] === 'error' && $res['provider'] === null, (string) $res['provider']);

$res = (new ChatService(model([
    [['runReadOnlyQuery', ['sql' => 'SELECT AVG(basic_salary) FROM hris_employee_salary']]], 'x',
])))->ask($hr, 'Average salary?');
check('hr.demo may query salary data', $res['path'] === 'data', $res['path'] . ' ' . $res['answer']);
check('exactly one audit row per turn', auditCount() - $before === 7, (string) (auditCount() - $before));
$last = Db::app()->query('SELECT employee_id, erp_user_id, role FROM ai_chat_audit_log ORDER BY id DESC LIMIT 1')->fetch();
check('audit row carries pbi_id + ERP user_id + tier', (int) $last['employee_id'] === $hr->pbiId && (int) $last['erp_user_id'] === $hr->userId && $last['role'] === 'hr', json_encode($last));

// ------------------------------------------------------------------------------------------
section('HTTP endpoint guards (ERP must be running)');
$base = rtrim($argv[1] ?? 'http://training.localhost:8090', '/');
$host = parse_url($base, PHP_URL_HOST);
$port = parse_url($base, PHP_URL_PORT) ?: 80;
$jar = tempnam(sys_get_temp_dir(), 'aic');
$http = function (string $method, string $path, array $headers = [], ?string $body = null) use ($base, $host, $port, $jar): array {
    $c = curl_init($base . $path);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
        CURLOPT_RESOLVE => ["$host:$port:127.0.0.1"],
    ]);
    if ($body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    return [$code, (string) $out];
};
$askPath = '/app/views/ai_chatbot/api/ask.php';
[$code] = $http('GET', '/app/views/auth/masters/index.php');
if ($code === 0) {
    $skip++;
    echo "  [SKIP] ERP not reachable at $base\n";
} else {
    [$code] = $http('POST', $askPath, ['Content-Type: application/json'], '{"question":"hi"}');
    check('guest -> 401', $code === 401, (string) $code);

    [, $html] = $http('GET', '/app/views/auth/masters/index.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', str_replace("\n", ' ', $html), $m);
    $http('POST', '/app/views/auth/masters/index.php', [], http_build_query(['cid' => 'training', 'uid' => 'tanvir', 'pass' => 'Demo@1234', 'csrf_token' => $m[1] ?? '']));
    [$code, $home] = $http('GET', '/app/views/auth/masters/home.php');
    check('signed-in home renders the widget', $code === 200 && str_contains($home, 'id="aic-widget"'), (string) $code);
    preg_match('/id="aic-widget" data-config="([^"]+)"/', $home, $m);
    $cfg = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true) ?: [];
    [$code, $page] = $http('GET', '/app/views/acc/main/home.php?mod_id=2');
    check('module page renders the widget once', $code === 200 && substr_count($page, 'id="aic-widget"') === 1, (string) $code);

    [$code] = $http('GET', $askPath);
    check('GET -> 405', $code === 405, (string) $code);
    [$code] = $http('POST', $askPath, ['Content-Type: application/json'], '{"question":"hi"}');
    check('POST without CSRF token -> 403', $code === 403, (string) $code);
    [$code, $out] = $http('POST', $askPath, ['Content-Type: application/json', 'X-CSRF-Token: ' . ($cfg['csrf'] ?? '')],
        json_encode(['question' => '', 'role' => 'ceo', 'user_id' => 1]));
    $j = json_decode($out, true) ?: [];
    check('role/user_id in the body are ignored (identity from session)', $code === 200 && ($j['role'] ?? '') === $tanvir->tier && ($j['path'] ?? '') === 'error', $out);
}
@unlink($jar);

echo "\n" . str_repeat('-', 60) . "\nPassed: $pass   Failed: $fail" . ($skip ? "   Skipped: $skip" : '') . "\n";
exit($fail ? 1 : 0);
