<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Offline provenance gate and atomic delivery receipts. This class never fetches URLs. */
final class ImportedImage
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_AGE = 30 * 86400;

    private static function allowedSource(string $url): bool
    {
        if (strlen($url) > 2048 || str_ends_with($url, ',') || preg_match('~[\x00-\x20\x7f\\\\]~', $url)) return false;
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'i.ebayimg.com'
            && !isset($p['user']) && !isset($p['pass'])
            && !isset($p['port']) && !isset($p['fragment']) && str_starts_with($p['path'] ?? '', '/');
    }

    private static function productId(string $id): bool { return (bool)preg_match('/^[1-9][0-9]{0,19}$/D', $id); }
    private static function hashValue($value): bool { return is_string($value) && (bool)preg_match('/^[a-f0-9]{64}$/D', $value); }
    private static function receiptName(string $productId, string $source): string { return 'remote-'.hash('sha256', $productId."\n".$source).'.json'; }
    private static function variantName(string $hash, int $width): string { return 'remote-'.$hash.'-'.$width.'.webp'; }

    private static function directory(string $root, bool $create): ?string
    {
        $root = realpath($root);
        if (!$root) return null;
        foreach (['/gallery', '/gallery/responsive'] as $suffix) {
            $dir = $root.$suffix;
            if (is_link($dir)) return null;
            if (!is_dir($dir) && (!$create || !@mkdir($dir, 0755))) return null;
            if (realpath($dir) !== $dir) return null;
        }
        return $root.'/gallery/responsive';
    }

    private static function validPeriod($verified, $until): bool
    {
        $now = time();
        return is_int($verified) && is_int($until) && $verified > 0 && $verified <= $now
            && $until > $now && $until > $verified && $until <= $verified + self::MAX_AGE;
    }

    private static function timestamp($value): int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return 0;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) ? $date->getTimestamp() : 0;
    }

    private static function verifiedVariant(string $file, int $width, int $height, array $meta): bool
    {
        if (is_link($file) || !is_file($file) || !self::hashValue($meta['sha256'] ?? null)
            || !is_int($meta['bytes'] ?? null) || $meta['bytes'] < 1 || $meta['bytes'] > self::MAX_BYTES) return false;
        clearstatcache(true, $file);
        if (@filesize($file) !== $meta['bytes'] || @hash_file('sha256', $file) !== $meta['sha256']) return false;
        $size = @getimagesize($file);
        return $size && $size[2] === IMAGETYPE_WEBP && $size[0] === $width && $size[1] === $height;
    }

    /** Only a matching, current receipt and individually verified files affect markup. */
    public static function attributes(string $source, string $sizes, int $maxWidth, string $root, string $productId): ?string
    {
        if (!self::productId($productId) || !self::allowedSource($source)) return null;
        $dir = self::directory($root, false);
        if (!$dir) return null;
        $file = $dir.'/'.self::receiptName($productId, $source);
        if (is_link($file) || !is_file($file)) return null;
        $json = @file_get_contents($file, false, null, 0, 16385);
        if ($json === false || strlen($json) > 16384) return null;
        $r = json_decode($json, true);
        if (!is_array($r) || ($r['version'] ?? null) !== 1 || ($r['product_id'] ?? null) !== $productId
            || ($r['source_url'] ?? null) !== $source || ($r['excluded'] ?? false) === true || ($r['optimization_authorized'] ?? null) !== true || ($r['provenance_basis'] ?? null) !== 'existing_catalog'
            || !self::hashValue($r['source_sha256'] ?? null) || !self::validPeriod($r['verified_at'] ?? null, $r['valid_until'] ?? null)
            || !is_int($r['width'] ?? null) || !is_int($r['height'] ?? null) || $r['width'] < 1 || $r['height'] < 1
            || $r['width'] > 20000000 || $r['height'] > 20000000 || $r['width'] * $r['height'] > 20000000
            || !is_int($r['original_bytes'] ?? null) || $r['original_bytes'] < 1 || $r['original_bytes'] > self::MAX_BYTES
            || !is_array($r['variants'] ?? null)) return null;
        $candidates = [];
        foreach (ResponsiveImage::WIDTHS as $width) {
            if ($width >= $r['width'] || ($maxWidth > 0 && $width > $maxWidth)) continue;
            $meta = $r['variants'][$width] ?? null;
            $name = self::variantName($r['source_sha256'], $width);
            $height = max(1, (int)round($r['height'] * $width / $r['width']));
            if (is_array($meta) && ($meta['bytes'] ?? self::MAX_BYTES) < $r['original_bytes']
                && self::verifiedVariant($dir.'/'.$name, $width, $height, $meta)) $candidates[] = '/gallery/responsive/'.$name.' '.$width.'w';
        }
        if (!$candidates) return null;
        // Preserve the measured native-resolution choice. Internal URL commas are valid srcset URL characters; trailing commas are rejected.
        if (!$maxWidth || $r['width'] <= $maxWidth) $candidates[] = $source.' '.$r['width'].'w';
        $escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return 'src="'.$escape($source).'" width="'.$r['width'].'" height="'.$r['height'].'" srcset="'.$escape(implode(', ', $candidates)).'" sizes="'.$escape($sizes).'"';
    }

    /** Bounded operator audit; warnings provide a refresh path before the original fallback resumes. */
    public static function status(string $root, int $limit = 100, int $offset = 0): array
    {
        $dir = self::directory($root, false);
        if (!$dir) return [];
        $rows = [];
        foreach (array_slice(glob($dir.'/remote-*.json'), max(0, $offset), max(1, min(500, $limit))) as $file) {
            $r = is_link($file) ? null : json_decode((string)@file_get_contents($file, false, null, 0, 16385), true);
            $source = is_array($r) && is_string($r['source_url'] ?? null) ? $r['source_url'] : '';
            $id = is_array($r) && is_string($r['product_id'] ?? null) ? $r['product_id'] : '';
            $until = is_array($r) && is_int($r['valid_until'] ?? null) ? $r['valid_until'] : 0;
            $attrs = basename($file) === self::receiptName($id, $source) ? self::attributes($source, '100vw', 0, $root, $id) : null;
            $excluded = is_array($r) && ($r['excluded'] ?? null) === true && basename($file) === self::receiptName($id, $source);
            $state = $excluded ? 'excluded' : ($attrs === null ? 'fallback' : ($until <= time() + 7 * 86400 ? 'expiring' : 'ready'));
            $rows[] = ['product_id' => $id, 'source' => $source, 'status' => $state,
                'valid_until' => $until > 0 ? gmdate('c', $until) : null,
                'reason' => $state === 'excluded' ? 'Explicitly excluded; original remains in use.' : ($state === 'fallback' ? 'Missing, expired, or invalid receipt/derivatives; original remains in use.'
                    : ($state === 'expiring' ? 'Re-verify original and re-import before valid_until.' : 'Verified derivative delivery is available.'))];
        }
        return $rows;
    }

    private static function publicationLock(string $dir)
    {
        if (is_link($dir.'/.remote.lock')) throw new \RuntimeException('Refusing a symlink lock.');
        $lock = @fopen($dir.'/.remote.lock', 'c');
        if (!$lock) throw new \RuntimeException('Cannot open remote image publication lock.');
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new \RuntimeException('Cannot lock remote image publication.'); }
        return $lock;
    }

    private static function atomicWrite(string $target, string $bytes): void
    {
        if (is_link($target)) throw new \RuntimeException('Refusing a symlink output.');
        $temp = tempnam(dirname($target), '.remote-');
        if ($temp === false) throw new \RuntimeException('Cannot create publication temporary file.');
        try {
            if (file_put_contents($temp, $bytes) !== strlen($bytes) || !chmod($temp, 0644) || !rename($temp, $target)) throw new \RuntimeException('Cannot publish image output.');
        } finally { if (is_file($temp)) unlink($temp); }
    }

    private static function removeWork(string $dir): void
    {
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $file = $dir.'/'.$name;
            if (is_dir($file) && !is_link($file)) self::removeWork($file); else unlink($file);
        }
        rmdir($dir);
    }

    /** local_file is relative to the supplied manifest directory; URLs are never opened. */
    public static function import(array $record, string $inputDirectory, string $root): array
    {
        $source = is_string($record['source_url'] ?? null) ? $record['source_url'] : '';
        $productId = is_string($record['product_id'] ?? null) ? $record['product_id'] : '';
        $result = ['product_id' => $productId, 'source' => $source];
        $work = null; $lock = null;
        try {
            if (!self::productId($productId) || !self::allowedSource($source)) throw new \InvalidArgumentException('Product ID or exact HTTPS eBay image URL is invalid.');
            if (is_array($record['provenance'] ?? null) && ($record['provenance']['excluded'] ?? null) === true) {
                $dir = self::directory($root, true);
                if (!$dir) throw new \RuntimeException('Image output directory must be a regular directory inside the site.');
                $lock = self::publicationLock($dir);
                $receipt = ['version' => 1, 'product_id' => $productId, 'source_url' => $source, 'excluded' => true, 'excluded_at' => time()];
                self::atomicWrite($dir.'/'.self::receiptName($productId, $source), json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                return $result + ['status' => 'excluded', 'reason' => 'Existing receipt revoked; original-only delivery is active.'];
            }
            $provenance = $record['provenance'] ?? null;
            if (!is_array($provenance) || ($provenance['basis'] ?? null) !== 'existing_catalog'
                || ($provenance['optimization_authorized'] ?? null) !== true || ($provenance['excluded'] ?? null) !== false
                || !is_string($provenance['reference'] ?? null) || trim($provenance['reference']) === '') {
                throw new \InvalidArgumentException('Existing-catalog provenance and authorized optimization are required; excluded or unrelated images cannot activate.');
            }
            $verified = self::timestamp($record['verified_at'] ?? null); $until = self::timestamp($record['valid_until'] ?? null);
            if (!self::validPeriod($verified, $until)) throw new \InvalidArgumentException('Source verification must be current and expire within 30 days.');
            if (!self::hashValue($record['source_sha256'] ?? null)) throw new \InvalidArgumentException('An original SHA-256 is required.');
            $relative = $record['local_file'] ?? null;
            if (!is_string($relative) || $relative === '' || strlen($relative) > 512 || preg_match('~[\x00-\x20\x7f\\\\:]|^(?:/)|(?:^|/)\.{1,2}(?:/|$)~', $relative)) throw new \InvalidArgumentException('local_file must be a safe relative file path.');
            $inputDirectory = realpath($inputDirectory);
            $file = $inputDirectory ? realpath($inputDirectory.'/'.$relative) : false;
            if (!$file || !is_file($file) || !str_starts_with($file, $inputDirectory.DIRECTORY_SEPARATOR)) throw new \InvalidArgumentException('Original file is outside the manifest directory or missing.');
            $part = $inputDirectory;
            foreach (explode('/', $relative) as $component) { $part .= '/'.$component; if (is_link($part)) throw new \InvalidArgumentException('Original symlinks are not accepted.'); }
            $bytes = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
            if ($bytes === false || strlen($bytes) > self::MAX_BYTES || hash('sha256', $bytes) !== $record['source_sha256']) throw new \InvalidArgumentException('Original size or content hash does not match provenance.');
            $size = @getimagesizefromstring($bytes);
            if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 20000000
                || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new \InvalidArgumentException('Original is not a supported image within the pixel limit.');
            if ($size[2] === IMAGETYPE_JPEG && !function_exists('exif_read_data')) throw new \InvalidArgumentException('JPEG imports require PHP EXIF to verify orientation.');
            $work = sys_get_temp_dir().'/fas-image-import-'.bin2hex(random_bytes(12));
            if (!mkdir($work, 0700) || !mkdir($work.'/gallery', 0700)) throw new \RuntimeException('Cannot create private image workspace.');
            $extension = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$size[2]];
            $local = '/gallery/source.'.$extension;
            if (file_put_contents($work.$local, $bytes) !== strlen($bytes)) throw new \RuntimeException('Cannot stage original.');
            unset($bytes);
            $built = ResponsiveImage::build($local, $work);
            if ($built['status'] !== 'ready' || !$built['variant_bytes']) throw new \InvalidArgumentException('Original cannot produce eligible smaller derivatives (including EXIF rotation).');
            $dir = self::directory($root, true);
            if (!$dir) throw new \RuntimeException('Image output directory must be a regular directory inside the site.');
            $lock = self::publicationLock($dir);
            $variants = [];
            foreach ($built['variant_bytes'] as $width => $count) {
                $matches = glob($work.'/gallery/responsive/*-'.$width.'.webp');
                if (count($matches) !== 1) throw new \RuntimeException('Derivative set is incomplete.');
                $meta = ['bytes' => $count, 'sha256' => hash_file('sha256', $matches[0])];
                $height = max(1, (int)round($size[1] * $width / $size[0]));
                if (!self::verifiedVariant($matches[0], $width, $height, $meta)) throw new \RuntimeException('Generated derivative failed verification.');
                $target = $dir.'/'.self::variantName($record['source_sha256'], $width);
                self::atomicWrite($target, file_get_contents($matches[0]));
                if (!self::verifiedVariant($target, $width, $height, $meta)) throw new \RuntimeException('Published derivative failed verification.');
                $variants[$width] = $meta;
            }
            $receipt = ['version' => 1, 'product_id' => $productId, 'source_url' => $source, 'source_sha256' => $record['source_sha256'],
                'verified_at' => $verified, 'valid_until' => $until, 'optimization_authorized' => true, 'provenance_basis' => 'existing_catalog', 'original_bytes' => $built['original_bytes'],
                'width' => $size[0], 'height' => $size[1], 'variants' => $variants];
            // The receipt is the final commit point; partial outputs cannot become visible through markup.
            self::atomicWrite($dir.'/'.self::receiptName($productId, $source), json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            return $result + ['status' => 'ready', 'original_bytes' => $built['original_bytes'], 'width' => $size[0], 'height' => $size[1], 'variant_bytes' => $built['variant_bytes']];
        } catch (\InvalidArgumentException $e) { return $result + ['status' => 'skipped', 'reason' => $e->getMessage()]; }
        catch (\Throwable $e) { return $result + ['status' => 'failed', 'reason' => $e->getMessage()]; }
        finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            if ($work && is_dir($work)) self::removeWork($work);
        }
    }
}
