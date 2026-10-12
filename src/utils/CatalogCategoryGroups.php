<?php
namespace FAS\Utils;

/** Derived browse groups only: original eBay category IDs and names stay intact. */
final class CatalogCategoryGroups
{
    public static function groupSiblings(array $categories): array
    {
        $groups = [];
        foreach ($categories as $category) {
            $key = strtolower(trim((string)($category['name'] ?? '')));
            // Unnamed categories are not evidence of equivalence.
            if ($key === '') $key = 'id:' . $category['id'];
            if (!isset($groups[$key])) {
                $groups[$key] = $category;
                $groups[$key]['ids'] = [];
                $groups[$key]['children'] = [];
            }
            $groups[$key]['ids'][] = $category['id'];
            $groups[$key]['children'] = array_merge($groups[$key]['children'], array_values($category['children'] ?? []));
        }
        foreach ($groups as &$group) {
            $group['ids'] = array_values(array_unique($group['ids']));
            $group['children'] = self::groupSiblings($group['children']);
        }
        unset($group);
        return array_values($groups);
    }

    /** Legacy alias IDs query their sibling group; inconsistent paths stay exact. */
    public static function selection(array $groups, array $requested): array
    {
        $result = ['scope'=>$requested, 'selected'=>$requested];
        $index = [];
        self::indexPaths($groups, [], $index);
        for ($level = 2; $level >= 0; $level--) {
            if ($requested[$level] === null || $requested[$level] === '') continue;
            $path = $index[(string)$requested[$level]] ?? [];
            if (count($path) !== $level + 1) return $result;
            foreach ($path as $i => $group) {
                if ($requested[$i] !== null && $requested[$i] !== ''
                    && !in_array((string)$requested[$i], array_map('strval', $group['ids']), true)) return $result;
            }
            foreach ($path as $i => $group) {
                $result['scope'][$i] = $group['ids'];
                $result['selected'][$i] = $group['id'];
            }
            return $result;
        }
        return $result;
    }

    private static function indexPaths(array $groups, array $parents, array &$index): void
    {
        foreach ($groups as $group) {
            $path = array_merge($parents, [$group]);
            foreach ($group['ids'] as $id) $index[(string)$id] = $path;
            self::indexPaths($group['children'], $path, $index);
        }
    }
}
