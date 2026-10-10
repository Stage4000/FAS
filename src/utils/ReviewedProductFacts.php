<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Owner-reviewed output facts, separate from immutable imported source values. */
final class ReviewedProductFacts
{
    // Confirmed 2026-10-10. Match both existing record ID and exact source SKU.
    // Never infer from title/description or write these values into inventory.
    private const IDENTIFIERS = [
        '6305' => ['sku'=>'10171 FAS', 'brand'=>'Wiseco', 'mpn'=>'PWR128-101'],
    ];
    private const FINAL_SALE = [
        '6419' => 'FW103 FAS',
        '6223' => 'fw107b tw1',
        '6221' => '(fw107c tw1)',
    ];

    private static function productId(array $product): string
    {
        $id = $product['id'] ?? null;
        return is_int($id) || is_string($id) ? (string)$id : '';
    }

    public static function identifiers(array $product): ?array
    {
        $facts = self::IDENTIFIERS[self::productId($product)] ?? null;
        if ($facts === null || ($product['sku'] ?? null) !== $facts['sku']) return null;
        return ['brand'=>$facts['brand'], 'mpn'=>$facts['mpn']];
    }

    /** Read-only storefront query expression; no imported columns are rewritten. */
    public static function identifierSql(string $field): string
    {
        if (!in_array($field, ['manufacturer','model'], true)) {
            throw new \InvalidArgumentException('Unsupported reviewed identifier column.');
        }
        $factField = $field === 'manufacturer' ? 'brand' : 'mpn';
        $sql = 'CASE';
        foreach (self::IDENTIFIERS as $id=>$facts) {
            $sku = str_replace("'", "''", $facts['sku']);
            $value = str_replace("'", "''", $facts[$factField]);
            $sql .= " WHEN id = ".(int)$id." AND sku = '".$sku."' COLLATE BINARY THEN '".$value."'";
        }
        return '('.$sql.' ELSE '.$field.' END)';
    }

    /** A changed SKU on a reviewed record needs review, not a new policy guess. */
    public static function returnPolicy(array $product): string
    {
        $sku = self::FINAL_SALE[self::productId($product)] ?? null;
        if ($sku === null) return 'standard';
        return ($product['sku'] ?? null) === $sku ? 'final_sale' : 'review_required';
    }
}
