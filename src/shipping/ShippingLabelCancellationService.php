<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingLabelCancellations.php';
require_once __DIR__.'/CarrierLabelClient.php';

/** A single carrier void/refund submission, separate from the original label purchase. */
final class ShippingLabelCancellationService
{
    private \PDO $ordersDb;
    private ShippingCache $cache;
    private array $config;
    private ShippingLabelCancellations $cancellations;
    private $clientFactory;

    /** Client factory is injectable only by test code. */
    public function __construct(\PDO $ordersDb,ShippingCache $cache,array $config,?callable $clientFactory=null)
    {
        $this->ordersDb=$ordersDb;
        $this->cache=$cache;
        $this->config=$config;
        $this->cancellations=new ShippingLabelCancellations($cache->database());
        $this->clientFactory=$clientFactory;
    }

    public function cancel(int $orderId,int $packageIndex,int $operatorId): array
    {
        $record=$this->cancellations->reserve($this->ordersDb,$orderId,$packageIndex,$operatorId);
        if ($record['state']!=='reserved') return self::summary($record);
        $provider=$record['provider'];
        $carrier=$this->config['carriers'][$provider] ?? null;
        if (!is_array($carrier)) throw new \RuntimeException('Carrier cancellation configuration is unavailable.');
        $client=$this->client($provider,$carrier);
        $authorization=$client->authorizeCancellation();
        $operationId=(int)$record['operation_id'];
        if (!$this->cancellations->markSubmitted($this->ordersDb,$operationId,$operatorId)) {
            return self::summary($this->cancellations->find($orderId,$packageIndex));
        }
        try {
            $result=$client->cancel($record['tracking_number'],$authorization);
            $this->cancellations->finish($operationId,$result);
            return self::summary($this->cancellations->find($orderId,$packageIndex));
        } catch (\Throwable $e) {
            try { $this->cancellations->markReview($operationId); }
            catch (\Throwable $ignored) { /* Never repeat an uncertain carrier DELETE. */ }
            throw new \RuntimeException('Carrier cancellation outcome needs reconciliation.',0,$e);
        }
    }

    private function client(string $provider,array $carrier): CarrierLabelClient
    {
        if ($this->clientFactory) {
            $client=($this->clientFactory)($provider,$carrier,$this->cache);
            if (!$client instanceof CarrierLabelClient) throw new \RuntimeException('Invalid carrier client.');
            return $client;
        }
        $deadline=microtime(true)+30;
        return new CarrierLabelClient($provider,$carrier,$this->cache,
            new CarrierHttp($deadline),new CarrierLabelHttp($deadline));
    }

    private static function summary(?array $record): array
    {
        if (!$record) throw new \RuntimeException('Cancellation operation unavailable.');
        return ['state'=>$record['state'],'provider'=>$record['provider'],
            'order_id'=>(int)$record['order_id'],'package_index'=>(int)$record['package_index'],
            'tracking_number'=>$record['tracking_number'],
            'carrier_reference'=>$record['carrier_reference']];
    }
}
