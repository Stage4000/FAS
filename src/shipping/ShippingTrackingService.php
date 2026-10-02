<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingTracking.php';
require_once __DIR__.'/CarrierTrackingClient.php';

/** Bounded, opt-in polling for confirmed direct labels only. */
final class ShippingTrackingService
{
    private ShippingCache $cache;
    private ShippingTracking $tracking;
    private array $config;
    private $clientFactory;

    public function __construct(ShippingCache $cache,array $config,?callable $clientFactory=null)
    {
        $this->cache=$cache;
        $this->tracking=new ShippingTracking($cache->database());
        $this->config=$config;
        $this->clientFactory=$clientFactory;
    }

    public function refresh(int $limit=20): array
    {
        if ($limit<1 || $limit>50) throw new \InvalidArgumentException('Invalid tracking batch size.');
        $providers=[];
        foreach (['usps','ups'] as $provider) {
            $carrier=$this->config['carriers'][$provider] ?? [];
            if (CarrierTrackingClient::enabled($carrier)
                && $this->cache->get('tracking-backoff:'.$provider)===null) $providers[]=$provider;
        }
        $result=['selected'=>0,'updated'=>0,'failed'=>0,'skipped'=>0,'enabled_carriers'=>$providers];
        if (!$providers) return $result;
        $rows=$this->tracking->due($providers,$limit);
        $result['selected']=count($rows);
        $stopped=[];
        foreach ($rows as $row) {
            $provider=$row['provider']; $number=$row['tracking_number'];
            if (isset($stopped[$provider]) || !$this->tracking->claim($number)) {
                $result['skipped']++; continue;
            }
            try {
                $carrier=$this->config['carriers'][$provider];
                $client=$this->client($provider,$carrier);
                $this->tracking->save($number,$client->track($number),$carrier['environment']);
                $result['updated']++;
            } catch (\Throwable $e) {
                $delay=$e instanceof CarrierTrackingUnavailable ? $e->retryAfter : 900;
                $this->tracking->fail($number,$delay);
                $this->cache->put('tracking-backoff:'.$provider,[],$delay);
                $stopped[$provider]=true;
                $result['failed']++;
            }
        }
        return $result;
    }

    private function client(string $provider,array $carrier): CarrierTrackingClient
    {
        if ($this->clientFactory) {
            $client=($this->clientFactory)($provider,$carrier,$this->cache);
            if (!$client instanceof CarrierTrackingClient) throw new \RuntimeException('Invalid carrier tracking client.');
            return $client;
        }
        $deadline=microtime(true)+12;
        return new CarrierTrackingClient($provider,$carrier,$this->cache,
            new CarrierHttp($deadline),new CarrierTrackingHttp($deadline));
    }
}
