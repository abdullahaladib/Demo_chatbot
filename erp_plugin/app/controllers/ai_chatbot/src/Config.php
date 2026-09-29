<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * Plug-in settings: config.php, then config.local.php (this server's secrets), then - on the
 * developer machine only - erp_local_dev/ai_chatbot.config.php, which lives outside the ERP folder.
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
            $dev = AI_CHATBOT_DEV_DIR . '/ai_chatbot.config.php';
            if (@is_file($dev)) {
                $values = array_replace_recursive($values, require $dev);
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
