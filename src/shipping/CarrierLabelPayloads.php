<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingShipment.php';

/** Carrier request bodies built only from paid orders and their saved shipping selection. */
final class CarrierLabelPayloads
{
    public static function usps(array $order,array $shipping,int $packageIndex,array $fulfillment,string $mailingDate): array
    {
        self::date($mailingDate);
        if (($shipping['provider'] ?? '')!=='usps') throw new \InvalidArgumentException('USPS order required.');
        $packages=$shipping['packages'] ?? [];
        $options=$shipping['fulfillment_options'] ?? [];
        $parcel=$packages[$packageIndex] ?? null;
        $option=$options[$packageIndex] ?? null;
        if (!is_array($parcel) || !is_array($option)
            || !in_array($shipping['service_code'] ?? '',['USPS_GROUND_ADVANTAGE','PRIORITY_MAIL','PRIORITY_MAIL_EXPRESS'],true)
            || !in_array($option['rate_indicator'] ?? '',['SP','DR'],true)
            || !in_array($option['processing_category'] ?? '',['MACHINABLE','NONSTANDARD'],true)
            || ($option['destination_entry_facility_type'] ?? '')!=='NONE') {
            throw new \RuntimeException('Saved USPS parcel options need review.');
        }
        $to=self::uspsAddress(self::destination($order));
        $to+=self::recipient($order);
        $from=self::uspsAddress(self::origin($shipping));
        $from['firm']=self::shipperName($fulfillment);
        $description=['mailClass'=>$shipping['service_code'],'rateIndicator'=>$option['rate_indicator'],
            'weightUOM'=>'lb','weight'=>self::measurement($parcel,'weight'),
            'dimensionsUOM'=>'in','length'=>self::measurement($parcel,'length'),
            'width'=>self::measurement($parcel,'width'),'height'=>self::measurement($parcel,'height'),
            'processingCategory'=>$option['processing_category'],'mailingDate'=>$mailingDate,
            'extraServices'=>[],'destinationEntryFacilityType'=>'NONE'];
        return ['imageInfo'=>['imageType'=>'PDF','labelType'=>'4X6LABEL','receiptOption'=>'NONE',
            'suppressPostage'=>false,'suppressMailDate'=>false,'returnLabel'=>false],
            'toAddress'=>$to,'fromAddress'=>$from,'packageDescription'=>$description];
    }

    public static function ups(array $order,array $shipping,array $fulfillment,string $accountNumber): array
    {
        if (($shipping['provider'] ?? '')!=='ups' || !in_array($shipping['service_code'] ?? '',
            ['01','02','03','12','13','14','59'],true)
            || !preg_match('/\A[A-Z0-9]{6,20}\z/D',$accountNumber)
            || empty($shipping['packages']) || count($shipping['packages'])>10) {
            throw new \RuntimeException('Saved UPS shipment needs review.');
        }
        $from=self::upsAddress(self::origin($shipping));
        $to=self::upsAddress(self::destination($order));
        $name=self::shipperName($fulfillment);
        $phone=self::phone($fulfillment['shipper_phone'] ?? '');
        $recipient=self::recipientName($order);
        $recipientPhone=self::phone($order['customer_phone'] ?? '');
        $packages=[];
        foreach ($shipping['packages'] as $parcel) {
            $packages[]=['Packaging'=>['Code'=>'02'],
                'Dimensions'=>['UnitOfMeasurement'=>['Code'=>'IN'],
                    'Length'=>(string)ceil(self::measurement($parcel,'length')),
                    'Width'=>(string)ceil(self::measurement($parcel,'width')),
                    'Height'=>(string)ceil(self::measurement($parcel,'height'))],
                'PackageWeight'=>['UnitOfMeasurement'=>['Code'=>'LBS'],
                    'Weight'=>(string)self::measurement($parcel,'weight')]];
        }
        $shipper=['Name'=>$name,'AttentionName'=>$name,'ShipperNumber'=>$accountNumber,
            'Phone'=>['Number'=>$phone],'Address'=>$from];
        return ['ShipmentRequest'=>[
            'Request'=>['RequestOption'=>'nonvalidate','TransactionReference'=>[
                'CustomerContext'=>self::orderNumber($order)]],
            'Shipment'=>['Description'=>'Automotive parts','Shipper'=>$shipper,
                'ShipFrom'=>['Name'=>$name,'AttentionName'=>$name,'Phone'=>['Number'=>$phone],'Address'=>$from],
                'ShipTo'=>['Name'=>$recipient,'AttentionName'=>$recipient,
                    'Phone'=>['Number'=>$recipientPhone],'Address'=>$to,'Residential'=>'Y'],
                'PaymentInformation'=>['ShipmentCharge'=>['Type'=>'01',
                    'BillShipper'=>['AccountNumber'=>$accountNumber]]],
                'Service'=>['Code'=>$shipping['service_code']],
                'ShipmentRatingOptions'=>['NegotiatedRatesIndicator'=>'Y'],
                'Package'=>$packages],
            'LabelSpecification'=>['LabelImageFormat'=>['Code'=>'GIF'],
                'LabelStockSize'=>['Height'=>'6','Width'=>'4']]]];
    }

    private static function recipient(array $order): array
    {
        $name=self::recipientName($order);
        $space=strpos($name,' ');
        return $space===false ? ['firm'=>$name] :
            ['firstName'=>substr($name,0,$space),'lastName'=>substr($name,$space+1)];
    }

    private static function recipientName(array $order): string
    {
        $name=trim((string)($order['customer_name'] ?? ''));
        if ($name==='' || strlen($name)>70 || preg_match('/[\x00-\x1f\x7f]/',$name)) {
            throw new \RuntimeException('Recipient name is required for a carrier label.');
        }
        return $name;
    }

    private static function shipperName(array $fulfillment): string
    {
        $name=trim((string)($fulfillment['shipper_name'] ?? ''));
        if ($name==='' || strlen($name)>35 || preg_match('/[\x00-\x1f\x7f]/',$name)) {
            throw new \RuntimeException('Verified shipper name is required.');
        }
        return $name;
    }

    private static function phone($value): string
    {
        if (!is_string($value) || strlen($value)>30) throw new \RuntimeException('Verified phone number is required.');
        $digits=preg_replace('/\D/','',$value);
        if (strlen($digits)===11 && $digits[0]==='1') $digits=substr($digits,1);
        if (strlen($digits)!==10) throw new \RuntimeException('Verified US phone number is required.');
        return $digits;
    }

    private static function destination(array $order): array
    {
        $address=json_decode((string)($order['shipping_address'] ?? ''),true);
        if (!is_array($address)) throw new \RuntimeException('Saved destination is missing.');
        $address=ShippingShipment::address($address);
        if ($address['address1']==='') throw new \RuntimeException('Destination street is missing.');
        return $address;
    }

    private static function origin(array $shipping): array
    {
        $address=$shipping['origin'] ?? null;
        if (!is_array($address)) throw new \RuntimeException('Saved origin is missing.');
        $address=ShippingShipment::address($address);
        if ($address['address1']==='') throw new \RuntimeException('Origin street is missing.');
        return $address;
    }

    private static function uspsAddress(array $address): array
    {
        $parts=explode('-',$address['zip'],2);
        $result=['streetAddress'=>$address['address1'],'city'=>$address['city'],
            'state'=>$address['state'],'ZIPCode'=>$parts[0]];
        if ($address['address2']!=='') $result['secondaryAddress']=$address['address2'];
        if (isset($parts[1])) $result['ZIPPlus4']=$parts[1];
        return $result;
    }

    private static function upsAddress(array $address): array
    {
        $lines=array_values(array_filter([$address['address1'],$address['address2']],static fn($v)=>$v!==''));
        return ['AddressLine'=>$lines,'City'=>$address['city'],'StateProvinceCode'=>$address['state'],
            'PostalCode'=>$address['zip'],'CountryCode'=>'US'];
    }

    private static function measurement(array $parcel,string $key): float
    {
        $value=$parcel[$key] ?? null;
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<=0 || (float)$value>150) {
            throw new \RuntimeException('Saved parcel measurements need review.');
        }
        return (float)$value;
    }

    private static function date(string $date): void
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $today=new \DateTimeImmutable('today');
        if (!$parsed || $parsed->format('Y-m-d')!==$date || $parsed<$today || $parsed>$today->modify('+7 days')) {
            throw new \InvalidArgumentException('Choose a mailing date within seven days.');
        }
    }

    private static function orderNumber(array $order): string
    {
        $number=$order['order_number'] ?? '';
        if (!is_string($number) || !preg_match('/\A[A-Za-z0-9-]{1,35}\z/D',$number)) {
            throw new \RuntimeException('Saved order number is invalid.');
        }
        return $number;
    }
}
