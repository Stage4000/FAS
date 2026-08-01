<?php
/**
 * Shared SEO helpers for canonical URLs, metadata, slugs, and schema payloads.
 */

namespace FAS\Utils;

class Seo
{
    private const BASE_URL = 'https://flipandstrip.com';

    public static function baseUrl(): string
    {
        return self::BASE_URL;
    }

    public static function absoluteUrl($path): string
    {
        $value = trim((string) $path);
        if ($value === '') {
            return self::BASE_URL . '/';
        }

        if (strpos($value, 'https://') === 0) {
            return $value;
        }

        if (strpos($value, 'http://') === 0) {
            return 'https://' . substr($value, strlen('http://'));
        }

        if ($value[0] !== '/') {
            $value = '/' . $value;
        }

        return self::BASE_URL . $value;
    }

    public static function canonicalUrl($path = '/'): string
    {
        $value = trim((string) $path);
        if ($value === '') {
            $value = '/';
        }

        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) {
            $parts = parse_url($value);
            $value = ($parts['path'] ?? '/')
                . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        }

        if ($value[0] !== '/') {
            $value = '/' . $value;
        }

        return self::BASE_URL . $value;
    }

    public static function cleanText($value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xEF\xBF\xBD", '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    public static function limitText($value, int $maxLength): string
    {
        $text = self::cleanText($value);
        if (self::length($text) <= $maxLength) {
            return $text;
        }

        $suffix = '...';
        $cutLength = max(0, $maxLength - strlen($suffix));
        $truncated = self::substring($text, 0, $cutLength);
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 40) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B-|,.;:") . $suffix;
    }

    public static function metaTitle($value): string
    {
        return self::limitText($value, 70);
    }

    public static function metaDescription($value): string
    {
        return self::limitText($value, 155);
    }

    public static function productSlug(array $product): string
    {
        $source = self::cleanText($product['name'] ?? '');
        $source = strtolower($source);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
        if ($ascii !== false) {
            $source = $ascii;
        }

        $source = preg_replace('/[^a-z0-9]+/', '-', $source);
        $source = trim((string) $source, '-');
        $source = self::limitSlug($source, 80);

        return $source !== '' ? $source : 'part';
    }

    public static function productUrl(array $product): string
    {
        $id = rawurlencode((string) ($product['id'] ?? ''));
        if ($id === '') {
            return self::canonicalUrl('/products');
        }

        return self::canonicalUrl('/product/' . $id . '/' . self::productSlug($product));
    }

    public static function schemaJson(array $schema): string
    {
        return (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function organizationSchema(array $config = []): array
    {
        $site = isset($config['site']) && is_array($config['site']) ? $config['site'] : [];

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => self::cleanText($site['name'] ?? 'Flip and Strip'),
            'url' => self::BASE_URL,
            'logo' => self::absoluteUrl('/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg'),
            'description' => 'Quality motorcycle, ATV/UTV, boat, and automotive parts from top brands.',
            'sameAs' => [
                'https://www.facebook.com/FLIPANDSTRIPMOTORCYCLES/',
            ],
        ];

        if (!empty($site['email'])) {
            $schema['email'] = self::cleanText($site['email']);
        }

        if (!empty($site['phone'])) {
            $schema['telephone'] = self::cleanText($site['phone']);
        }

        return $schema;
    }

    public static function breadcrumbSchema(array $items): array
    {
        $elements = [];
        $position = 1;

        foreach ($items as $item) {
            $name = self::cleanText($item['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $entry = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
            ];

            if (!empty($item['url'])) {
                $entry['item'] = self::absoluteUrl($item['url']);
            }

            $elements[] = $entry;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    public static function productSchema(array $product, array $images, array $priceInfo, string $canonicalUrl, string $description, $categoryPath = null): array
    {
        $imageUrls = [];
        foreach ($images as $image) {
            $imageUrl = self::absoluteUrl($image);
            if (!in_array($imageUrl, $imageUrls, true)) {
                $imageUrls[] = $imageUrl;
            }
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => self::cleanText($product['name'] ?? ''),
            'image' => $imageUrls,
            'description' => self::metaDescription($description),
            'sku' => self::cleanText($product['sku'] ?? ($product['id'] ?? '')),
            'offers' => [
                '@type' => 'Offer',
                'url' => $canonicalUrl,
                'priceCurrency' => 'USD',
                'price' => number_format((float) ($priceInfo['effective_price'] ?? $product['price'] ?? 0), 2, '.', ''),
                'availability' => ((int) ($product['quantity'] ?? 0) > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => self::itemConditionUrl($product['condition_name'] ?? ''),
                'seller' => [
                    '@type' => 'Organization',
                    'name' => 'Flip and Strip',
                ],
            ],
        ];

        $brand = self::cleanText($product['manufacturer'] ?? '');
        if ($brand !== '') {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $brand,
            ];
        }

        $mpn = self::cleanText($product['model'] ?? '');
        if ($mpn !== '') {
            $schema['mpn'] = $mpn;
        }

        $category = self::cleanText($categoryPath ?? ($product['category'] ?? ''));
        if ($category !== '') {
            $schema['category'] = $category;
        }

        return self::filterEmpty($schema);
    }

    public static function collectionPageSchema(string $name, string $description, string $canonicalUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => self::cleanText($name),
            'description' => self::cleanText($description),
            'url' => $canonicalUrl,
        ];
    }

    public static function itemListSchema(array $products): array
    {
        $elements = [];
        $position = 1;

        foreach ($products as $product) {
            if (empty($product['id']) || empty($product['name'])) {
                continue;
            }

            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'url' => self::productUrl($product),
                'name' => self::cleanText($product['name']),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $elements,
        ];
    }

    private static function itemConditionUrl($condition): string
    {
        $value = strtolower(self::cleanText($condition));

        if (strpos($value, 'new') !== false) {
            return 'https://schema.org/NewCondition';
        }

        if (strpos($value, 'refurb') !== false || strpos($value, 'reman') !== false) {
            return 'https://schema.org/RefurbishedCondition';
        }

        if (strpos($value, 'for parts') !== false || strpos($value, 'damaged') !== false) {
            return 'https://schema.org/DamagedCondition';
        }

        return 'https://schema.org/UsedCondition';
    }

    private static function filterEmpty(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::filterEmpty($item);
            }

            if ($item === '' || $item === null || $item === []) {
                unset($value[$key]);
                continue;
            }

            $value[$key] = $item;
        }

        return $value;
    }

    private static function limitSlug(string $slug, int $maxLength): string
    {
        if (strlen($slug) <= $maxLength) {
            return $slug;
        }

        $truncated = substr($slug, 0, $maxLength);
        $lastDash = strrpos($truncated, '-');
        if ($lastDash !== false && $lastDash > 30) {
            $truncated = substr($truncated, 0, $lastDash);
        }

        return trim($truncated, '-');
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function substring(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
    }
}
