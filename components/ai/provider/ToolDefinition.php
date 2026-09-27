<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * A function the model may call. $parameters is a JSON-Schema object, or null for a
 * function that takes no arguments.
 */
final class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?array $parameters = null,
    ) {
    }
}
