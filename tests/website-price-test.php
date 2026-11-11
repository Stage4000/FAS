<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// No singleton, credentials, API instance, HTTP client or application config is used.
if (is_file(__DIR__.'/../src/config/config.php')) throw new RuntimeException('Run only in a credential-free isolated copy.');
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/utils/WebsitePrice.php';
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../src/payments/CheckoutPricing.php';
use FAS\Models\Product;
use FAS\Utils\{WebsitePrice,ProductSyncOwnership,MerchantFeedBuilder,Seo};
use FAS\Payments\{CheckoutPricing,CheckoutProblem};
$checks=0;
function verify(bool $ok,string $why):void { global $checks; $checks++; if (!$ok) throw new RuntimeException($why); }
function rejects(callable $fn,string $why):void { try { $fn(); } catch (DomainException|PDOException $e) { verify(true,$why); return; } throw new RuntimeException('Expected rejection: '.$why); }
$path = tempnam(sys_get_temp_dir(),'fas-price-');
try {
    $db = new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
    $db->exec("UPDATE products SET ebay_item_id='QA-1',source='ebay',sale_price=80 WHERE id=1");
    $db->exec("INSERT INTO orders(id,order_number,subtotal,total_amount) VALUES(1,'SYNTHETIC',75,84)");
    $db->exec("INSERT INTO order_items(id,order_id,product_id,unit_price,total_price) VALUES(1,1,1,75,75)");
    $before=$db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
    $orders=$db->query('SELECT * FROM orders')->fetchAll(PDO::FETCH_ASSOC);
    $orderItems=$db->query('SELECT * FROM order_items')->fetchAll(PDO::FETCH_ASSOC);
    WebsitePrice::install($db); WebsitePrice::install($db);
    $after=$db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
    unset($after[0]['website_override_cents']);
    verify($after===$before,'Idempotent migration preserves every baseline product value');
    verify((int)$db->query('SELECT COUNT(*) FROM website_price_source')->fetchColumn()===0,'Migration does not infer a source or override');
    ProductSyncOwnership::install($db);
    $products=new Product($db);
    $sync=static function(string $price,int $quantity=7,string $currency='USD')use($products):void {
        $products->syncFromEbay(['id'=>'QA-1','title'=>'eBay replacement title','description'=>'eBay replacement description','price'=>$price,'currency'=>$currency,'quantity'=>$quantity,'images'=>[], 'weight'=>1,'length'=>10,'width'=>10,'height'=>10]);
    };
    $hash=WebsitePrice::snapshot($db,1);
    rejects(fn()=>WebsitePrice::save($db,1,$hash,1,'override','60.00','USD'),'Cannot assume source before sync');
    $sync('110.00');
    $p=$products->getById(1);
    verify((float)$p['price']===110.0 && (int)$p['quantity']===7,'Unowned price and quantity follow source');
    verify($p['name']===$before[0]['name'] && $p['description']===$before[0]['description'],'Released text retention survives sync');
    WebsitePrice::save($db,1,WebsitePrice::snapshot($db,1),17,'override','60.25','USD');
    $p=$products->getById(1);
    verify((int)$p['website_override_cents']===6025 && (float)$p['price']===60.25,'Explicit override materializes website base price');
    verify(getProductEffectivePrice($p)['effective_price']===60.25 && !getProductEffectivePrice($p)['on_sale'],'Override does not infer a sale or apply retained individual discount');
    $sync('120.00',8); $sync('120.00',9); $sync('130.00',10);
    $p=$products->getById(1);
    verify((float)$p['price']===60.25 && (int)$p['quantity']===10,'Repeated source changes retain override and sync quantity');
    verify((int)WebsitePrice::source($db,1)['amount_cents']===13000,'Latest source remains separate');
    verify((int)WebsitePrice::source($db,1)['revision']===4,'Repeated syncs carry a source revision');
    $products->applySalePrices([1=>30]);
    verify((float)$products->getById(1)['sale_price']===80.0,'Bulk markdown excludes explicit override');
    rejects(fn()=>$products->update(1,['price'=>5]),'Legacy update cannot bypass explicit controls');
    $feed=(new MerchantFeedBuilder($products))->buildItem($p);
    verify($feed['price']==='60.25 USD' && $feed['sale_price']===null,'Feed agrees without misleading sale');
    $schema=Seo::productSchema($p,[],getProductEffectivePrice($p),'https://example.invalid/product','Synthetic');
    verify($schema['offers']['price']==='60.25','Structured data agrees');
    $input=['items'=>[['product_id'=>1,'quantity'=>1,'unit_price'=>60.25]],'subtotal'=>60.25,'tax_amount'=>0,'discount_amount'=>0,'total_amount'=>69.25];
    verify(CheckoutPricing::calculate($input,[1=>1],$products,900)['total_amount']===69.25,'Checkout agrees');
    $oldInput=$input; $oldInput['items'][0]['unit_price']=100;
    try { CheckoutPricing::calculate($oldInput,[1=>1],$products,900); throw new RuntimeException('Stale checkout accepted'); }
    catch(CheckoutProblem $e) { verify($e->reason==='total_changed','Stale cart must refresh before payment'); }
    $old=WebsitePrice::snapshot($db,1); $sync('140.00');
    rejects(fn()=>WebsitePrice::save($db,1,$old,17,'source',null,'USD'),'Sync invalidates stale save');
    foreach (['0','-1','1.001','1e2','NaN','INF','1,000',' 10','01.00','1000000',[],1.23] as $bad) {
        rejects(fn()=>WebsitePrice::save($db,1,WebsitePrice::snapshot($db,1),17,'override',$bad,'USD'),'Invalid amount');
    }
    foreach (['EUR','usd','',null,[]] as $badCurrency) rejects(fn()=>WebsitePrice::save($db,1,WebsitePrice::snapshot($db,1),17,'override','10',$badCurrency),'Invalid currency');
    rejects(fn()=>WebsitePrice::save($db,1,WebsitePrice::snapshot($db,1),0,'override','10','USD'),'Missing authorized actor');
    $q=$db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
    rejects(fn()=>$sync('7.123'),'Invalid source precision');
    rejects(fn()=>$sync('7',100,'EUR'),'Invalid source currency');
    verify($db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC)===$q,'Invalid source update rolls back completely');
    // Independent connections simulate two stale editor tabs; the writer lock is also exercised.
    $other=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('PRAGMA busy_timeout=0');
    $shared=WebsitePrice::snapshot($other,1);
    WebsitePrice::save($db,1,$shared,18,'override','65','USD');
    rejects(fn()=>WebsitePrice::save($other,1,$shared,19,'override','66','USD'),'Second connection rejects stale editor');
    $db->beginTransaction(); $db->exec('UPDATE website_price_meta SET version=version WHERE id=1');
    rejects(fn()=>WebsitePrice::save($other,1,WebsitePrice::snapshot($other,1),19,'override','66','USD'),'Concurrent writer cannot overwrite');
    $db->rollBack();
    $same=WebsitePrice::snapshot($db,1);
    WebsitePrice::save($db,1,$same,18,'override','65','USD');
    rejects(fn()=>WebsitePrice::save($other,1,$same,19,'override','66','USD'),'Repeated identical save advances audit snapshot');
    WebsitePrice::save($db,1,WebsitePrice::snapshot($db,1),18,'source',null,'USD');
    $p=$products->getById(1);
    verify($p['website_override_cents']===null && (float)$p['price']===140.0,'Return applies latest received base price immediately');
    verify(getProductEffectivePrice($p)['effective_price']===80.0,'Return restores unchanged existing sale rules');
    $sync('150.00');verify((float)$products->getById(1)['price']===150.0,'Later unowned sync follows eBay normally');
    verify($db->query('SELECT * FROM orders')->fetchAll(PDO::FETCH_ASSOC)===$orders && $db->query('SELECT * FROM order_items')->fetchAll(PDO::FETCH_ASSOC)===$orderItems,'Existing order totals and item prices never change');
    $history=$db->query('SELECT * FROM website_price_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    verify(count($history)===4 && (int)$history[0]['actor_id']===17 && json_decode($history[0]['after_json'],true)['override_cents']===6025,'Successful explicit edits audited with actor and both price states; rejects add nothing');
    // Text save/release remains the existing helper, independent of price ownership.
    $text=$products->getById(1);$textHash=ProductSyncOwnership::snapshot($db,$text);
    ProductSyncOwnership::save($db,1,['name'=>'Website revised title'],$textHash,17,fn($id,$data)=>$products->update($id,$data));
    $sync('160.00');verify($products->getById(1)['name']==='Website revised title','Existing manual text save remains protected');
    $text=$products->getById(1);
    ProductSyncOwnership::save($db,1,[],ProductSyncOwnership::snapshot($db,$text),17,fn($id,$data)=>$products->update($id,$data),['name']);
    $sync('170.00');verify($products->getById(1)['name']==='eBay replacement title','Explicit text return still applies next sync');
    // Source metadata must roll back if the materialized inventory write fails.
    $sourceBefore=WebsitePrice::source($db,1);
    $db->exec("CREATE TRIGGER qa_price_failure BEFORE UPDATE ON products BEGIN SELECT RAISE(ABORT,'synthetic write failure'); END");
    rejects(fn()=>$sync('180.00'),'Inventory failure aborts source update');
    verify(WebsitePrice::source($db,1)===$sourceBefore && (float)$products->getById(1)['price']===170.0,'Source observation and product write roll back together');
    $db->exec('DROP TRIGGER qa_price_failure');
    $products->syncFromEbay(['id'=>'QA-2','title'=>'New synthetic item','description'=>'Fixture','price'=>'20.10','currency'=>'USD','quantity'=>2,'weight'=>1,'length'=>2,'width'=>3,'height'=>4]);
    $new=$products->getByEbayId('QA-2');
    verify($new['website_override_cents']===null && (int)WebsitePrice::source($db,(int)$new['id'])['amount_cents']===2010,'New source imports record observations without inferring overrides');
    verify(WebsitePrice::cents('0.01','USD')===1 && WebsitePrice::cents('999999.99','USD')===99999999,'Exact allowed amount boundaries');
    // Disposable backup/restore integrity, not a production application rollback claim.
    $snapshot=$products->getById(1); $restored=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("VACUUM INTO '".str_replace("'","''",$path.'.backup')."'");
    $backup=new PDO('sqlite:'.$path.'.backup',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    verify($backup->query('PRAGMA integrity_check')->fetchColumn()==='ok','Disposable backup integrity verified');
    verify($backup->query('SELECT price FROM products WHERE id=1')->fetchColumn()===$snapshot['price'],'Backup retains current baseline price');
    echo "PASS $checks website price assertions; isolated SQLite, no network or production data.\n";
} finally {
    unset($sync,$backup,$other,$products,$db,$restored);
    foreach ([$path,$path.'.backup'] as $file) if (is_file($file)) unlink($file);
}
