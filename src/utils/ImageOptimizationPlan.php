<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Pure catalog-to-work planning. No DB, files, fetches, encoding or receipt renewal. */
final class ImageOptimizationPlan
{
    public const VERSION = 1;
    public const MAX_SOURCES = 50;
    public const MAX_BATCH = 10;
    public const MAX_ATTEMPTS = 5;
    public const MAX_SOURCE_BYTES = 20971520;
    public const MAX_SOURCE_PIXELS = 20000000;

    /** Inputs must be a freshly committed catalog row, not request fields. */
    public static function forProduct(array $product): array
    {
        $id = self::id($product['id'] ?? null);
        $active = self::flag($product['is_active'] ?? null);
        $visible = self::flag($product['show_on_website'] ?? null);
        $main = $product['image_url'] ?? '';
        if ($main === null) $main = '';
        if (!is_string($main) || strlen($main) > 2048) throw new \InvalidArgumentException('Invalid catalog primary image.');
        $additional = $product['images'] ?? [];
        if ($additional === null || $additional === '') $additional = [];
        if (is_string($additional)) {
            if (strlen($additional) > 131072) throw new \InvalidArgumentException('Catalog image list exceeds limit.');
            $additional = json_decode($additional, true, 8, JSON_THROW_ON_ERROR);
        }
        if (!is_array($additional) || !array_is_list($additional) || count($additional) > self::MAX_SOURCES) {
            throw new \InvalidArgumentException('Invalid catalog image list.');
        }
        $sources = $main === '' ? [] : [$main];
        foreach ($additional as $source) {
            if (!is_string($source) || strlen($source) > 2048 || $source === '') throw new \InvalidArgumentException('Invalid catalog image entry.');
            $sources[] = $source;
        }
        $sources = array_values(array_unique($sources));
        if (count($sources) > self::MAX_SOURCES) throw new \InvalidArgumentException('Catalog image count exceeds limit.');
        // Bind the full ordered set and visibility, so removed/replaced catalog references supersede old work.
        $binding = hash('sha256', json_encode([self::VERSION, $id, $active, $visible, $sources], JSON_THROW_ON_ERROR));
        $jobs = []; $unsupported = 0;
        if ($active && $visible) foreach ($sources as $source) {
            $kind = self::sourceKind($source);
            if ($kind === null) { $unsupported++; continue; }
            $jobs[] = ['version' => self::VERSION, 'product_id' => $id, 'source' => $source, 'kind' => $kind,
                'catalog_binding' => $binding, 'work_key' => hash('sha256', self::VERSION."\n".$id."\n".$binding."\n".$source)];
        }
        return ['product_id' => $id, 'catalog_binding' => $binding, 'jobs' => $jobs, 'unsupported_count' => $unsupported];
    }

    /** Re-read catalog immediately before processing and again before receipt activation. */
    public static function isCurrent(array $intent, array $currentProduct): bool
    {
        foreach (self::forProduct($currentProduct)['jobs'] as $candidate) {
            if ($intent === $candidate) return true;
        }
        return false;
    }

    /** A bounded scheduling decision, not a claim/lease or persistent queue implementation. */
    public static function afterFailure(int $attempt, int $now, string $failure): array
    {
        if ($attempt < 1 || $attempt > self::MAX_ATTEMPTS || $now < 1 || $now > PHP_INT_MAX - 86400) {
            throw new \InvalidArgumentException('Invalid retry context.');
        }
        if (!in_array($failure, ['temporary_io', 'temporary_network', 'unsupported_image', 'invalid_source', 'provenance_failed'], true)) {
            throw new \InvalidArgumentException('Unknown image failure category.');
        }
        if ($attempt === self::MAX_ATTEMPTS || !in_array($failure, ['temporary_io', 'temporary_network'], true)) {
            return ['state' => 'quarantined', 'next_attempt_at' => null, 'failure' => $failure];
        }
        return ['state' => 'retry', 'next_attempt_at' => $now + min(3600, 60 * (2 ** ($attempt - 1))), 'failure' => $failure];
    }

    private static function id(mixed $id): string
    {
        if (is_int($id)) $id = (string)$id;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) throw new \InvalidArgumentException('Invalid product identity.');
        return $id;
    }

    private static function flag(mixed $flag): bool
    {
        if (in_array($flag, [true, 1, '1'], true)) return true;
        if (in_array($flag, [false, 0, '0'], true)) return false;
        throw new \InvalidArgumentException('Catalog activity and visibility must be explicit.');
    }

    private static function sourceKind(string $source): ?string
    {
        if (preg_match('~[\x00-\x20\x7f\\\\]~', $source)) return null;
        if (preg_match('~^/(?:gallery/(?!responsive/)|public/uploads/)[A-Za-z0-9_/-]+\.(?:jpe?g|png|webp)$~Di', $source)
            && !str_contains($source, '//')) return 'local';
        if (str_ends_with($source, ',')) return null;
        $p = parse_url($source);
        if (is_array($p) && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'i.ebayimg.com'
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) && !isset($p['fragment'])
            && str_starts_with($p['path'] ?? '', '/')) return 'ebay';
        return null;
    }
}
