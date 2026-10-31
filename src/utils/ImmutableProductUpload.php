<?php
declare(strict_types=1);
namespace FAS\Utils;

/** New-upload ingestion only. No encoding, catalog writes or receipt freshness claims. */
final class ImmutableProductUpload
{
    private const MAX_BYTES = 20971520;
    private const MAX_PIXELS = 20000000;

    public static function receive(string $temporaryUpload, string $applicationRoot): array
    {
        if (!is_uploaded_file($temporaryUpload)) throw new \RuntimeException('Expected a successful HTTP upload.');
        $bytes = @file_get_contents($temporaryUpload, false, null, 0, self::MAX_BYTES + 1);
        if ($bytes === false) throw new \RuntimeException('Unable to read uploaded image.');
        return self::storeSnapshot($bytes, $applicationRoot);
    }

    // Private seam for synthetic fixture tests; HTTP callers cannot substitute an upload validator.
    private static function storeSnapshot(string $bytes, string $applicationRoot, ?string $fixtureNonce = null): array
    {
        $length = strlen($bytes);
        if ($length < 1 || $length > self::MAX_BYTES) throw new \InvalidArgumentException('Image exceeds upload byte limit.');
        $size = @getimagesizefromstring($bytes);
        $extensions = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_GIF=>'gif', IMAGETYPE_WEBP=>'webp'];
        if (!$size || !isset($extensions[$size[2]]) || $size[0] < 1 || $size[1] < 1
            || $size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Unsupported image or pixel limit exceeded.');
        }
        $root = realpath($applicationRoot);
        if ($root === false || !is_dir($root)) throw new \RuntimeException('Application root unavailable.');
        $dir = $root;
        foreach (['gallery','uploads','immutable'] as $component) {
            $dir .= DIRECTORY_SEPARATOR.$component;
            if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) throw new \RuntimeException('Unsafe upload directory.');
            if (!is_dir($dir) && !@mkdir($dir,0755) && !is_dir($dir)) throw new \RuntimeException('Unable to prepare upload directory.');
            if (realpath($dir) !== $dir) throw new \RuntimeException('Upload directory is not canonical.');
        }
        $hash = hash('sha256',$bytes);
        // Originals are never shared across uploads: catalog-save/cleanup are not one transaction.
        $nonce = $fixtureNonce ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) throw new \InvalidArgumentException('Invalid upload identity.');
        $name = 'sha256-'.$hash.'-'.$nonce.'.'.$extensions[$size[2]];
        $target = $dir.DIRECTORY_SEPARATOR.$name;
        $temporary = null;
        try {
            if (!file_exists($target) && !is_link($target)) {
                $temporary = tempnam($dir,'.ingest-');
                if ($temporary === false || dirname($temporary) !== $dir) throw new \RuntimeException('Unable to stage upload.');
                if (file_put_contents($temporary,$bytes) !== $length || !chmod($temporary,0644)
                    || hash_file('sha256',$temporary) !== $hash) throw new \RuntimeException('Staged upload verification failed.');
                // Hard-link publication is no-clobber: an existing final name is never overwritten.
                if (!@link($temporary,$target) && !file_exists($target)) throw new \RuntimeException('Unable to publish upload without replacement.');
            }
            clearstatcache(true,$target);
            if (is_link($target) || !is_file($target) || filesize($target) !== $length
                || hash_file('sha256',$target) !== $hash) throw new \RuntimeException('Existing image identity conflicts; retained unchanged.');
            return ['path'=>'/gallery/uploads/immutable/'.$name, 'sha256'=>$hash,
                'bytes'=>$length,'width'=>$size[0],'height'=>$size[1],'mime'=>$size['mime']];
        } finally {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
        }
    }
}
