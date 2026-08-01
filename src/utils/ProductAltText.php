<?php
/**
 * Generates concise, product-specific image alt text from existing product data.
 */

namespace FAS\Utils;

class ProductAltText
{
    private const MAX_LENGTH = 125;

    /**
     * Build SEO-friendly alt text for a product image without keyword stuffing.
     *
     * @param array<string, mixed> $product
     */
    public static function forProductImage(array $product, int $imageIndex = 0): string
    {
        $name = self::clean($product['name'] ?? '');
        $condition = self::clean($product['condition_name'] ?? '');
        $manufacturer = self::clean($product['manufacturer'] ?? '');
        $model = self::clean($product['model'] ?? '');
        $category = self::categoryLabel($product);

        $parts = [];
        foreach ([$condition, $manufacturer, $model] as $part) {
            if (
                $part !== ''
                && !self::containsText($name, $part)
                && !self::containsText(implode(' ', $parts), $part)
            ) {
                $parts[] = $part;
            }
        }

        if ($name !== '') {
            $parts[] = $name;
        }

        $altText = trim(implode(' ', $parts));

        if ($category !== '' && !self::containsText($altText, $category)) {
            $altText = $altText === '' ? $category : $altText . ' for ' . $category;
        }

        if ($imageIndex > 0) {
            $altText .= ' alternate view ' . ($imageIndex + 1);
        }

        if ($altText === '') {
            $altText = 'Product image';
        }

        return self::limit($altText, self::MAX_LENGTH);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function categoryLabel(array $product): string
    {
        foreach (['ebay_store_cat3_name', 'ebay_store_cat2_name', 'ebay_store_cat1_name'] as $field) {
            $value = self::clean($product[$field] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        $category = strtolower(self::clean($product['category'] ?? ''));

        switch ($category) {
            case 'motorcycle':
                return 'motorcycle parts';
            case 'atv':
                return 'ATV parts';
            case 'boat':
                return 'marine parts';
            case 'automotive':
                return 'automotive parts';
            case 'gifts':
                return 'powersports gifts';
            case 'other':
                return '';
            default:
                return self::clean($product['category'] ?? '');
        }
    }

    /**
     * @param mixed $value
     */
    private static function clean($value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);

        return trim($text, " \t\n\r\0\x0B-|,.;:");
    }

    private static function containsText(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return stripos($haystack, $needle) !== false;
    }

    private static function limit(string $text, int $maxLength): string
    {
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        if (!function_exists('mb_strlen') && strlen($text) <= $maxLength) {
            return $text;
        }

        if (function_exists('mb_substr')) {
            $truncated = mb_substr($text, 0, $maxLength, 'UTF-8');
        } else {
            $truncated = substr($text, 0, $maxLength);
        }

        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 50) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B-|,.;:");
    }
}
