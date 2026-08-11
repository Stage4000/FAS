<?php

require_once __DIR__ . '/sale-helper.php';
require_once __DIR__ . '/../src/utils/Analytics.php';
require_once __DIR__ . '/../src/utils/ProductAltText.php';
require_once __DIR__ . '/../src/utils/ShippingRules.php';
require_once __DIR__ . '/../src/utils/Seo.php';

use FAS\Models\Product;
use FAS\Utils\Analytics;
use FAS\Utils\ProductAltText;
use FAS\Utils\ShippingRules;
use FAS\Utils\Seo;

function fasProductImagePath(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '/gallery/default.jpg';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return '/' . ltrim($path, '/');
}

function fasProductCategoryLabel(array $product): string
{
    return (string)($product['ebay_store_cat3_name']
        ?? $product['ebay_store_cat2_name']
        ?? $product['ebay_store_cat1_name']
        ?? $product['category']
        ?? '');
}

function fasProductCardUrl(array $product): string
{
    if (class_exists(Seo::class)) {
        $path = parse_url(Seo::productUrl($product), PHP_URL_PATH);
        if ($path) {
            return $path;
        }
    }

    return '/product/' . (int)($product['id'] ?? 0);
}

function fasProductCard(array $product, string $columnClass = 'col-lg-3 col-md-6 col-sm-12', int $delay = 0): string
{
    $imageUrl = fasProductImagePath($product['image_url'] ?? null);
    $imageAltText = ProductAltText::forProductImage($product);
    $priceInfo = getEffectivePrice(
        (float)($product['price'] ?? 0),
        !empty($product['sale_price']) ? (float)$product['sale_price'] : null
    );
    $category = fasProductCategoryLabel($product);
    $productFreeShipping = ShippingRules::productQualifiesForFreeShipping($product);
    $stock = isset($product['quantity']) ? (int)$product['quantity'] : 999;
    $productUrl = fasProductCardUrl($product);

    ob_start();
    ?>
    <div class="<?php echo htmlspecialchars($columnClass); ?>" data-aos="fade-up" data-aos-delay="<?php echo (int)$delay; ?>">
        <div class="card product-card h-100">
            <a href="<?php echo htmlspecialchars($productUrl); ?>" class="text-decoration-none">
                <div class="position-relative">
                    <img src="<?php echo htmlspecialchars($imageUrl); ?>"
                         class="card-img-top product-image"
                         alt="<?php echo htmlspecialchars($imageAltText); ?>">
                    <?php if (!empty($product['condition_name'])): ?>
                        <span class="badge bg-info product-badge"><?php echo htmlspecialchars($product['condition_name']); ?></span>
                    <?php endif; ?>
            <?php if ($priceInfo['on_sale']): ?>
                <span class="badge bg-danger product-badge" style="top: <?php echo !empty($product['condition_name']) ? '50px' : '10px'; ?>;"><?php echo htmlspecialchars($priceInfo['sale_label']); ?></span>
            <?php endif; ?>
            <?php if ($productFreeShipping): ?>
                <span class="badge bg-success product-badge" style="top: <?php echo !empty($product['condition_name']) && $priceInfo['on_sale'] ? '90px' : (!empty($product['condition_name']) || $priceInfo['on_sale'] ? '50px' : '10px'); ?>;">
                    <i class="fas fa-truck-fast me-1"></i>Free Ship*
                </span>
            <?php endif; ?>
            <?php if (isset($product['show_on_website']) && (int)$product['show_on_website'] === 0): ?>
                <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2">Hidden</span>
            <?php endif; ?>
        </div>
            </a>
            <div class="card-body d-flex flex-column">
                <a href="<?php echo htmlspecialchars($productUrl); ?>" class="text-decoration-none text-dark">
                    <h6 class="card-title text-truncate mb-2"><?php echo htmlspecialchars($product['name'] ?? 'Product'); ?></h6>
                </a>
                <?php if ($category !== ''): ?>
                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($category); ?></div>
                <?php endif; ?>
                <div class="mt-auto">
                    <div class="mb-2">
                        <?php if ($priceInfo['on_sale']): ?>
                            <span class="product-price text-danger fw-bold">$<?php echo number_format($priceInfo['effective_price'], 2); ?></span>
                            <small class="text-muted text-decoration-line-through ms-1">$<?php echo number_format($priceInfo['original_price'], 2); ?></small>
                        <?php else: ?>
                            <span class="product-price fw-bold">$<?php echo number_format($priceInfo['original_price'], 2); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($product['sku'])): ?>
                        <small class="text-muted d-block mb-2">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                    <?php endif; ?>
                    <button class="btn btn-danger w-100 add-to-cart"
                            data-id="<?php echo (int)($product['id'] ?? 0); ?>"
                            data-name="<?php echo htmlspecialchars($product['name'] ?? ''); ?>"
                            data-price="<?php echo htmlspecialchars((string)$priceInfo['effective_price']); ?>"
                            data-image="<?php echo htmlspecialchars($imageUrl); ?>"
                            data-image-alt="<?php echo htmlspecialchars($imageAltText); ?>"
                            data-sku="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                            data-category="<?php echo htmlspecialchars($category); ?>"
                            data-manufacturer="<?php echo htmlspecialchars($product['manufacturer'] ?? ''); ?>"
                            data-source="<?php echo htmlspecialchars($product['source'] ?? ''); ?>"
                            data-condition="<?php echo htmlspecialchars($product['condition_name'] ?? ''); ?>"
                            data-weight="<?php echo !empty($product['weight']) ? (float)$product['weight'] : 1.0; ?>"
                            data-length="<?php echo !empty($product['length']) ? (float)$product['length'] : 10.0; ?>"
                            data-width="<?php echo !empty($product['width']) ? (float)$product['width'] : 10.0; ?>"
                            data-height="<?php echo !empty($product['height']) ? (float)$product['height'] : 10.0; ?>"
                            data-free-shipping="<?php echo $productFreeShipping ? '1' : '0'; ?>"
                            data-stock="<?php echo $stock; ?>">
                        <i class="fas fa-cart-plus"></i> Add to Cart
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

function fasAnalyticsRankedProducts(\PDO $db, Product $productModel, int $limit = 8, array $excludeIds = [], int $days = 30): array
{
    $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));
    $products = [];

    try {
        $analytics = new Analytics($db);
        $rankedIds = array_values(array_diff($analytics->getRankedProductIds($days, $limit * 3), $excludeIds));
        $products = $productModel->getVisibleByIds($rankedIds, $limit);
    } catch (Throwable $e) {
        error_log('Merchandising analytics ranking failed: ' . $e->getMessage());
    }

    if (count($products) < $limit) {
        $fallbackExclude = array_merge($excludeIds, array_column($products, 'id'));
        $products = array_merge($products, $productModel->getRecentVisible($limit - count($products), $fallbackExclude));
    }

    return array_slice($products, 0, $limit);
}

function fasRelatedMerchandisingProducts(\PDO $db, Product $productModel, array $product, int $limit = 4): array
{
    $currentId = (int)($product['id'] ?? 0);
    $category = fasProductCategoryLabel($product);
    $manufacturer = trim((string)($product['manufacturer'] ?? ''));
    $ranked = fasAnalyticsRankedProducts($db, $productModel, $limit * 3, [$currentId]);
    $related = [];

    foreach ($ranked as $candidate) {
        $candidateCategory = fasProductCategoryLabel($candidate);
        $candidateManufacturer = trim((string)($candidate['manufacturer'] ?? ''));
        if (($category !== '' && $candidateCategory === $category) || ($manufacturer !== '' && $candidateManufacturer === $manufacturer)) {
            $related[] = $candidate;
        }
        if (count($related) >= $limit) {
            break;
        }
    }

    if (count($related) < $limit) {
        $fallbackExclude = array_merge([$currentId], array_column($related, 'id'));
        $related = array_merge($related, $productModel->getRelatedVisible($product, $limit - count($related), $fallbackExclude));
    }

    return array_slice($related, 0, $limit);
}
