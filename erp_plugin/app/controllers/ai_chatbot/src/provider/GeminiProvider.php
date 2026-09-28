<?php

declare(strict_types=1);

namespace AiChatbot\provider;

/**
 * Google Gemini via the REST `models.generateContent` endpoint.
 *
 * Wire format (checked against ai.google.dev, Sept 2026):
 *   POST {baseUrl}/models/{model}:generateContent      header  x-goog-api-key: KEY
 *   { systemInstruction: {parts:[{text}]},
 *     contents: [{role:'user'|'model', parts:[...]}],
 *     tools: [{functionDeclarations: [{name, description, parameters}]}],
 *     toolConfig: {functionCallingConfig: {mode: 'AUTO'}},
 *     generationConfig: {temperature, thinkingConfig?} }
 *   reply: candidates[0].content = {role:'model', parts:[{text} | {functionCall:{name,args,id?}}, ...]}
 *   tool result turn: {role:'user', parts:[{functionResponse:{name, id?, response:{...}}}]}
 *
 * The model's content is appended to the history VERBATIM, so any thoughtSignature
 * parts are sent back exactly as received (mandatory for Gemini 3 function calling,
 * harmless for 2.5).
 */
final class GeminiProvider implements LlmProvider
{
    private string $system = '';
    private array $contents = [];
    private array $tools = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelName,
        private readonly string $baseUrl,
        private readonly float $temperature,
        private readonly HttpJson $http,
        private readonly ?array $thinkingConfig = null,
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function start(string $systemPrompt, string $userMessage, array $tools): void
    {
        $this->system = $systemPrompt;
        $this->contents = [['role' => 'user', 'parts' => [['text' => $userMessage]]]];
        $this->tools = [[
            'functionDeclarations' => array_map(static function (ToolDefinition $t): array {
                $decl = ['name' => $t->name, 'description' => $t->description];
                if ($t->parameters !== null) {
                    $decl['parameters'] = $t->parameters;
                }
                return $decl;
            }, $tools),
        ]];
    }

    public function send(): LlmTurn
    {
        $generationConfig = ['temperature' => $this->temperature];
        if ($this->thinkingConfig) {
            $generationConfig['thinkingConfig'] = $this->thinkingConfig;
        }

        $response = $this->http->post(
            rtrim($this->baseUrl, '/') . '/models/' . rawurlencode($this->modelName) . ':generateContent',
            ['x-goog-api-key: ' . $this->apiKey],
            [
                'systemInstruction' => ['parts' => [['text' => $this->system]]],
                'contents' => $this->contents,
                'tools' => $this->tools,
                'toolConfig' => ['functionCallingConfig' => ['mode' => 'AUTO']],
                'generationConfig' => $generationConfig,
            ],
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            HttpJson::fail('gemini', $response);
        }

        $candidate = $response['json']['candidates'][0] ?? null;
        $content = $candidate['content'] ?? null;
        if (!is_array($content) || empty($content['parts'])) {
            $why = $candidate['finishReason'] ?? ($response['json']['promptFeedback']['blockReason'] ?? 'no candidates');
            throw new ProviderError(ProviderError::BAD_RESPONSE, "gemini returned no content ($why)");
        }

        // Replay verbatim on the next request (keeps thoughtSignature intact).
        // PHP decodes `"args": {}` to [] and would re-encode it as a JSON list, which
        // Gemini rejects - restore empty args as an object.
        foreach ($content['parts'] as &$part) {
            if (isset($part['functionCall']) && empty($part['functionCall']['args'])) {
                $part['functionCall']['args'] = new \stdClass();
            }
        }
        unset($part);
        $content['role'] = 'model';
        $this->contents[] = $content;

        $text = '';
        $calls = [];
        foreach ($content['parts'] as $part) {
            if (isset($part['functionCall'])) {
                $calls[] = [
                    'id' => $part['functionCall']['id'] ?? null,
                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
            } elseif (isset($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }
        return new LlmTurn(trim($text), $calls);
    }

    public function addToolResults(array $results): void
    {
        $parts = [];
        foreach ($results as $r) {
            $fr = ['name' => $r['name'], 'response' => $r['result']];
            if (!empty($r['id'])) {
                $fr['id'] = $r['id'];
            }
            $parts[] = ['functionResponse' => $fr];
        }
        $this->contents[] = ['role' => 'user', 'parts' => $parts];
    }
}
