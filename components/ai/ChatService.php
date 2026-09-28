<?php

declare(strict_types=1);

namespace app\components\ai;

use app\components\ai\provider\LlmProvider;
use app\components\ai\provider\ProviderError;
use app\components\ai\provider\ProviderFactory;
use app\components\ai\provider\ToolDefinition;
use app\models\ChatAuditLog;
use app\models\Employee;
use Yii;

/**
 * One chat turn, end to end.
 *
 *  1. The employee (and so the role) comes from the session - the caller passes the
 *     identity loaded by Yii's user component, never anything from the request body.
 *  2. System prompt for THAT role (PromptBuilder).
 *  3. Model called with two tools: getUserRole(), runReadOnlyQuery(sql).
 *  4. No tool call -> information answer (or the model's own ACCESS_DENIED refusal).
 *  5. getUserRole -> the session role.
 *  6. runReadOnlyQuery -> QueryGateway (validate, bind :me/:dept, run on dbAi) -> rows
 *     back to the model, which phrases the answer. A refusal ends the turn immediately
 *     with a plain-English message written by US, not by the model.
 *  7. Exactly one chat_audit_log row, whatever happened.
 */
final class ChatService
{
    public const MAX_QUESTION_LENGTH = 1000;

    /** A failed (not refused) query is given back to the model this many times to fix. */
    private const MAX_QUERY_RETRIES = 2;

    /**
     * @param LlmProvider|null   $provider injected (tests); otherwise built from config/ai.php
     * @param ResponseCache|null $cache    injected (tests); otherwise from config ('cache')
     * @param string|null        $mode     override config 'mode' (live|record|replay)
     */
    public function __construct(
        private readonly ?LlmProvider $provider = null,
        private ?ResponseCache $cache = null,
        private readonly ?string $mode = null,
    ) {
    }

    /**
     * @return array{
     *   answer: string, path: string, role: string,
     *   queries: array<int, array>, table: ?array, trace: array<int, array>,
     *   latencyMs: int, provider: ?string, model: ?string
     * }
     */
    public function ask(Employee $employee, string $question): array
    {
        $started = microtime(true);
        $question = trim($question);
        $state = [
            'answer' => '', 'path' => ChatAuditLog::PATH_INFO, 'role' => $employee->role,
            'queries' => [], 'table' => null, 'trace' => [],
            'denial' => null, 'provider' => null, 'model' => null, 'cached' => false,
        ];
        $auditProvider = null;

        try {
            if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
                throw new \DomainException('Please ask a question of up to ' . self::MAX_QUESTION_LENGTH . ' characters.');
            }
            // Building the provider makes no network call.
            $provider = $this->provider
                ?? ProviderFactory::create(null, ['user' => $employee->email, 'question' => $question], $this->mode);
            $state['provider'] = $provider->name();
            $state['model'] = $provider->model();
            $auditProvider = $provider->name();

            $this->cache ??= ResponseCache::fromConfig();
            $hit = $this->cache->get($employee, $question, $provider->name(), $provider->model());
            if ($hit !== null) {
                // Same user, role and question, same data: replay the stored turn with NO AI call.
                $state = array_merge($state, array_intersect_key($hit, array_flip(['answer', 'path', 'queries', 'table', 'trace', 'denial'])));
                $state['cached'] = true;
                $state['trace'][] = ['step' => 'cache', 'detail' => 'answer served from the response cache (no AI call)'];
                $auditProvider = substr($provider->name() . ' (cached)', 0, 30);
            } else {
                $this->converse($provider, $employee, $question, $state);
                $this->cache->put($employee, $question, $provider->name(), $provider->model(), $state);
            }
        } catch (ProviderError $e) {
            Yii::error("AI provider error [{$e->category}]: {$e->getMessage()}", __METHOD__);
            $state['path'] = ChatAuditLog::PATH_ERROR;
            $state['answer'] = $e->userMessage();
            $state['denial'] = "provider:{$e->category}";
        } catch (\DomainException $e) {
            $state['path'] = ChatAuditLog::PATH_ERROR;
            $state['answer'] = $e->getMessage();
            $state['denial'] = 'invalid_question';
        } catch (\Throwable $e) {
            Yii::error('Chat turn failed: ' . $e, __METHOD__);
            $state['path'] = ChatAuditLog::PATH_ERROR;
            $state['answer'] = 'Something went wrong while answering. Please try again.';
            $state['denial'] = 'internal: ' . get_class($e);
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        $sqls = array_column($state['queries'], 'sql');
        $lastOk = null;
        foreach ($state['queries'] as $q) {
            if ($q['status'] === 'ok') {
                $lastOk = $q;
            }
        }
        ChatAuditLog::record([
            'employee_id' => $employee->id,
            'role' => $employee->role,
            'question' => $question,
            'path' => $state['path'],
            'generated_sql' => $sqls ? implode(";\n\n", $sqls) : null,
            'denial_reason' => $state['denial'],
            'row_count' => $lastOk['rowCount'] ?? null,
            'latency_ms' => $latency,
            'provider' => $auditProvider,
            'model' => $state['model'],
        ]);

        unset($state['denial']);
        $state['latencyMs'] = $latency;
        return $state;
    }

    /** @return ToolDefinition[] */
    public static function tools(): array
    {
        return [
            new ToolDefinition(
                'getUserRole',
                "Returns the role of the user asking the question (employee, manager, dept_head, hr or ceo). Call this before answering any question about ERP data.",
            ),
            new ToolDefinition(
                'runReadOnlyQuery',
                "Runs ONE read-only MySQL SELECT against the views listed in the system prompt and returns the rows. Use :me for the current user's employee id and :dept for their department id; the server binds them. Must include LIMIT.",
                [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string', 'description' => 'A single MySQL SELECT statement.'],
                    ],
                    'required' => ['sql'],
                ],
            ),
        ];
    }

    private function converse(LlmProvider $provider, Employee $employee, string $question, array &$state): void
    {
        $provider->start((new PromptBuilder())->build($employee), $question, self::tools());
        $gateway = new QueryGateway($employee);
        $maxRounds = (int) (ProviderFactory::configOrDefault()['maxToolRounds'] ?? 6);
        $failedQueries = 0;
        $usedData = false;

        for ($round = 0; $round < $maxRounds; $round++) {
            $turn = $provider->send();

            if (!$turn->hasToolCalls()) {
                $text = $turn->text;
                if (str_starts_with(ltrim($text), PromptBuilder::DENIAL_MARKER)) {
                    // The model recognised it has no view for this - it never wrote SQL.
                    $subject = trim(substr(ltrim($text), strlen(PromptBuilder::DENIAL_MARKER)), " \t\n\r.\"'");
                    $state['path'] = ChatAuditLog::PATH_DENIED;
                    $state['answer'] = "You're not authorised to access " . ($subject !== '' ? $subject : 'that information') . '.';
                    $state['denial'] = 'model: no permitted view for "' . ($subject ?: 'unspecified') . '"';
                    $state['trace'][] = ['step' => 'refusal', 'detail' => 'Model found no permitted view; no SQL generated'];
                    return;
                }
                if ($text === '') {
                    throw new ProviderError(ProviderError::BAD_RESPONSE, 'Model returned an empty answer');
                }
                $state['answer'] = $text;
                $state['path'] = $usedData ? ChatAuditLog::PATH_DATA : ChatAuditLog::PATH_INFO;
                return;
            }

            $results = [];
            foreach ($turn->toolCalls as $call) {
                if ($call['name'] === 'getUserRole') {
                    // Not a security control: the backend already knows the role. This
                    // makes the role check visible and traceable in the demo.
                    $state['trace'][] = ['step' => 'getUserRole', 'detail' => $employee->role];
                    $results[] = ['id' => $call['id'], 'name' => $call['name'], 'result' => [
                        'role' => $employee->role,
                        'role_label' => $employee->getRoleLabel(),
                    ]];
                    continue;
                }

                if ($call['name'] !== 'runReadOnlyQuery') {
                    $results[] = ['id' => $call['id'], 'name' => $call['name'], 'result' => ['error' => 'Unknown tool.']];
                    continue;
                }

                $sql = (string) ($call['args']['sql'] ?? '');
                $r = $gateway->run($sql);
                $state['queries'][] = [
                    'sql' => $sql,
                    'executedSql' => $r['finalSql'],
                    'bindings' => $r['bindings'],
                    'notes' => $r['notes'],
                    'status' => $r['status'],
                    'code' => $r['code'],
                    'rowCount' => count($r['rows']),
                ];
                $state['trace'][] = ['step' => 'runReadOnlyQuery', 'detail' => $r['status'] . ($r['status'] === 'ok' ? ' (' . count($r['rows']) . ' rows)' : " ({$r['code']})")];

                if ($r['status'] === 'denied') {
                    // Refusal is final and worded by the server, not the model.
                    $state['path'] = ChatAuditLog::PATH_DENIED;
                    $state['answer'] = $r['userMessage'];
                    $state['denial'] = "{$r['code']}: {$r['reason']}";
                    return;
                }

                if ($r['status'] === 'error') {
                    $failedQueries++;
                    if ($r['code'] !== 'bad_query' || $failedQueries > self::MAX_QUERY_RETRIES) {
                        $state['path'] = ChatAuditLog::PATH_ERROR;
                        $state['answer'] = $r['userMessage'];
                        $state['denial'] = "{$r['code']}: {$r['reason']}";
                        return;
                    }
                    // Give the model a sanitised hint - never the raw MySQL error text.
                    $results[] = ['id' => $call['id'], 'name' => $call['name'], 'result' => [
                        'error' => 'The query could not be executed (for example an unknown column, a GROUP BY problem or a syntax error). Re-check the column list in the system prompt and try again.',
                    ]];
                    continue;
                }

                $usedData = true;
                $state['table'] = $r['rows'] ? ['columns' => array_keys($r['rows'][0]), 'rows' => $r['rows']] : ['columns' => [], 'rows' => []];
                $results[] = ['id' => $call['id'], 'name' => $call['name'], 'result' => [
                    'row_count' => count($r['rows']),
                    'rows' => $r['rows'],
                ]];
            }
            $provider->addToolResults($results);
        }

        throw new ProviderError(ProviderError::BAD_RESPONSE, "No final answer after $maxRounds tool rounds");
    }
}
