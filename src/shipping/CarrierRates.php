<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/CarrierHttp.php';
require_once __DIR__.'/ShippingCache.php';

/** Direct rate adapters only. This class cannot buy labels or create shipments. */
final class CarrierRates
{
    private string $name;
    private array $config;
    private ShippingCache $cache;
    private CarrierHttp $http;
    private string $key;
    private int $ttl;
    private const SERVICES=[
        'usps'=>['USPS_GROUND_ADVANTAGE'=>'USPS Ground Advantage','PRIORITY_MAIL'=>'Priority Mail','PRIORITY_MAIL_EXPRESS'=>'Priority Mail Express'],
        'ups'=>['01'=>'Next Day Air','02'=>'2nd Day Air','03'=>'Ground','12'=>'3 Day Select','13'=>'Next Day Air Saver','14'=>'Next Day Air Early','59'=>'2nd Day Air A.M.'],
    ];

    public function __construct(string $name,array $config,ShippingCache $cache,CarrierHttp $http,int $ttl=180)
    {
        if (!isset(self::SERVICES[$name])) throw new \RuntimeException('Unknown carrier.');
        $this->name=$name; $this->config=$config; $this->cache=$cache; $this->http=$http; $this->ttl=$ttl;
        $this->key=hash('sha256',$name.json_encode($config,JSON_THROW_ON_ERROR));
    }

    private function base(): string
    {
        $live=$this->config['environment']==='production';
        if ($this->name==='ups') return $live?'https://onlinetools.ups.com':'https://wwwcie.ups.com';
        if (($this->config['gateway'] ?? 'apis')==='api') return $live?'https://api.usps.com':'https://api-cat.usps.com';
        return $live?'https://apis.usps.com':'https://apis-tem.usps.com';
    }

    private function checked(array $response): array
    {
        $status=(int)($response['status'] ?? 0);
        if ($status===429) {
            $this->cache->put('cooldown:'.$this->key,[],max(1,min(86400,(int)($response['retry_after'] ?? 60) ?: 60)));
        } elseif ($status>=500) {
            $this->cache->put('cooldown:'.$this->key,[],15);
        } elseif (in_array($status,[401,403],true)) {
            $this->cache->put('cooldown:'.$this->key,[],60);
        }
        if ($status<200 || $status>=300) throw new \RuntimeException('Carrier HTTP '.$status);
        return $response['data'] ?? [];
    }

    private function token(): string
    {
        $key='token:'.$this->key;
        $saved=$this->cache->get($key);
        if ($saved) return $saved['token'];
        $data=['grant_type'=>'client_credentials','client_id'=>$this->config['client_id'],'client_secret'=>$this->config['client_secret']];
        $headers=[];
        if ($this->name==='ups') {
            $url='/security/v1/oauth/token';
            $headers[]='Authorization: Basic '.base64_encode($data['client_id'].':'.$data['client_secret']);
            $body='grant_type=client_credentials';
        } else {
            $url='/oauth2/v3/token';
            $body=json_encode($data,JSON_THROW_ON_ERROR);
        }
        $headers[]='Content-Type: '.($this->name==='usps'?'application/json':'application/x-www-form-urlencoded');
        $result=$this->checked($this->http->post($this->base().$url,$headers,$body));
        $token=$result['access_token'] ?? '';
        $expires=$result['expires_in'] ?? 0;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9._~+\/=-]{1,16000}$/D',$token)
            || !is_numeric($expires) || (int)$expires<=60) {
            throw new \RuntimeException('Invalid carrier authorization response.');
        }
        $this->cache->put($key,['token'=>$token],min(3600,(int)$expires-60));
        return $token;
    }

    private function request(string $path,array $payload): array
    {
        if ($this->cache->get('cooldown:'.$this->key)!==null) throw new \RuntimeException('Carrier cooling down.');
        for($attempt=0;$attempt<2;$attempt++) {
            $response=$this->http->post($this->base().$path,[
                'Content-Type: application/json','Authorization: Bearer '.$this->token(),
            ],json_encode($payload,JSON_THROW_ON_ERROR));
            if (($response['status'] ?? 0)===401 && $attempt===0) {
                $this->cache->forget('token:'.$this->key); continue;
            }
            return $this->checked($response);
        }
        throw new \RuntimeException('Carrier authorization unavailable.');
    }

    public static function cents($value): ?int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)) return null;
        $amount=(float)$value;
        return is_finite($amount) && $amount>0 && $amount<=100000 ? (int)round($amount*100) : null;
    }

    private function rate(string $code,$value,string $currency='USD',string $basis='published'): ?array
    {
        $cents=self::cents($value);
        if (!isset(self::SERVICES[$this->name][$code]) || $currency!=='USD' || $cents===null || $cents<1) return null;
        return ['courier_id'=>'direct_'.$this->name.'_'.$code,'courier_name'=>['usps'=>'USPS','ups'=>'UPS'][$this->name],
            'service_name'=>self::SERVICES[$this->name][$code],'total_charge'=>$cents/100,'currency'=>'USD',
            'min_delivery_time'=>null,'max_delivery_time'=>null,'delivery_time_text'=>'Delivery estimate unavailable',
            'provider'=>$this->name,'service_code'=>$code,'rate_basis'=>$basis];
    }

    public function rates(array $shipment): array
    {
        if ($this->name!=='usps' && preg_match('/\bP(?:OST)?\.?\s*O(?:FFICE)?\.?\s*BOX\b/i',
                $shipment['destination']['address1'].' '.$shipment['destination']['address2'])) return [];
        $key='quote:'.$this->key.':'.hash('sha256',json_encode($shipment,JSON_THROW_ON_ERROR));
        $cached=$this->cache->get($key);
        if ($cached!==null) return $cached;
        $rates=$this->name==='usps'?$this->usps($shipment):$this->ups($shipment);
        if ($rates) $this->cache->put($key,$rates,$this->ttl);
        return $rates;
    }

    private function usps(array $shipment): array
    {
        $common=null; $byParcel=[];
        foreach ($shipment['packages'] as $parcel) {
            if ($parcel['weight']>70) return [];
            $payload=$parcel+['originZIPCode'=>substr($shipment['origin']['zip'],0,5),
                'destinationZIPCode'=>substr($shipment['destination']['zip'],0,5),
                'mailClasses'=>array_keys(self::SERVICES['usps']),'priceType'=>$this->config['price_type'],
                'mailingDate'=>$shipment['ship_date']];
            // Repeated identical packed units need one lookup, but are charged per unit.
            $parcelKey=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
            if (!isset($byParcel[$parcelKey])) {
                $result=$this->request('/prices/v3/base-rates-list/search',$payload);
                $options=[];
                foreach ($result['rateOptions'] ?? [] as $option) {
                    $lines=$option['rates'] ?? [];
                    if (count($lines)!==1) continue;
                    $line=$lines[0]; $code=$line['mailClass'] ?? '';
                    // No flat-rate packaging, destination-entry discounts, cubic tiers or restricted mail classes.
                    if (!in_array($line['rateIndicator'] ?? '',['SP','DR'],true)
                        || ($line['destinationEntryFacilityType'] ?? '')!=='NONE'
                        || !in_array($line['processingCategory'] ?? '',['MACHINABLE','NONSTANDARD'],true)
                        || ($line['priceType'] ?? '')!==$this->config['price_type']) continue;
                    $rate=$this->rate((string)$code,$option['totalBasePrice'] ?? null,'USD',strtolower($this->config['price_type']));
                    if ($rate && (!isset($options[$code]) || $rate['total_charge']<$options[$code]['total_charge'])) $options[$code]=$rate;
                }
                $byParcel[$parcelKey]=$options;
            }
            $options=$byParcel[$parcelKey];
            if ($common===null) { $common=$options; continue; }
            foreach ($common as $code=>&$rate) {
                if (!isset($options[$code])) { unset($common[$code]); continue; }
                $rate['total_charge']=(self::cents($rate['total_charge'])+self::cents($options[$code]['total_charge']))/100;
            }
            unset($rate);
            if (!$common) return [];
        }
        return array_values($common ?? []);
    }

    private function upsAddress(array $address,bool $residential=false): array
    {
        $value=['City'=>$address['city'],'StateProvinceCode'=>$address['state'],'PostalCode'=>$address['zip'],'CountryCode'=>'US'];
        $lines=array_values(array_filter([$address['address1'],$address['address2']],static fn($v)=>$v!==''));
        if ($lines) $value['AddressLine']=$lines;
        // Storefront has no verified commercial-address classification. Include residential surcharges.
        if ($residential) $value['ResidentialAddressIndicator']='Y';
        return $value;
    }

    private function ups(array $shipment): array
    {
        $packages=[];
        foreach ($shipment['packages'] as $p) {
            $packages[]=['PackagingType'=>['Code'=>'02'],
                'Dimensions'=>['UnitOfMeasurement'=>['Code'=>'IN'],'Length'=>(string)ceil($p['length']),'Width'=>(string)ceil($p['width']),'Height'=>(string)ceil($p['height'])],
                'PackageWeight'=>['UnitOfMeasurement'=>['Code'=>'LBS'],'Weight'=>(string)$p['weight']]];
        }
        $shipper=['ShipperNumber'=>$this->config['account_number'],'Address'=>$this->upsAddress($shipment['origin'])];
        $payload=['RateRequest'=>['Request'=>['RequestOption'=>'Shop'],'Shipment'=>[
            'Shipper'=>$shipper,'ShipFrom'=>['Address'=>$shipper['Address']],
            'ShipTo'=>['Address'=>$this->upsAddress($shipment['destination'],true)],
            'PaymentDetails'=>['ShipmentCharge'=>[['Type'=>'01','BillShipper'=>['AccountNumber'=>$this->config['account_number']]]]],
            'ShipmentRatingOptions'=>['NegotiatedRatesIndicator'=>'Y'],'NumOfPieces'=>(string)count($packages),'Package'=>$packages]]];
        $result=$this->request('/api/rating/v2409/Shop',$payload);
        $options=$result['RateResponse']['RatedShipment'] ?? [];
        if (isset($options['Service'])) $options=[$options];
        $rates=[];
        foreach ($options as $option) {
            $negotiated=$option['NegotiatedRateCharges']['TotalCharge'] ?? null;
            $charge=$negotiated ?? $option['TotalCharges'] ?? [];
            $rate=$this->rate((string)($option['Service']['Code'] ?? ''),$charge['MonetaryValue'] ?? null,
                (string)($charge['CurrencyCode'] ?? ''),$negotiated?'account':'published');
            if ($rate) $rates[]=$rate;
        }
        return $rates;
    }

}
