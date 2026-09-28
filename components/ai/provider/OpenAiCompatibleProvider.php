<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * ONE client for every OpenAI-compatible chat-completions API - Mistral (primary) and
 * Groq (fallback). Instances differ only by name, base URL, model, key and two small
 * wire options, all from config/ai.php.
 *
 *   POST {baseUrl}/chat/completions        Authorization: Bearer KEY
 *   { model, temperature, messages:[system|user|assistant|tool ...],
 *     tools:[{type:'function', function:{name, description, parameters}}], tool_choice:'auto' }
 *   reply: choices[0].message = {role:'assistant', content, tool_calls:[{id, type:'function',
 *          function:{name, arguments:"<JSON string>"}}]}
 *   tool result: {role:'tool', tool_call_id, content:"<JSON string>", name?}
 *
 * Mistral (docs.mistral.ai/api, verified Sept 2026): same shape; its ToolMessage also
 * carries `name` -> option toolMessageName. Groq's OpenAI schema has no `name` there.
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
    private array $messages = [];
    private array $tools = [];

    /**
     * @param array{toolMessageName?: bool, extraBody?: array} $options
     */
    public function __construct(
        private readonly string $providerName,
        private readonly string $apiKey,
        private readonly string $modelName,
        private readonly string $baseUrl,
        private readonly float $temperature,
        private readonly Transport $http,
        private readonly array $options = [],
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
        $body = array_merge([
            'model' => $this->modelName,
            'temperature' => $this->temperature,
            'messages' => $this->messages,
            'tools' => $this->tools,
            'tool_choice' => 'auto',
        ], $this->options['extraBody'] ?? []);

        $response = $this->http->post(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            ['Authorization: Bearer ' . $this->apiKey],
            $body,
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            HttpJson::fail($this->providerName, $response);
        }

        $msg = $response['json']['choices'][0]['message'] ?? null;
        if (!is_array($msg)) {
            throw new ProviderError(ProviderError::BAD_RESPONSE, "{$this->providerName} returned no message");
        }

        $content = $msg['content'] ?? null;
        if (is_array($content)) {
            // Some models return content as chunks: [{type:'text', text:'...'}, ...]
            $content = implode('', array_map(static fn($c) => is_array($c) ? (string) ($c['text'] ?? '') : (string) $c, $content));
        }
        $content = (string) ($content ?? '');

        // Replay only the fields the API accepts back (drops e.g. Groq's 'reasoning').
        $replay = ['role' => 'assistant', 'content' => $content];
        if (!empty($msg['tool_calls'])) {
            $replay['tool_calls'] = $msg['tool_calls'];
            if ($content === '') {
                $replay['content'] = null; // nullable per the OpenAI and Mistral schemas
            }
        }
        $this->messages[] = $replay;

        $calls = [];
        foreach ($msg['tool_calls'] ?? [] as $call) {
            $raw = $call['function']['arguments'] ?? '{}';
            // Documented as a JSON string; accept an already-decoded object defensively.
            $args = is_array($raw) ? $raw : json_decode((string) $raw, true);
            $calls[] = [
                'id' => $call['id'] ?? null,
                'name' => (string) ($call['function']['name'] ?? ''),
                'args' => is_array($args) ? $args : [],
            ];
        }
        return new LlmTurn(trim($content), $calls);
    }

    public function addToolResults(array $results): void
    {
        foreach ($results as $r) {
            $message = [
                'role' => 'tool',
                'tool_call_id' => $r['id'],
                'content' => json_encode($r['result'], JSON_UNESCAPED_UNICODE),
            ];
            if (!empty($this->options['toolMessageName'])) {
                $message['name'] = $r['name'];
            }
            $this->messages[] = $message;
        }
    }
}
