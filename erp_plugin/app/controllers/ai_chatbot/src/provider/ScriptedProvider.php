<?php

declare(strict_types=1);

namespace AiChatbot\provider;

/**
 * TEST-ONLY stand-in for an LLM. A PHP callback plays the model: given the question and
 * the tool results so far, it returns the next LlmTurn. Lets `php yii verify/phase5`
 * exercise the full chat flow - tool loop, validator, binding, audit - with no API key,
 * including a deliberately malicious "model".
 */
final class ScriptedProvider implements LlmProvider
{
    private string $question = '';
    private string $system = '';
    /** @var array<int, array{name:string, result:array}> */
    private array $results = [];
    private int $round = 0;

    /**
     * @param callable(string $question, array $results, int $round, string $system): LlmTurn $script
     */
    public function __construct(private $script)
    {
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function model(): string
    {
        return 'test-script';
    }

    public function start(string $systemPrompt, string $userMessage, array $tools): void
    {
        $this->system = $systemPrompt;
        $this->question = $userMessage;
        $this->results = [];
        $this->round = 0;
    }

    public function send(): LlmTurn
    {
        return ($this->script)($this->question, $this->results, $this->round++, $this->system);
    }

    public function addToolResults(array $results): void
    {
        foreach ($results as $r) {
            $this->results[] = ['name' => $r['name'], 'result' => $r['result']];
        }
    }
}
