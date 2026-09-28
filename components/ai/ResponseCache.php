<?php

declare(strict_types=1);

namespace app\components\ai;

use app\components\ai\provider\FixtureStore;
use app\components\ai\provider\ProviderFactory;
use app\models\Employee;
use Yii;
use yii\caching\FileCache;

/**
 * Quota saver: caches a finished chat turn (answer, path, generated SQL, rows) so asking
 * the same question again costs no API call - rehearsing the five demo questions is free.
 *
 * KEY = user id + role + normalised question + provider/model + a data version.
 *   - The USER is part of the key on purpose. Keyed on role alone, dev2 asking
 *     "how many leave days do I have left?" would receive dev1's cached answer (both are
 *     'employee'). Answers to :me / :dept questions are per person, so the cache is too.
 *   - The data version (latest employees.created_at + company_info.updated_at) changes
 *     whenever the seed is re-run or the knowledge base is edited, so stale answers
 *     expire automatically.
 *
 * Only successful turns are cached (info / data / denied); errors never are.
 * Bypass: config/ai.php 'cache' => ['enabled' => false]. Clear: `php yii ai-cache/clear`.
 * Stored under runtime/ai-cache/ (separate from the app cache).
 */
final class ResponseCache
{
    private const CACHEABLE_PATHS = ['info', 'data', 'denied'];

    public function __construct(
        private readonly ?FileCache $store,
        private readonly int $ttlSeconds = 604800,
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = ProviderFactory::configOrDefault()['cache'] ?? [];
        if (!($cfg['enabled'] ?? true)) {
            return self::disabled();
        }
        return new self(self::fileCache(Yii::getAlias($cfg['path'] ?? '@runtime/ai-cache')), (int) ($cfg['ttlSeconds'] ?? 604800));
    }

    public static function disabled(): self
    {
        return new self(null);
    }

    public static function fileCache(string $path): FileCache
    {
        return new FileCache(['cachePath' => $path, 'keyPrefix' => 'ai.']);
    }

    public function enabled(): bool
    {
        return $this->store !== null;
    }

    public function get(Employee $employee, string $question, string $provider, string $model): ?array
    {
        if ($this->store === null) {
            return null;
        }
        $hit = $this->store->get($this->key($employee, $question, $provider, $model));
        return is_array($hit) ? $hit : null;
    }

    public function put(Employee $employee, string $question, string $provider, string $model, array $turn): void
    {
        if ($this->store === null || !in_array($turn['path'] ?? '', self::CACHEABLE_PATHS, true)) {
            return;
        }
        $this->store->set($this->key($employee, $question, $provider, $model), $turn, $this->ttlSeconds);
    }

    public function clear(): void
    {
        $this->store?->flush();
    }

    private function key(Employee $employee, string $question, string $provider, string $model): array
    {
        return ['v1', (int) $employee->id, $employee->role, FixtureStore::normaliseQuestion($question), $provider, $model, self::dataVersion()];
    }

    private static function dataVersion(): string
    {
        return (string) Yii::$app->db->createCommand(
            'SELECT CONCAT(COALESCE((SELECT MAX(created_at) FROM {{%employees}}), \'\'), \'|\',
                           COALESCE((SELECT MAX(updated_at) FROM {{%company_info}}), \'\'))'
        )->queryScalar();
    }
}
