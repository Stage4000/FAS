<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Offline private preparation only. This class cannot publish into the application root. */
final class ImageOptimizationStage
{
    private string $privateRoot;
    public function __construct(private ImageOptimizationQueue $queue, string $applicationRoot, string $privateRoot)
    {
        clearstatcache(true, $privateRoot);
        $app = realpath($applicationRoot); $private = realpath($privateRoot);
        if (!$app || !$private || !is_dir($private) || is_link($privateRoot)
            || $private !== rtrim($privateRoot, '/') || $private === $app
            || str_starts_with($private, $app.'/') || str_starts_with($app, $private.'/')
            || (fileperms($private) & 0077) !== 0) {
            throw new \InvalidArgumentException('An isolated owner-private staging directory outside the application is required.');
        }
        $this->privateRoot = $private;
    }

    /** Supplied verification must already exist; no network or renewed timestamps. */
    public function prepare(array $claim, array $record, string $inputDirectory): array
    {
        $intent = $this->queue->currentIntent($claim, time());
        if (!$intent || $intent['kind'] !== 'ebay'
            || ($record['product_id'] ?? null) !== $intent['product_id']
            || ($record['source_url'] ?? null) !== $intent['source']
            || ($record['provenance']['excluded'] ?? null) !== false) {
            throw new \DomainException('Current lease, catalog binding and matching verified source are required.');
        }
        $work = $this->privateRoot.'/image-stage-'.bin2hex(random_bytes(16));
        if (!mkdir($work,0700)) throw new \RuntimeException('Cannot create private image stage.');
        $prepared = false;
        try {
        // Existing importer writes only to this new private root. Original supplied bytes remain untouched.
        $result = ImportedImage::import($record,$inputDirectory,$work);
        if (($result['status'] ?? null) !== 'ready') throw new \RuntimeException('Image preparation did not produce a ready private stage.');
        $name = 'remote-'.hash('sha256',$intent['product_id']."\n".$intent['source']).'.json';
        $receiptPath = $work.'/gallery/responsive/'.$name;
        if (is_link($receiptPath) || !is_file($receiptPath)) throw new \RuntimeException('Missing staged receipt.');
        $json = file_get_contents($receiptPath,false,null,0,16385);
        if (!is_string($json) || strlen($json)>16384) throw new \RuntimeException('Invalid staged receipt size.');
        $r = json_decode($json,true,16,JSON_THROW_ON_ERROR);
        $now=time();
        if (!is_array($r) || ($r['version'] ?? null)!==1 || ($r['product_id'] ?? null)!==$intent['product_id']
            || ($r['source_url'] ?? null)!==$intent['source'] || ($r['source_sha256'] ?? null)!==($record['source_sha256'] ?? null)
            || !is_string($r['source_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$r['source_sha256'])
            || ($r['excluded'] ?? false)!==false
            || ($r['verified_at'] ?? null) !== strtotime((string)($record['verified_at'] ?? '')) || ($r['valid_until'] ?? null) !== strtotime((string)($record['valid_until'] ?? ''))
            || ($r['optimization_authorized'] ?? null)!==true || ($r['provenance_basis'] ?? null)!=='existing_catalog'
            || !is_int($r['verified_at'] ?? null) || $r['verified_at']<1 || $r['verified_at']>$now
            || !is_int($r['valid_until'] ?? null) || $r['valid_until']<=$now || $r['valid_until']>$r['verified_at']+30*86400
            || !is_int($r['width'] ?? null) || !is_int($r['height'] ?? null) || $r['width']<1 || $r['height']<1
            || $r['width']>20000000 || $r['height']>20000000 || $r['width']*$r['height']>20000000
            || !is_int($r['original_bytes'] ?? null) || $r['original_bytes']<1 || $r['original_bytes']>20971520
            || !is_array($r['variants'] ?? null) || count($r['variants'])<1 || count($r['variants'])>count(ResponsiveImage::WIDTHS)) {
            throw new \RuntimeException('Staged receipt failed verification.');
        }
        $files=[];
        foreach ($r['variants'] as $width=>$meta) {
            if (!is_int($width) || !in_array($width,ResponsiveImage::WIDTHS,true) || $width >= $r['width']
                || !is_array($meta) || !is_int($meta['bytes'] ?? null) || $meta['bytes']<1 || $meta['bytes']>=$r['original_bytes']
                || !is_string($meta['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$meta['sha256'])) throw new \RuntimeException('Invalid staged variant metadata.');
            $relative='gallery/responsive/remote-'.$r['source_sha256'].'-'.$width.'.webp';
            $path=$work.'/'.$relative;
            $size=is_link($path) ? false : @getimagesize($path);
            if (!is_file($path) || !$size || $size[2]!==IMAGETYPE_WEBP || $size[0]!==$width
                || $size[1]!==max(1,(int)round($r['height']*$width/$r['width']))
                || filesize($path)!==$meta['bytes'] || hash_file('sha256',$path)!==$meta['sha256']) throw new \RuntimeException('Staged derivative failed verification.');
            $files[$relative]=$meta;
        }
        if ($this->queue->currentIntent($claim,time()) !== $intent) throw new \DomainException('Catalog or lease changed during preparation; stage cannot activate.');
        $files['gallery/responsive/'.$name]=['bytes'=>strlen($json),'sha256'=>hash('sha256',$json)];
        $prepared = true;
        return ['state'=>'prepared_private','workspace'=>$work,'intent'=>$intent,'files'=>$files,'valid_until'=>$r['valid_until']];
        } finally {
            if (!$prepared) self::removePrivateWork($work);
        }
    }

    private static function removePrivateWork(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            self::removePrivateWork($entry->getPathname());
        }
        rmdir($path);
    }
}
