<?php

declare(strict_types=1);

namespace AiChatbot\provider;

use AiChatbot\Config;

/**
 * Builds the provider named in the plug-in config ('provider' => 'gemini' | 'groq').
 * Switching provider is a one-line config change, never a code change.
 */
final class ProviderFactory
{
    public static function create(?string $provider = null): LlmProvider
    {
        $name = $provider ?? (string) Config::get('provider', 'gemini');
        $p = Config::get('providers')[$name] ?? null;
        if ($p === null) {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unknown AI provider '$name'");
        }
        // The key saved in the chat's settings panel (or config.local.php) wins; an environment
        // variable is only a fallback, so a panel change always takes effect.
        $key = (string) ($p['apiKey'] ?? '') !== '' ? (string) $p['apiKey'] : (string) getenv(strtoupper($name) . '_API_KEY');
        if ($key === '') {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "No API key for '$name' (add one in the chat's settings)");
        }

        $http = new HttpJson((int) Config::get('timeoutSeconds', 45), Config::get('caBundle'));
        $temperature = (float) Config::get('temperature', 0);

        return match ($p['type'] ?? $name) {
            'gemini' => new GeminiProvider($key, $p['model'], $p['baseUrl'], $temperature, $http, $p['thinkingConfig'] ?? null),
            'openai-compatible' => new OpenAiCompatibleProvider($name, $key, $p['model'], $p['baseUrl'], $temperature, $http),
            default => throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unsupported provider type for '$name'"),
        };
    }
}
