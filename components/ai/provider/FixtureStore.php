<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * Where recorded AI responses live:
 *
 *   <dir>/<provider>/<user>__<question-slug>__<hash>/01.json, 02.json, ...
 *
 * One file per API call in a chat turn (a data question is usually 3: getUserRole,
 * runReadOnlyQuery, final answer). Files hold the request BODY and the response - never
 * the headers, so API keys are never written to disk.
 */
final class FixtureStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public static function normaliseQuestion(string $question): string
    {
        $q = mb_strtolower(trim($question));
        $q = str_replace(['’', '‘', '“', '”'], ["'", "'", '"', '"'], $q);
        $q = preg_replace('/\s+/u', ' ', $q);
        return rtrim($q, " ?.!");
    }

    /** Directory for one (provider, user, question) conversation. */
    public function conversationDir(string $provider, string $user, string $question): string
    {
        $norm = self::normaliseQuestion($question);
        $slug = trim(substr(preg_replace('/[^a-z0-9]+/', '-', $norm), 0, 50), '-');
        $userSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(strstr($user, '@', true) ?: $user));
        $hash = substr(sha1($provider . '|' . strtolower($user) . '|' . $norm), 0, 8);
        return rtrim($this->dir, '/\\') . "/$provider/{$userSlug}__{$slug}__$hash";
    }

    public function file(string $conversationDir, int $round): string
    {
        return sprintf('%s/%02d.json', $conversationDir, $round);
    }
}
