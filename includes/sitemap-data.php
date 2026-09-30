<?php
require_once __DIR__ . '/catalog-query.php';

/** Build completely before publishing: a failed query must never yield a partial sitemap. */
function fasSitemapUrls(\PDO $db, \FAS\Models\Product $model): array
{
    $products = $model->getAllVisibleForFeed();
    $paths = ['/', '/about', '/contact'];
    if ($products) {
        $paths[] = '/products';
    } else {
        error_log('Sitemap inventory warning: no visible products; review inventory visibility and sync status.');
    }
    foreach (array_keys(\FAS\Models\HomepageCategoryMapping::HOMEPAGE_CATEGORIES) as $category) {
        if ($model->getCountByEbayCategory(null, null, null, null, null, false, null, $category) > 0) {
            $paths[] = '/products/' . $category;
        }
    }
    $collections = ['trending'=>'trending', 'best-sellers'=>'best', 'recent-arrivals'=>'recent',
        'free-shipping'=>'free_shipping', 'sale'=>'sale'];
    foreach ($collections as $slug => $collection) {
        if (fasCatalogCollection($db, $model, $collection, 1, 24)['totalProducts'] > 0) {
            $paths[] = '/products/' . $slug;
        }
    }
    // Fitment pages remain out until their source identifiers and discovery links are reviewed.
    $urls = array_map([\FAS\Utils\Seo::class, 'canonicalUrl'], $paths);
    foreach ($products as $product) {
        $urls[] = \FAS\Utils\Seo::productUrl($product);
    }
    return array_values(array_unique($urls));
}
