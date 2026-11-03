<?php
/** Seeded, offline catalog helper contracts. No application bootstrap or live inventory. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (file_exists(__DIR__ . '/../src/config/config.php')) {
    throw new RuntimeException('Run this synthetic test in a checkout without runtime config.php');
}
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
require_once __DIR__ . '/../includes/catalog-query.php';
require_once __DIR__ . '/../src/models/Product.php';

$checks = 0;
function checkCatalogBoundary($ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function catalogBoundaryParams(string $url): array
{
    $params = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    return $params;
}
function catalogBoundaryRequest(array $query): array
{
    $original = $query;
    $request = fasCatalogRequest($query);
    checkCatalogBoundary($query === $original, 'Request must not rewrite caller query');
    checkCatalogBoundary(is_bool($request['catalogNotFound']), 'Not-found flag is boolean');
    checkCatalogBoundary(is_int($request['page']) && $request['page'] >= 1
        && $request['page'] <= intdiv(PHP_INT_MAX, 24), 'Page is a bounded integer');
    checkCatalogBoundary($request['perPage'] === 24, 'Fixed catalog page size');
    return $request;
}

$default = catalogBoundaryRequest([]);
checkCatalogBoundary(!$default['catalogNotFound'] && $default['page'] === 1, 'Absent route is page one');
$maxPage = intdiv(PHP_INT_MAX, 24);
foreach ([1, 2, 24, 1000, $maxPage, '1', '2', (string)$maxPage, ' +2 ', "\t24\n"] as $page) {
    $request = catalogBoundaryRequest(['page' => $page]);
    checkCatalogBoundary(!$request['catalogNotFound'] && $request['page'] === (int)$page, 'Valid page boundary');
    checkCatalogBoundary(is_int(($request['page'] - 1) * 24), 'Accepted page offset does not overflow');
}
foreach (['', '0', '-1', '01', '1.0', '1e2', '0x10', '+', '1 2', '2junk', "2\n3", (string)($maxPage + 1), str_repeat('9', 100), [], ['1'], ['x' => ['2']]] as $page) {
    $request = catalogBoundaryRequest(['page' => $page]);
    checkCatalogBoundary($request['catalogNotFound'] && $request['page'] === 1, 'Invalid page fails closed');
}
foreach (array_keys(FAS\Models\HomepageCategoryMapping::HOMEPAGE_CATEGORIES) as $category) {
    $request = catalogBoundaryRequest(['category' => ' ' . strtoupper($category) . ' ']);
    checkCatalogBoundary(!$request['catalogNotFound'] && $request['homepageCategory'] === $category, 'Category normalization');
}
foreach (['motorcycle/other', 'unknown', "motorcycle\0other", 'mötörcycle', "\xff"] as $category) {
    checkCatalogBoundary(catalogBoundaryRequest(['category' => $category])['catalogNotFound'], 'Unknown category rejected');
}

$filterMap = ['category' => 'homepageCategory', 'cat1' => 'ebayCat1', 'cat2' => 'ebayCat2', 'cat3' => 'ebayCat3',
    'manufacturer' => 'manufacturer', 'model' => 'fitmentModel', 'manufacturer_slug' => 'manufacturerSlug',
    'model_slug' => 'modelSlug', 'search' => 'search'];
$filters = ['category' => 'motorcycle', 'cat1' => '0001', 'cat2' => '002', 'cat3' => '3',
    'manufacturer' => ' Honda ', 'model' => ' XR/100 ', 'manufacturer_slug' => 'honda',
    'model_slug' => 'xr-100', 'search' => ' rotor & bolt ', 'page' => '3'];
$request = catalogBoundaryRequest($filters);
foreach ($filterMap as $input => $output) {
    checkCatalogBoundary($request[$output] === trim($filters[$input]), 'Ordinary route keeps filter ' . $input);
}
$aliases = ['trending' => 'trending', 'best' => 'best', 'best-sellers' => 'best', 'recent' => 'recent',
    'recent-arrivals' => 'recent', 'free_shipping' => 'free_shipping', 'free-shipping' => 'free_shipping',
    'sale' => 'sale', 'on-sale' => 'sale'];
foreach ($aliases as $alias => $canonical) {
    $request = catalogBoundaryRequest($filters + ['collection' => ' ' . strtoupper($alias) . ' ']);
    checkCatalogBoundary(!$request['catalogNotFound'] && $request['discoveryCollection'] === $canonical
        && $request['page'] === 3, 'Alias keeps collection and page');
    foreach ($filterMap as $output) checkCatalogBoundary($request[$output] === null, 'Collection drops ordinary filter');
    foreach (['category', 'cat1', 'page', 'manufacturer_slug', 'model_slug'] as $key) {
        $bad = $filters + ['collection' => $alias];
        $bad[$key] = ['nested' => ['bad']];
        checkCatalogBoundary(catalogBoundaryRequest($bad)['catalogNotFound'], 'Collection cannot erase invalid-route status');
    }
}
foreach (['category', 'collection', 'page', 'manufacturer_slug', 'model_slug', 'cat1', 'cat2', 'cat3'] as $key) {
    foreach ([[], ['x'], ['x' => ['y']]] as $value) {
        checkCatalogBoundary(catalogBoundaryRequest([$key => $value])['catalogNotFound'], 'Array route value rejected');
    }
    checkCatalogBoundary(!catalogBoundaryRequest([$key => null])['catalogNotFound'], 'Null route key is absent');
}
// Optional free-text arrays are ignored by the existing contract, not interpreted as strings.
foreach (['manufacturer' => 'manufacturer', 'model' => 'fitmentModel', 'search' => 'search'] as $input => $output) {
    $request = catalogBoundaryRequest([$input => ['x']]);
    checkCatalogBoundary(!$request['catalogNotFound'] && $request[$output] === null, 'Optional array is ignored safely');
}
foreach (['cat1' => 'ebayCat1', 'cat2' => 'ebayCat2', 'cat3' => 'ebayCat3'] as $input => $output) {
    foreach (['0', '00001', str_repeat('9', 100), ' 123 '] as $value) {
        $request = catalogBoundaryRequest([$input => $value]);
        checkCatalogBoundary(!$request['catalogNotFound'] && $request[$output] === trim($value), 'Digit ID preserved without integer overflow');
    }
    foreach (['-1', '+1', '1.0', '1e2', '1/2', '１２', '1a', "1\n2", "1\0x"] as $value) {
        checkCatalogBoundary(catalogBoundaryRequest([$input => $value])['catalogNotFound'], 'Non-digit category ID rejected');
    }
}

// Reproducible combinations, with an oracle based on generated values rather than FILTER_VALIDATE_INT.
mt_srand(20261103);
$categories = array_keys(FAS\Models\HomepageCategoryMapping::HOMEPAGE_CATEGORIES);
$bytes = ['rotor & bolt', 'XR/100 + special', 'a=b?c#d', '100% "quoted"', 'moto café', "bad\xffbyte", '<tag>', '0'];
for ($case = 0; $case < 240; $case++) {
    $page = mt_rand(1, 100000);
    $id = '00' . mt_rand(1, 100000);
    $category = $categories[mt_rand(0, count($categories) - 1)];
    $text = $bytes[mt_rand(0, count($bytes) - 1)] . '-' . $case;
    $query = ['category' => strtoupper($category), 'cat1' => $id, 'search' => ' ' . $text . ' ',
        'manufacturer' => 'Honda', 'model' => 'XR/100', 'manufacturer_slug' => 'honda',
        'model_slug' => 'xr-100', 'page' => (string)$page, 'ignored' => ['nested']];
    $request = catalogBoundaryRequest($query);
    checkCatalogBoundary(!$request['catalogNotFound'] && $request['homepageCategory'] === $category
        && $request['page'] === $page && $request['ebayCat1'] === $id && $request['search'] === $text, 'Seeded request preserves values');
    $params = ['search' => $text, 'manufacturer' => 'Honda & Co', 'model' => 'XR/100', 'cat1' => $id, 'page' => 999];
    $copy = $params;
    foreach ([1, 2, $page, $maxPage] as $targetPage) {
        $url = fasCatalogPageUrl('/products/motorcycle/make/honda', $params, $targetPage);
        $parsed = catalogBoundaryParams($url);
        $expected = $params;
        unset($expected['page']);
        if ($targetPage > 1) $expected['page'] = (string)$targetPage;
        checkCatalogBoundary($parsed === $expected, 'Pagination query round-trip and single current page');
        checkCatalogBoundary(parse_url($url, PHP_URL_PATH) === '/products/motorcycle/make/honda'
            && parse_url($url, PHP_URL_FRAGMENT) === null, 'Encoded filter cannot change path or fragment');
        checkCatalogBoundary($params === $copy, 'Pagination leaves caller parameters untouched');
    }
    checkCatalogBoundary(catalogBoundaryParams(fasCatalogPageUrl('/products', $params, 1, true))['show_hidden'] === '1', 'Explicit hidden flag preserved');
    $query += ['collection' => 'sale', 'show_hidden' => '1'];
    $copy = $query;
    $url = fasCatalogCategoryUrl(['cat1' => '9', 'cat2' => '08'], $query);
    checkCatalogBoundary(catalogBoundaryParams($url) === ['search' => $text, 'manufacturer' => 'Honda', 'model' => 'XR/100',
        'manufacturer_slug' => 'honda', 'model_slug' => 'xr-100', 'show_hidden' => '1', 'cat1' => '9', 'cat2' => '08'], 'Category navigation resets old scope and retains shopping filters');
    checkCatalogBoundary($query === $copy, 'Category navigation leaves caller query untouched');
    $label = 'Model' . $case . ' XR';
    $slug = 'model' . $case . '-xr';
    checkCatalogBoundary(fasResolveSlugOption([$label, strtoupper($label), ' ' . strtolower($label) . ' '], strtoupper($slug)) === $label, 'Case/trim equivalent slug labels resolve');
    $collision = 'Model' . $case . '/XR';
    foreach ([[$label, $collision], [$collision, $label]] as $options) {
        checkCatalogBoundary(fasResolveSlugOption($options, $slug) === null, 'Punctuation collision rejects either order');
    }
    checkCatalogBoundary(fasResolveSlugOption([$label], $slug . '-missing') === null, 'Unknown slug never broadens filter');
    checkCatalogBoundary(fasCatalogRepresentativeOption([$label], ' ' . strtoupper($label) . ' ') === $label, 'Representative retains stored display bytes');
    checkCatalogBoundary(fasCatalogRepresentativeOption([$label], $collision) === $collision, 'Representative does not merge punctuation distinctions');
}
$prefix = str_repeat('a', 80);
checkCatalogBoundary(fasResolveSlugOption([$prefix . 'x', $prefix . 'y'], $prefix) === null, 'Length-truncated slug collisions reject');
checkCatalogBoundary(fasCatalogPageUrl('/products', ['page' => 8], 1) === '/products', 'Page-one URL has no empty query');
checkCatalogBoundary(catalogBoundaryParams(fasCatalogCategoryUrl([], ['search' => ['x'], 'manufacturer' => '', 'page' => 9])) === [], 'Empty and array navigation filters dropped');

// Exercise real collection sorting/slicing with synthetic inventory only. Any DB use must fail.
class CatalogBoundaryPdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { throw new RuntimeException('Unexpected DB prepare'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { throw new RuntimeException('Unexpected DB query'); }
}
class CatalogBoundaryProduct extends FAS\Models\Product
{
    public array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function getAllVisibleForFeed() { return $this->rows; }
    public function getDb() { throw new RuntimeException('Unexpected database access'); }
}
$rows = [];
for ($id = 1; $id <= 173; $id++) {
    $rows[] = ['id' => $id, 'created_at' => '2026-01-' . sprintf('%02d', 1 + $id % 7),
        'price' => 100, 'sale_price' => $id % 3 === 0 ? 75 : null];
}
shuffle($rows);
$model = new CatalogBoundaryProduct($rows);
$db = new CatalogBoundaryPdo();
foreach (['recent', 'sale'] as $collection) {
    // Independent expected ordering: visit known day buckets newest-first, IDs newest-first.
    $expected = [];
    for ($day = 7; $day >= 1; $day--) {
        for ($id = 173; $id >= 1; $id--) {
            if (1 + $id % 7 === $day && ($collection === 'recent' || $id % 3 === 0)) $expected[] = $id;
        }
    }
    $seen = [];
    for ($page = 1; $page <= (int)ceil(count($expected) / 24) + 1; $page++) {
        $result = fasCatalogCollection($db, $model, $collection, $page, 24);
        $ids = array_column($result['products'], 'id');
        checkCatalogBoundary($result['totalProducts'] === count($expected), 'Collection total does not vary by page');
        checkCatalogBoundary($ids === array_slice($expected, ($page - 1) * 24, 24), 'Exact stable collection page');
        $seen = array_merge($seen, $ids);
    }
    checkCatalogBoundary($seen === $expected && count(array_unique($seen)) === count($seen), 'Complete traversal has no gaps or duplicates');
    checkCatalogBoundary(fasCatalogCollection($db, $model, $collection, $maxPage, 24)['products'] === [], 'Maximum valid page is safely empty');
    checkCatalogBoundary(fasCatalogCollection($db, $model, $collection, 0, 24) === fasCatalogCollection($db, $model, $collection, 1, 24), 'Collection clamps direct nonpositive page');
    checkCatalogBoundary($model->rows === $rows, 'Sorting and slicing do not mutate source inventory');
}
$empty = new CatalogBoundaryProduct([]);
checkCatalogBoundary(fasCatalogCollection($db, $empty, 'recent', 1, 24) === ['products' => [], 'totalProducts' => 0], 'Empty catalog remains empty');
try {
    fasCatalogCollection($db, $empty, 'not-a-collection', 1, 24);
    throw new RuntimeException('Unknown collection did not throw');
} catch (InvalidArgumentException $expected) {
    checkCatalogBoundary(true, 'Unknown direct collection rejects');
}
echo "PASS $checks catalog request boundary assertions; seed 20261103, 240 generated requests, 173 synthetic products\n";
