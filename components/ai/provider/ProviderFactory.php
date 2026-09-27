<?php

declare(strict_types=1);

namespace app\components\ai\provider;

use Yii;

/**
 * Builds the provider named in config/ai.php. Switching providers is a one-line
 * config change ('provider' => 'gemini' | 'groq'), never a code change.
 */
final class ProviderFactory
{
    public static function config(): array
    {
        $path = Yii::getAlias('@app/config/ai.php');
        if (!is_file($path)) {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, 'config/ai.php is missing (copy config/ai.php.example)');
        }
        $config = require $path;

        // Per-device keys live in the gitignored config/ai-local.php, merged over ai.php.
        $localPath = Yii::getAlias('@app/config/ai-local.php');
        if (is_file($localPath)) {
            $config = array_replace_recursive($config, require $localPath);
        }
        return $config;
    }

    /** The AI config, or an empty array if config/ai.php does not exist yet. */
    public static function configOrDefault(): array
    {
        try {
            return self::config();
        } catch (ProviderError) {
            return [];
        }
    }

    public static function create(?string $provider = null): LlmProvider
    {
        $cfg = self::config();
        $name = $provider ?? $cfg['provider'];
        $p = $cfg['providers'][$name] ?? null;
        if ($p === null) {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unknown AI provider '$name' in config/ai.php");
        }

        $key = getenv(strtoupper($name) . '_API_KEY') ?: ($p['apiKey'] ?? '');
        if ($key === '') {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "No API key for '$name' in config/ai.php");
        }

        $http = new HttpJson((int) ($cfg['timeoutSeconds'] ?? 45), $cfg['caBundle'] ?? null);
        $temperature = (float) ($cfg['temperature'] ?? 0);

        return match ($name) {
            'gemini' => new GeminiProvider($key, $p['model'], $p['baseUrl'], $temperature, $http, $p['thinkingConfig'] ?? null),
            'groq' => new OpenAiCompatibleProvider('groq', $key, $p['model'], $p['baseUrl'], $temperature, $http),
            default => throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unsupported AI provider '$name'"),
        };
    }
}
