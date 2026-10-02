<?php
declare(strict_types=1);
namespace FAS\Shipping;

final class ShippingShipment
{
    private const STATES='AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY';

    public static function address(array $input, bool $origin = false): array
    {
        $result=[];
        $keys=$origin ? ['address1'=>'address_line1','address2'=>'address_line2','city'=>'city','state'=>'state','zip'=>'postal_code','country'=>'country_code']
            : ['address1'=>'address1','address2'=>'address2','city'=>'city','state'=>'state','zip'=>'zip','country'=>'country'];
        foreach ($keys as $key=>$source) {
            $value=$input[$source] ?? ($key==='country'?'US':'');
            if (!is_string($value) || strlen($value)>255 || preg_match('/[\x00-\x1f\x7f]/',$value)) {
                throw new \RuntimeException('Invalid carrier address.');
            }
            $result[$key]=trim($value);
        }
        $result['country']=strtoupper($result['country']);
        if ($result['country']==='UNITED STATES') $result['country']='US';
        $result['state']=strtoupper($result['state']);
        if ($result['country']!=='US' || !in_array($result['state'],explode(' ',self::STATES),true)
            || !preg_match('/^\d{5}(?:-\d{4})?$/D',$result['zip']) || $result['city']==='') {
            throw new \RuntimeException('Destination is outside the direct domestic rollout.');
        }
        return $result;
    }

    public static function build(array $items, array $destination, ?array $warehouse, array $config, ?callable $originResolver=null): array
    {
        if (!$config['parcel_data_verified']) throw new \RuntimeException('Packed catalog measurements are not verified.');
        if (!$items || count($items)>100) throw new \RuntimeException('Unsupported shipment size.');
        $address=self::address($destination);
        $estimate=($destination['_estimate'] ?? false)===true;
        if (!$estimate && $address['address1']==='') throw new \RuntimeException('Street address required.');
        if ($estimate) { $address['address1']=''; $address['address2']=''; }
        $origin=null; $packages=[]; $resolved=[];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['has_complete_shipping_data'] ?? false)!==true) {
                throw new \RuntimeException('Packed measurements are missing.');
            }
            $id=(int)($item['product_id'] ?? $item['id'] ?? 0);
            if ($id<1) throw new \RuntimeException('Invalid shipment item.');
            if (!isset($resolved[$id])) $resolved[$id]=$originResolver ? $originResolver($id) : $warehouse;
            $candidate=$resolved[$id];
            if (!is_array($candidate) || (isset($candidate['is_active']) && !(bool)$candidate['is_active'])) {
                throw new \RuntimeException('An active origin warehouse is required.');
            }
            $current=self::address($candidate,true);
            if ($current['address1']==='') throw new \RuntimeException('Origin street address required.');
            if ($origin!==null && $current!==$origin) throw new \RuntimeException('Multi-origin direct shipping is not yet enabled.');
            $origin=$current;
            $quantity=$item['quantity'] ?? 0;
            if (!is_int($quantity) || $quantity<1 || count($packages)+$quantity>$config['max_packages']) {
                throw new \RuntimeException('Unsupported parcel quantity.');
            }
            $parcel=[];
            foreach (['weight','length','width','height'] as $key) {
                $value=$item[$key] ?? 0;
                if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<=0 || (float)$value>150) {
                    throw new \RuntimeException('Invalid packed measurements.');
                }
                $parcel[$key]=round((float)$value,3);
                if ($parcel[$key]<=0) throw new \RuntimeException('Invalid packed measurements.');
            }
            // Preserve all measured edges; no invented combined box or combined weight.
            $edges=[$parcel['length'],$parcel['width'],$parcel['height']]; rsort($edges,SORT_NUMERIC);
            if ($edges[0]>108 || $edges[0]+2*($edges[1]+$edges[2])>165) {
                throw new \RuntimeException('Parcel requires a separate shipping arrangement.');
            }
            // USPS expects length and width to be the longest and second-longest edges.
            [$parcel['length'],$parcel['width'],$parcel['height']]=$edges;
            for($unit=0;$unit<$quantity;$unit++) $packages[]=$parcel;
        }
        return ['origin'=>$origin,'destination'=>$address,'packages'=>$packages,'estimate'=>$estimate,
            // Rates only. No unsupported delivery promises derived from today's date.
            'ship_date'=>date('Y-m-d')];
    }
}
