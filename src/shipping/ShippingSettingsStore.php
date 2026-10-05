<?php
declare(strict_types=1);
namespace FAS\Shipping;

require_once __DIR__.'/ShippingConfig.php';
require_once __DIR__.'/ShippingCache.php';
require_once __DIR__.'/ShippingOrder.php';
require_once __DIR__.'/ShippingCatalogReadiness.php';

/** Writes only the deployment-owned shipping configuration, never site settings. */
final class ShippingSettingsStore
{
    private const FIELDS = [
        'usps'=>['client_id','client_secret','crid','mid','manifest_mid','eps_account_number'],
        'ups'=>['client_id','client_secret','account_number'],
    ];

    public static function credentialPresence(array $config, string $carrier): array
    {
        if (!isset(self::FIELDS[$carrier])) throw new \InvalidArgumentException('Unknown carrier.');
        $status=[];
        foreach (self::FIELDS[$carrier] as $field) {
            $status[$field]=($config['carriers'][$carrier][$field] ?? '')!=='';
        }
        return $status;
    }

    public static function save(string $carrier, array $submitted, bool $clear = false): void
    {
        if (!isset(self::FIELDS[$carrier])) throw new \InvalidArgumentException('Unknown carrier.');
        self::updateManaged(static function(array $config) use($carrier,$submitted,$clear): array {
            if ($config['mode']!=='easyship') {
                throw new \InvalidArgumentException('Switch checkout to Easyship before changing carrier credentials.');
            }
            foreach ($config['carriers'] as $state) {
                if ($state['label_purchasing_enabled'] || ($state['label_reprint_enabled'] ?? false)
                    || $state['label_cancellation_enabled'] || $state['tracking_enabled']) {
                    throw new \InvalidArgumentException('Pause carrier operations before changing credentials.');
                }
            }
            foreach (self::FIELDS[$carrier] as $field) {
                $value=$submitted[$field] ?? '';
                if (!is_string($value)) throw new \InvalidArgumentException('Invalid credential field.');
                $value=trim($value);
                if (strlen($value)>4096 || preg_match('/[\x00-\x1f\x7f]/',$value)) {
                    throw new \InvalidArgumentException('Invalid credential field.');
                }
                if ($clear || $value!=='') $config['carriers'][$carrier][$field]=$clear?'':$value;
            }
            if ($carrier==='usps' && !$clear) {
                foreach (['gateway'=>['apis','api'],'price_type'=>['RETAIL','COMMERCIAL']] as $field=>$allowed) {
                    $value=$submitted[$field] ?? $config['carriers']['usps'][$field];
                    if (!is_string($value) || !in_array($value,$allowed,true)) {
                        throw new \InvalidArgumentException('Choose valid USPS account options.');
                    }
                    $config['carriers']['usps'][$field]=$value;
                }
            }
            // New credentials never imply approval to quote, buy, cancel, track or email.
            $config['carriers'][$carrier]['enabled']=false;
            $config['carriers'][$carrier]['production_verified']=false;
            return $config;
        });
    }

    public static function approveCarrier(string $carrier, bool $confirmed): void
    {
        if (!isset(self::FIELDS[$carrier])) throw new \InvalidArgumentException('Unknown carrier.');
        if (!$confirmed) throw new \InvalidArgumentException('Confirm production account and rate testing first.');
        self::updateManaged(static function(array $config) use($carrier): array {
            if ($config['mode']!=='easyship') {
                throw new \InvalidArgumentException('Switch checkout to Easyship before approving a carrier.');
            }
            $candidate=$config['carriers'][$carrier];
            $candidate['enabled']=true;
            if (!ShippingConfig::ready($candidate,$carrier,false)) {
                throw new \InvalidArgumentException('Enter the required carrier account details first.');
            }
            $candidate['environment']='production';
            $candidate['production_verified']=true;
            $config['carriers'][$carrier]=$candidate;
            return $config;
        });
    }

    /** A local readiness gate, not a substitute for a tested carrier account. */
    public static function directBlockers(array $config, \PDO $orders): array
    {
        $blockers=[];
        if (!ShippingOrder::directReady($orders)) $blockers[]='install the direct order-shipping migration';
        if ($config['cache_path']==='') $blockers[]='configure private shipping storage';
        else {
            try {
                if (!(new ShippingCache($config['cache_path']))->health()['healthy']) {
                    $blockers[]='repair private shipping storage';
                }
            } catch (\Throwable $e) { $blockers[]='initialize private shipping storage'; }
        }
        try { $catalog=ShippingCatalogReadiness::report($orders); }
        catch (\Throwable $e) { $catalog=['data_complete_for_direct_quotes'=>false,'issues'=>[]]; }
        if (!$catalog['data_complete_for_direct_quotes']) {
            $blockers[]='resolve packed measurements, ship-from origins and mixed-origin carts';
        }
        $active=[];
        foreach (['usps','ups'] as $carrier) {
            if (!$config['carriers'][$carrier]['enabled']) continue;
            $active[]=$carrier;
            if (!ShippingConfig::ready($config['carriers'][$carrier],$carrier,true)) {
                $blockers[]='finish '.$carrier.' production account approval';
            }
        }
        if (!$active) $blockers[]='approve at least one production carrier';
        if ($active===['usps'] && (($catalog['issues']['usps_weight'] ?? 0)>0
            || ($catalog['issues']['usps_size'] ?? 0)>0)) {
            $blockers[]='resolve parcels outside USPS limits or approve UPS too';
        }
        return $blockers;
    }

    public static function setMode(string $mode, \PDO $orders, bool $confirmedPacking, bool $confirmedRates): void
    {
        if (!in_array($mode,['easyship','direct'],true)) throw new \InvalidArgumentException('Choose a valid checkout provider.');
        self::updateManaged(static function(array $config) use($mode,$orders,$confirmedPacking,$confirmedRates): array {
            if ($mode==='direct') {
                if (!$confirmedPacking || !$confirmedRates) {
                    throw new \InvalidArgumentException('Confirm packed inventory and tested production rates before switching.');
                }
                $blockers=self::directBlockers($config,$orders);
                if ($blockers) throw new \InvalidArgumentException('Direct shipping needs: '.implode('; ',$blockers).'.');
                $config['parcel_data_verified']=true;
            } elseif ($config['mode']!=='easyship' && !self::easyshipConfigured()) {
                throw new \InvalidArgumentException('Save a valid Easyship API key before switching back.');
            }
            $config['mode']=$mode;
            return $config;
        });
    }

    public static function easyshipConfigured(): bool
    {
        $config=self::siteConfig();
        $key=$config['easyship']['api_key'] ?? '';
        return is_string($key) && $key!=='' && $key!=='YOUR_EASYSHIP_API_KEY';
    }

    public static function saveEasyship(array $submitted): void
    {
        self::updateSite(static function(array $config) use($submitted): array {
            $key=$submitted['easyship_api_key'] ?? '';
            $name=$submitted['easyship_platform_name'] ?? '';
            $prefix=$submitted['easyship_prefix'] ?? '';
            if (!is_string($key) || !is_string($name) || !is_string($prefix)) {
                throw new \InvalidArgumentException('Invalid Easyship settings.');
            }
            $key=trim($key); $name=trim($name); $prefix=trim($prefix);
            if (strlen($key)>4096 || preg_match('/[\x00-\x1f\x7f]/',$key)
                || $name==='' || strlen($name)>100 || preg_match('/[\x00-\x1f\x7f]/',$name)
                || !preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D',$prefix)) {
                throw new \InvalidArgumentException('Check the Easyship key, platform name and order prefix.');
            }
            $config['easyship']['platform_name']=$name;
            $config['easyship']['platform_order_number_prefix']=$prefix;
            if ($key!=='') $config['easyship']['api_key']=$key;
            return $config;
        });
    }

    /** General settings never overwrite a newer Easyship save. */
    public static function saveGeneralSettings(array $submitted): array
    {
        return self::updateSite(static function(array $current) use($submitted): array {
            $submitted['easyship']=$current['easyship'] ?? [];
            $submitted['sale']=$current['sale'] ?? ($submitted['sale'] ?? []);
            $submitted['shipping']=$current['shipping'] ?? ($submitted['shipping'] ?? []);
            return $submitted;
        });
    }

    private static function updateManaged(callable $change): void
    {
        $path=self::writablePath();
        $lock=fopen($path.'.lock','c');
        if ($lock===false) throw new \RuntimeException('Private shipping settings are not writable.');
        try {
            if (!chmod($path.'.lock',0600)) throw new \RuntimeException('Private shipping settings lock is not protected.');
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Private shipping settings are busy.');
            $config=$change(self::currentForWrite($path));
            self::atomicWrite($path,ShippingConfig::validate($config));
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private static function siteConfig(): array
    {
        $path=__DIR__.'/../config/config.php';
        $config=require (is_file($path) ? $path : __DIR__.'/../config/config.example.php');
        if (!is_array($config)) throw new \RuntimeException('Site settings are invalid.');
        return $config;
    }

    private static function updateSite(callable $change): array
    {
        $path=__DIR__.'/../config/config.php';
        $lock=fopen($path.'.shipping-write.lock','c');
        if ($lock===false) throw new \RuntimeException('Site settings are not writable.');
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Site settings are busy.');
            $config=$change(self::siteConfig());
            $mode=is_file($path) ? (fileperms($path) & 0777) : 0600;
            self::atomicWrite($path,$config,$mode);
            return $config;
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private static function currentForWrite(string $path): array
    {
        // On first save, carry forward any old deployment-owned configuration.
        if (is_file($path)) return ShippingConfig::load();
        $legacy=__DIR__.'/../config/shipping.php';
        $config=require __DIR__.'/../config/shipping.example.php';
        if (is_file($legacy)) {
            $previous=require $legacy;
            if (!is_array($previous)) throw new \RuntimeException('Existing shipping configuration is invalid.');
            $config=array_replace_recursive($config,$previous);
        }
        return ShippingConfig::validate($config);
    }

    private static function writablePath(): string
    {
        $path=ShippingConfig::managedPath();
        if (str_contains($path,"\0") || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path)
            || is_link($path)) {
            throw new \RuntimeException('A private absolute shipping settings path is required.');
        }
        $parent=dirname($path);
        $default=getenv('FAS_SHIPPING_CONFIG_PATH')===false || getenv('FAS_SHIPPING_CONFIG_PATH')==='';
        if ($default && !is_dir($parent) && !mkdir($parent,0700,false)) {
            throw new \RuntimeException('Private shipping settings directory could not be created.');
        }
        $realParent=realpath($parent);
        if ($realParent===false || !is_dir($realParent)) {
            throw new \RuntimeException('Create the private shipping settings directory first.');
        }
        $target=self::normalize(is_file($path) ? (realpath($path) ?: $path) : $realParent.'/'.basename($path));
        foreach ([dirname(__DIR__,2),$_SERVER['DOCUMENT_ROOT'] ?? ''] as $webRoot) {
            if ($webRoot!=='' && ($realWeb=realpath($webRoot))) {
                $web=self::normalize($realWeb);
                if ($target===$web || str_starts_with($target,$web.'/')) {
                    throw new \RuntimeException('Shipping settings must stay outside the web root.');
                }
            }
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($realParent) & 0077)) {
            throw new \RuntimeException('Private shipping settings directory must be owner-only.');
        }
        return $path;
    }

    private static function normalize(string $path): string
    {
        return strtolower(rtrim(str_replace('\\','/',$path),'/'));
    }

    private static function atomicWrite(string $path,array $config): void
    {
        $temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $content="<?php\n// Private shipping settings. Never serve or commit this file.\nreturn "
            .var_export($config,true).";\n";
        $oldMask=umask(0077);
        try {
            $handle=fopen($temporary,'x');
            if ($handle===false) throw new \RuntimeException('Private shipping settings are not writable.');
            try {
                $remaining=$content;
                while ($remaining!=='') {
                    $written=fwrite($handle,$remaining);
                    if ($written===false || $written===0) throw new \RuntimeException('Private shipping settings write failed.');
                    $remaining=substr($remaining,$written);
                }
                if (!fflush($handle)) throw new \RuntimeException('Private shipping settings write failed.');
            } finally { fclose($handle); }
            if (!chmod($temporary,0600) || !rename($temporary,$path)) {
                throw new \RuntimeException('Private shipping settings could not be saved.');
            }
            clearstatcache(true,$path);
            if (function_exists('opcache_invalidate')) opcache_invalidate($path,true);
        } finally {
            if (is_file($temporary)) unlink($temporary);
            umask($oldMask);
        }
    }
}
