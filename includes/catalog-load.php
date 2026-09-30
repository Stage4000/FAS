<?php
require_once __DIR__ . '/catalog-query.php';
extract(fasCatalogRequest($_GET), EXTR_OVERWRITE);
$discoveryCollections = ['trending'=>'Trending Parts', 'best'=>'Best Sellers', 'recent'=>'Recent Arrivals', 'free_shipping'=>'Free Shipping Eligible', 'sale'=>'On Sale'];
$discoveryCollectionSlugs = ['trending'=>'trending', 'best'=>'best-sellers', 'recent'=>'recent-arrivals', 'free_shipping'=>'free-shipping', 'sale'=>'sale'];
$db = \FAS\Config\Database::getInstance()->getConnection();
$productModel = new \FAS\Models\Product($db);
$visibleCategoryIds = $productModel->getVisibleEbayCategoryIds($includeHiddenProducts);
if (!$manufacturer && $manufacturerSlug) {
    $manufacturer = fasResolveSlugOption($productModel->getManufacturers($includeHiddenProducts), $manufacturerSlug);
}
if (!$fitmentModel && $modelSlug) {
    $fitmentModel = fasResolveSlugOption($productModel->getModels($includeHiddenProducts, $manufacturer), $modelSlug);
}

$ebayAPI = null;
$ebayCategories = [];
$flatCategories = [];
try {
    $config = require __DIR__ . '/../src/config/config.php';
    $ebayAPI = new \FAS\Integrations\EbayAPI($config);
    $ebayCategories = pruneEmptyEbayCategories($ebayAPI->getStoreCategoriesHierarchical(), $visibleCategoryIds);
    if ($ebayCat1 || $ebayCat2 || $ebayCat3) {
        $flatCategories = $ebayAPI->getStoreCategories();
    }
} catch (\Throwable $e) {
    error_log('Catalog sidebar unavailable: ' . $e->getMessage());
}

if ($discoveryCollection !== '') {
    $collectionResult = fasCatalogCollection($db, $productModel, $discoveryCollection, $page, $perPage);
    $products = $collectionResult['products'];
    $totalProducts = $collectionResult['totalProducts'];
} else {
    $products = $productModel->getAllByEbayCategory($page, $perPage, $ebayCat1, $ebayCat2, $ebayCat3, $search, $manufacturer, $includeHiddenProducts, $fitmentModel, $homepageCategory);
    $totalProducts = $productModel->getCountByEbayCategory($ebayCat1, $ebayCat2, $ebayCat3, $search, $manufacturer, $includeHiddenProducts, $fitmentModel, $homepageCategory);
}
$allManufacturers = $productModel->getManufacturers($includeHiddenProducts);
$allModels = $productModel->getModels($includeHiddenProducts, $manufacturer);
$totalPages = (int)ceil($totalProducts / $perPage);
$currentCategoryName = 'All Products';
if ($discoveryCollection !== '') {
    $currentCategoryName = $discoveryCollections[$discoveryCollection];
} elseif ($homepageCategory) {
    $currentCategoryName = \FAS\Models\HomepageCategoryMapping::HOMEPAGE_CATEGORIES[$homepageCategory];
} elseif ($ebayCat3 || $ebayCat2 || $ebayCat1) {
    $cat = $flatCategories[$ebayCat3 ?: ($ebayCat2 ?: $ebayCat1)] ?? [];
    $currentCategoryName = implode(' > ', array_filter([$cat['topLevel'] ?? '', $cat['parent'] ?? '', $cat['name'] ?? 'Category']));
}
