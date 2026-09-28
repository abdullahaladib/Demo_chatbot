<?php

declare(strict_types=1);

namespace AiChatbot;

use AiChatbot\provider\LlmProvider;
use AiChatbot\provider\ProviderError;
use AiChatbot\provider\ProviderFactory;
use AiChatbot\provider\ToolDefinition;

/**
 * One chat turn, end to end (ported from the Demo_chatbot reference, extended for the ERP):
 *
 *  1. Identity comes from the ERP session (never the request body).
 *  2. System prompt for THAT user: their modules' tables, their tier's views, the KB.
 *  3. Tools: getUserRole(), describeTables(tables[]), runReadOnlyQuery(sql).
 *  4. No tool call -> information answer (or the model's own ACCESS_DENIED refusal).
 *  5. describeTables -> exact columns / joins / notes of ALLOWED tables only.
 *  6. runReadOnlyQuery -> QueryGateway (validate, bind :me/:dept/:group, run read-only) ->
 *     rows back to the model. A SECURITY refusal ends the turn with a server-written message;
 *     a correctable mistake (typo'd table, SELECT *, unknown column...) goes back to the model
 *     as a sanitised hint - never a raw MySQL error.
 *  7. Exactly one ai_chat_audit_log row per turn.
 */
final class ChatService
{
    public const MAX_QUESTION_LENGTH = 1000;

    public function __construct(private readonly ?LlmProvider $provider = null)
    {
    }

    /**
     * @return array{answer:string, path:string, role:string, queries:array, table:?array,
     *               trace:array, latencyMs:int, provider:?string, model:?string}
     */
    public function ask(Identity $identity, string $question): array
    {
        $started = microtime(true);
        $question = trim($question);
        $state = [
            'answer' => '', 'path' => 'info', 'role' => $identity->tier,
            'queries' => [], 'table' => null, 'trace' => [],
            'denial' => null, 'provider' => null, 'model' => null,
        ];

        try {
            if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
                throw new \DomainException('Please ask a question of up to ' . self::MAX_QUESTION_LENGTH . ' characters.');
            }
            $provider = $this->provider ?? ProviderFactory::create();
            $state['provider'] = $provider->name();
            $state['model'] = $provider->model();
            $this->converse($provider, $identity, $question, $state);
        } catch (ProviderError $e) {
            Log::error("AI provider error [{$e->category}]: {$e->getMessage()}");
            $state['path'] = 'error';
            $state['answer'] = $e->userMessage();
            $state['denial'] = "provider:{$e->category}";
        } catch (\DomainException $e) {
            $state['path'] = 'error';
            $state['answer'] = $e->getMessage();
            $state['denial'] = 'invalid_question';
        } catch (\Throwable $e) {
            Log::error('Chat turn failed: ' . $e);
            $state['path'] = 'error';
            $state['answer'] = 'Something went wrong while answering. Please try again.';
            $state['denial'] = 'internal: ' . get_class($e);
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $lastOk = null;
        foreach ($state['queries'] as $q) {
            if ($q['status'] === 'ok') {
                $lastOk = $q;
            }
        }
        $sqls = array_column($state['queries'], 'sql');
        Audit::record([
            'employee_id' => $identity->pbiId,
            'user_id' => $identity->userId,
            'role' => $identity->tier,
            'question' => $question,
            'path' => $state['path'],
            'generated_sql' => $sqls ? implode(";\n\n", $sqls) : null,
            'denial_reason' => $state['denial'],
            'row_count' => $lastOk['rowCount'] ?? null,
            'latency_ms' => $latency,
            'provider' => $state['provider'],
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
                'Returns the signed-in user\'s chatbot tier and their ERP modules. Call this before answering any question about ERP data.',
            ),
            new ToolDefinition(
                'describeTables',
                'Returns the exact columns, keys, likely joins, row count and notes of up to '
                . (int) Config::get('describeMaxTables', 6) . ' tables or views from the DATA ACCESS list. Call it before writing SQL on tables you have not described yet.',
                [
                    'type' => 'object',
                    'properties' => [
                        'tables' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Table or view names.'],
                    ],
                    'required' => ['tables'],
                ],
            ),
            new ToolDefinition(
                'runReadOnlyQuery',
                'Runs ONE read-only MySQL SELECT over the tables/views listed in the system prompt and returns the rows. '
                . 'Use :me for the signed-in employee and :dept for their department where a view REQUIRES it; company '
                . 'scope is added automatically. Name the columns (no SELECT *). Must include LIMIT.',
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

    private function converse(LlmProvider $provider, Identity $identity, string $question, array &$state): void
    {
        $policy = new AccessPolicy($identity);
        $provider->start((new PromptBuilder())->build($policy), $question, self::tools());
        $gateway = new QueryGateway($identity, $policy);
        $maxRounds = (int) Config::get('maxToolRounds', 8);
        $maxRetries = (int) Config::get('maxQueryRetries', 2);
        $failedQueries = 0;
        $usedData = false;

        for ($round = 0; $round < $maxRounds; $round++) {
            $turn = $provider->send();

            if (!$turn->hasToolCalls()) {
                $text = $turn->text;
                if (str_starts_with(ltrim($text), PromptBuilder::DENIAL_MARKER)) {
                    // The model recognised it has no permitted table for this - it never wrote SQL.
                    $subject = trim(substr(ltrim($text), strlen(PromptBuilder::DENIAL_MARKER)), " \t\n\r.\"'");
                    $state['path'] = 'denied';
                    $state['answer'] = "You're not authorised to access " . ($subject !== '' ? $subject : 'that information') . '.';
                    $state['denial'] = 'model: no permitted table for "' . ($subject ?: 'unspecified') . '"';
                    $state['trace'][] = ['step' => 'refusal', 'detail' => 'Model found no permitted table; no SQL generated'];
                    return;
                }
                if ($text === '') {
                    throw new ProviderError(ProviderError::BAD_RESPONSE, 'Model returned an empty answer');
                }
                $state['answer'] = $text;
                $state['path'] = $usedData ? 'data' : 'info';
                return;
            }

            $results = [];
            foreach ($turn->toolCalls as $call) {
                // (a closure BY REFERENCE: an arrow fn would capture $results by value and drop replies)
                $reply = function (array $result) use (&$results, $call): void {
                    $results[] = ['id' => $call['id'], 'name' => $call['name'], 'result' => $result];
                };

                if ($call['name'] === 'getUserRole') {
                    // Not a security control: the backend already knows the user. This makes
                    // the role check visible and traceable.
                    $state['trace'][] = ['step' => 'getUserRole', 'detail' => $identity->tier];
                    $reply(['tier' => $identity->tier, 'tier_label' => $identity->tierLabel(),
                        'erp_modules' => array_values($policy->moduleNames())]);
                    continue;
                }

                if ($call['name'] === 'describeTables') {
                    $names = array_slice(array_map('strval', (array) ($call['args']['tables'] ?? [])),
                        0, (int) Config::get('describeMaxTables', 6));
                    $state['trace'][] = ['step' => 'describeTables', 'detail' => implode(', ', $names)];
                    $reply(['tables' => TableDescriber::describe($policy, $names)]);
                    continue;
                }

                if ($call['name'] !== 'runReadOnlyQuery') {
                    $reply(['error' => 'Unknown tool.']);
                    continue;
                }

                $sql = (string) ($call['args']['sql'] ?? '');
                $r = $gateway->run($sql);
                $state['queries'][] = [
                    'sql' => $sql, 'executedSql' => $r['finalSql'], 'bindings' => $r['bindings'],
                    'notes' => $r['notes'], 'status' => $r['status'], 'code' => $r['code'], 'rowCount' => count($r['rows']),
                ];
                $state['trace'][] = ['step' => 'runReadOnlyQuery',
                    'detail' => $r['status'] . ($r['status'] === 'ok' ? ' (' . count($r['rows']) . ' rows)' : " ({$r['code']})")];

                if ($r['status'] !== 'ok' && $r['retryable'] && $failedQueries < $maxRetries) {
                    // A correctable mistake: explain it to the model (safe text, no raw MySQL error).
                    $failedQueries++;
                    $reply(['error' => $r['code'] === 'bad_query'
                        ? 'The query could not be executed (unknown column, GROUP BY problem or syntax error). '
                          . 'Re-check the exact columns with describeTables and try again.'
                        : 'Query not run: ' . $r['reason'] . '. Fix it and try again.']);
                    continue;
                }
                if ($r['status'] === 'denied') {
                    // Security refusal: final, worded by the server, not the model.
                    $state['path'] = 'denied';
                    $state['answer'] = $r['userMessage'];
                    $state['denial'] = "{$r['code']}: {$r['reason']}";
                    return;
                }
                if ($r['status'] === 'error') {
                    $state['path'] = 'error';
                    $state['answer'] = $r['userMessage'];
                    $state['denial'] = "{$r['code']}: {$r['reason']}";
                    return;
                }

                $usedData = true;
                $state['table'] = $r['rows'] ? ['columns' => array_keys($r['rows'][0]), 'rows' => $r['rows']] : ['columns' => [], 'rows' => []];
                $reply(['row_count' => count($r['rows']), 'rows' => $r['rows']]);
            }
            $provider->addToolResults($results);
        }

        throw new ProviderError(ProviderError::BAD_RESPONSE, "No final answer after $maxRounds tool rounds");
    }
}
