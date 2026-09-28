<?php

declare(strict_types=1);

namespace AiChatbot\provider;

/**
 * OpenAI-compatible chat completions with tools. Used for Groq (openai/gpt-oss-120b).
 *
 *   POST {baseUrl}/chat/completions      header  Authorization: Bearer KEY
 *   { model, temperature, messages:[{role:'system'|'user'|'assistant'|'tool', ...}],
 *     tools:[{type:'function', function:{name, description, parameters}}], tool_choice:'auto' }
 *   reply: choices[0].message = {role:'assistant', content, tool_calls:[{id, type:'function',
 *          function:{name, arguments:"<json string>"}}]}
 *   tool result: {role:'tool', tool_call_id, content:"<json string>"}
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
    private array $messages = [];
    private array $tools = [];

    public function __construct(
        private readonly string $providerName,
        private readonly string $apiKey,
        private readonly string $modelName,
        private readonly string $baseUrl,
        private readonly float $temperature,
        private readonly HttpJson $http,
    ) {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function start(string $systemPrompt, string $userMessage, array $tools): void
    {
        $this->messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];
        $this->tools = array_map(static fn(ToolDefinition $t): array => [
            'type' => 'function',
            'function' => [
                'name' => $t->name,
                'description' => $t->description,
                'parameters' => $t->parameters ?? ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ], $tools);
    }

    public function send(): LlmTurn
    {
        $response = $this->http->post(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            ['Authorization: Bearer ' . $this->apiKey],
            [
                'model' => $this->modelName,
                'temperature' => $this->temperature,
                'messages' => $this->messages,
                'tools' => $this->tools,
                'tool_choice' => 'auto',
            ],
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            HttpJson::fail($this->providerName, $response);
        }

        $msg = $response['json']['choices'][0]['message'] ?? null;
        if (!is_array($msg)) {
            throw new ProviderError(ProviderError::BAD_RESPONSE, "{$this->providerName} returned no message");
        }

        // Keep only the fields the API accepts back (drops e.g. provider-specific 'reasoning').
        $replay = ['role' => 'assistant', 'content' => $msg['content'] ?? ''];
        if (!empty($msg['tool_calls'])) {
            $replay['tool_calls'] = $msg['tool_calls'];
        }
        $this->messages[] = $replay;

        $calls = [];
        foreach ($msg['tool_calls'] ?? [] as $call) {
            $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $calls[] = [
                'id' => $call['id'] ?? null,
                'name' => (string) ($call['function']['name'] ?? ''),
                'args' => is_array($args) ? $args : [],
            ];
        }
        return new LlmTurn(trim((string) ($msg['content'] ?? '')), $calls);
    }

    public function addToolResults(array $results): void
    {
        foreach ($results as $r) {
            $this->messages[] = [
                'role' => 'tool',
                'tool_call_id' => $r['id'],
                'content' => json_encode($r['result'], JSON_UNESCAPED_UNICODE),
            ];
        }
    }
}
