<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingConfig.php';
require_once __DIR__.'/ShippingShipment.php';
require_once __DIR__.'/CarrierRates.php';

/** Maintains the checkout rate contract while carriers are introduced gradually. */
final class ShippingRateService
{
    private array $config;
    private $legacy;
    private $transport;
    private $originResolver;
    private ?float $deadline;
    private array $diagnostics=[];
    private ?array $shipmentSnapshot=null;

    public function __construct(?array $config=null,?callable $legacy=null,?callable $transport=null,
        ?callable $originResolver=null,?float $deadline=null)
    {
        $this->config=$config===null?ShippingConfig::load():ShippingConfig::validate($config);
        $this->legacy=$legacy ?? static function($items,$address,$warehouse) {
            require_once __DIR__.'/../integrations/EasyShipAPI.php';
            return (new \FAS\Integrations\EasyShipAPI())->getShippingRates($items,$address,$warehouse);
        };
        $this->transport=$transport; $this->originResolver=$originResolver; $this->deadline=$deadline;
    }

    public static function forDatabase(\PDO $db,?array $config=null,?float $deadline=null): self
    {
        return new self($config,null,null,static fn($id)=>self::originForProduct($db,(int)$id),$deadline);
    }

    /** Direct quotes require an explicit, unique active default for unassigned products. */
    public static function originForProduct(\PDO $db,int $productId): ?array
    {
        if ($productId<1) return null;
        $stmt=$db->prepare('SELECT warehouse_id FROM products WHERE id=?');
        $stmt->execute([$productId]);
        $product=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$product) return null;
        if (!empty($product['warehouse_id'])) {
            $stmt=$db->prepare('SELECT * FROM warehouses WHERE id=? AND is_active=1');
            $stmt->execute([(int)$product['warehouse_id']]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $defaults=$db->query('SELECT * FROM warehouses WHERE is_default=1 AND is_active=1 ORDER BY id LIMIT 2')
            ->fetchAll(\PDO::FETCH_ASSOC);
        return count($defaults)===1 ? $defaults[0] : null;
    }

    private function legacy(array $items,array $address,?array $warehouse): ?array
    {
        unset($address['_estimate']);
        try {
            $rates=($this->legacy)($items,$address,$warehouse);
            if (!is_array($rates)) return null;
            // Preserve original courier IDs; fulfillment must not treat direct IDs as Easyship IDs.
            foreach ($rates as &$rate) $rate['provider']='easyship';
            unset($rate);
            return $rates;
        } catch (\Throwable $e) {
            $this->diagnostics['easyship']='unavailable';
            error_log('Shipping provider unavailable: easyship');
            return null;
        }
    }

    public function getShippingRates(array $items,array $address,?array $warehouse=null,int $limit=5): ?array
    {
        $this->diagnostics=[];
        $this->shipmentSnapshot=null;
        if ($this->config['mode']==='easyship') return $this->legacy($items,$address,$warehouse);
        $rates=[];
        try {
            $shipment=ShippingShipment::build($items,$address,$warehouse,$this->config,$this->originResolver);
            $cache=new ShippingCache($this->config['cache_path']);
            $http=new CarrierHttp(min($this->deadline ?? INF,
                microtime(true)+$this->config['request_budget_seconds']),$this->transport);
            foreach (['usps','ups'] as $name) {
                $carrier=$this->config['carriers'][$name];
                if (!ShippingConfig::ready($carrier,$name,true)) {
                    $this->diagnostics[$name]='not_activated'; continue;
                }
                try {
                    $result=(new CarrierRates($name,$carrier,$cache,$http,$this->config['quote_ttl_seconds']))->rates($shipment);
                    $this->diagnostics[$name]=$result?'quoted':'no_supported_service';
                    foreach ($result as $rate) $rates[$rate['courier_id']]=$rate;
                } catch (\Throwable $e) {
                    $this->diagnostics[$name]='unavailable';
                    error_log('Shipping provider unavailable: '.$name);
                }
            }
        } catch (\Throwable $e) {
            $this->diagnostics['direct']='shipment_or_storage_unavailable';
            error_log('Direct shipping unavailable: check private storage and verified parcel/origin data.');
        }
        if (!$rates) {
            return $this->config['mode']==='direct_with_fallback'?$this->legacy($items,$address,$warehouse):null;
        }
        $this->shipmentSnapshot=['origin'=>$shipment['origin'],'packages'=>$shipment['packages']];
        $rates=array_values($rates);
        usort($rates,static fn($a,$b)=>($a['total_charge']<=>$b['total_charge']) ?: strcmp($a['courier_id'],$b['courier_id']));
        return array_slice($rates,0,max(1,min(10,$limit)));
    }

    public function diagnostics(): array { return $this->diagnostics; }
    public function shipmentSnapshot(): ?array { return $this->shipmentSnapshot; }
}
