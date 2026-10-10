<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ReviewedProductFacts.php';

/** Reviewed identifiers and fallback holds; never rewrite source inventory. */
final class ProductIdentifierOutput
{
    // Keep the hold if the owner-confirmed ID/SKU association no longer matches.
    // A later import is not verification. See docs/identifier-output-holds.md.
    private const HELD_PRODUCT_IDS = ['6305'];

    public static function isHeld(array $product): bool
    {
        $id = $product['id'] ?? null;
        return (is_int($id) || is_string($id))
            && in_array((string)$id, self::HELD_PRODUCT_IDS, true)
            && ReviewedProductFacts::identifiers($product) === null;
    }

    /** Preserve each caller's existing normalization for every non-held product. */
    public static function forProduct(array $product, string $brand, string $mpn): array
    {
        $reviewed = ReviewedProductFacts::identifiers($product);
        if ($reviewed !== null) return $reviewed + ['held'=>false];
        $held = self::isHeld($product);
        return ['brand' => $held ? '' : $brand, 'mpn' => $held ? '' : $mpn, 'held' => $held];
    }
}
