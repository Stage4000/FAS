<?php
/**
 * Shared SEO helpers for canonical URLs, metadata, slugs, and schema payloads.
 */

namespace FAS\Utils;

require_once __DIR__ . '/ShippingRules.php';

class Seo
{
    private const BASE_URL = 'https://flipandstrip.com';
    private const RETURN_POLICY_DAYS = 30;

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

    public static function limitText($value, int $maxLength, string $suffix = '...'): string
    {
        $text = self::cleanText($value);
        if (self::length($text) <= $maxLength) {
            return $text;
        }

        $cutLength = max(0, $maxLength - self::length($suffix));
        $truncated = self::substring($text, 0, $cutLength);
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 40) {
            $truncated = self::substring($truncated, 0, $lastSpace);
        }

        $trimmed = rtrim($truncated, " \t\n\r\0\x0B-|,.;:");
        return $suffix === '' ? $trimmed : $trimmed . $suffix;
    }

    public static function metaTitle($value): string
    {
        return self::limitText($value, 110, '');
    }

    public static function metaDescription($value): string
    {
        return self::limitText($value, 160, '');
    }

    public static function cleanProductSeoDescription($value): string
    {
        $text = self::cleanText($value);
        if ($text === '') {
            return '';
        }

        $boilerplatePatterns = [
            '/\bPlease visit our eBay Store for more parts!?+\b.*$/i',
            '/\bFor other .*? parts click here\b.*$/i',
            '/\bVideo will open in new window\b.*$/i',
            '/\bUsing mobile app\? Copy this link into your browser\b.*$/i',
        ];

        $cleaned = preg_replace($boilerplatePatterns, '', $text);
        $cleaned = preg_replace('/\s+/u', ' ', (string) $cleaned);

        return trim((string) $cleaned, " \t\n\r\0\x0B-|,.;:");
    }

    public static function productMetaDescription(string $productName, string $description, float $price, array $metaDetails = []): string
    {
        $parts = [];

        $name = self::cleanText($productName);
        if ($name !== '') {
            $parts[] = $name . '.';
        }

        $detailParts = [];
        foreach ($metaDetails as $detail) {
            $cleanDetail = self::cleanText($detail);
            if ($cleanDetail === '' || in_array($cleanDetail, $detailParts, true)) {
                continue;
            }

            $detailParts[] = $cleanDetail;
            if (count($detailParts) >= 2) {
                break;
            }
        }

        if ($detailParts !== []) {
            $parts[] = implode(' ', $detailParts) . '.';
        }

        $parts[] = 'Price: $' . number_format($price, 2) . '.';

        $cleanDescription = self::cleanProductSeoDescription($description);
        if ($cleanDescription !== '') {
            $parts[] = $cleanDescription;
        }

        return self::metaDescription(implode(' ', $parts));
    }

    public static function productSlug(array $product): string
    {
    $source = self::slug(self::cleanText($product['name'] ?? ''), 80);
    return $source !== '' ? $source : 'part';
    }

    public static function slug($value, int $maxLength = 80): string
    {
    $source = self::cleanText($value);
    $source = strtolower($source);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
    if ($ascii !== false) {
            $source = $ascii;
        }

    $source = preg_replace('/[^a-z0-9]+/', '-', $source);
    $source = trim((string) $source, '-');
    $source = self::limitSlug($source, $maxLength);

    return $source;
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
            'hasMerchantReturnPolicy' => self::merchantReturnPolicySchema(),
        ],
    ];

    if (ShippingRules::productQualifiesForFreeShipping($product)) {
    $schema['offers']['shippingDetails'] = [
    '@type' => 'OfferShippingDetails',
    'shippingDestination' => [
    '@type' => 'DefinedRegion',
    'addressCountry' => 'US',
    'addressRegion' => self::continentalUsRegions(),
    ],
    'shippingRate' => [
    '@type' => 'MonetaryAmount',
    'value' => '0.00',
    'currency' => 'USD',
    ],
    ];
    }

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

    private static function continentalUsRegions(): array
    {
        return [
            'AL', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA',
    'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA',
    'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM',
    'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD',
    'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
        ];
    }

    private static function merchantReturnPolicySchema(): array
    {
        return [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'US',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => self::RETURN_POLICY_DAYS,
            'returnMethod' => 'https://schema.org/ReturnByMail',
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
