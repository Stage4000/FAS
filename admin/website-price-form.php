<?php
// Included only after the authenticated product editor has loaded this product.
if (!isset($product, $db)) { http_response_code(404); exit; }
$priceReady = false;
try {
    $priceReady = \FAS\Utils\WebsitePrice::installed($db);
    $priceView = $priceReady ? \FAS\Utils\WebsitePrice::view($db, (int)$product['id']) : null;
    $priceProduct = $priceView['product'] ?? $product;
    $priceSource = $priceView['source'] ?? null;
    $priceSnapshot = $priceView['snapshot'] ?? '';
} catch (Throwable $e) { $priceReady = false; }
$priceEscape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<section class="card mb-4" aria-labelledby="website-price-heading">
<div class="card-body">
    <h2 id="website-price-heading" class="h5">Website price</h2>
    <?php if (!$priceReady): ?>
        <p class="mb-0">Website price controls are unavailable until maintenance setup is complete.</p>
    <?php else: ?>
        <p>Active source: <strong><?= isset($priceProduct['website_override_cents']) ? 'Website override' : 'eBay price with existing sale rules' ?></strong></p>
        <dl class="row">
            <dt class="col-sm-4">Latest received eBay price</dt><dd class="col-sm-8"><?= $priceSource ? '$'.\FAS\Utils\WebsitePrice::decimal((int)$priceSource['amount_cents']).' USD · '.$priceEscape($priceSource['observed_at']).' UTC' : 'Not received since setup; sync first' ?></dd>
            <dt class="col-sm-4">Website override</dt><dd class="col-sm-8"><?= isset($priceProduct['website_override_cents']) ? '$'.\FAS\Utils\WebsitePrice::decimal((int)$priceProduct['website_override_cents']).' USD' : 'Off' ?></dd>
        </dl>
        <p>An override is the exact website unit price before shipping and any separately applied coupon. Product and site sales do not reduce it. Stock continues to sync.</p>
        <p>Returning to eBay applies the latest received eBay base price immediately when saved, with existing sale rules restored. Later syncs update it normally. Existing orders keep their original totals. Carts must refresh changed prices before payment.</p>
        <?php if ($priceSource): ?>
        <form method="post">
            <?= \FAS\Utils\CSRF::tokenField() ?>
            <input type="hidden" name="action" value="website_price">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="price_snapshot" value="<?= $priceEscape($priceSnapshot) ?>">
            <input type="hidden" name="website_currency" value="USD">
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label for="price-action" class="form-label">Price source</label>
                    <select name="price_action" id="price-action" class="form-select"><option value="override">Set website override</option><option value="source">Return to latest received eBay price now</option></select>
                </div>
                <div class="col-md-6"><label for="website-amount" class="form-label">Website override (USD)</label>
                    <input id="website-amount" name="website_amount" class="form-control" type="text" inputmode="decimal" placeholder="e.g. 125.00" value="<?= isset($priceProduct['website_override_cents']) ? \FAS\Utils\WebsitePrice::decimal((int)$priceProduct['website_override_cents']) : '' ?>">
                    <div class="form-text">0.01–999999.99; at most two decimal places. Ignored when returning to eBay.</div>
                </div>
            </div>
            <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm_website_price" value="1" required><span class="form-check-label">Apply this price source now. I have reviewed both prices and the timing above.</span></label>
            <button type="submit" class="btn btn-primary">Save website price source</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</section>
