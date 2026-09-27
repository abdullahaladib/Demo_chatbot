<?php

declare(strict_types=1);

namespace app\components\ai;

use Yii;

/**
 * Read-only accessor over config/access-map.php.
 */
final class AccessMap
{
    private static ?array $config = null;

    public static function config(): array
    {
        return self::$config ??= require Yii::getAlias('@app/config/access-map.php');
    }

    public static function maxRows(): int
    {
        return (int) self::config()['maxRows'];
    }

    /** @return string[] view names this role may query */
    public static function viewsFor(string $role): array
    {
        return self::config()['roles'][$role] ?? [];
    }

    public static function roleGuidance(string $role): string
    {
        return self::config()['roleGuidance'][$role] ?? '';
    }

    public static function view(string $name): ?array
    {
        return self::config()['views'][$name] ?? null;
    }

    public static function isKnownRole(string $role): bool
    {
        return isset(self::config()['roles'][$role]);
    }

    /** Plain-English name of what an identifier protects, for refusal messages. */
    public static function subjectOf(string $identifier): string
    {
        $c = self::config();
        return $c['views'][$identifier]['subject']
            ?? $c['baseTableSubjects'][$identifier]
            ?? 'that data';
    }
}
