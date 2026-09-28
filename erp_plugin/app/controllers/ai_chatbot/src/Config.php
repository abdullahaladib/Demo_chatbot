<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * Plug-in settings: config.php merged with the machine's config.local.php (secrets).
 */
final class Config
{
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null) {
            $values = require AI_CHATBOT_DIR . '/config.php';
            $local = AI_CHATBOT_DIR . '/config.local.php';
            if (is_file($local)) {
                $values = array_replace_recursive($values, require $local);
            }
            self::$values = $values;
        }
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** For tests: override settings in memory. */
    public static function override(array $values): void
    {
        self::$values = array_replace_recursive(self::all(), $values);
    }
}
