<?php
/**
 * Google Merchant feed item builder.
 */

namespace FAS\Utils;

use FAS\Models\Product;

require_once __DIR__ . '/Seo.php';

class MerchantFeedBuilder
{
    private $productModel;
    private $baseUrl;

    public function __construct(Product $productModel, array $config = [])
    {
        $this->productModel = $productModel;
        $siteUrl = $config['site']['url'] ?? 'https://flipandstrip.com';
        $this->baseUrl = rtrim((string) $siteUrl, '/');
    }

    /**
     * Transform visible product rows into Merchant feed item arrays.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array{items: array<int, array<string, mixed>>, skipped: array<int, array<string, string>>}
     */
    public function buildItems(array $products)
    {
        $items = [];
        $skipped = [];

        foreach ($products as $product) {
            $item = $this->buildItem($product);

            if ($item === null) {
                $skipped[] = [
                    'product_id' => isset($product['id']) ? (string) $product['id'] : 'unknown',
                    'reason' => 'Product could not be normalized into a valid feed item',
                ];
                continue;
            }

            $items[] = $item;
        }

        return [
            'items' => $items,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    public function buildItem(array $product)
    {
        $id = $this->resolveProductId($product);
        $title = $this->normalizeText($product['name'] ?? '');
        $link = $this->buildProductUrl($product);

        if ($id === null || $title === '' || $link === null) {
            return null;
        }

        $images = $this->collectImageUrls($product);
        $mainImage = $images[0] ?? $this->toAbsoluteUrl('/gallery/default.jpg');
        // Google Merchant Center supports up to 10 additional images per item.
        $additionalImages = array_slice($images, 1, 10);

        $brand = $this->normalizeText($product['manufacturer'] ?? '');
        $mpn = $this->normalizeText($product['model'] ?? '');
        $description = $this->buildDescription($product);
        $productType = $this->resolveProductType($product);
        $price = $this->resolveEffectivePrice($product);
        $quantity = (int) ($product['quantity'] ?? 0);
        $shippingWeight = $this->resolveShippingWeight($product);

        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'image_link' => $mainImage,
            'additional_image_links' => $additionalImages,
            'availability' => $quantity > 0 ? 'in_stock' : 'out_of_stock',
            'price' => number_format($price, 2, '.', '') . ' USD',
            'condition' => $this->normalizeCondition($product['condition_name'] ?? ''),
            'brand' => $brand,
            'mpn' => $mpn,
            'identifier_exists' => ($brand !== '' || $mpn !== '') ? 'yes' : 'no',
            'product_type' => $productType,
            'shipping_weight' => $shippingWeight,
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveProductId(array $product)
    {
        $candidates = [
            $product['sku'] ?? null,
            $product['ebay_item_id'] ?? null,
            $product['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = $this->normalizeText($candidate ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function buildProductUrl(array $product)
    {
        if (empty($product['id'])) {
            return null;
        }

        return Seo::productUrl($product);
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, string>
     */
    private function collectImageUrls(array $product)
    {
        $normalized = [];
        $seen = [];
        $sources = [];

        if (!empty($product['image_url'])) {
            $sources[] = $product['image_url'];
        }

        if (!empty($product['images'])) {
            $decoded = json_decode((string) $product['images'], true);
            if (is_array($decoded)) {
                $sources = array_merge($sources, $decoded);
            }
        }

        foreach ($sources as $source) {
            $url = $this->normalizeImageUrl($source);
            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $normalized[] = $url;
        }

        if (empty($normalized)) {
            $normalized[] = $this->toAbsoluteUrl('/gallery/default.jpg');
        }

        return $normalized;
    }

    /**
     * @param mixed $path
     */
    private function normalizeImageUrl($path)
    {
        $value = trim((string) $path);
        if ($value === '') {
            return null;
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

        return $this->toAbsoluteUrl($value);
    }

    private function toAbsoluteUrl($path)
    {
        return $this->baseUrl . $path;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function buildDescription(array $product)
    {
        $description = $this->normalizeText(strip_tags((string) ($product['description'] ?? '')));
        if ($description !== '') {
            return $description;
        }

        return $this->normalizeText($product['name'] ?? '');
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveProductType(array $product)
    {
        $path = $this->productModel->getEbayStoreCategoryPath($product);
        if (!empty($path)) {
            return $path;
        }

        $category = $this->normalizeText($product['category'] ?? '');
        return $category !== '' ? $category : 'Uncategorized';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveEffectivePrice(array $product)
    {
        $basePrice = (float) ($product['price'] ?? 0);
        $salePrice = null;

        if (array_key_exists('sale_price', $product) && $product['sale_price'] !== null && $product['sale_price'] !== '') {
            $salePrice = (float) $product['sale_price'];
        }

        $priceInfo = getEffectivePrice($basePrice, $salePrice);
        return (float) $priceInfo['effective_price'];
    }

    /**
     * @param array<string, mixed> $product
     * @return string|null
     */
    private function resolveShippingWeight(array $product)
    {
        if (!isset($product['weight']) || $product['weight'] === '' || $product['weight'] === null) {
            return null;
        }

        $weight = (float) $product['weight'];
        if ($weight <= 0) {
            return null;
        }

        return number_format($weight, 2, '.', '') . ' lb';
    }

    /**
     * @param mixed $value
     */
    private function normalizeText($value)
    {
        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    /**
     * @param mixed $condition
     */
    private function normalizeCondition($condition)
    {
        $value = strtolower($this->normalizeText($condition));

        if ($value === 'new') {
            return 'new';
        }

        if ($value === 'used' || $value === 'pre-owned' || $value === 'pre owned') {
            return 'used';
        }

        return 'used';
    }
}
