<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/payments/CheckoutPricing.php';
use FAS\Models\Product;
use FAS\Payments\CheckoutPricing;
use FAS\Payments\CheckoutProblem;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
$db->exec('CREATE TABLE coupons(code TEXT PRIMARY KEY,discount_type TEXT,discount_value REAL,
    minimum_purchase REAL,max_uses INTEGER,times_used INTEGER,expires_at TEXT,is_active INTEGER)');
$products=new Product($db);
$input=['items'=>[['product_id'=>1,'quantity'=>1,'unit_price'=>100,'product_name'=>'Forged']],
    'subtotal'=>100,'shipping_cost'=>9,'tax_amount'=>0,'discount_code'=>null,'discount_amount'=>0,'total_amount'=>109];
$checks=0;
function checkPricing(bool $ok,string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function rejectPricing(array $input,array $cart,Product $products,string $reason): void {
    try { CheckoutPricing::calculate($input,$cart,$products,900); }
    catch (CheckoutProblem $e) { checkPricing($e->reason===$reason,"Expected $reason, got {$e->reason}"); return; }
    throw new RuntimeException('Expected checkout rejection: '.$reason);
}
$priced=CheckoutPricing::calculate($input,[1=>1],$products,900);
checkPricing((float)$priced['subtotal']===100.0 && (float)$priced['total_amount']===109.0,'Catalog total is authoritative');
checkPricing($priced['items'][0]['product_name']==='Synthetic test part' && $priced['items'][0]['product_sku']==='TEST-1',
    'Catalog name and SKU replace submitted content');
$bad=$input; $bad['items'][0]['unit_price']=0.01; rejectPricing($bad,[1=>1],$products,'total_changed');
$bad=$input; $bad['subtotal']=1; rejectPricing($bad,[1=>1],$products,'total_changed');
$bad=$input; $bad['total_amount']=1; rejectPricing($bad,[1=>1],$products,'total_changed');
$bad=$input; $bad['tax_amount']=1; rejectPricing($bad,[1=>1],$products,'total_changed');
$bad=$input; $bad['items'][]=$bad['items'][0]; rejectPricing($bad,[1=>2],$products,'invalid_cart');
$db->exec('UPDATE products SET sale_price=75 WHERE id=1');
rejectPricing($input,[1=>1],$products,'total_changed');
$sale=$input; $sale['items'][0]['unit_price']=75; $sale['subtotal']=75; $sale['total_amount']=84;
checkPricing((float)CheckoutPricing::calculate($sale,[1=>1],$products,900)['total_amount']===84.0,
    'Active sale price is used');
$db->exec('UPDATE products SET sale_price=NULL,quantity=0 WHERE id=1');
rejectPricing($input,[1=>1],$products,'unavailable');
$db->exec('UPDATE products SET quantity=1,show_on_website=0 WHERE id=1');
rejectPricing($input,[1=>1],$products,'unavailable');
$db->exec('UPDATE products SET show_on_website=1 WHERE id=1');
$db->exec("INSERT INTO coupons VALUES('SAVE10','fixed',10,50,NULL,0,NULL,1)");
$coupon=$input; $coupon['discount_code']='save10'; $coupon['discount_amount']=10; $coupon['total_amount']=99;
$priced=CheckoutPricing::calculate($coupon,[1=>1],$products,900);
checkPricing($priced['discount_code']==='SAVE10' && (float)$priced['discount_amount']===10.0 && (float)$priced['total_amount']===99.0,
    'Coupon is validated and repriced from records');
$bad=$coupon; $bad['discount_amount']=20; rejectPricing($bad,[1=>1],$products,'total_changed');
$db->exec("UPDATE coupons SET minimum_purchase=150 WHERE code='SAVE10'");
rejectPricing($coupon,[1=>1],$products,'invalid_coupon');
echo "PASS $checks server-priced checkout assertions; synthetic inventory only.\n";
