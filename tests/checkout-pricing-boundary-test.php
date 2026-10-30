<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../src/payments/CheckoutPricing.php';
use FAS\Payments\{CheckoutPricing,CheckoutProblem};
// Override inventory lookup only; constructor/database/coupon paths never run.
class BoundaryInventory extends FAS\Models\Product {
    public array $rows;
    public function __construct(array $rows) { $this->rows=$rows; }
    public function getById($id, $includeInactive=false) { return $this->rows[$id] ?? null; }
    public function getDb() { throw new RuntimeException('Database access forbidden in boundary cohort'); }
}
$checks=0; $failures=[]; $failureCount=0;
function pricingBoundaryCheck(bool $ok,string $name): void {
    global $checks,$failures,$failureCount; $checks++;
    if (!$ok) { $failureCount++; if(count($failures)<12)$failures[]=$name; }
}
function pricingBoundaryReject(array $input,array $cart,BoundaryInventory $inventory,int $shipping,string $reason,string $name): void {
    try { CheckoutPricing::calculate($input,$cart,$inventory,$shipping); pricingBoundaryCheck(false,$name.': accepted'); }
    catch(CheckoutProblem $e) { pricingBoundaryCheck($e->reason===$reason,$name.': '.$e->reason); }
}
// Fixed arithmetic PRNG, independent integer-cent oracle, reproducible seed.
$seed=20261030;
function pricingBoundaryRandom(int $max): int {
    global $seed; $seed=($seed*48271)%2147483647; return $seed%$max;
}
for($case=0;$case<400;$case++) {
    $rows=[]; $cart=[]; $items=[]; $subtotal=0;
    $count=1+pricingBoundaryRandom(5);
    for($i=1;$i<=$count;$i++) {
        $base=2+pricingBoundaryRandom(100000); $sale=1+pricingBoundaryRandom($base-1);
        $useSale=pricingBoundaryRandom(2)===1; $unit=$useSale?$sale:$base;
        $qty=1+pricingBoundaryRandom(9); $cart[$i]=$qty; $subtotal+=$qty*$unit;
        $rows[$i]=['id'=>$i,'name'=>'Synthetic '.$i,'sku'=>'FIXTURE-'.$i,'price'=>$base/100,
            'sale_price'=>$useSale?$sale/100:null,'is_active'=>1,'show_on_website'=>1,'quantity'=>$qty];
        $items[]=['product_id'=>(string)$i,'quantity'=>(string)$qty,'unit_price'=>$unit/100,
            'product_name'=>'FORGED','product_sku'=>'FORGED'];
    }
    $shipping=pricingBoundaryRandom(20000); $total=$subtotal+$shipping;
    $input=['items'=>array_reverse($items),'subtotal'=>$subtotal/100,'total_amount'=>$total/100,
        'tax_amount'=>0,'discount_amount'=>0,'discount_code'=>null,'shipping_cost'=>-9999];
    $inventory=new BoundaryInventory($rows); $before=[$input,$cart,$rows];
    $result=CheckoutPricing::calculate($input,$cart,$inventory,$shipping);
    pricingBoundaryCheck((int)round($result['subtotal']*100)===$subtotal,'subtotal oracle');
    pricingBoundaryCheck((int)round($result['total_amount']*100)===$total,'total oracle');
    pricingBoundaryCheck((int)round($result['shipping_cost']*100)===$shipping,'server shipping authoritative');
    pricingBoundaryCheck([$input,$cart,$inventory->rows]===$before,'input and inventory preserved');
    pricingBoundaryCheck(count($result['items'])===count($cart),'all order lines retained');
    foreach($result['items'] as $row) {
        pricingBoundaryCheck($row['product_name']===$rows[$row['product_id']]['name'] && $row['product_sku']===$rows[$row['product_id']]['sku'],'metadata from catalog');
        $expected=$items[$row['product_id']-1];
        pricingBoundaryCheck($row['quantity']===(int)$expected['quantity'],'line quantity from cart');
        pricingBoundaryCheck((int)round($row['unit_price']*100)===(int)round($expected['unit_price']*100),'line unit from catalog');
        pricingBoundaryCheck((int)round($row['total_price']*100)===(int)round($expected['unit_price']*100)*(int)$expected['quantity'],'line total oracle');
    }
    foreach(['subtotal','total_amount','tax_amount','discount_amount'] as $field) {
        $bad=$input; $bad[$field]+=0.01;
        pricingBoundaryReject($bad,$cart,$inventory,$shipping,'total_changed','tampered '.$field);
    }
    $bad=$input; $bad['items'][0]['unit_price']+=0.01;
    pricingBoundaryReject($bad,$cart,$inventory,$shipping,'total_changed','tampered unit price');
    $bad=$input; $bad['items'][0]['quantity']='0';
    pricingBoundaryReject($bad,$cart,$inventory,$shipping,'invalid_cart','zero quantity');
    $bad=$input; $bad['items'][]=$bad['items'][0];
    pricingBoundaryReject($bad,$cart,$inventory,$shipping,'invalid_cart','duplicate product');
    pricingBoundaryReject($input,$cart,$inventory,-1,'invalid_shipping','negative server shipping');
    foreach(['is_active','show_on_website','quantity'] as $field) {
        $inventory->rows[1][$field]=0;
        pricingBoundaryReject($input,$cart,$inventory,$shipping,'unavailable','inventory '.$field);
        $inventory->rows=$rows;
    }
    $differentCart=$cart; $differentCart[1]++;
    pricingBoundaryReject($input,$differentCart,$inventory,$shipping,'shipping_changed','stale cart');
}
echo json_encode(['seed'=>20261030,'carts'=>400,'checks'=>$checks,'failureCount'=>$failureCount,'failures'=>$failures],JSON_PRETTY_PRINT)."\n";
exit($failureCount?1:0);
