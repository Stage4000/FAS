<?php
use FAS\Utils\Seo;

$categoryMeta = [
    'motorcycle' => [
        'label' => 'Motorcycle Parts',
        'description' => 'Browse motorcycle parts for Harley Davidson, Honda, Yamaha, Kawasaki, Suzuki, BMW, and more.',
    ],
    'atv' => [
        'label' => 'ATV/UTV Parts',
        'description' => 'Browse ATV and UTV parts for Polaris, Honda, Yamaha, Can-Am, Kawasaki, and more.',
    ],
    'boat' => [
        'label' => 'Boat & Marine Parts',
        'description' => 'Browse boat and marine parts at Flip and Strip. Review photos, condition details, and descriptions before ordering.',
    ],
    'automotive' => [
        'label' => 'Automotive Parts',
        'description' => 'Browse automotive parts and accessories at Flip and Strip. Review each listing for condition and fitment details.',
    ],
    'gifts' => [
        'label' => 'Biker Gifts & Accessories',
        'description' => 'Shop biker gifts, watches, clothing, collectibles, and accessories from Flip and Strip.',
    ],
    'other' => [
        'label' => 'Other Powersports Parts',
        'description' => 'Shop other powersports parts and accessories from Flip and Strip.',
    ],
];

$searchTerm = Seo::cleanText($search ?? '');
$manufacturerName = Seo::cleanText($manufacturer ?? '');
$fitmentModelName = Seo::cleanText($fitmentModel ?? '');
$categoryLabel = ($homepageCategory && isset($categoryMeta[$homepageCategory]))
? $categoryMeta[$homepageCategory]['label']
: Seo::cleanText($currentCategoryName);
$pageSuffix = $page > 1 ? ' Page ' . $page : '';
$isCuratedFitmentLanding = (($manufacturerSlug || $modelSlug) && $manufacturerName !== '' && $searchTerm === '');
$collectionMeta = [
    'trending' => [
        'title' => 'Trending Parts | Flip and Strip',
        'description' => 'Shop powersports, marine, and automotive parts with recent shopper activity.',
        'copy' => 'Explore up to 24 parts getting the most recent attention from shoppers. Use this page to compare in-demand inventory before it sells.',
    ],
    'best' => [
        'title' => 'Best-Selling Parts | Flip and Strip',
        'description' => 'Browse best-selling motorcycle, ATV, boat, and automotive parts from Flip and Strip.',
        'copy' => 'Explore up to 24 best sellers, ranked from completed orders first, then recent shopper engagement when sales history is limited.',
    ],
    'recent' => [
        'title' => 'Recent Arrivals | Flip and Strip',
        'description' => 'See recently added parts with actual item photos, fitment details, and secure checkout.',
        'copy' => 'Recent arrivals help buyers find recently added parts before it is picked over on high-demand makes and models.',
    ],
    'free_shipping' => [
        'title' => 'Free Shipping Eligible Parts | Flip and Strip',
        'description' => 'Shop parts that qualify for free shipping to continental US addresses.',
        'copy' => 'These listings qualify for free shipping to continental US addresses. Confirm shipping options for your address at checkout.',
    ],
    'sale' => [
        'title' => 'Parts On Sale | Flip and Strip',
        'description' => 'Shop discounted motorcycle, ATV, boat, automotive, and powersports parts from Flip and Strip.',
        'copy' => 'Sale inventory highlights current markdowns and active promotions so buyers can find discounted parts faster.',
    ],
];

$canonicalPath = '/products';
if ($discoveryCollection !== '') {
    $canonicalPath .= '/' . ($discoveryCollectionSlugs[$discoveryCollection] ?? $discoveryCollection);
} elseif ($isCuratedFitmentLanding) {
    if ($homepageCategory && isset($categoryMeta[$homepageCategory])) {
        $canonicalPath .= '/' . $homepageCategory;
    }
    $canonicalPath .= '/make/' . Seo::slug($manufacturerName);
    if ($fitmentModelName !== '') {
        $canonicalPath .= '/' . Seo::slug($fitmentModelName);
    }
}

if ($discoveryCollection === '' && !$isCuratedFitmentLanding && $homepageCategory) {
    $canonicalPath .= '/' . $homepageCategory;
}

$canonicalParams = [];
if ($discoveryCollection !== '') {
    // Clean curated landing URLs should not canonicalize back to query strings.
} else {
    if ($ebayCat1) {
        $canonicalParams['cat1'] = $ebayCat1;
    }
    if ($ebayCat2) {
        $canonicalParams['cat2'] = $ebayCat2;
    }
    if ($ebayCat3) {
        $canonicalParams['cat3'] = $ebayCat3;
    }
}
if (!$isCuratedFitmentLanding && $manufacturerName !== '') {
$canonicalParams['manufacturer'] = $manufacturerName;
}
if (!$isCuratedFitmentLanding && $fitmentModelName !== '') {
$canonicalParams['model'] = $fitmentModelName;
}
if ($searchTerm !== '') {
    $canonicalParams['search'] = $searchTerm;
}
if ($page > 1) {
    $canonicalParams['page'] = $page;
}

$canonicalQuery = $canonicalParams ? '?' . http_build_query($canonicalParams) : '';
$canonicalUrl = Seo::canonicalUrl($canonicalPath . $canonicalQuery);
$robotsMeta = 'index, follow';

if ($discoveryCollection !== '') {
    $activeCollectionMeta = $collectionMeta[$discoveryCollection] ?? null;
    $metaTitle = Seo::metaTitle(str_replace(' | Flip and Strip', $pageSuffix . ' | Flip and Strip', $activeCollectionMeta['title'] ?? ($currentCategoryName . ' | Flip and Strip')));
    $metaDescription = Seo::metaDescription($activeCollectionMeta['description'] ?? ('Browse ' . strtolower($currentCategoryName) . ' from Flip and Strip.'));
} elseif ($searchTerm !== '') {
$metaTitle = Seo::metaTitle('Search results for ' . $searchTerm . ' | Flip and Strip');
$metaDescription = Seo::metaDescription('Browse matching Flip and Strip parts for ' . $searchTerm . '. Product search pages are provided for shopping navigation.');
$robotsMeta = 'noindex, follow';
} elseif ($isCuratedFitmentLanding) {
    $fitmentLabel = trim($manufacturerName . ' ' . $fitmentModelName);
    $metaTitle = Seo::metaTitle($fitmentLabel . ' Parts' . $pageSuffix . ' | Flip and Strip');
    $metaDescription = Seo::metaDescription('Shop available ' . $fitmentLabel . ' parts. Review listing photos, condition, and fitment details before ordering.');
} elseif ($manufacturerName !== '' || $fitmentModelName !== '') {
$fitmentLabel = trim($manufacturerName . ' ' . $fitmentModelName);
$metaTitle = Seo::metaTitle($fitmentLabel . ' Parts | Flip and Strip');
    $metaDescription = Seo::metaDescription('Browse available ' . $fitmentLabel . ' parts from Flip and Strip.');
    $robotsMeta = 'noindex, follow';
} elseif ($homepageCategory && isset($categoryMeta[$homepageCategory])) {
    $metaTitle = Seo::metaTitle($categoryLabel . $pageSuffix . ' | Flip and Strip');
    $metaDescription = Seo::metaDescription($categoryMeta[$homepageCategory]['description']);
} elseif ($ebayCat1 || $ebayCat2 || $ebayCat3) {
    $metaTitle = Seo::metaTitle($categoryLabel . $pageSuffix . ' | Flip and Strip');
    $metaDescription = Seo::metaDescription('Browse available ' . $categoryLabel . ' from Flip and Strip.');
    $robotsMeta = 'noindex, follow';
} else {
    $metaTitle = Seo::metaTitle('New & Used Motorcycle, ATV, Boat & Automotive Parts' . $pageSuffix . ' | Flip and Strip');
    $metaDescription = Seo::metaDescription('Browse new and used motorcycle, ATV/UTV, boat, and automotive parts at Flip and Strip. Review each listing for condition and fitment details.');
}

if ($includeHiddenProducts) {
$robotsMeta = 'noindex, nofollow';
} elseif ($ebayCat1 || $ebayCat2 || $ebayCat3 || $totalProducts === 0) {
    $robotsMeta = 'noindex, follow';
}

$landingIntroCopy = '';
if ($discoveryCollection !== '' && isset($collectionMeta[$discoveryCollection])) {
    $landingIntroCopy = $collectionMeta[$discoveryCollection]['copy'];
} elseif ($isCuratedFitmentLanding) {
    $landingIntroCopy = 'Use this focused inventory page to review matching parts, confirm fitment from photos and SKU details, and estimate shipping before checkout.';
} elseif ($homepageCategory && isset($categoryMeta[$homepageCategory])) {
    $landingIntroCopy = $categoryMeta[$homepageCategory]['description'] . ' Check manufacturer, model, SKU, photos, and notes before purchase because fitment can vary by year and trim.';
}

$landingIntroLinks = [
    '/products/recent-arrivals' => 'Recent Arrivals',
    '/products/best-sellers' => 'Best Sellers',
    '/products/free-shipping' => 'Free Shipping Eligible',
    '/products/sale' => 'On Sale',
];

$pageTitle = $categoryLabel;
$ogTitle = $metaTitle;
$ogDescription = $metaDescription;
$currentProductIds = array_values(array_filter(array_map('intval', array_column($products, 'id'))));
$merchandisingCategory = $categoryLabel !== 'All Products' ? $categoryLabel : null;
$merchandisingManufacturer = $manufacturerName !== '' ? $manufacturerName : null;
$noResultsProducts = [];
$searchSuggestions = $searchTerm !== '' ? $productModel->getSearchSuggestionQueries($searchTerm, 6) : [];

if (empty($products)) {
    $noResultsProducts = $searchTerm !== ''
        ? $productModel->getSearchFallbackRecommendations($searchTerm, 6, [], $ebayCat1, $ebayCat2, $ebayCat3, $manufacturer, $includeHiddenProducts, $fitmentModel)
        : [];
    if (count($noResultsProducts) < 6) {
        $noResultsProducts = array_merge(
            $noResultsProducts,
            fasAnalyticsRankedProducts($db, $productModel, 6 - count($noResultsProducts), array_column($noResultsProducts, 'id'))
        );
    }
    if (count($noResultsProducts) < 6) {
        $noResultsProducts = array_merge(
            $noResultsProducts,
            $productModel->getRecentVisible(
                6 - count($noResultsProducts),
                array_column($noResultsProducts, 'id'),
                $merchandisingCategory,
                $merchandisingManufacturer
            )
        );
    }
    $noResultsProducts = array_slice($noResultsProducts, 0, 6);
}
$structuredData = [
    Seo::breadcrumbSchema([
        ['name' => 'Home', 'url' => '/'],
        ['name' => 'Products', 'url' => $canonicalPath],
    ]),
    Seo::collectionPageSchema($metaTitle, $metaDescription, $canonicalUrl),
    Seo::itemListSchema($products),
];


$paginationUrl = function ($number) use ($canonicalPath, $canonicalParams, $includeHiddenProducts) {
    return fasCatalogPageUrl($canonicalPath, $canonicalParams, max(1, (int)$number), $includeHiddenProducts);
};
