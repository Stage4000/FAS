<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Extract only a short status for the exact saved package; never persist raw responses. */
final class CarrierTrackingResponses
{
    public static function parse(string $provider,string $tracking,array $data): array
    {
        if ($provider==='usps') {
            $rows=array_is_list($data)?$data:[$data];
            if (count($rows)!==1 || !is_array($rows[0])
                || ($rows[0]['trackingNumber'] ?? null)!==$tracking) {
                throw new \RuntimeException('USPS tracking needs review.');
            }
            $row=$rows[0];
            $text=self::clean($row['status'] ?? null,160);
            $code=self::clean($row['statusCategory'] ?? null,60,true);
        } elseif ($provider==='ups') {
            $shipments=$data['trackResponse']['shipment'] ?? null;
            if (!is_array($shipments) || !array_is_list($shipments) || count($shipments)!==1
                || ($shipments[0]['inquiryNumber'] ?? null)!==$tracking) {
                throw new \RuntimeException('UPS tracking needs review.');
            }
            $packages=$shipments[0]['package'] ?? null;
            if (!is_array($packages) || !array_is_list($packages)) {
                throw new \RuntimeException('UPS tracking needs review.');
            }
            $matching=array_values(array_filter($packages,static fn($row)=>is_array($row)
                && ($row['trackingNumber'] ?? null)===$tracking));
            if (count($matching)!==1) throw new \RuntimeException('UPS tracking needs review.');
            $status=$matching[0]['currentStatus'] ?? null;
            if (!is_array($status)) throw new \RuntimeException('UPS tracking needs review.');
            $text=self::clean($status['simplifiedTextDescription'] ?? $status['description'] ?? null,160);
            $code=self::clean($status['code'] ?? null,60,true);
        } else {
            throw new \InvalidArgumentException('Unknown tracking carrier.');
        }
        return ['status_text'=>$text,'status_code'=>$code];
    }

    private static function clean($value,int $limit,bool $optional=false): ?string
    {
        if ($optional && $value===null) return null;
        if (!is_string($value)) throw new \RuntimeException('Carrier tracking status needs review.');
        $value=trim(preg_replace('/\s+/u',' ',strip_tags($value)) ?? '');
        if ($value==='' || strlen($value)>$limit || preg_match('/[\x00-\x1f\x7f]/',$value)) {
            throw new \RuntimeException('Carrier tracking status needs review.');
        }
        return $value;
    }
}
