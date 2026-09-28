<?php

declare(strict_types=1);

namespace AiChatbot\provider;

/**
 * One chat conversation with a tool-calling LLM.
 *
 * Each provider keeps the conversation in its own wire format, because replaying the
 * model's turns verbatim matters (Gemini's thoughtSignature, OpenAI tool_call ids).
 * The chat service only ever sees the normalised LlmTurn.
 */
interface LlmProvider
{
    public function name(): string;

    public function model(): string;

    /**
     * @param ToolDefinition[] $tools
     */
    public function start(string $systemPrompt, string $userMessage, array $tools): void;

    /** Sends the conversation so far and appends the model's reply to it. */
    public function send(): LlmTurn;

    /**
     * Appends tool results for the tool calls in the last turn.
     *
     * @param array<int, array{id: ?string, name: string, result: array}> $results
     */
    public function addToolResults(array $results): void;
}
