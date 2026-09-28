<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * The describeTables tool: exact, PERMITTED columns of the tables/views a user may query.
 * Forbidden or unknown names get "not available" - their columns are never revealed.
 */
final class TableDescriber
{
    /**
     * @param string[] $names
     * @return array<int, array<string,mixed>>
     */
    public static function describe(AccessPolicy $policy, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $lower = strtolower(trim($name));
            if ($lower === '' || !$policy->allows($lower)) {
                $out[] = ['table' => $name, 'available' => false,
                    'message' => 'Not available to this user (not in their DATA ACCESS list).'];
                continue;
            }

            if (isset(Catalog::VIEWS[$lower])) {
                $v = Catalog::VIEWS[$lower];
                $out[] = array_filter([
                    'table' => $lower,
                    'available' => true,
                    'kind' => 'view',
                    'description' => $v['description'],
                    'requires' => $v['scope'] ? "WHERE {$v['column']} = :{$v['scope']}" : null,
                    'columns' => self::viewColumns($lower),
                ], fn($x) => $x !== null);
                continue;
            }

            $t = Catalog::table($lower);
            $relations = array_values(array_filter($t['relations'], function (string $rel) use ($policy) {
                // only point at tables the user may actually join to
                return preg_match('/->\s*([A-Za-z0-9_]+)\./', $rel, $m) && $policy->allows($m[1]);
            }));
            $out[] = array_filter([
                'table' => $t['name'],
                'available' => true,
                'rows' => $t['rows'],
                'note' => Catalog::note($t['name']),
                'primary_key' => $t['pk'],
                'company_scoped' => $t['groupFor'] ? 'yes (applied automatically - do not filter group_for)' : null,
                'columns' => array_map(fn($c) => $c['name'] . ' ' . $c['type'], $t['columns']),
                'joins' => $relations ?: null,
            ], fn($x) => $x !== null);
        }
        return $out;
    }

    /** @return string[] "name type" of a view's columns */
    private static function viewColumns(string $view): array
    {
        $s = Db::app()->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$view]);
        return array_map(fn($r) => $r['COLUMN_NAME'] . ' ' . $r['COLUMN_TYPE'], $s->fetchAll());
    }
}
