<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Execute the actual source parser and isolated display fragments, never API calls/bootstrap.
$root = $argv[1] ?? dirname(__DIR__);
require_once $root.'/src/integrations/EbayAPI.php';
$parser = new ReflectionMethod(FAS\Integrations\EbayAPI::class, 'parseStoreCategories');
$parser->setAccessible(true);
$api = (new ReflectionClass(FAS\Integrations\EbayAPI::class))->newInstanceWithoutConstructor();
$flat = $parser->invoke($api, [
    ['CategoryID'=>'7836106013','Name'=>'MOTORCYCLE','ChildCategory'=>[
        ['CategoryID'=>'11','Name'=>'Honda','ChildCategory'=>[
            ['CategoryID'=>'111','Name'=>'XR'],['CategoryID'=>'112','Name'=>'Honda'],
            ['CategoryID'=>'113','Name'=>'MOTORCYCLE'],
        ]],
        ['CategoryID'=>'12','Name'=>'MOTORCYCLE'],
    ]],
    ['CategoryID'=>'2','Name'=>'Motorcycle Parts','ChildCategory'=>[
        ['CategoryID'=>'21','Name'=>'Motorcycle'],
    ]],
]);
$source = file_get_contents($root.'/includes/catalog-load.php');
$marker = '$currentCategoryName = \'All Products\';';
if (substr_count($source, $marker) !== 1) throw new RuntimeException('Unique display block required');
$display = substr($source, strpos($source, $marker));
$templates = [];
foreach (['products.php','api/products.php'] as $path) {
    $template = file_get_contents($root.'/'.$path);
    if (!preg_match('~<h1\b[^>]*>[\s\S]*?</h1>~', $template, $match)) throw new RuntimeException('Missing H1: '.$path);
    $templates[$path] = $match[0];
}
$base = ['ebayCat1'=>null,'ebayCat2'=>null,'ebayCat3'=>null,'homepageCategory'=>null,
    'discoveryCollection'=>'','search'=>'','manufacturer'=>'','fitmentModel'=>''];
$cases = [
    ['root', ['ebayCat1'=>'7836106013'], 'MOTORCYCLE'],
    ['second level', ['ebayCat1'=>'7836106013','ebayCat2'=>'11'], 'MOTORCYCLE > Honda'],
    ['third level', ['ebayCat1'=>'7836106013','ebayCat2'=>'11','ebayCat3'=>'111'], 'MOTORCYCLE > Honda > XR'],
    ['same label at distinct second level', ['ebayCat1'=>'7836106013','ebayCat2'=>'12'], 'MOTORCYCLE > MOTORCYCLE'],
    ['same label at distinct third level', ['ebayCat1'=>'7836106013','ebayCat2'=>'11','ebayCat3'=>'112'], 'MOTORCYCLE > Honda > Honda'],
    ['nonadjacent repeated label', ['ebayCat1'=>'7836106013','ebayCat2'=>'11','ebayCat3'=>'113'], 'MOTORCYCLE > Honda > MOTORCYCLE'],
    ['distinct nested wording', ['ebayCat1'=>'2','ebayCat2'=>'21'], 'Motorcycle Parts > Motorcycle'],
    ['no category', [], 'All Products'],
    ['unknown category', ['ebayCat1'=>'999'], 'Category'],
    ['legacy map lacks level', ['ebayCat1'=>'7836106013','ebayCat2'=>'11','legacy'=>true], 'MOTORCYCLE > Honda'],
    ['collection', ['discoveryCollection'=>'trending'], 'Trending Parts'],
    ['homepage category', ['homepageCategory'=>'motorcycle'], 'Motorcycle Parts'],
    ['search override', ['ebayCat1'=>'7836106013','search'=>'rotor <&>'], 'MOTORCYCLE', 'Search Results for "rotor <&>"'],
    ['manufacturer override', ['ebayCat1'=>'7836106013','manufacturer'=>'Fixture','fitmentModel'=>'XR'], 'MOTORCYCLE', 'Fixture XR Parts'],
];
require_once $root.'/src/models/HomepageCategoryMapping.php';
$checks = 0; $failures = [];
function headingCheck(bool $ok, string $message): void {
    global $checks, $failures; $checks++; if (!$ok) $failures[] = $message;
}
function categoryDisplay(string $display, array $fixture, array $flat): array {
    extract($fixture, EXTR_SKIP);
    $flatCategories = $flat;
    if (!empty($fixture['legacy'])) unset($flatCategories['11']['level']);
    $discoveryCollections = ['trending'=>'Trending Parts'];
    $totalProducts = 473; // Synthetic display count, never fetched from inventory.
    $before = [$ebayCat1,$ebayCat2,$ebayCat3,$homepageCategory,$discoveryCollection,
        $search,$manufacturer,$fitmentModel,$flatCategories,$totalProducts];
    eval($display);
    return [$currentCategoryName, $before === [$ebayCat1,$ebayCat2,$ebayCat3,$homepageCategory,
        $discoveryCollection,$search,$manufacturer,$fitmentModel,$flatCategories,$totalProducts]];
}
function renderHeading(string $fragment, string $currentCategoryName, array $fixture): string {
    extract($fixture, EXTR_SKIP);
    ob_start();
    try { eval('?>'.$fragment); return trim(html_entity_decode(strip_tags((string)ob_get_contents()), ENT_QUOTES, 'UTF-8')); }
    finally { ob_end_clean(); }
}
foreach ($cases as $case) {
    [$name,$changes,$expected] = $case;
    $fixture = array_replace($base, $changes);
    [$actual,$unchanged] = categoryDisplay($display,$fixture,$flat);
    headingCheck($actual === $expected, $name.': exact level-based category label');
    headingCheck($unchanged, $name.': display leaves source/filter/count inputs unchanged');
    foreach ($templates as $path=>$fragment) {
        headingCheck(renderHeading($fragment,$actual,$fixture) === ($case[3] ?? $expected), $name.': '.$path.' H1');
    }
}
foreach (['products.php','api/products.php'] as $path) {
    headingCheck(str_contains(file_get_contents($root.'/'.$path), "'/includes/catalog-load.php'")
        || str_contains(file_get_contents($root.'/'.$path), "'/../includes/catalog-load.php'"), $path.': shared loader remains wired');
}
echo json_encode(['cases'=>count($cases),'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT)."\n";
exit($failures ? 1 : 0);
