<?php
declare(strict_types=1);
namespace FAS\Shipping;

require_once __DIR__.'/ShippingConfig.php';

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
        $path=self::writablePath();
        $lock=fopen($path.'.lock','c');
        if ($lock===false) throw new \RuntimeException('Private shipping settings are not writable.');
        try {
            if (!chmod($path.'.lock',0600)) throw new \RuntimeException('Private shipping settings lock is not protected.');
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Private shipping settings are busy.');
            $config=self::currentForWrite($path);
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
            $config=ShippingConfig::validate($config);
            self::atomicWrite($path,$config);
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
