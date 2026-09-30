<?php
/** Shared inventory selection and pagination for the storefront and AJAX endpoint. */
require_once __DIR__ . '/product-merchandising.php';
require_once __DIR__ . '/../src/models/HomepageCategoryMapping.php';

function fasCatalogString(array $query, string $key): ?string
{
    return isset($query[$key]) && is_scalar($query[$key]) ? trim((string)$query[$key]) : null;
}

function fasCatalogRequest(array $query): array
{
    $aliases = ['trending'=>'trending', 'best'=>'best', 'best-sellers'=>'best',
        'recent'=>'recent', 'recent-arrivals'=>'recent', 'free_shipping'=>'free_shipping',
        'free-shipping'=>'free_shipping', 'sale'=>'sale', 'on-sale'=>'sale'];
    $category = fasCatalogString($query, 'category');
    $request = [
        'homepageCategory' => isset(\FAS\Models\HomepageCategoryMapping::HOMEPAGE_CATEGORIES[$category ?? '']) ? $category : null,
        'ebayCat1'=>fasCatalogString($query, 'cat1'), 'ebayCat2'=>fasCatalogString($query, 'cat2'),
        'ebayCat3'=>fasCatalogString($query, 'cat3'), 'manufacturer'=>fasCatalogString($query, 'manufacturer'),
        'fitmentModel'=>fasCatalogString($query, 'model'), 'manufacturerSlug'=>fasCatalogString($query, 'manufacturer_slug'),
        'modelSlug'=>fasCatalogString($query, 'model_slug'), 'search'=>fasCatalogString($query, 'search'),
        'discoveryCollection'=>$aliases[fasCatalogString($query, 'collection') ?? ''] ?? '',
        'page'=>max(1, (int)(fasCatalogString($query, 'page') ?? 1)), 'perPage'=>24,
    ];
    if ($request['discoveryCollection'] !== '') {
        foreach (['homepageCategory','ebayCat1','ebayCat2','ebayCat3','manufacturer','fitmentModel','manufacturerSlug','modelSlug','search'] as $key) {
            $request[$key] = null;
        }
    }
    return $request;
}

function fasResolveSlugOption(array $options, ?string $slug): ?string
{
    foreach ($options as $option) {
        if (\FAS\Utils\Seo::slug((string)$option) === $slug) {
            return (string)$option;
        }
    }
    return null;
}

function fasCatalogCollection(\PDO $db, \FAS\Models\Product $model, string $collection, int $page, int $perPage): array
{
    // These two collections deliberately show the top 24 ranked listings.
    if ($collection === 'trending') {
        $all = fasAnalyticsRankedProducts($db, $model, 24);
    } elseif ($collection === 'best') {
        $all = fasBestSellingProducts($db, $model, 24);
    } else {
        $all = $model->getAllVisibleForFeed();
        usort($all, function ($a, $b) {
            return strcmp((string)$b['created_at'], (string)$a['created_at']) ?: ((int)$b['id'] <=> (int)$a['id']);
        });
        if ($collection === 'free_shipping') {
            $settings = \FAS\Utils\ShippingRules::getFreeShippingSettings();
            $all = array_values(array_filter($all, function ($product) use ($settings) {
                return \FAS\Utils\ShippingRules::productQualifiesForFreeShipping($product, $settings);
            }));
        } elseif ($collection === 'sale') {
            $all = array_values(array_filter($all, function ($product) {
                return getEffectivePrice((float)$product['price'], !empty($product['sale_price']) ? (float)$product['sale_price'] : null)['on_sale'];
            }));
        } elseif ($collection !== 'recent') {
            throw new \InvalidArgumentException('Unknown catalog collection');
        }
    }
    return ['products'=>array_slice($all, (max(1, $page) - 1) * $perPage, $perPage), 'totalProducts'=>count($all)];
}

function fasCatalogPageUrl(string $path, array $params, int $page, bool $includeHidden = false): string
{
    unset($params['page']);
    if ($page > 1) {
        $params['page'] = $page;
    }
    if ($includeHidden) {
        $params['show_hidden'] = '1';
    }
    return $path . ($params ? '?' . http_build_query($params) : '');
}
