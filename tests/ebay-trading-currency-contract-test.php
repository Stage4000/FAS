<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
if(is_file($root.'/src/config/config.php'))throw new RuntimeException('Credential-free isolated source required.');
require_once $root.'/src/integrations/EbayAPI.php';
require_once $root.'/src/models/Product.php';
$checks=0;
function checkCurrency(bool $ok,string $message):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$reflection=new ReflectionClass(\FAS\Integrations\EbayAPI::class);
$api=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('userToken')->setValue($api,'SYNTHETIC-NOT-A-CREDENTIAL');
foreach(['buildGetSellerListRequest'=>[1,100,'2026-01-01','2026-01-02'],
    'buildGetSellerEventsRequest'=>[new DateTime('2026-01-01'),new DateTime('2026-01-02'),1,100]] as $method=>$args){
    $xml=$reflection->getMethod($method)->invokeArgs($api,$args);
    checkCurrency(substr_count($xml,'<OutputSelector>Currency</OutputSelector>')===1,$method.' must explicitly request Item.Currency.');
}
$parser=$reflection->getMethod('parseTradingApiResponse');
foreach(['USD','EUR',null] as $currency){
    $raw=['ItemID'=>'QA-1','Title'=>'Synthetic product','Description'=>'Fixture description','Quantity'=>7,
        'SellingStatus'=>['CurrentPrice'=>'110.00','ListingStatus'=>'Active']];
    if($currency!==null)$raw['Currency']=$currency;
    $parsed=$parser->invoke($api,['ItemArray'=>['Item'=>$raw]],1,100);
    checkCurrency(count($parsed['items'])===1,'Active item survives parsing.');
    $item=$parsed['items'][0];
    checkCurrency($item['currency']===$currency,'Parser must preserve actual currency without defaulting USD.');
    $item+=['weight'=>1,'length'=>10,'width'=>10,'height'=>10];
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec(file_get_contents($root.'/tests/fixtures/security-catalog.sql'));
    $db->exec("UPDATE products SET ebay_item_id='QA-1',source='ebay' WHERE id=1");
    \FAS\Utils\ProductSyncOwnership::install($db);\FAS\Utils\WebsitePrice::install($db);
    $model=new \FAS\Models\Product($db);
    $before=$db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
    $error=null;try{$model->syncFromEbay($item);}catch(DomainException $e){$error=$e;}
    $after=$db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
    $observations=$db->query('SELECT * FROM website_price_source')->fetchAll(PDO::FETCH_ASSOC);
    if($currency==='USD'){
        checkCurrency($error===null&&count($observations)===1&&(int)$observations[0]['amount_cents']===11000,'USD parsed sync must record actual price.');
    }else{
        checkCurrency($error instanceof DomainException,'Missing/non-USD parsed sync must fail closed.');
        checkCurrency($before===$after&&$observations===[],'Rejected parsed sync must preserve inventory and observations.');
    }
}
echo "PASS {$checks} Trading currency contract assertions; no constructor, credentials, network or providers.\n";
