<?php
declare(strict_types=1);
namespace FAS\Payments;

require_once __DIR__.'/ApplePayContext.php';

/** Reprices the classic checkout from inventory and coupon records. */
final class CheckoutPricing
{
    public static function calculate(array $input, array $cart, \FAS\Models\Product $products, int $shippingCents): array
    {
        if (!is_array($input['items'] ?? null) || !$input['items'] || count($input['items']) > 100) {
            throw new CheckoutProblem('invalid_cart', 'Review your cart and try again.', 400);
        }
        if ($shippingCents < 0) {
            throw new CheckoutProblem('invalid_shipping', 'Calculate shipping again.', 400);
        }
        $submitted=[];
        foreach ($input['items'] as $item) {
            if (!is_array($item)) throw new CheckoutProblem('invalid_cart', 'Review your cart and try again.', 400);
            $id=$item['product_id'] ?? null;
            $quantity=$item['quantity'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,8}$/D',(string)$id)
                || (!is_int($quantity) && !is_string($quantity))
                || !preg_match('/^[1-9][0-9]{0,2}$/D',(string)$quantity)) {
                throw new CheckoutProblem('invalid_cart', 'Review your cart and try again.', 400);
            }
            $id=(int)$id;
            if (isset($submitted[$id])) throw new CheckoutProblem('invalid_cart', 'Review your cart and try again.', 400);
            $submitted[$id]=['quantity'=>(int)$quantity,'unit_price'=>$item['unit_price'] ?? null];
        }
        ksort($submitted,SORT_NUMERIC);
        if (array_map(static fn($item)=>$item['quantity'],$submitted)!==$cart) {
            throw new CheckoutProblem('shipping_changed', 'Your cart changed. Calculate shipping again.');
        }
        $subtotal=0;
        $orderItems=[];
        foreach ($submitted as $id=>$item) {
            $product=$products->getById($id,true);
            if (!$product || empty($product['is_active']) || empty($product['show_on_website'])
                || (int)$product['quantity']<$item['quantity']) {
                throw new CheckoutProblem('unavailable', 'An item is no longer available. Review your cart.');
            }
            $price=(float)($product['price'] ?? 0);
            $sale=(float)($product['sale_price'] ?? 0);
            if ($sale>0 && $sale<$price) $price=$sale;
            $unit=ApplePayContext::catalogCents($price);
            if ($unit!==ApplePayContext::catalogCents($item['unit_price'])) {
                throw new CheckoutProblem('total_changed', 'An item price changed. Refresh your cart before paying.');
            }
            $line=$unit*$item['quantity'];
            $subtotal+=$line;
            $orderItems[]=['product_id'=>$id,'product_name'=>(string)$product['name'],
                'product_sku'=>$product['sku'] ?? null,'quantity'=>$item['quantity'],
                'unit_price'=>$unit/100,'total_price'=>$line/100];
        }
        if ($subtotal!==ApplePayContext::catalogCents($input['subtotal'] ?? null)
            || ApplePayContext::catalogCents($input['tax_amount'] ?? 0)!==0) {
            throw new CheckoutProblem('total_changed', 'Your checkout total changed. Review the cart before paying.');
        }
        $code=$input['discount_code'] ?? null;
        if ($code!==null && (!is_string($code) || strlen($code)>100)) {
            throw new CheckoutProblem('invalid_coupon', 'Remove the invalid coupon and try again.', 400);
        }
        $code=strtoupper(trim((string)$code));
        $discount=0;
        if ($code!=='') {
            require_once __DIR__.'/../models/Coupon.php';
            $coupon=new \FAS\Models\Coupon($products->getDb());
            $result=$coupon->validateCoupon($code,$subtotal/100);
            if (empty($result['valid'])) {
                throw new CheckoutProblem('invalid_coupon', 'Coupon eligibility changed. Reapply the coupon before paying.');
            }
            $discount=ApplePayContext::catalogCents($result['discount']);
        }
        if ($discount>$subtotal || $discount!==ApplePayContext::catalogCents($input['discount_amount'] ?? 0)) {
            throw new CheckoutProblem('total_changed', 'The discount changed. Reapply the coupon before paying.');
        }
        $total=$subtotal+$shippingCents-$discount;
        if ($total<1 || $total!==ApplePayContext::catalogCents($input['total_amount'] ?? null)) {
            throw new CheckoutProblem('total_changed', 'Your checkout total changed. Review the cart before paying.');
        }
        ApplePayContext::money($total);
        return ['items'=>$orderItems,'subtotal'=>$subtotal/100,'shipping_cost'=>$shippingCents/100,
            'tax_amount'=>0,'discount_code'=>$code!==''?$code:null,'discount_amount'=>$discount/100,
            'total_amount'=>$total/100];
    }
}
