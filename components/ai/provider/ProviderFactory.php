<?php

declare(strict_types=1);

namespace app\components\ai\provider;

use Yii;

/**
 * Builds the provider named in config/ai.php. Switching providers is a one-line config
 * change ('provider' => 'mistral' | 'gemini' | 'groq'), never a code change.
 *
 * Each provider block names its client `type`:
 *   'openai-compatible' -> OpenAiCompatibleProvider (Mistral, Groq, any other such API)
 *   'gemini'            -> GeminiProvider (native generateContent)
 *
 * `mode` picks the transport: 'live' (HTTPS), 'record' (HTTPS + save fixtures) or
 * 'replay' (recorded fixtures only - no network, no API key needed).
 */
final class ProviderFactory
{
    public const MODES = ['live', 'record', 'replay'];

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

    public static function fixtureStore(?array $cfg = null): FixtureStore
    {
        $cfg ??= self::configOrDefault();
        return new FixtureStore(Yii::getAlias($cfg['fixturesDir'] ?? '@app/tests/fixtures/ai'));
    }

    /**
     * @param array{user?: string, question?: string} $context who is asking what - used only
     *        to locate fixtures in record/replay mode
     * @param string|null $mode override config 'mode' (live|record|replay)
     */
    public static function create(?string $provider = null, array $context = [], ?string $mode = null): LlmProvider
    {
        $cfg = self::config();
        $name = $provider ?? ($cfg['provider'] ?? 'mistral');
        $p = $cfg['providers'][$name] ?? null;
        if ($p === null) {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unknown AI provider '$name' in config/ai.php");
        }
        $mode ??= $cfg['mode'] ?? 'live';
        if (!in_array($mode, self::MODES, true)) {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unknown AI mode '$mode' (use live, record or replay)");
        }

        $key = getenv(strtoupper($name) . '_API_KEY') ?: ($p['apiKey'] ?? '');
        if ($key === '' && $mode !== 'replay') {
            throw new ProviderError(ProviderError::NOT_CONFIGURED, "No API key for '$name' (config/ai-local.php)");
        }

        $transport = self::transport($name, $p, $cfg, $context, $mode);
        $temperature = (float) ($cfg['temperature'] ?? 0);

        return match ($p['type'] ?? $name) {
            'gemini' => new GeminiProvider($key, $p['model'], $p['baseUrl'], $temperature, $transport, $p['thinkingConfig'] ?? null),
            'openai-compatible' => new OpenAiCompatibleProvider($name, $key, $p['model'], $p['baseUrl'], $temperature, $transport, [
                'toolMessageName' => (bool) ($p['toolMessageName'] ?? false),
                'extraBody' => $p['extraBody'] ?? [],
            ]),
            default => throw new ProviderError(ProviderError::NOT_CONFIGURED, "Unsupported provider type for '$name'"),
        };
    }

    private static function transport(string $name, array $p, array $cfg, array $context, string $mode): Transport
    {
        $conversationDir = self::fixtureStore($cfg)->conversationDir($name, $context['user'] ?? 'anonymous', $context['question'] ?? '');
        if ($mode === 'replay') {
            return new ReplayTransport($conversationDir);
        }

        $retry = $cfg['retry'] ?? [];
        $live = new HttpJson(
            (int) ($cfg['timeoutSeconds'] ?? 45),
            $cfg['caBundle'] ?? null,
            new Throttle(Yii::getAlias("@runtime/ai-throttle-$name.txt"), (int) ($p['minIntervalMs'] ?? 0)),
            (int) ($retry['maxAttempts'] ?? 3),
            (float) ($retry['backoffBaseSeconds'] ?? 1.5),
        );
        if ($mode === 'record') {
            return new RecordingTransport($live, $conversationDir, [
                'provider' => $name, 'model' => $p['model'] ?? '', 'user' => $context['user'] ?? '',
                'question' => $context['question'] ?? '',
            ]);
        }
        return $live;
    }
}
