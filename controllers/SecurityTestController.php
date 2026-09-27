<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ai\PromptBuilder;
use app\components\ai\QueryGateway;
use app\models\ChatAuditLog;
use app\models\Employee;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Security test bench: drive the permission layer with HAND-WRITTEN SQL, no AI involved.
 *
 * Exactly the same path the AI's runReadOnlyQuery tool uses (QueryGateway), as the
 * logged-in user. Useful in the demo to show that the permission layer stands on its
 * own - the AI is just one more untrusted SQL author.
 */
class SecurityTestController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
        ];
    }

    public function actionIndex(): string
    {
        /** @var Employee $me */
        $me = Yii::$app->user->identity;
        $sql = (string) $this->request->post('sql', '');
        $result = null;

        if ($this->request->isPost && trim($sql) !== '') {
            $started = microtime(true);
            $result = (new QueryGateway($me))->run($sql);
            ChatAuditLog::record([
                'employee_id' => $me->id,
                'role' => $me->role,
                'question' => '[test bench] ' . $sql,
                'path' => match ($result['status']) {
                    'ok' => ChatAuditLog::PATH_DATA,
                    'denied' => ChatAuditLog::PATH_DENIED,
                    default => ChatAuditLog::PATH_ERROR,
                },
                'generated_sql' => $sql,
                'denial_reason' => $result['reason'] ?: null,
                'row_count' => $result['status'] === 'ok' ? count($result['rows']) : null,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'provider' => 'test-bench',
            ]);
        }

        return $this->render('index', [
            'me' => $me,
            'sql' => $sql,
            'result' => $result,
            'prompt' => (new PromptBuilder())->build($me),
            'presets' => $this->presets(),
        ]);
    }

    /** Example queries covering each validator rule. */
    private function presets(): array
    {
        return [
            'My leave balance (self-scoped)' => "SELECT leave_type, entitled, used, remaining\nFROM v_my_leave_balance\nWHERE employee_id = :me AND year = YEAR(CURDATE())",
            'Row-scope bypass attempt (OR 1=1)' => "SELECT DISTINCT employee_id FROM v_my_attendance\nWHERE employee_id = :me OR 1=1",
            'Average salary in Engineering' => "SELECT ROUND(AVG(gross_salary)) AS avg_salary\nFROM v_hr_employees_full\nWHERE department = 'Engineering'",
            'Base table: salaries' => 'SELECT AVG(basic) FROM salaries',
            'Missing :me' => 'SELECT * FROM v_my_leave_balance',
            'Pending leave in my department (:dept)' => "SELECT employee_name, leave_type, start_date, days\nFROM v_dept_leave_requests\nWHERE department_id = :dept AND status = 'pending'",
            "My team's pending leave (manager_id = :me)" => "SELECT employee_name, leave_type, start_date, days\nFROM v_team_leave_requests\nWHERE manager_id = :me AND status = 'pending'",
            'Non-SELECT (UPDATE)' => 'UPDATE v_hr_payroll SET basic_salary = 1',
            'Stacked statements' => 'SELECT 1; DROP TABLE employees',
            'Comment smuggling' => 'SELECT full_name FROM v_employee_directory -- WHERE 1=0',
            'LIMIT 5000 (rewritten to 200)' => 'SELECT full_name, department FROM v_employee_directory LIMIT 5000',
            'INFORMATION_SCHEMA probe' => 'SELECT table_name FROM information_schema.tables',
        ];
    }
}
