<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Read-only, bounded immutable-upload snapshot. No decode, encoding or publication. */
final class ImmutableImageSnapshot
{
    private string $directory;
    public function __construct(private string $site, bool $enabled=false)
    {
        if(!$enabled||PHP_SAPI!=='cli'||DIRECTORY_SEPARATOR!=='/'||!function_exists('posix_geteuid'))throw new \RuntimeException('Explicit POSIX CLI snapshot required');
        $this->directory=$site.'/gallery/uploads/immutable';
        foreach([$site,$site.'/gallery',$site.'/gallery/uploads',$this->directory]as$p){
            clearstatcache(true,$p);
            if(is_link($p)||!is_dir($p)||realpath($p)!==$p)throw new \RuntimeException('Canonical fixed upload directories required');
            for($a=$p;$a!==dirname($a);$a=dirname($a)){
                $mode=fileperms($a);$owner=fileowner($a);
                if(is_link($a)||!is_int($mode)||($mode&0022)!==0||!in_array($owner,[0,posix_geteuid()],true))throw new \RuntimeException('Trusted upload ancestors required');
            }
        }
    }
    private static function identity(array $s):array
    {
        $out=[];foreach(['dev','ino','mode','nlink','uid','size','mtime','ctime']as$k){if(!is_int($s[$k]??null))throw new \RuntimeException('Incomplete descriptor identity');$out[$k]=$s[$k];}
        if(($out['mode']&0170000)!==0100000||($out['mode']&0022)!==0||$out['nlink']!==1
            ||!in_array($out['uid'],[0,posix_geteuid()],true)||$out['size']<1||$out['size']>2097152)throw new \RuntimeException('Bounded trusted single-link original required');
        return $out;
    }
    public function read(string $source):array
    {
        if(!preg_match('~^/gallery/uploads/immutable/sha256-([a-f0-9]{64})-([a-f0-9]{32})\.(jpg|png|webp)$~D',$source,$m))throw new \RuntimeException('Exact supported immutable source required');
        $path=$this->site.$source;clearstatcache(true,$path);
        $before=lstat($path);if(!is_array($before)||is_link($path)||realpath($path)!==$path)throw new \RuntimeException('Existing canonical original required');
        $identity=self::identity($before);$h=fopen($path,'rb');if(!$h)throw new \RuntimeException('Original open failed');
        try{
            if(self::identity(fstat($h))!==$identity)throw new \RuntimeException('Original changed at open');
            $bytes=stream_get_contents($h,2097153);
            if(!is_string($bytes)||strlen($bytes)!==$identity['size']||self::identity(fstat($h))!==$identity)throw new \RuntimeException('Original changed during bounded read');
        }finally{fclose($h);}
        clearstatcache(true,$path);$after=lstat($path);
        if(!is_array($after)||self::identity($after)!==$identity||is_link($path)||realpath($path)!==$path||hash('sha256',$bytes)!==$m[1])throw new \RuntimeException('Original path/content binding changed');
        return ['source'=>$source,'source_sha256'=>$m[1],'bytes'=>$bytes,'identity'=>$identity,
            'image_validation_required'=>true,'publication_authorized'=>false];
    }
    public function assertCurrent(array $snapshot):void
    {
        if(!is_string($snapshot['source']??null)||!is_array($snapshot['identity']??null)||!is_string($snapshot['source_sha256']??null)||!is_string($snapshot['bytes']??null))throw new \RuntimeException('Snapshot proof required');
        // Validate the cached bytes as well as the still-current path generation.
        $identity=self::identity($snapshot['identity']);
        if(strlen($snapshot['bytes'])!==$identity['size']||hash('sha256',$snapshot['bytes'])!==$snapshot['source_sha256'])throw new \RuntimeException('Cached snapshot bytes changed');
        $now=$this->read($snapshot['source']);
        if($now['identity']!==$snapshot['identity']||$now['source_sha256']!==$snapshot['source_sha256'])throw new \RuntimeException('Immutable original generation changed');
    }
}
