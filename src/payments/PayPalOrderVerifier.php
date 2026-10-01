<?php
declare(strict_types=1);
namespace FAS\Payments;

require_once __DIR__.'/ApplePayContext.php';

/** Verify a browser-created PayPal order before local fulfillment. */
final class PayPalOrderVerifier
{
    public static function id($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z0-9]{6,32}$/D',$value)) {
            throw new CheckoutProblem('invalid_reference','Invalid payment reference.',400);
        }
        return $value;
    }

    public static function capture(array $order,string $paypalId,array $provider,$claimedCapture=''): string
    {
        $units=$provider['purchase_units'] ?? null;
        $unit=is_array($units) && count($units)===1 ? $units[0] : null;
        if (!is_array($unit) || ($provider['id'] ?? null)!==$paypalId
            || ($provider['intent'] ?? null)!=='CAPTURE'
            || ($unit['invoice_id'] ?? null)!==($order['order_number'] ?? null)
            || ($unit['custom_id'] ?? null)!=='FAS-CHECKOUT-'.($order['id'] ?? '')
            || ($unit['amount']['currency_code'] ?? null)!=='USD'
            || self::cents($unit['amount']['value'] ?? null)!==ApplePayContext::catalogCents($order['total_amount'] ?? null)
            || !is_string($unit['payee']['merchant_id'] ?? null)
            || $unit['payee']['merchant_id']==='') {
            throw new CheckoutProblem('verification_failed','Payment details need review. Do not pay again; contact support.');
        }
        $address=json_decode((string)($order['shipping_address'] ?? ''),true);
        $paidAddress=$unit['shipping']['address'] ?? null;
        if (!is_array($address) || !is_array($paidAddress)) {
            throw new CheckoutProblem('verification_failed','Payment shipping address needs review. Do not pay again.');
        }
        $expected=['address_line_1'=>$address['address1'] ?? '',
            'address_line_2'=>$address['address2'] ?? '',
            'admin_area_2'=>$address['city'] ?? '',
            'admin_area_1'=>$address['state'] ?? '',
            'postal_code'=>$address['zip'] ?? '',
            'country_code'=>$address['country'] ?? ''];
        $clean=static fn($value): string => strtoupper(trim(preg_replace('/\s+/u',' ',(string)$value)));
        foreach ($expected as $key=>$value) {
            if ($clean($value)!==$clean($paidAddress[$key] ?? '')) {
                throw new CheckoutProblem('verification_failed','Payment shipping address needs review. Do not pay again.');
            }
        }
        $captures=$unit['payments']['captures'] ?? [];
        if (($provider['status'] ?? null)!=='COMPLETED' || !is_array($captures) || count($captures)!==1) {
            throw new CheckoutProblem('payment_pending','PayPal has not confirmed this payment yet. Retry this order confirmation.',503);
        }
        $capture=$captures[0];
        if (is_array($capture) && ($capture['status'] ?? null)==='PENDING') {
            throw new CheckoutProblem('payment_pending','PayPal has not confirmed this payment yet. Retry this order confirmation.',503);
        }
        if (!is_array($capture) || ($capture['status'] ?? null)!=='COMPLETED'
            || !is_string($capture['id'] ?? null) || !preg_match('/^[A-Z0-9]{6,64}$/D',$capture['id'])
            || ($capture['amount']['currency_code'] ?? null)!=='USD'
            || self::cents($capture['amount']['value'] ?? null)!==ApplePayContext::catalogCents($order['total_amount'])
            || (isset($capture['final_capture']) && $capture['final_capture']!==true)
            || ($claimedCapture!=='' && (!is_string($claimedCapture) || !hash_equals($capture['id'],$claimedCapture)))) {
            throw new CheckoutProblem('verification_failed','Captured payment details need review. Do not pay again; contact support.');
        }
        return $capture['id'];
    }

    private static function cents($value): ?int
    {
        try { return ApplePayContext::cents($value); }
        catch (CheckoutProblem $e) { return null; }
    }
}
