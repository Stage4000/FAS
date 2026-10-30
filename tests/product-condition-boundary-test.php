<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/ProductCondition.php';
use FAS\Utils\ProductCondition;
$checks = 0; $cases = 0; $failures = []; $failureCount = 0;
function conditionBoundaryCheck(bool $ok, string $name): void {
    global $checks, $failures, $failureCount;
    $checks++;
    if (!$ok) $failureCount++;
    if (!$ok && count($failures) < 12) $failures[] = $name;
}
function conditionBoundaryCase($input, string $merchant, string $schema, string $identifier, string $mpn): void {
    global $cases;
    $cases++;
    $before = $input;
    foreach (['merchant'=>$merchant, 'schema'=>$schema, 'identifier'=>$identifier, 'merchantMpn'=>$mpn] as $method=>$expected) {
        try { conditionBoundaryCheck(ProductCondition::$method($input) === $expected, "$cases:$method output"); }
        catch (Throwable $e) { conditionBoundaryCheck(false, "$cases:$method ".get_class($e)); }
    }
    conditionBoundaryCheck($input === $before, "$cases:input preserved");
}
// Deterministic malformed encodings, not random data or customer records.
$invalid = ["\xc0\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x82", "\xf0\x9f\x92"];
for ($byte=128; $byte<=255; $byte++) $invalid[] = chr($byte);
foreach ($invalid as $bytes) {
    foreach (['new','refurbished','for parts','AB-123','n/a'] as $prefix) {
        foreach ([$bytes.$prefix, $prefix.$bytes, substr($prefix,0,1).$bytes.substr($prefix,1)] as $input) {
            conditionBoundaryCase($input, 'used', 'https://schema.org/UsedCondition', $input, '');
        }
    }
}
foreach (['new'=>'NewCondition','refurbished'=>'RefurbishedCondition','used'=>'UsedCondition','for parts'=>'DamagedCondition'] as $input=>$schema) {
    conditionBoundaryCase($input, $input==='for parts'?'used':$input, 'https://schema.org/'.$schema, $input, $input);
}
foreach (['AB-123','é','部品-42',str_repeat('é',70),str_repeat('X',70)] as $input) {
    conditionBoundaryCase($input, 'used', 'https://schema.org/UsedCondition', $input, $input);
}
foreach ([str_repeat('é',71),str_repeat('X',71)] as $input) {
    conditionBoundaryCase($input, 'used', 'https://schema.org/UsedCondition', $input, '');
}
foreach (['','n/a','Does Not Apply','--'] as $input) {
    conditionBoundaryCase($input, 'used', 'https://schema.org/UsedCondition', '', '');
}
foreach ([null,[],new stdClass(),false] as $input) {
    conditionBoundaryCase($input, 'used', 'https://schema.org/UsedCondition', '', '');
}
conditionBoundaryCase(' <b>NEW</b> ', 'new', 'https://schema.org/NewCondition', 'NEW', 'NEW');
conditionBoundaryCase("new\twith\nbox", 'new', 'https://schema.org/NewCondition', "new\twith\nbox", "new\twith\nbox");
echo json_encode(['cases'=>$cases,'checks'=>$checks,'failureCount'=>$failureCount,'failures'=>$failures],JSON_PRETTY_PRINT)."\n";
exit($failures ? 1 : 0);
