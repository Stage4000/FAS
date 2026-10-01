<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Parse bounded carrier confirmations before private label persistence. No network calls. */
final class CarrierLabelResponses
{
    /** Keep a requested USPS refund distinct from a voided, unusable label. */
    public static function cancellation(string $provider,string $tracking,array $response): array
    {
        if ($provider==='usps') {
            if (!self::uspsTracking($tracking) || ($response['trackingNumber'] ?? null)!==$tracking) {
                throw new \RuntimeException('USPS cancellation needs reconciliation.');
            }
            if (($response['status'] ?? null)==='CANCELED' && !isset($response['disputeId'])) {
                return ['state'=>'cancelled','carrier_reference'=>null];
            }
            $dispute=$response['disputeId'] ?? null;
            if (is_string($dispute) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D',$dispute)
                && ($response['status'] ?? null)!=='CANCELED') {
                return ['state'=>'refund_pending','carrier_reference'=>$dispute];
            }
            throw new \RuntimeException('USPS cancellation needs reconciliation.');
        }
        if ($provider==='ups' && self::upsTracking($tracking)) {
            $result=$response['VoidShipmentResponse'] ?? null;
            if (is_array($result)
                && ($result['Response']['ResponseStatus']['Code'] ?? null)==='1'
                && ($result['SummaryResult']['Status']['Code'] ?? null)==='1') {
                $packages=$result['PackageLevelResults'] ?? [];
                if (is_array($packages) && array_is_list($packages)) {
                    foreach ($packages as $package) {
                        if (($package['Status']['Code'] ?? null)!=='1') {
                            throw new \RuntimeException('UPS void needs reconciliation.');
                        }
                    }
                    return ['state'=>'cancelled','carrier_reference'=>null];
                }
            }
            throw new \RuntimeException('UPS void needs reconciliation.');
        }
        throw new \InvalidArgumentException('Unknown carrier cancellation.');
    }

    public static function usps(string $contentType,string $body): array
    {
        if (strlen($body)>8388608
            || !preg_match('~^multipart/(?:form-data|mixed);.*\bboundary=(?:"([A-Za-z0-9_\'()+,./:=?-]{1,70})"|([A-Za-z0-9_\'()+,./:=?-]{1,70}))(?:;|$)~iD',$contentType,$match)) {
            throw new \RuntimeException('USPS label response needs review.');
        }
        $boundary=$match[1]!=='' ? $match[1] : $match[2];
        $chunks=explode('--'.$boundary,$body);
        if (count($chunks)!==4 || trim($chunks[0])!=='' || trim($chunks[3])!=='--') {
            throw new \RuntimeException('USPS label response needs review.');
        }
        $parts=[];
        foreach ([1,2] as $index) {
            $chunk=$chunks[$index];
            if (!str_starts_with($chunk,"\r\n") || !str_ends_with($chunk,"\r\n")) {
                throw new \RuntimeException('USPS label response needs review.');
            }
            $chunk=substr($chunk,2,-2);
            $split=strpos($chunk,"\r\n\r\n");
            if ($split===false || $split>8192) throw new \RuntimeException('USPS label response needs review.');
            $headers=substr($chunk,0,$split);
            $content=substr($chunk,$split+4);
            if (!preg_match('/^Content-Disposition: form-data;[^\r\n]*\bname="(labelMetadata|labelImage)"/im',$headers,$name)
                || isset($parts[$name[1]])) throw new \RuntimeException('USPS label response needs review.');
            $type=$name[1]==='labelMetadata' ? 'application/json' : 'application/pdf';
            if (!preg_match('~^Content-Type: '.preg_quote($type,'~').'(?:;[^\r\n]*)?$~im',$headers)) {
                throw new \RuntimeException('USPS label response needs review.');
            }
            $parts[$name[1]]=$content;
        }
        if (!isset($parts['labelMetadata'],$parts['labelImage'])
            || strlen($parts['labelMetadata'])>65536 || strlen($parts['labelImage'])>6291456
            || !str_starts_with($parts['labelImage'],'%PDF-')) {
            throw new \RuntimeException('USPS label response needs review.');
        }
        $metadata=json_decode($parts['labelMetadata'],true,32);
        if (!is_array($metadata) || !self::uspsTracking($metadata['trackingNumber'] ?? null)) {
            throw new \RuntimeException('USPS label confirmation needs review.');
        }
        return ['shipment_id'=>$metadata['trackingNumber'],'packages'=>[[
            'tracking_number'=>$metadata['trackingNumber'],'format'=>'pdf','label'=>$parts['labelImage']]],
            'billed_cents'=>self::money($metadata['postage'] ?? null)];
    }

    public static function ups(array $response,int $expectedPackages): array
    {
        if ($expectedPackages<1 || $expectedPackages>10
            || ($response['ShipmentResponse']['Response']['ResponseStatus']['Code'] ?? null)!=='1') {
            throw new \RuntimeException('UPS shipment response needs review.');
        }
        $result=$response['ShipmentResponse']['ShipmentResults'] ?? null;
        if (!is_array($result)) throw new \RuntimeException('UPS shipment response needs review.');
        $id=$result['ShipmentIdentificationNumber'] ?? null;
        if (!self::upsTracking($id)) throw new \RuntimeException('UPS shipment confirmation needs review.');
        $rawPackages=$result['PackageResults'] ?? null;
        if (!is_array($rawPackages) || !array_is_list($rawPackages)
            || count($rawPackages)!==$expectedPackages) {
            throw new \RuntimeException('UPS package count needs reconciliation.');
        }
        $packages=[]; $seen=[];
        foreach ($rawPackages as $raw) {
            $tracking=$raw['TrackingNumber'] ?? null;
            $image=$raw['ShippingLabel']['GraphicImage'] ?? null;
            if (!self::upsTracking($tracking) || isset($seen[$tracking])
                || ($raw['ShippingLabel']['ImageFormat']['Code'] ?? null)!=='GIF'
                || !is_string($image) || strlen($image)>8388608) {
                throw new \RuntimeException('UPS package confirmation needs review.');
            }
            $binary=base64_decode($image,true);
            if ($binary===false || strlen($binary)>6291456
                || !(str_starts_with($binary,'GIF87a') || str_starts_with($binary,'GIF89a'))) {
                throw new \RuntimeException('UPS label image needs review.');
            }
            $seen[$tracking]=true;
            $packages[]=['tracking_number'=>$tracking,'format'=>'gif','label'=>$binary];
        }
        if ($packages[0]['tracking_number']!==$id) {
            throw new \RuntimeException('UPS shipment tracking needs reconciliation.');
        }
        $charge=$result['NegotiatedRateCharges']['TotalCharge'] ?? null;
        if ($charge===null) $charge=$result['ShipmentCharges']['TotalCharges'] ?? null;
        if (!is_array($charge) || ($charge['CurrencyCode'] ?? null)!=='USD') {
            throw new \RuntimeException('UPS shipment charge needs review.');
        }
        return ['shipment_id'=>$id,'packages'=>$packages,
            'billed_cents'=>self::money($charge['MonetaryValue'] ?? null)];
    }

    private static function uspsTracking($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{20,34}\z/D',$value)===1;
    }

    private static function upsTracking($value): bool
    {
        return is_string($value) && preg_match('/\A1Z[A-Z0-9]{16}\z/D',$value)===1;
    }

    private static function money($value): int
    {
        if (is_int($value)) $amount=(string)$value;
        elseif (is_float($value) && is_finite($value)) {
            $amount=number_format($value,2,'.','');
            if (abs($value-(float)$amount)>0.00001) throw new \RuntimeException('Carrier shipment charge needs review.');
        } elseif (is_string($value)) $amount=$value;
        else throw new \RuntimeException('Carrier shipment charge needs review.');
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?\z/D',$amount)
            || (float)$amount<=0 || (float)$amount>999999.99) {
            throw new \RuntimeException('Carrier shipment charge needs review.');
        }
        return (int)round((float)$amount*100);
    }
}
