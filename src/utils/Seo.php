<?php
/**
 * Shared SEO helpers for canonical URLs, metadata, slugs, and schema payloads.
 */

namespace FAS\Utils;

require_once __DIR__ . '/ShippingRules.php';
require_once __DIR__ . '/ProductCondition.php';
require_once __DIR__ . '/ProductIdentifierOutput.php';

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

    /**
     * Remove only the two reviewed marketplace calculator/customer-label paragraphs.
     * This source-only presentation cleanup is not an item review or a policy decision.
     * Keep unknown, partial and embedded text; never change published overrides or inventory.
     */
    public static function cleanSourceShippingInstructions($value): string
    {
        $source = (string) $value;
        $paragraphs = [
            'Shipping Costs: We do not set shipping prices. Shipping fees are automatically calculated by the eBay shipping calculator based on package weight, size, and your destination. If you believe you can ship the item cheaper or faster, we are happy to use your own shipping label. In that case, there will be no additional shipping or handling charges from us.',
            'Shipping Costs:We do not set shipping prices. Shipping fees are automatically calculated by the eBay shipping calculator based on package weight, size, and your destination.If you believe you can ship the item cheaper or faster, we are happy to use your own shipping label. In that case, there will be no additional shipping or handling charges from us.',
            'We do NOT set our shipping prices. We simply enter the weight and size , then the Ebay shipping calculator determines the shipping fee due to your destination. If at any time you feel you can get the item shipped cheaper or better we would be completely willing to use your shipping label and there will be no other shipping or handling charges.',
            'We do NOT set our shipping prices. We simply enter the weight and size , then the Ebay shipping calculator determines the shipping fee due to your destination. If at any time you feel you can get the item shipped cheaper or better we would be completely willingto use your shipping label and there will be no other shipping or handling charges.',
            'We do NOT set our shipping prices. We simply enter the weight and size , then the Ebay shipping calculator determines the shipping fee due to your destination. If at anytime you feel you can get the item shipped cheaper or better we would be completely willing to use your shipping label and there will be no other shipping or handling charges.',
            'We do NOT set our shipping prices. We simply enter the weight and size , then the Ebay shipping calculator determines the shipping fee due to your destination. If at any time youfeel you can get the item shipped cheaper or better we would be completely willingto use your shipping label and there will be no other shipping or handling charges.',
            'We do NOT set our shipping prices. We simply enter the weight and size , then the Ebay shipping calculator determines the shipping fee due to your destination. If at anytime you feel you can get the item shipped cheaper or better we would be completelywilling to use your shipping label and there will be no other shipping or handling charges.',
            'We do NOT set our shipping prices. We simply enter the weight and size , then the Ebay shipping calculator determines the shipping fee due to your destination. If at anytime you feel you can get the item shipped cheaper or better we would be completely willing to use your shipping label and there will be no other shipping or handling charges',
        ];
        $newLead = 'These are inexpensive options that help protect against porch pirates, theft, and fraud.';
        $legacyLead = 'These are inexpensive options to help protect you and cut down on porch pirates , theft and fraud. Thank you!!';
        $legacyLeads = [
            $legacyLead,
            $legacyLead.' ((( THIS PRICE IS FOR 1 EACH , BUY ONE OR MORE )))',
            'Buyers will be responsible for all taxes , tariffs , custom fees and any other shipper or government fees imposed.',
        ];
        $literalPattern = static function (string $text): string {
            return str_replace(' ', '[\s\x{00A0}]+', preg_quote($text, '~'));
        };
        $insideQuote = static function (string $prefix): bool {
            // Decode for inspection only; offsets and returned source bytes stay original.
            $prefix = html_entity_decode($prefix, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $quotePattern = '~&(?:quot|apos);|&#(?:0*34|0*39|x0*22|x0*27);|["“”‘]|(?<![\p{L}\p{N}])[\x{0027}’]|[\x{0027}’](?![\p{L}\p{N}])~iu';
            if (preg_match_all($quotePattern, $prefix, $quotes) === false) return true;
            $double = false;
            $single = false;
            foreach ($quotes[0] as $encoded) {
                $quote = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($quote === '“') $double = true;
                elseif ($quote === '”') $double = false;
                elseif ($quote === '‘') $single = true;
                elseif ($quote === '’') $single = false;
                elseif ($quote === '"') $double = !$double;
                else $single = !$single;
            }
            return $double || $single;
        };
        $cleaned = $source;
        foreach ($paragraphs as $paragraph) {
            // Require its observed template context, not merely a word boundary.
            // This leaves quoted/embedded descriptions and unknown contexts for review.
            $leads = strpos($paragraph, 'Shipping Costs:') === 0 ? [$newLead] : $legacyLeads;
            $contexts = array_map($literalPattern, $leads);
            $leading = '(?:(?<=[.!?])|^)([\s\x{00A0}]*(?:'.implode('|', $contexts).')[\s\x{00A0}]*)';
            $trailing = substr($paragraph, -1) === '.'
                ? '(?=$|[\s\x{00A0}.!?,;:>\)\]]|Shipping Carrier Notice:|PLEASE READ BEFORE ORDERING)'
                : '$';
            $result = preg_replace_callback(
                '~'.$leading.$literalPattern($paragraph).$trailing.'~u',
                static function (array $match) use ($cleaned, $insideQuote): string {
                    return $insideQuote(substr($cleaned, 0, $match[0][1])) ? $match[0][0] : $match[1][0];
                },
                $cleaned, -1, $count, PREG_OFFSET_CAPTURE
            );
            if ($result === null) return $source;
            $cleaned = $result;
        }
        // Unicode whitespace or empty markup is not usable product content.
        return self::cleanText($cleaned) !== '' ? $cleaned : $source;
    }

    public static function cleanProductSeoDescription($value): string
    {
        $text = self::cleanText($value);
        if ($text === '') {
            return '';
        }

        $boilerplatePatterns = [
            '/\bPlease visit our eBay Store for more parts[.!]*/i',
            '/\bFor other [^.!?\r\n]{1,100} parts click here[.!]*/i',
            '/\bVideo will open in new window[.!]*/i',
            '/\bUsing mobile app\?\s*Copy this link into your browser[.!]*/i',
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
        return (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public static function schemaJsonString(string $schema): string
    {
        $decoded = json_decode($schema);
        if (json_last_error() !== JSON_ERROR_NONE || (!is_object($decoded) && !is_array($decoded))) {
            return '';
        }

        // In valid JSON these characters occur only inside strings. Escape
        // them without re-encoding objects or rounding raw number tokens.
        return str_replace(['<', '>', '&', "'"], ['\\u003C', '\\u003E', '\\u0026', '\\u0027'], $schema);
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
            'description' => self::limitText($product['storefront_description'] ?? $description, 5000, ''),
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
            'hasMerchantReturnPolicy' => self::merchantReturnPolicySchema($product),
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

    $identifiers = ProductIdentifierOutput::forProduct(
        $product,
        self::cleanText($product['manufacturer'] ?? ''),
        ProductCondition::merchantMpn($product['model'] ?? '')
    );
    $brand = $identifiers['brand'];
        if ($brand !== '') {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $brand,
            ];
        }

        $mpn = $identifiers['mpn'];
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

    private static function merchantReturnPolicySchema(array $product): array
    {
        $policy = ReviewedProductFacts::returnPolicy($product);
        if ($policy === 'review_required') return [];
        if ($policy === 'final_sale') {
            return [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => 'US',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
            ];
        }
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
        return ProductCondition::schema($condition);
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
