<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * The model's reply, normalised across providers.
 */
final class LlmTurn
{
    /**
     * @param array<int, array{id: ?string, name: string, args: array}> $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
