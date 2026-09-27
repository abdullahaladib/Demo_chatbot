<?php

declare(strict_types=1);

namespace app\components\ai;

use app\models\Employee;

/**
 * The ONE path by which any SQL (hand-written on the test bench, or produced by the
 * model via runReadOnlyQuery) reaches the database:
 *
 *     SqlValidator (layer 4) -> QueryExecutor on dbAi with session binding (layer 3)
 *         -> role-scoped views (layer 2) -> erp_ai_ro grants (layer 1)
 *
 * The role and ids come from the Employee passed in, which the controller loads from
 * the session. Nothing here accepts a role or id from the caller's input.
 */
final class QueryGateway
{
    public function __construct(private readonly Employee $employee)
    {
    }

    /**
     * @return array{
     *   status: 'ok'|'denied'|'error',
     *   userMessage: string,     // safe to show the user ('' when ok)
     *   reason: string,          // technical, for the audit log only
     *   code: string,
     *   sql: string,             // query as submitted
     *   finalSql: string,        // after rewrites, named placeholders
     *   notes: string[],
     *   views: string[],
     *   bindings: array<string,int>,
     *   rows: array<int, array<string,mixed>>
     * }
     */
    public function run(string $sql): array
    {
        $result = [
            'status' => 'ok', 'userMessage' => '', 'reason' => '', 'code' => 'ok',
            'sql' => $sql, 'finalSql' => '', 'notes' => [], 'views' => [], 'bindings' => [], 'rows' => [],
        ];

        $validated = (new SqlValidator($this->employee->role))->validate($sql);
        if (!$validated->ok) {
            return array_merge($result, [
                'status' => 'denied', 'code' => $validated->code,
                'reason' => $validated->reason, 'userMessage' => $validated->userMessage,
            ]);
        }

        $result['finalSql'] = $validated->sql;
        $result['notes'] = $validated->notes;
        $result['views'] = $validated->views;

        try {
            $out = (new QueryExecutor($this->employee))->execute($validated);
        } catch (QueryFailed $e) {
            // A MySQL permission error means layer 1 caught something layer 4 missed:
            // report it as a refusal, not a crash.
            return array_merge($result, [
                'status' => $e->category === 'permission_denied' ? 'denied' : 'error',
                'code' => $e->category,
                'reason' => $e->getMessage(),
                'userMessage' => $e->userMessage(),
            ]);
        }

        $result['rows'] = $out['rows'];
        $result['bindings'] = $out['bindings'];
        return $result;
    }
}
