<?php
/**
 * Site-wide sale helper.
 *
 * Loads the sale configuration from config.php and provides a helper to compute
 * the effective (final) price for any product, considering both the site-wide
 * sale and a product's individual sale_price column.
 */

/**
 * Return the active sale config array, or null if no sale is currently active.
 * Result is cached in a static variable so the config file is read only once.
 *
 * @return array|null
 */
function getSaleConfig(): ?array
{
    static $cache  = null;
    static $loaded = false;

    if ($loaded) {
        return $cache;
    }
    $loaded = true;

    $configPath = __DIR__ . '/../src/config/config.php';
    if (!file_exists($configPath)) {
        return null;
    }

    try {
        $config = require $configPath;
    } catch (Exception $e) {
        return null;
    }

    $sale = $config['sale'] ?? null;
    if (empty($sale['enabled'])) {
        return null;
    }

    // Check optional start / end dates
    $now = time();
    if (!empty($sale['starts_at'])) {
        $ts = strtotime($sale['starts_at']);
        if ($ts !== false && $ts > $now) {
            return null;
        }
    }
    if (!empty($sale['ends_at'])) {
        $ts = strtotime($sale['ends_at']);
        if ($ts !== false && $ts < $now) {
            return null;
        }
    }

    $cache = $sale;
    return $cache;
}

/**
 * Compute the effective (final) price for a product.
 *
 * Priority rules:
 *  1. Start from the base $price.
 *  2. If the site-wide sale is active, compute a site_sale_price.
 *  3. Use whichever price is lower: site_sale_price, $individualSalePrice, or $price.
 *
 * @param  float      $price               Product base price
 * @param  float|null $individualSalePrice  Product's own sale_price column (may be null)
 * @return array {
 *   float  original_price   The base price to show with strikethrough
 *   float  effective_price  The price to charge / display prominently
 *   bool   on_sale          Whether a reduced price applies
 *   string sale_label       Badge text (e.g. "SALE", "Save 20%", "$5 Off")
 * }
 */
function getEffectivePrice(float $price, ?float $individualSalePrice): array
{
    $sale = getSaleConfig();

    $siteSalePrice = null;
    $siteLabel     = '';

    if ($sale !== null) {
        $value = (float) ($sale['value'] ?? 0);
        $type  = $sale['type']  ?? 'percentage';
        $label = $sale['label'] ?? 'SALE';

        if ($type === 'percentage' && $value > 0 && $value < 100) {
            $siteSalePrice = round($price * (1 - $value / 100), 2);
            $siteLabel     = $label ?: 'Save ' . round($value) . '%';
        } elseif ($type === 'fixed' && $value > 0) {
            $siteSalePrice = max(0.01, round($price - $value, 2));
            $siteLabel     = $label ?: '$' . number_format($value, 0) . ' Off';
        }
    }

    // Determine the best (lowest) price among all candidates
    $effectivePrice = $price;
    $onSale         = false;
    $saleLabel      = '';

    // Individual product sale_price
    if (!empty($individualSalePrice) && $individualSalePrice < $effectivePrice) {
        $effectivePrice = $individualSalePrice;
        $onSale         = true;
        $pct            = round(($price - $effectivePrice) / $price * 100);
        $saleLabel      = 'Save ' . $pct . '%';
    }

    // Site-wide sale (wins if it gives a lower price)
    if ($siteSalePrice !== null && $siteSalePrice < $effectivePrice) {
        $effectivePrice = $siteSalePrice;
        $onSale         = true;
        $saleLabel      = htmlspecialchars($siteLabel);
    } elseif ($siteSalePrice !== null && $onSale) {
        // Site sale is active but individual price is already lower —
        // prefer the site-wide label so the banner is consistent.
        $saleLabel = htmlspecialchars($siteLabel);
    }

    return [
        'original_price'  => $price,
        'effective_price' => $effectivePrice,
        'on_sale'         => $onSale,
        'sale_label'      => $saleLabel,
    ];
}
