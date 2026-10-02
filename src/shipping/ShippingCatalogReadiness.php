<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingShipment.php';

/** Read-only scan of currently saleable catalog parcels and direct origins. */
final class ShippingCatalogReadiness
{
    public static function report(?\PDO $db): array
    {
        $empty=['schema_initialized'=>false,'saleable_products'=>0,'complete_measurements'=>0,
            'assigned_origin'=>0,'default_origin'=>0,'distinct_origin_addresses'=>0,
            'issues'=>['measurements'=>0,'size'=>0,'origin'=>0,'usps_weight'=>0,'usps_size'=>0],
            'example_product_ids'=>['measurements'=>[],'size'=>[],'origin'=>[],
                'usps_weight'=>[],'usps_size'=>[]],
            'potential_mixed_origin_carts'=>false,'data_complete_for_direct_quotes'=>false];
        if (!$db) return $empty;
        $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('products','warehouses')")
            ->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array('products',$tables,true) || !in_array('warehouses',$tables,true)) return $empty;
        foreach (['products'=>['id','warehouse_id','weight','length','width','height','quantity','is_active','show_on_website'],
            'warehouses'=>['id','is_active','is_default','address_line1','address_line2','city','state',
                'postal_code','country_code']] as $table=>$required) {
            $columns=$db->query('PRAGMA table_info('.$table.')')->fetchAll(\PDO::FETCH_COLUMN,1);
            if (array_diff($required,$columns)) return $empty;
        }
        $report=$empty;
        $report['schema_initialized']=true;
        $warehouses=[]; $defaults=[];
        foreach ($db->query('SELECT * FROM warehouses') as $warehouse) {
            $id=(int)$warehouse['id'];
            $warehouses[$id]=$warehouse;
            if ((int)$warehouse['is_active']===1 && (int)$warehouse['is_default']===1) {
                $defaults[]=$warehouse;
            }
        }
        $origins=[];
        $stmt=$db->query('SELECT id,warehouse_id,weight,length,width,height FROM products
            WHERE is_active=1 AND show_on_website=1 AND quantity>0 ORDER BY id');
        while ($product=$stmt->fetch(\PDO::FETCH_ASSOC)) {
            $report['saleable_products']++;
            $id=(int)$product['id'];
            $edges=[]; $measured=true;
            foreach (['weight','length','width','height'] as $field) {
                $value=$product[$field] ?? null;
                if (!is_numeric($value) || !is_finite((float)$value)
                    || (float)$value<=0 || (float)$value>150
                    || round((float)$value,3)<=0) { $measured=false; break; }
                if ($field!=='weight') $edges[]=round((float)$value,3);
            }
            if (!$measured) self::issue($report,'measurements',$id);
            else {
                $report['complete_measurements']++;
                rsort($edges);
                if ($edges[0]>108 || $edges[0]+2*($edges[1]+$edges[2])>165) {
                    self::issue($report,'size',$id);
                }
                if ((float)$product['weight']>70) self::issue($report,'usps_weight',$id);
                if ($edges[0]+2*($edges[1]+$edges[2])>130) self::issue($report,'usps_size',$id);
            }
            $assigned=(int)($product['warehouse_id'] ?? 0);
            $warehouse=$assigned>0 ? ($warehouses[$assigned] ?? null)
                : (count($defaults)===1 ? $defaults[0] : null);
            if (is_array($warehouse) && (int)$warehouse['is_active']!==1) $warehouse=null;
            if (!is_array($warehouse)) { self::issue($report,'origin',$id); continue; }
            try {
                $origin=ShippingShipment::address($warehouse,true);
                if ($origin['address1']==='') throw new \RuntimeException('Origin street missing.');
                $origins[hash('sha256',json_encode($origin,JSON_THROW_ON_ERROR))]=true;
                if ($assigned===0) $report['default_origin']++;
                else $report['assigned_origin']++;
            } catch (\Throwable $e) { self::issue($report,'origin',$id); }
        }
        $report['distinct_origin_addresses']=count($origins);
        $report['potential_mixed_origin_carts']=count($origins)>1;
        $report['data_complete_for_direct_quotes']=$report['saleable_products']>0
            && $report['issues']['measurements']===0 && $report['issues']['size']===0
            && $report['issues']['origin']===0 && !$report['potential_mixed_origin_carts'];
        return $report;
    }

    private static function issue(array &$report,string $type,int $productId): void
    {
        $report['issues'][$type]++;
        if (count($report['example_product_ids'][$type])<20) {
            $report['example_product_ids'][$type][]=$productId;
        }
    }
}
