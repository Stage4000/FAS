<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/ShippingConfig.php';
require_once __DIR__.'/ShippingNotifications.php';

/** Local configuration inventory, explicitly not carrier or production acceptance. */
final class ShippingReadiness
{
    public static function report(array $config,array $health): array
    {
        $storage=($health['direct_order_storage'] ?? false)===true
            && ($health['cache']['healthy'] ?? false)===true;
        $result=['external_services_checked'=>false,'production_acceptance_verified'=>false,
            'current_mode'=>$config['mode'],'storage_configured'=>$storage,
            'fulfillment_storage_configured'=>($health['label_storage']['initialized'] ?? false)===true
                && ($health['reservation_handoff_storage']['initialized'] ?? false)===true
                && ($health['label_resolution_storage']['initialized'] ?? false)===true,
            'reprint_storage_configured'=>($health['label_reprint_storage']['initialized'] ?? false)===true,
            'label_resolution_storage_configured'=>($health['label_resolution_storage']['initialized'] ?? false)===true,
            'cancellation_storage_configured'=>($health['label_cancellation_storage']['initialized'] ?? false)===true
                && ($health['cancellation_review_storage']['initialized'] ?? false)===true,
            'tracking_storage_configured'=>($health['tracking_storage']['initialized'] ?? false)===true,
            'parcel_data_marked_verified'=>$config['parcel_data_verified'],
            'catalog'=>$health['catalog'] ?? ['schema_initialized'=>false,
                'data_complete_for_direct_quotes'=>false],
            'cancellation_review_storage_initialized'=>($health['cancellation_review_storage']['initialized'] ?? false)===true,
            'carriers'=>[],
            'notifications'=>['configuration_enabled'=>ShippingNotifications::ready($config),
                'storage_initialized'=>($health['notifications']['initialized'] ?? false)===true,
                'requires'=>['controlled recipient inbox verification','scheduled prepare/send worker',
                    'production label and tracking provenance','review of uncertain mail handoffs']],
            'remaining_acceptance'=>['carrier credentials and sandbox acceptance',
                'deployed checkout and payment recovery tests','packed inventory and origin validation',
                'label costs, cancellation and tracking checks','controlled production pilot and billed-cost comparison']];
        foreach (['usps','ups'] as $name) {
            $carrier=$config['carriers'][$name];
            $blockers=[];
            if ($config['mode']==='easyship') $blockers[]='Easyship remains the selected provider';
            if (!$storage) $blockers[]='initialize private cache and migrated direct order storage';
            if (!$config['parcel_data_verified']) $blockers[]='verify packed parcel data';
            $catalog=$result['catalog'];
            if (!($catalog['schema_initialized'] ?? false)) {
                $blockers[]='initialize the saleable inventory schema';
            } elseif (($catalog['saleable_products'] ?? 0)<1) {
                $blockers[]='scan at least one saleable product';
            } else {
                $issues=$catalog['issues'] ?? [];
                if (($issues['measurements'] ?? 0)>0 || ($issues['size'] ?? 0)>0
                    || ($issues['origin'] ?? 0)>0) {
                    $blockers[]='resolve saleable catalog measurement, size and origin gaps';
                }
                if ($catalog['potential_mixed_origin_carts'] ?? false) {
                    $blockers[]='plan mixed-origin carts before full direct rollout';
                }
                if ($name==='usps' && (($issues['usps_weight'] ?? 0)>0
                    || ($issues['usps_size'] ?? 0)>0)) {
                    $blockers[]='plan products over USPS weight or size limits';
                }
            }
            if (!$carrier['enabled']) $blockers[]='carrier disabled';
            if (!ShippingConfig::ready(array_replace($carrier,['enabled'=>true]),$name,false)) $blockers[]='credentials incomplete';
            if ($carrier['environment']!=='production') $blockers[]='sandbox environment';
            if (!$carrier['production_verified']) $blockers[]='production verification not recorded';
            $result['carriers'][$name]=['checkout_configuration_ready'=>$blockers===[],
                'configuration_blockers'=>$blockers,
                'label_purchasing_enabled'=>$carrier['label_purchasing_enabled'],
                'label_reprint_enabled'=>$name==='usps' && ($carrier['label_reprint_enabled'] ?? false)===true,
                'label_cancellation_enabled'=>$carrier['label_cancellation_enabled'],
                'tracking_enabled'=>$carrier['tracking_enabled']];
        }
        return $result;
    }
}
