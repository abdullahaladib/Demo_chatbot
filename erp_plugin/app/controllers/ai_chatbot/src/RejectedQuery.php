<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * Internal control-flow exception for SqlValidator. Never escapes the validator.
 */
final class RejectedQuery extends \RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        string $reason,
        public readonly string $userMessage,
    ) {
        parent::__construct($reason);
    }
}
