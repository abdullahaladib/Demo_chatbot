<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * The ONE path by which any SQL (produced by the model via runReadOnlyQuery, or hand-written
 * in the CLI tests) reaches the database:
 *
 *   SqlValidator (layer 4, per-user ERP-module allowlist) -> QueryExecutor on the read-only
 *   connection with session binding (layer 3) -> catalogued tables/columns and v_* views
 *   (layer 2) -> the AI account's MySQL grants (layer 1)
 */
final class QueryGateway
{
    private SqlValidator $validator;

    public function __construct(private readonly Identity $identity, ?AccessPolicy $policy = null)
    {
        $policy ??= new AccessPolicy($identity);
        $this->validator = new SqlValidator($policy, (int) Config::get('maxRows', 200), self::tableExistsChecker());
    }

    /**
     * @return array{status:'ok'|'denied'|'error', retryable:bool, userMessage:string, reason:string,
     *               code:string, sql:string, finalSql:string, notes:string[], tables:string[],
     *               bindings:array<string,int>, rows:array<int,array<string,mixed>>}
     */
    public function run(string $sql): array
    {
        $result = [
            'status' => 'ok', 'retryable' => false, 'userMessage' => '', 'reason' => '', 'code' => 'ok',
            'sql' => $sql, 'finalSql' => '', 'notes' => [], 'tables' => [], 'bindings' => [], 'rows' => [],
        ];

        $validated = $this->validator->validate($sql);
        if (!$validated->ok) {
            return array_merge($result, [
                'status' => 'denied', 'retryable' => $validated->retryable(), 'code' => $validated->code,
                'reason' => $validated->reason, 'userMessage' => $validated->userMessage,
            ]);
        }

        $result['finalSql'] = $validated->sql;
        $result['notes'] = $validated->notes;
        $result['tables'] = $validated->tables;

        try {
            $out = (new QueryExecutor($this->identity))->execute($validated);
        } catch (QueryFailed $e) {
            // A MySQL permission error means layer 1 caught something layer 4 let through
            // (e.g. a hidden column): report it as a refusal, not a crash.
            return array_merge($result, [
                'status' => $e->category === 'permission_denied' ? 'denied' : 'error',
                'retryable' => $e->category === 'bad_query',
                'code' => $e->category,
                'reason' => $e->getMessage(),
                'userMessage' => $e->userMessage(),
            ]);
        }

        $result['rows'] = $out['rows'];
        $result['bindings'] = $out['bindings'];
        return $result;
    }

    /** Does a table exist in the tenant at all? (typo vs. forbidden table) */
    private static function tableExistsChecker(): callable
    {
        return static function (string $lower): bool {
            $stmt = Db::app()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = ?');
            $stmt->execute([$lower]);
            return (int) $stmt->fetchColumn() > 0;
        };
    }
}
