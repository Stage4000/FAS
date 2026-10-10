<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Explicit output holds; never rewrite source inventory or infer a replacement. */
final class ProductIdentifierOutput
{
    // 6305 has conflicting source brand/MPN versus its title and photographed label.
    // Keep the hold until an explicit fact-backed review resolves the actual item.
    // A later import is not verification. See docs/identifier-output-holds.md.
    private const HELD_PRODUCT_IDS = ['6305'];

    public static function isHeld(array $product): bool
    {
        $id = $product['id'] ?? null;
        return (is_int($id) || is_string($id))
            && in_array((string)$id, self::HELD_PRODUCT_IDS, true);
    }

    /** Preserve each caller's existing normalization for every non-held product. */
    public static function forProduct(array $product, string $brand, string $mpn): array
    {
        $held = self::isHeld($product);
        return ['brand' => $held ? '' : $brand, 'mpn' => $held ? '' : $mpn, 'held' => $held];
    }
}
