<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/CarrierRates.php';
require_once __DIR__.'/CarrierTrackingHttp.php';
require_once __DIR__.'/CarrierTrackingResponses.php';

final class CarrierTrackingUnavailable extends \RuntimeException
{
    public int $retryAfter;
    public bool $throttled;

    public function __construct(int $retryAfter=900,bool $throttled=false)
    {
        parent::__construct('Carrier tracking is temporarily unavailable.');
        $this->retryAfter=max(900,min(86400,$retryAfter));
        $this->throttled=$throttled;
    }
}

/** Read-only carrier tracking for previously saved labels. */
final class CarrierTrackingClient
{
    private string $provider;
    private array $config;
    private CarrierRates $oauth;
    private CarrierTrackingHttp $http;

    public function __construct(string $provider,array $config,ShippingCache $cache,
        CarrierHttp $authHttp,CarrierTrackingHttp $trackingHttp)
    {
        if (!in_array($provider,['usps','ups'],true)) throw new \InvalidArgumentException('Unknown carrier.');
        $this->provider=$provider;
        $this->config=$config;
        $this->oauth=new CarrierRates($provider,$config,$cache,$authHttp);
        $this->http=$trackingHttp;
    }

    public static function enabled(array $config): bool
    {
        return ($config['enabled'] ?? false)===true
            && ($config['tracking_enabled'] ?? false)===true
            && in_array($config['environment'] ?? '',['sandbox','production'],true)
            && (($config['environment'] ?? '')!=='production'
                || ($config['production_verified'] ?? false)===true)
            && !empty($config['client_id']) && !empty($config['client_secret']);
    }

    public function track(string $tracking): array
    {
        if (!self::enabled($this->config)) throw new \RuntimeException('Carrier tracking is disabled.');
        if (($this->provider==='usps' && preg_match('/\A[0-9]{20,34}\z/D',$tracking)!==1)
            || ($this->provider==='ups' && preg_match('/\A1Z[A-Z0-9]{16}\z/D',$tracking)!==1)) {
            throw new \InvalidArgumentException('Invalid saved tracking number.');
        }
        $token=$this->oauth->accessToken();
        if ($this->provider==='usps') {
            $method='POST'; $path='/tracking/v3r2/tracking';
            $body=json_encode([['trackingNumber'=>$tracking]],JSON_THROW_ON_ERROR);
            $headers=['Authorization: Bearer '.$token,'Accept: application/json',
                'Content-Type: application/json'];
        } else {
            $method='GET'; $path='/api/track/v1/details/'.$tracking; $body='';
            $headers=['Authorization: Bearer '.$token,'Accept: application/json',
                'transId: '.bin2hex(random_bytes(16)),'transactionSrc: FlipAndStrip'];
        }
        $response=$this->http->request($method,$this->oauth->baseUrl().$path,$headers,$body);
        if ($response['status']===401) {
            $this->oauth->invalidateAccessToken();
            throw new CarrierTrackingUnavailable();
        }
        if ($response['status']===429) {
            throw new CarrierTrackingUnavailable((int)$response['retry_after'],true);
        }
        if ($response['status']!==200
            || !str_starts_with(strtolower($response['content_type']),'application/json')) {
            throw new CarrierTrackingUnavailable();
        }
        $data=json_decode($response['body'],true,32);
        if (!is_array($data)) throw new CarrierTrackingUnavailable();
        return CarrierTrackingResponses::parse($this->provider,$tracking,$data);
    }
}
