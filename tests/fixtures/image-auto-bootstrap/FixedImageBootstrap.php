<?php
declare(strict_types=1);
namespace FAS\Utils;
/** Local candidate only. Pins MUST come from a trusted fixed launcher, never request/config data.
 * PHP path restriction is defense in depth, not a native-code or same-UID sandbox. */
final class FixedImageBootstrap
{
    private static bool $started=false;
    public static function start(string $package,string $config,string $configSha,array $pins,int $uid,bool $acquire):array
    {
        if(PHP_SAPI!=='cli'||self::$started||ini_get('open_basedir')!=='')throw new \RuntimeException('Fresh unrestricted trusted bootstrap required');
        self::$started=true;
        if(!function_exists('posix_geteuid')||posix_geteuid()!==$uid)throw new \RuntimeException('Fixed runtime identity required');
        self::trusted($package,$uid);self::trusted($config,$uid);
        if(!is_dir($package)||$config!==dirname($package).'/config.json'||!is_file($config)||(fileperms($config)&0077)!==0)throw new \RuntimeException('Fixed private configuration required');
        if(!preg_match('/^[a-f0-9]{64}$/D',$configSha))throw new \RuntimeException('Config pin required');
        $raw=file_get_contents($config,false,null,0,16385);
        if(!is_string($raw)||strlen($raw)>16384||!hash_equals($configSha,hash('sha256',$raw)))throw new \RuntimeException('Configuration drift');
        $c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        $keys=['database','enabled','input','job','network_enabled','private','site'];$actual=is_array($c)?array_keys($c):[];sort($actual);
        if($actual!==$keys||$c['enabled']!==true||!is_bool($c['network_enabled'])||($acquire&&$c['network_enabled']!==true))throw new \RuntimeException('Exact enabled configuration required');
        foreach(['database','input','job','private','site']as$k)if(!is_string($c[$k])||$c[$k]==='')throw new \RuntimeException('Fixed string paths required');
        if(!preg_match('/^[a-f0-9]{32}$/D',$c['job'])||$c['input']!==$c['private'].'/inputs')throw new \RuntimeException('Fixed job/input required');
        $roots=[$package,$c['private'],$c['site']];
        foreach($roots as$i=>$p){self::trusted($p,$uid);if(!is_dir($p)||$p==='/')throw new \RuntimeException('Dedicated roots required');foreach($roots as$j=>$q)if($i!==$j&&($p===$q||str_starts_with($p,$q.'/')))throw new \RuntimeException('Disjoint dedicated roots required');}
        $dbdir=dirname($c['database']);self::trusted($dbdir,$uid);
        if(!is_dir($dbdir)||$dbdir==='/')throw new \RuntimeException('Dedicated database directory required');
        // Only a strict DB descendant of the already-allowed site is redundant.
        // Never add a parent, equal root, or package/private overlap to the allowlist.
        if(!str_starts_with($dbdir,$c['site'].'/')){
            foreach($roots as$p)if($dbdir===$p||str_starts_with($dbdir,$p.'/')||str_starts_with($p,$dbdir.'/'))throw new \RuntimeException('Database root must not broaden or overlap roots');
            $roots[]=$dbdir;
        }
        foreach([$c['input'],$c['private'].'/tmp',$c['site'].'/gallery',$c['site'].'/gallery/responsive',$c['database']]as$p)self::trusted($p,$uid);
        if(!is_file($c['database'])||sys_get_temp_dir()!==$c['private'].'/tmp'||(fileperms($c['private'].'/tmp')&0077)!==0)throw new \RuntimeException('Existing DB and private temp required');
        $required=['ImageIntakeBinding.php','ImagePilot.php','ImageSourceIntake.php','ImageSourceSelection.php','ImportedImage.php','PosixImageIntakeStore.php','ResponsiveImage.php'];$names=array_keys($pins);sort($names);
        $entries=array_values(array_diff(scandir($package),['.','..']));sort($entries);
        if($entries!==$required)throw new \RuntimeException('Exact pinned package contents required');
        if($names!==$required)throw new \RuntimeException('Exact module pin set required');
        foreach($pins as$name=>$sha){$p=$package.'/'.$name;self::trusted($p,$uid);if(!is_file($p)||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!hash_equals($sha,hash_file('sha256',$p)))throw new \RuntimeException('Module drift');}
        // Load fixed audited definitions only, before request/image bytes or DB handles.
        require_once $package.'/ImagePilot.php';require_once $package.'/PosixImageIntakeStore.php';
        $store=$acquire?new PosixImageIntakeStore($c['private'],$c['site']):null;
        $allowed=implode(PATH_SEPARATOR,$roots);
        if(ini_set('open_basedir',$allowed)===false||ini_get('open_basedir')!==$allowed)throw new \RuntimeException('Restriction activation failed');
        return ['config'=>$c,'store'=>$store,'restriction'=>$allowed];
    }
    private static function trusted(string $p,int $uid):void
    {
        if($p===''||$p[0]!=='/'||str_contains($p,PATH_SEPARATOR)||str_contains($p,"\0")||is_link($p)||realpath($p)!==$p)throw new \RuntimeException('Canonical fixed path required');
        for($a=$p;;$a=dirname($a)){
            $mode=fileperms($a);$owner=fileowner($a);
            if(is_link($a)||$mode===false||$owner===false||($mode&0022)!==0||!in_array($owner,[0,$uid],true))throw new \RuntimeException('Trusted ancestors required');
            if($a===dirname($a))break;
        }
    }
}
