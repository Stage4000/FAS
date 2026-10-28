<?php
declare(strict_types=1);

// Render only each actual search-form fragment; no application bootstrap or HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$checks = 0;
$failures = [];
function check(bool $ok, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) $failures[] = $message;
}
function renderForm(string $fragment, array $fixture): string
{
    extract($fixture, EXTR_SKIP);
    ob_start();
    try { eval('?>'.$fragment); return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
$base = ['homepageCategory'=>'','ebayCat1'=>'','ebayCat2'=>'','ebayCat3'=>'',
    'manufacturer'=>'','fitmentModel'=>'','includeHiddenProducts'=>false,'search'=>''];
$fixtures = [
    'catalog empty'=>$base,
    'catalog query'=>array_replace($base, ['search'=>'rotor " < > &']),
    'category empty'=>array_replace($base, ['homepageCategory'=>'motorcycle']),
    'category query and filters'=>array_replace($base, ['homepageCategory'=>'motorcycle',
        'search'=>'fixture rotor','ebayCat1'=>'1','ebayCat2'=>'11','ebayCat3'=>'111',
        'manufacturer'=>'Fixture Make','fitmentModel'=>'Fixture Model']),
];
$scenarios = 0;
foreach (['products.php', 'api/products.php'] as $path) {
    $source = file_get_contents($root.'/'.$path);
    if ($source === false || !preg_match('~<form\b[^>]*\bid="search-form"[\s\S]*?</form>~', $source, $match)) {
        throw new RuntimeException('Actual search form missing: '.$path);
    }
    foreach ($fixtures as $name=>$fixture) {
        $label = $path.' / '.$name;
        $html = renderForm($match[0], $fixture);
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<!doctype html><html><body>'.$html.'</body></html>'); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new DOMXPath($doc);
        $inputs = $xpath->query('//input[@id="product-search"]');
        $labels = $xpath->query('//label[@for="product-search"]');
        check($inputs->length === 1, $label.': unique search input');
        check($labels->length === 1, $label.': persistent explicit label');
        $input = $inputs->item(0);
        $associatedLabel = $labels->item(0);
        check($associatedLabel !== null && trim($associatedLabel->textContent) === 'Search products', $label.': meaningful label text');
        check($associatedLabel !== null && in_array('visually-hidden', explode(' ', $associatedLabel->getAttribute('class')), true), $label.': existing layout-preserving class');
        check($associatedLabel !== null && !$associatedLabel->hasAttribute('hidden') && $associatedLabel->getAttribute('aria-hidden') !== 'true', $label.': no explicit label hiding attributes');
        check($input !== null && $input->getAttribute('name') === 'search' && $input->getAttribute('type') === 'text', $label.': search identity preserved');
        check($input !== null && $input->getAttribute('value') === $fixture['search'], $label.': escaped query round trip');
        check($input !== null && $input->getAttribute('placeholder') === 'Search products...', $label.': placeholder preserved');
        check($input !== null && !$input->hasAttribute('aria-label') && !$input->hasAttribute('aria-labelledby'), $label.': no competing explicit accessible name');
        $form = $xpath->query('//form[@id="search-form"]')->item(0);
        check($form !== null && $form->getAttribute('method') === 'get' && $form->getAttribute('action') === '/products', $label.': submission unchanged');
        check($xpath->query('//button[@type="submit"]')->length === 1, $label.': submit preserved');
        check($xpath->query('//button[@type="button" and contains(@class,"saved-search-save")]')->length === 1, $label.': Save control preserved');
        check($xpath->query('//a[@title="Clear search"]')->length === ($fixture['search'] === '' ? 0 : 1), $label.': conditional Clear preserved');
        foreach (['category'=>'homepageCategory','cat1'=>'ebayCat1','cat2'=>'ebayCat2','cat3'=>'ebayCat3','manufacturer'=>'manufacturer','model'=>'fitmentModel'] as $field=>$variable) {
            $nodes = $xpath->query('//input[@type="hidden" and @name="'.$field.'"]');
            check($nodes->length === ($fixture[$variable] === '' ? 0 : 1), $label.': '.$field.' filter presence');
            if ($fixture[$variable] !== '') check($nodes->item(0)->getAttribute('value') === $fixture[$variable], $label.': '.$field.' filter preserved');
        }
        $scenarios++;
    }
}
echo json_encode(['scenarios'=>$scenarios,'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT)."\n";
exit($failures ? 1 : 0);
