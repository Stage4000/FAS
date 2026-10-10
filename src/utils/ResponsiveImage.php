<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Read-only rendering; derivatives are built by the CLI, never by public requests. */
final class ResponsiveImage
{
    public const WIDTHS=[80,160,320,640,960,1440];

    private static function local(string $source,string $root): ?array
    {
        $parts=parse_url($source);
        if($parts===false || isset($parts['host']) || isset($parts['scheme']) || isset($parts['query']) || isset($parts['fragment']))return null;
        $path='/'.ltrim(rawurldecode($parts['path']??''),'/');
        if(strpos($path,"\0")!==false || strpos($path,'\\')!==false || preg_match('~(?:^|/)\.\.(?:/|$)~',$path))return null;
        if(!preg_match('~^/(?:gallery/|public/uploads/)~',$path) || strpos($path,'/gallery/responsive/')===0)return null;
        $root=realpath($root);$file=$root?realpath($root.$path):false;
        if(!$file || !is_file($file) || !preg_match('/\.(?:jpe?g|png|webp)$/i',$file))return null;
        $normalized=str_replace('\\','/',$file);$prefix=rtrim(str_replace('\\','/',$root),'/').'/';
        if(DIRECTORY_SEPARATOR==='\\'){$normalized=strtolower($normalized);$prefix=strtolower($prefix);}
        if(strpos($normalized,$prefix)!==0)return null;
        $bytes=filesize($file);if($bytes===false || $bytes>20*1024*1024)return null;
        $size=@getimagesize($file);
        if(!$size || !in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))return null;
        // Rotated EXIF photos keep the original, which browsers orient correctly.
        if($size[2]===IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif=@exif_read_data($file,'IFD0',true,false);
            if((int)($exif['IFD0']['Orientation']??1)!==1)return null;
        }
        return ['file'=>$file,'path'=>$path,'width'=>(int)$size[0],'height'=>(int)$size[1],'bytes'=>$bytes,
            'key'=>hash('sha256',$path.'|'.filemtime($file).'|'.$bytes),'type'=>$size[2]];
    }

    public static function attributes(string $source,string $sizes,int $maxWidth=0,?string $root=null,?string $productId=null): string
    {
        $root=$root??dirname(__DIR__,2);
        $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $attrs='src="'.$escape($source).'"';
        $info=self::local($source,$root);
        if(!$info) {
            if($productId!==null) {
                require_once __DIR__.'/ImportedImage.php';
                return ImportedImage::attributes($source,$sizes,$maxWidth,$root,$productId)??$attrs;
            }
            return $attrs;
        }
        $attrs.=' width="'.$info['width'].'" height="'.$info['height'].'"';
        $candidates=[];
        foreach(self::WIDTHS as $width) {
            if($width>=$info['width'] || ($maxWidth>0 && $width>$maxWidth))continue;
            $path='/gallery/responsive/'.$info['key'].'-'.$width.'.webp';
            if(is_file($root.$path))$candidates[]=$path.' '.$width.'w';
        }
        if($candidates) {
            if(!$maxWidth || $info['width']<=$maxWidth)$candidates[]=$source.' '.$info['width'].'w';
            $attrs.=' srcset="'.$escape(implode(', ',$candidates)).'" sizes="'.$escape($sizes).'"';
        }
        return $attrs;
    }

    /** Offline import; no network requests or inventory mutations. */
    public static function importRemote(array $record,string $inputDirectory,?string $root=null): array
    {
        require_once __DIR__.'/ImportedImage.php';
        return ImportedImage::import($record,$inputDirectory,$root??dirname(__DIR__,2));
    }

    public static function build(string $source,?string $root=null): array
    {
        $root=$root??dirname(__DIR__,2);$info=self::local($source,$root);
        if(!$info)return ['source'=>$source,'status'=>'skipped','reason'=>'Not an eligible local image'];
        if($info['width']*$info['height']>20000000)return ['source'=>$source,'status'=>'skipped','reason'=>'Image exceeds the processing pixel limit'];
        if(!function_exists('imagewebp'))throw new \RuntimeException('PHP GD with WebP support is required.');
        $dir=$root.'/gallery/responsive';
        if(is_link($dir))throw new \RuntimeException('The derivative directory must not be a symlink.');
        if(!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir))throw new \RuntimeException('Cannot create image derivative directory.');
        $lock=fopen($dir.'/.build.lock','c');
        if(!$lock || !flock($lock,LOCK_EX))throw new \RuntimeException('Cannot lock image builder.');
        $image=null;$variants=[];
        try {
            foreach(self::WIDTHS as $width) {
                if($width>=$info['width'])continue;
                $target=$dir.'/'.$info['key'].'-'.$width.'.webp';
                if(!is_file($target)) {
                    if(!$image)$image=match($info['type']) {
                        IMAGETYPE_JPEG=>imagecreatefromjpeg($info['file']),IMAGETYPE_PNG=>imagecreatefrompng($info['file']),IMAGETYPE_WEBP=>imagecreatefromwebp($info['file'])
                    };
                    if(!$image)throw new \RuntimeException('Could not decode image.');
                    $height=max(1,(int)round($info['height']*$width/$info['width']));
                    $resized=imagecreatetruecolor($width,$height);
                    imagealphablending($resized,false);imagesavealpha($resized,true);
                    imagefill($resized,0,0,imagecolorallocatealpha($resized,0,0,0,127));
                    imagecopyresampled($resized,$image,0,0,0,0,$width,$height,$info['width'],$info['height']);
                    $temp=tempnam($dir,'.image-');
                    try {
                        if(!imagewebp($resized,$temp,84) || !filesize($temp))throw new \RuntimeException('Could not encode derivative.');
                        if(filesize($temp)<$info['bytes']) {
                            if(!rename($temp,$target))throw new \RuntimeException('Could not publish derivative.');
                            @chmod($target,0644);
                        }
                    }finally{imagedestroy($resized);if(is_file($temp))unlink($temp);}
                }
                if(is_file($target))$variants[(string)$width]=filesize($target);
            }
        } finally {if($image)imagedestroy($image);flock($lock,LOCK_UN);fclose($lock);}
        return ['source'=>$source,'status'=>'ready','original_bytes'=>$info['bytes'],'width'=>$info['width'],'height'=>$info['height'],'variant_bytes'=>$variants];
    }
}
