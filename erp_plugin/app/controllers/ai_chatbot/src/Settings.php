<?php

declare(strict_types=1);

namespace AiChatbot;

use AiChatbot\provider\HttpJson;

/**
 * The AI key and model chosen in the chat's settings panel (the gear icon, chatbot admins only).
 *
 * Stored on the server in the runtime folder (settings.php, first line a 404 guard), so a new key
 * or model takes effect on the next question without anyone editing code. Merged by Config over
 * config.php and config.local.php. The full key is never sent back to the browser, and never
 * written to the change history.
 */
final class Settings
{
    /** Model ids we accept (Gemini style: gemini-3.7-flash, gemini-3.8-flash-lite...). */
    private const MODEL_ID = '/^[a-z0-9][a-z0-9.\-]{2,60}$/';

    /** Gemini models that cannot chat with tools, so they are left out of the dropdown. */
    private const NOT_CHAT = '/(embedding|embed|tts|image|imagen|veo|audio|live|aqa|robotics|computer-use|native)/i';

    private const CACHE_SECONDS = 3600;

    public static function file(): string
    {
        return AI_CHATBOT_RUNTIME_DIR . '/settings.php';
    }

    /** What the panel saved: ['provider' => 'gemini', 'apiKey' => ..., 'model' => ..., 'updatedBy' => ..., 'updatedAt' => ...] */
    public static function load(): array
    {
        return self::readGuarded(self::file()) ?? [];
    }

    /** The saved values in Config's shape, for merging over config.php / config.local.php. */
    public static function overrides(): array
    {
        $s = self::load();
        $provider = (string) ($s['provider'] ?? '');
        if ($provider === '') {
            return [];
        }
        $p = array_filter(['apiKey' => (string) ($s['apiKey'] ?? ''), 'model' => (string) ($s['model'] ?? '')], fn($v) => $v !== '');
        return ['provider' => $provider, 'providers' => [$provider => $p]];
    }

    public static function isAdmin(Identity $identity): bool
    {
        $admins = array_map('strtolower', array_map('strval', (array) Config::get('settingsAdmins', [])));
        return in_array(strtolower($identity->username), $admins, true);
    }

    /** What the panel shows. Never contains the key itself. */
    public static function state(): array
    {
        $provider = (string) Config::get('provider', 'gemini');
        $p = Config::get('providers')[$provider] ?? [];
        $saved = self::load();
        $key = (string) ($p['apiKey'] ?? '');
        return [
            'provider' => $provider,
            'providerLabel' => $provider === 'gemini' ? 'Google Gemini' : ucfirst($provider),
            'model' => (string) ($p['model'] ?? ''),
            'keyMask' => self::mask($key),
            'keySource' => $key === '' ? 'none' : (($saved['apiKey'] ?? '') !== '' ? 'panel' : 'server file'),
            'fallbackModels' => array_values((array) ($p['fallbackModels'] ?? [])),
            'updatedBy' => $saved['updatedBy'] ?? null,
            'updatedAt' => $saved['updatedAt'] ?? null,
        ];
    }

    /**
     * Models this key can use for chat, straight from Google (cached for an hour per key).
     * @return array{ok:bool, models:list<array{id:string,label:string}>, error:?string}
     */
    public static function listModels(?string $apiKey = null): array
    {
        $provider = (string) Config::get('provider', 'gemini');
        $p = Config::get('providers')[$provider] ?? [];
        if (($p['type'] ?? $provider) !== 'gemini') {
            return ['ok' => false, 'models' => [], 'error' => 'Live model lists are available for Gemini only.'];
        }
        $key = $apiKey ?? (string) ($p['apiKey'] ?? '');
        if ($key === '') {
            return ['ok' => false, 'models' => [], 'error' => 'No API key yet. Paste one first.'];
        }

        $cacheFile = AI_CHATBOT_RUNTIME_DIR . '/models.' . substr(hash('sha256', $key), 0, 16) . '.php';
        $cached = self::readGuarded($cacheFile);
        if ($cached !== null && ($cached['at'] ?? 0) > time() - self::CACHE_SECONDS) {
            return ['ok' => true, 'models' => $cached['models'], 'error' => null];
        }

        try {
            $http = new HttpJson(10, Config::get('caBundle'));
            $r = $http->get(rtrim((string) $p['baseUrl'], '/') . '/models?pageSize=1000', ['x-goog-api-key: ' . $key]);
        } catch (\Throwable $e) {
            Log::warning('settings: model list failed: ' . $e->getMessage());
            return ['ok' => false, 'models' => [], 'error' => 'Could not reach Google to check the key. Try again in a moment.'];
        }
        if ($r['status'] === 400 || $r['status'] === 401 || $r['status'] === 403) {
            return ['ok' => false, 'models' => [], 'error' => 'Google rejected this API key. Check that it was copied completely.'];
        }
        if ($r['status'] !== 200 || !isset($r['json']['models'])) {
            Log::warning("settings: model list HTTP {$r['status']}");
            return ['ok' => false, 'models' => [], 'error' => "Google did not return a model list (HTTP {$r['status']}). Try again in a moment."];
        }

        $models = [];
        foreach ($r['json']['models'] as $m) {
            $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if (!str_starts_with($id, 'gemini') || preg_match(self::NOT_CHAT, $id)
                || !in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
                continue;
            }
            $models[$id] = ['id' => $id, 'label' => (string) ($m['displayName'] ?? $id)];
        }
        uksort($models, fn($a, $b) => strnatcmp($b, $a)); // newest version first
        $models = array_values($models);
        self::writeGuarded($cacheFile, ['at' => time(), 'models' => $models]);
        return ['ok' => true, 'models' => $models, 'error' => null];
    }

    /**
     * Save a model and, optionally, a new key (blank = keep the current key).
     * @return array{ok:bool, error:?string, state:?array}
     */
    public static function save(Identity $by, string $newKey, string $model): array
    {
        $newKey = trim($newKey);
        $model = trim($model);
        if (!preg_match(self::MODEL_ID, $model)) {
            return ['ok' => false, 'error' => 'Choose a model from the list.', 'state' => null];
        }
        if ($newKey !== '' && (strlen($newKey) < 20 || strlen($newKey) > 200 || preg_match('/\s/', $newKey))) {
            return ['ok' => false, 'error' => 'That does not look like an API key.', 'state' => null];
        }

        $provider = (string) Config::get('provider', 'gemini');
        if ($newKey !== '') {
            // a new key must work before it replaces the old one
            $list = self::listModels($newKey);
            if (!$list['ok']) {
                return ['ok' => false, 'error' => $list['error'], 'state' => null];
            }
            if (!in_array($model, array_column($list['models'], 'id'), true)) {
                return ['ok' => false, 'error' => "This key cannot use $model. Pick a model from the list.", 'state' => null];
            }
        }

        $old = self::load();
        $record = [
            'provider' => $provider,
            'apiKey' => $newKey !== '' ? $newKey : (string) ($old['apiKey'] ?? ''),
            'model' => $model,
            'updatedBy' => $by->username,
            'updatedAt' => date('Y-m-d H:i'),
        ];
        self::writeGuarded(self::file(), $record);
        self::appendHistory([
            'at' => date('c'), 'by' => $by->username, 'provider' => $provider, 'model' => $model,
            'keyChanged' => $newKey !== '', 'key' => self::mask($record['apiKey'] !== '' ? $record['apiKey'] : null),
        ]);
        Config::reset();
        return ['ok' => true, 'error' => null, 'state' => self::state()];
    }

    public static function mask(?string $key): string
    {
        return $key === null || $key === '' ? '' : str_repeat('•', 8) . substr($key, -4);
    }

    private static function appendHistory(array $entry): void
    {
        $file = AI_CHATBOT_RUNTIME_DIR . '/settings_history.log.php';
        @file_put_contents($file, (is_file($file) ? '' : AI_CHATBOT_FILE_GUARD) . json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function readGuarded(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = (string) file_get_contents($file);
        if (str_starts_with($raw, AI_CHATBOT_FILE_GUARD)) {
            $raw = substr($raw, strlen(AI_CHATBOT_FILE_GUARD));
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Write atomically (temp file + rename), so a reader never sees half a file. */
    private static function writeGuarded(string $file, array $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp.php'; // .php: never served as text
        if (file_put_contents($tmp, AI_CHATBOT_FILE_GUARD . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('AI chatbot: cannot write ' . basename($file) . ' (is the data folder writable?)');
        }
        if (!@rename($tmp, $file)) {
            @unlink($file); // Windows cannot rename over an existing file
            if (!rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('AI chatbot: cannot replace ' . basename($file));
            }
        }
    }
}
