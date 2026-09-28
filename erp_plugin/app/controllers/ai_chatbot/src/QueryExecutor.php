<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * LAYER 3 - executes a validated query on the restricted AI connection, binding the row
 * scope from the ERP session:
 *   :me    signed-in employee's pbi_id        (v_my_* / v_team_* views)
 *   :dept  department the dept head heads      (v_dept_* views)
 *   :group company the user signed into        (every table with a group_for column)
 * Values come ONLY from the Identity (built from the ERP session) - never from the model,
 * never by string interpolation.
 */
final class QueryExecutor
{
    public function __construct(private readonly Identity $identity)
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

        $session = ['me' => $this->identity->pbiId, 'dept' => $this->identity->departmentId, 'group' => $this->identity->group];
        $positional = [];
        foreach ($validated->placeholders as $name) {
            if ($session[$name] === null) {
                throw new QueryFailed('scope_unavailable', "Session has no value for :$name");
            }
            $positional[] = $session[$name];
        }

        try {
            $stmt = Db::ai()->prepare($validated->executableSql);
            foreach ($positional as $n => $value) {
                $stmt->bindValue($n + 1, $value, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            Log::error("AI query failed (MySQL $code): {$e->getMessage()} | SQL: {$validated->executableSql}");
            throw new QueryFailed(match (true) {
                in_array($code, [1142, 1143, 1044, 1045, 1227, 1370], true) => 'permission_denied',
                $code === 3024, $code === 1969 => 'timeout', // MySQL / MariaDB statement timeout
                in_array($code, [1054, 1146, 1064, 1052, 1055, 1111, 1247, 1242, 1241, 1222, 1060], true) => 'bad_query',
                default => 'database_error',
            }, "MySQL error $code", $code);
        }

        $used = array_intersect_key($session, array_flip($validated->placeholders));
        return ['rows' => $rows, 'bindings' => $used];
    }
}
