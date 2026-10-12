<?php
namespace FAS\Utils;

/** Recover source ancestry by IDs; legacy name-only maps must be unambiguous. */
final class StoreCategoryHierarchy
{
    public static function path(array $categories, $id): array
    {
        $path = [];
        $leaf = $categories[$id] ?? null;
        if ($leaf === null) return $path;
        $level = (int)($leaf['level'] ?? 0);
        if ($level < 1 || $level > 3) return $path;
        while (isset($categories[$id])) {
            $category = $categories[$id];
            if ((int)($category['level'] ?? 0) !== $level) break;
            $path[$level] = ['id'=>$id] + $category;
            if ($level === 1) break;
            if (array_key_exists('parent_id', $category)) {
                $parentId = $category['parent_id'];
            } else {
                $parentName = $level === 2 ? ($category['topLevel'] ?? null) : ($category['parent'] ?? null);
                $matches = [];
                foreach ($categories as $candidateId=>$candidate) {
                    if ((int)($candidate['level'] ?? 0) === $level - 1
                        && ($candidate['name'] ?? null) === $parentName
                        && ($candidate['topLevel'] ?? null) === ($category['topLevel'] ?? null)) $matches[] = $candidateId;
                }
                $parentId = count($matches) === 1 ? $matches[0] : null;
            }
            if ($parentId === null || !isset($categories[$parentId])) break;
            $id = $parentId;
            $level--;
        }
        if (isset($leaf['topLevel_id'], $path[1]) && (string)$leaf['topLevel_id'] !== (string)$path[1]['id']) {
            // A conflicting chain is not evidence for attaching any ancestor.
            return [(int)$leaf['level']=>reset($path)];
        }
        ksort($path);
        return $path;
    }
}
