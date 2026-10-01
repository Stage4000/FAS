<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingOrder.php';
require_once __DIR__.'/ShippingLabelOperations.php';
require_once __DIR__.'/CarrierLabelPayloads.php';
require_once __DIR__.'/CarrierLabelClient.php';

/** One administrator-confirmed attempt, guarded by the private operation ledger. */
final class ShippingLabelService
{
    private \PDO $ordersDb;
    private ShippingCache $cache;
    private ShippingLabelOperations $operations;
    private array $config;
    private $clientFactory;

    /** Client factory is only injectable from test code, never request or deployment data. */
    public function __construct(\PDO $ordersDb,ShippingCache $cache,array $config,?callable $clientFactory=null)
    {
        $this->ordersDb=$ordersDb;
        $this->cache=$cache;
        $this->operations=new ShippingLabelOperations($cache->database());
        $this->config=$config;
        $this->clientFactory=$clientFactory;
    }

    public function purchase(int $orderId,int $packageIndex,int $operatorId,int $confirmedCents,
        string $mailingDate): array
    {
        if ($confirmedCents<1) throw new \InvalidArgumentException('Confirm the selected shipping price.');
        $operation=$this->operations->reserve($this->ordersDb,$orderId,$packageIndex,$operatorId);
        if ($operation['state']!=='reserved') return self::summary($operation);
        $data=$this->orderData($orderId);
        $shipping=$data['shipping'];
        $provider=$operation['provider'];
        if ($shipping['provider']!==$provider) throw new \RuntimeException('Saved carrier changed.');
        $quote=$provider==='usps'
            ? ($shipping['fulfillment_options'][$packageIndex]['quoted_cents'] ?? null)
            : ($shipping['quoted_cents'] ?? null);
        if (!is_int($quote) || $quote!==$confirmedCents) {
            throw new \InvalidArgumentException('The saved shipping price changed. Review the order again.');
        }
        $fulfillment=['shipper_name'=>$this->config['shipper_name'] ?? '',
            'shipper_phone'=>$this->config['shipper_phone'] ?? ''];
        $carrier=$this->config['carriers'][$provider] ?? null;
        if (!is_array($carrier)) throw new \RuntimeException('Carrier label configuration is unavailable.');
        $payload=$provider==='usps'
            ? CarrierLabelPayloads::usps($data['order'],$shipping,$packageIndex,$fulfillment,$mailingDate)
            : CarrierLabelPayloads::ups($data['order'],$shipping,$fulfillment,(string)($carrier['account_number'] ?? ''));
        $client=$this->client($provider,$carrier);
        $authorization=$client->authorize();
        if (!$this->operations->markSubmitted($this->ordersDb,$orderId,$packageIndex,$operatorId)) {
            return self::summary($this->operations->find($orderId,$packageIndex));
        }
        try {
            $confirmation=$client->purchase($payload,$operation['idempotency_key'],
                (int)$operation['expected_packages'],$authorization);
            $this->operations->recordReady($orderId,$packageIndex,$confirmation);
            return self::summary($this->operations->find($orderId,$packageIndex));
        } catch (\Throwable $e) {
            try { $this->operations->markReview($orderId,$packageIndex); }
            catch (\Throwable $ignored) { /* Preserve the original error; never retry the POST. */ }
            throw new \RuntimeException('Carrier label outcome needs reconciliation.',0,$e);
        }
    }

    private function orderData(int $orderId): array
    {
        $stmt=$this->ordersDb->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$orderId]);
        $order=$stmt->fetch(\PDO::FETCH_ASSOC);
        $shipping=ShippingOrder::find($this->ordersDb,$orderId);
        if (!$order || !$shipping) throw new \RuntimeException('Paid shipping selection is unavailable.');
        return ['order'=>$order,'shipping'=>$shipping];
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

    private static function summary(?array $operation): array
    {
        if (!$operation) throw new \RuntimeException('Shipment operation unavailable.');
        return ['state'=>$operation['state'],'provider'=>$operation['provider'],
            'order_id'=>(int)$operation['order_id'],'package_index'=>(int)$operation['package_index'],
            'expected_packages'=>(int)$operation['expected_packages'],
            'shipment_id'=>$operation['shipment_id'],'tracking_number'=>$operation['tracking_number'],
            'billed_cents'=>$operation['billed_cents']===null?null:(int)$operation['billed_cents']];
    }
}
