<?php

declare(strict_types=1);

namespace app\components\ai;

use app\models\Employee;
use Yii;

/**
 * LAYER 3 - executes a validated query on the restricted `dbAi` connection (erp_ai_ro),
 * binding :me and :dept from the logged-in employee's session record.
 *
 * The values come ONLY from the Employee identity loaded from the session - never from
 * the model's output, never by string interpolation. The validator reports which
 * placeholder sits in each position; this class supplies the value.
 */
final class QueryExecutor
{
    public function __construct(private readonly Employee $employee)
    {
    }

    /**
     * @return array{rows: array<int, array<string,mixed>>, bindings: array<string,int>}
     * @throws QueryFailed with a safe category; the raw MySQL error is logged, never returned
     */
    public function execute(ValidationResult $validated): array
    {
        if (!$validated->ok) {
            throw new \LogicException('Refusing to execute a query that failed validation.');
        }

        $session = [
            'me' => (int) $this->employee->id,
            'dept' => $this->employee->department_id === null ? null : (int) $this->employee->department_id,
        ];

        $positional = [];
        foreach ($validated->placeholders as $n => $name) {
            if ($session[$name] === null) {
                throw new QueryFailed('scope_unavailable', "Session has no value for :$name");
            }
            $positional[$n + 1] = $session[$name];
        }

        try {
            $rows = Yii::$app->dbAi->createCommand($validated->executableSql)
                ->bindValues($positional)
                ->queryAll();
        } catch (\yii\db\Exception $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            Yii::error("AI query failed (MySQL $code): {$e->getMessage()}\nSQL: {$validated->executableSql}", __METHOD__);
            throw new QueryFailed(match (true) {
                in_array($code, [1142, 1143, 1044, 1045, 1227, 1370], true) => 'permission_denied',
                $code === 3024 => 'timeout',
                in_array($code, [1054, 1146, 1064, 1052, 1055, 1111, 1247], true) => 'bad_query',
                default => 'database_error',
            }, "MySQL error $code", $code);
        }

        $used = array_intersect_key($session, array_flip($validated->placeholders));
        return ['rows' => $rows, 'bindings' => $used];
    }
}
