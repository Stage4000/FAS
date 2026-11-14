<?php
declare(strict_types=1);
// Pure helper regression. Explicit settings only: no configuration, DB or transport access.
require getenv('FAS_SHIPPING_RULES_SOURCE') ?: __DIR__ . '/../src/utils/ShippingRules.php';
use FAS\Utils\ShippingRules;
$checks = 0; $failures = [];
function destinationSame($expected, $actual, string $label): void {
    global $checks, $failures;
    ++$checks;
    if ($expected !== $actual) $failures[] = $label;
}
function destinationEligible(array $address, string $label): bool {
    try { return ShippingRules::isContinentalUsAddress($address); }
    catch (Throwable $e) { global $failures; $failures[] = $label . ' threw ' . get_class($e); return false; }
}
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$states = [
 'AL'=>'Alabama','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado',
 'CT'=>'Connecticut','DE'=>'Delaware','DC'=>'District of Columbia','FL'=>'Florida','GA'=>'Georgia',
 'ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky',
 'LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan',
 'MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska',
 'NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York',
 'NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon',
 'PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota',
 'TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington',
 'WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
];
$manual = ['enabled'=>true,'product_flags_enabled'=>true,'auto_rules_enabled'=>false];
$automatic = ShippingRules::normalizeFreeShippingSettings(['enabled'=>true,'product_flags_enabled'=>false,'auto_rules_enabled'=>true,'max_weight'=>5]);
foreach ($states as $code=>$name) {
    foreach ([$code, strtolower($code), $name, ' '.strtolower($name).' ', str_replace(' ', '-', $name)] as $variant) {
        $address = ['country'=>'US','state'=>$variant];
        destinationSame(true, destinationEligible($address, $variant), "$variant recognized");
        destinationSame('manual', ShippingRules::getFreeShippingReason(['free_shipping'=>1],$manual,$address), "$variant manual");
        destinationSame('size_weight', ShippingRules::getFreeShippingReason(['weight'=>5],$automatic,$address), "$variant automatic boundary");
        destinationSame('', ShippingRules::getFreeShippingReason(['weight'=>5.01],$automatic,$address), "$variant overweight unchanged");
    }
    destinationSame(true, destinationEligible(['country'=>'US','province'=>$code],$code), "$code province fallback");
}
foreach (['US','us','USA','U.S.','U.S.A.','United States','United States of America',' united-states-of-america '] as $country) {
    foreach (['OR','District of Columbia'] as $state) {
        destinationSame(true, destinationEligible(compact('country','state'),$country), "$country supported country alias");
    }
}
$excluded = ['AK','Alaska','HI','Hawaii','AS','American Samoa','GU','Guam','MP','Northern Mariana Islands',
 'PR','Puerto Rico','VI','Virgin Islands','US Virgin Islands','U.S. Virgin Islands','UM','US Minor Outlying Islands',
 'FM','Federated States of Micronesia','MH','Marshall Islands','PW','Palau',
 'AA','AE','AP','Armed Forces Americas','Armed Forces Europe','Armed Forces Pacific',
 'Armed Forces Europe, the Middle East, and Canada','ZZ','Ontario','British Columbia','Unknown','California USA','', ' '];
foreach ($excluded as $state) {
    foreach ([$state,strtolower($state)] as $variant) {
        $address=['country'=>'US','state'=>$variant];
        destinationSame(false,destinationEligible($address,$variant),"$variant excluded");
        destinationSame('',ShippingRules::getFreeShippingReason(['free_shipping'=>1],$manual,$address),"$variant no manual grant");
        destinationSame('',ShippingRules::getFreeShippingReason(['weight'=>1],$automatic,$address),"$variant no automatic grant");
    }
}
foreach (['CA','Canada','MX','United Kingdom','US1','U/S','U_S','U$S',"US\n","US\0",'ＵＳ', '', ' '] as $country) {
    destinationSame(false,destinationEligible(['country'=>$country,'state'=>'OR'],'country'), 'malformed/foreign country rejected');
}
foreach (['OR1','O/R','O_R','O$R',"OR\n","OR\0",'ＯＲ','0R','OR!','<OR>',str_repeat(' ',129).'OR'] as $state) {
    destinationSame(false,destinationEligible(['country'=>'US','state'=>$state],'state'), 'malformed state rejected');
}
foreach ([null,true,false,0,1,1.5,[],['OR'],(object)['state'=>'OR'],new class { public function __toString(): string { return 'OR'; }}] as $value) {
    foreach (['country','state'] as $field) {
        $address=['country'=>'US','state'=>'OR']; $address[$field]=$value;
        destinationSame(false,destinationEligible($address,$field),'non-string '.$field.' rejected without coercion');
    }
}
foreach ([[],['state'=>'OR'],['country'=>'US'],['country'=>'US','state'=>'','province'=>'OR'],['country'=>'US','state'=>'ZZ','province'=>'OR']] as $address) {
    destinationSame(false,destinationEligible($address,'missing/conflicting'),'missing/conflicting location is not eligible');
}
destinationSame(true,destinationEligible(['country'=>'US','state'=>null,'province'=>'OR'],'null fallback'),'existing null-state province fallback');
destinationSame('manual',ShippingRules::getFreeShippingReason(['free_shipping'=>1],$manual),'addressless catalog manual preserved');
destinationSame('size_weight',ShippingRules::getFreeShippingReason(['weight'=>1],$automatic),'addressless catalog automatic preserved');
destinationSame('',ShippingRules::getFreeShippingReason(['free_shipping'=>1],array_replace($manual,['enabled'=>false]),['country'=>'US','state'=>'OR']),'master disabled preserved');
destinationSame(false,ShippingRules::productQualifiesForFreeShipping(['free_shipping'=>1],$manual,['country'=>'US','state'=>'PW']),'boolean wrapper excludes unsupported destination');
destinationSame(true,ShippingRules::productQualifiesForFreeShipping(['free_shipping'=>1],$manual,['country'=>'US','state'=>'OR']),'boolean wrapper retains eligible destination');
$rate=ShippingRules::freeShippingRate(['item_count'=>3]);
destinationSame(0.0,$rate['total_charge'],'free rate value unchanged');
destinationSame(3,$rate['free_shipping_item_count'],'free rate quantity unchanged');
restore_error_handler();
echo json_encode(['checks'=>$checks,'failures'=>count($failures),'failed'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures ? 1 : 0);
