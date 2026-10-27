<?php
namespace FAS\Utils;

/** Preserve imported values when the admin edits unrelated product fields. */
final class ProductEditor
{
    public static function catalogConditions(\PDO $db): array
    {
        try {
            $result = $db->query('SELECT DISTINCT condition_name FROM products WHERE condition_name IS NOT NULL ORDER BY condition_name');
            if ($result === false) throw new \RuntimeException('Condition query failed.');
            return $result->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            // No fallback taxonomy may be used to process a write after a read failure.
            throw new \RuntimeException('Cannot load catalog conditions; no product changes saved.', 0, $e);
        }
    }

    public static function conditionOptions(?array $product, array $catalogConditions = []): array
    {
        // Labels already supported by the importer, plus the observed display label.
        $options = ['New', 'New other', 'New other (see details)', 'New with defects',
            'Manufacturer refurbished', 'Seller refurbished', 'Used', 'Very Good',
            'Good', 'Acceptable', 'For parts or not working', 'Refurbished'];
        foreach ($catalogConditions as $condition) {
            if (is_string($condition) && !in_array($condition, $options, true)) $options[] = $condition;
        }
        if ($product !== null) {
            $current = (string) ($product['condition_name'] ?? '');
            if (!in_array($current, $options, true)) $options[] = $current;
        }
        return $options;
    }

    public static function renderConditionOptions(?array $product, array $catalogConditions = []): string
    {
        $current = $product === null ? 'New' : (string) ($product['condition_name'] ?? '');
        $html = '';
        foreach (self::conditionOptions($product, $catalogConditions) as $condition) {
            $escaped = htmlspecialchars($condition, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<option value="' . $escaped . '"'
                . ($condition === $current ? ' selected' : '') . '>' . $escaped . '</option>';
        }
        return $html;
    }

    public static function fields(array $post, ?array $current, array $uploads, array $catalogConditions = []): array
    {
        $condition = $post['condition_name'] ?? ($current === null ? 'New' : (string) ($current['condition_name'] ?? ''));
        if (!is_string($condition) || !in_array($condition, self::conditionOptions($current, $catalogConditions), true)) {
            throw new \InvalidArgumentException('Choose a valid product condition.');
        }
        // Empty legacy NULL must survive the select's empty-string roundtrip.
        if ($current !== null && array_key_exists('condition_name', $current)
            && $current['condition_name'] === null && $condition === '') $condition = null;
        $fields = ['condition_name' => $condition];
        // Omit images entirely for ordinary edits. This preserves the stored JSON
        // and cannot resurrect an image removed by the dedicated removal endpoint.
        if ($current === null) {
            $fields['images'] = $uploads;
        } elseif ($uploads !== []) {
            $images = $current['images'] ?? null;
            $existing = is_string($images) ? json_decode($images, true) : $images;
            if ($images === null || $images === '') $existing = [];
            if (!is_array($existing) || array_values($existing) !== $existing
                || count(array_filter($existing, 'is_string')) !== count($existing)) {
                throw new \InvalidArgumentException('Existing image list is invalid; no changes saved.');
            }
            $fields['images'] = array_merge($existing, $uploads);
        }
        return $fields;
    }
}
