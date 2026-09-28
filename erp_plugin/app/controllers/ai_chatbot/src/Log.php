<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * Technical log (raw MySQL / provider errors) for operators. Never shown to users.
 * Written to data/ai_chatbot.log.php next to the plug-in.
 */
final class Log
{
    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    private static function write(string $level, string $message): void
    {
        $file = AI_CHATBOT_DIR . '/data/ai_chatbot.log.php';
        @file_put_contents(
            $file,
            (is_file($file) ? '' : AI_CHATBOT_FILE_GUARD) . sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, $message),
            FILE_APPEND
        );
    }
}
