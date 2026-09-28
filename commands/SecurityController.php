<?php

declare(strict_types=1);

namespace app\commands;

use app\components\ai\PromptBuilder;
use app\components\ai\QueryGateway;
use app\models\Employee;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Demo helpers for showing the permission layer from a terminal.
 *
 *   php yii security/prompt tanvir
 *       Print the exact system prompt that role's AI receives.
 *
 *   php yii security/check tanvir "SELECT ..."
 *       Run SQL through the validator + dbAi as that user (same path as the AI tool).
 *
 *   php yii security/raw "SELECT AVG(gross_salary) FROM salary_info"
 *       BYPASS the validator and send SQL straight to MySQL as erp_ai_ro, to show that
 *       the grant alone refuses base tables. Console-only (operator access) by design.
 */
class SecurityController extends Controller
{
    public function actionPrompt(string $username): int
    {
        $employee = $this->employee($username);
        if ($employee === null) {
            return ExitCode::DATAERR;
        }
        $this->stdout((new PromptBuilder())->build($employee) . "\n");
        return ExitCode::OK;
    }

    public function actionCheck(string $username, string $sql): int
    {
        $employee = $this->employee($username);
        if ($employee === null) {
            return ExitCode::DATAERR;
        }
        $r = (new QueryGateway($employee))->run($sql);
        $color = ['ok' => Console::FG_GREEN, 'denied' => Console::FG_RED, 'error' => Console::FG_YELLOW][$r['status']];
        $this->stdout(strtoupper($r['status']) . "\n", $color, Console::BOLD);
        if ($r['status'] !== 'ok') {
            $this->stdout("User sees : {$r['userMessage']}\nAudit     : {$r['code']} - {$r['reason']}\n");
            return ExitCode::OK;
        }
        $this->stdout("Executed  : {$r['finalSql']}\nBound     : " . json_encode($r['bindings']) . "\n");
        foreach ($r['notes'] as $n) {
            $this->stdout("Note      : $n\n");
        }
        $this->stdout('Rows (' . count($r['rows']) . "):\n");
        foreach (array_slice($r['rows'], 0, 20) as $row) {
            $this->stdout('  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
        }
        return ExitCode::OK;
    }

    public function actionRaw(string $sql): int
    {
        $conn = Yii::$app->dbAi;
        $this->stdout('As ' . $conn->createCommand('SELECT CURRENT_USER()')->queryScalar() . ", validator BYPASSED:\n  $sql\n");
        try {
            $rows = $conn->createCommand($sql)->queryAll();
            $this->stdout('MySQL ALLOWED it (' . count($rows) . " rows)\n", Console::FG_YELLOW);
        } catch (\yii\db\Exception $e) {
            $this->stdout("MySQL REFUSED it: {$e->errorInfo[2]} (error {$e->errorInfo[1]})\n", Console::FG_RED, Console::BOLD);
        }
        return ExitCode::OK;
    }

    private function employee(string $username): ?Employee
    {
        $u = \app\models\ErpUser::findByUsername($username);
        $e = $u ? Employee::findIdentity((int) $u->PBI_ID) : null;
        if ($e === null) {
            $this->stderr("No in-service employee with ERP username $username\n", Console::FG_RED);
        }
        return $e;
    }
}
