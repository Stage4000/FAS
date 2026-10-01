<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/utils/Seo.php';
require_once __DIR__.'/../src/utils/ProductContentQuality.php';
use FAS\Utils\{CSRF, Seo, ProductContent, ProductContentQuality, Timezone};

$auth = new AdminAuth();
$admin = $auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$db = \FAS\Config\Database::getInstance()->getConnection();
$id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404); exit('Product not found.'); }
$stmt = $db->prepare('SELECT * FROM products WHERE id=? AND is_active=1');
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) { http_response_code(404); exit('Product not found.'); }
function contentH($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$error = '';
$storageReady = false;
$review = null;
$history = [];
$draft = ['description'=>'','seo_title'=>'','seo_description'=>''];
$published = [];
try {
    $storageReady = ProductContent::installed($db);
    $review = ProductContent::get($db,$id);
    if ($review) {
        $draft = ProductContent::validate(ProductContent::decode($review['draft_json']),false);
        if ($review['published_json'] !== null) $published = ProductContent::validate(ProductContent::decode($review['published_json']),true);
    }
    if ($storageReady) {
        $stmt=$db->prepare('SELECT revision,action,actor_id,created_at FROM product_content_history WHERE product_id=? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$id]); $history=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $storageReady = false;
    $review = null;
    http_response_code(503);
    $error = 'Editorial storage is unavailable. Your published content has not been changed.';
    error_log('Product content read failed: '.$e->getMessage());
}
$revision = (int)($review['revision'] ?? 0);
$sourceHash = ProductContent::sourceHash($product);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) { http_response_code(403); throw new InvalidArgumentException('Refresh the page and try again.'); }
        if (!$storageReady) { http_response_code(503); throw new RuntimeException('Editorial storage is not initialized.'); }
        $action = $_POST['action'] ?? '';
        if (!is_string($action) || !in_array($action,['draft','publish','withdraw'],true)) throw new InvalidArgumentException('Choose a valid content action.');
        $submittedRevision = filter_var($_POST['revision'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $submittedHash = $_POST['source_hash'] ?? '';
        if ($submittedRevision === false || !is_string($submittedHash) || !preg_match('/^[a-f0-9]{64}$/',$submittedHash)) throw new InvalidArgumentException('Invalid review reference. Reload this page.');
        $revision = $submittedRevision;
        $sourceHash = $submittedHash;
        if ($action !== 'withdraw') {
            foreach (array_keys($draft) as $key) {
                if (isset($_POST[$key]) && is_string($_POST[$key]) && strlen($_POST[$key])<=30000) $draft[$key]=$_POST[$key];
            }
            $draft = ProductContent::validate($_POST,$action === 'publish');
        }
        if ($action === 'publish' && ($_POST['verified'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm that you checked the description against this item before publishing.');
        }
        if ($action === 'withdraw' && ($_POST['confirm_withdraw'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm that you want to return to the source description.');
        }
        if ($action !== 'draft' && (int)($_SESSION['security_reauth_at'] ?? 0) < time()-600) {
            if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
                if (http_response_code()<400) http_response_code(403);
                throw new InvalidArgumentException($auth->lastError ?: 'Verify your current password before publishing or withdrawing.');
            }
            $_SESSION['security_reauth_at'] = time();
        }
        ProductContent::save($db,$id,$submittedRevision,$submittedHash,$action,$_POST,(int)$admin['id']);
        header('Location: product-content.php?id='.$id.'&saved='.$action,true,303);
        exit;
    } catch (DomainException $e) {
        http_response_code(409); $error=$e->getMessage().' Your submitted text remains below; copy it before reloading.';
        // Keep the submitted tokens stale, so a second click cannot bypass conflict review.
        $revision = $submittedRevision; $sourceHash = $submittedHash;
    } catch (InvalidArgumentException $e) {
        if (http_response_code()<400) http_response_code(422);
        $error=$e->getMessage();
    } catch (Throwable $e) {
        http_response_code(503); $error='The change could not be saved. Your published content has not been changed.';
        error_log('Product content write failed: '.$e->getMessage());
    }
}
$state = ProductContent::state($product,$review);
$warnings = ProductContentQuality::issues($product,$review,[]);
$notices = ['draft'=>'Draft saved. Published content is unchanged.','publish'=>'Reviewed description and search text published.','withdraw'=>'Published overrides withdrawn. The source description is active again.'];
$saved = is_string($_GET['saved'] ?? null) ? ($_GET['saved'] ?? '') : '';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Review Product Content - Flip and Strip Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-style.css">
<style>
/* Keep the full-width navigation inside the viewport during page entry. */
.navbar { animation: none; }
.content-review { overflow-wrap:anywhere; }
.content-review > .row > * { min-width:0; }
.content-review textarea { resize:vertical; }
.content-review .source-notes { white-space:pre-wrap; max-height:32rem; overflow:auto; }
.content-review .review-photo { width:100%; max-height:220px; object-fit:contain; }
</style>
</head><body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="content-review">
    <div class="admin-hero mb-4">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="product-quality.php" class="btn btn-dark btn-sm">Back to product quality</a>
            <a href="#review-heading" class="btn btn-dark btn-sm">Edit reviewed content</a>
        </div>
        <h1 class="h3">Review product content</h1><p class="mb-2 fw-semibold"><?= contentH($product['name']) ?></p>
        <span class="badge text-bg-<?= $state==='Reviewed'?'success':'warning' ?>"><?= contentH($state) ?></span>
        <span class="small ms-2">SKU: <?= contentH($product['sku']) ?> · Source: <?= contentH($product['source'] ?? 'manual') ?></span>
    </div>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= contentH($error) ?></div><?php endif; ?>
    <?php if (isset($notices[$saved]) && !$error): ?><div class="alert alert-success" role="status"><?= contentH($notices[$saved]) ?></div><?php endif; ?>
    <?php if (!$storageReady): ?><div class="alert alert-warning">Editorial storage needs initialization. Run <code>php scripts/product-content-maintenance.php init</code> on the server before saving reviews.</div><?php endif; ?>
    <?php if ($state==='Source changed'): ?><div class="alert alert-warning">The source listing changed after publication. Compare the current facts and photos before reviewing again. The last published text remains active.</div><?php endif; ?>
    <div class="row g-4">
        <section class="col-xl-5" aria-labelledby="source-heading"><div class="card border-0 shadow-sm"><div class="card-body p-4">
            <h2 class="h5" id="source-heading">Current source listing</h2>
            <p class="small text-muted">This is the imported or manually entered source. Saving this review does not replace it.</p>
            <?php $photo=(string)($product['image_url'] ?? ''); if (preg_match('~^(?:https?://|/(?!/))~',$photo)): ?>
                <img src="<?= contentH($photo) ?>" alt="<?= contentH($product['name']) ?>" class="review-photo mb-3" loading="lazy">
            <?php endif; ?>
            <dl class="row small">
                <?php foreach (['manufacturer'=>'Manufacturer','model'=>'Source model / identifier','condition_name'=>'Condition','category'=>'Category','ebay_item_id'=>'eBay item'] as $field=>$label): ?>
                    <dt class="col-sm-5"><?= $label ?></dt><dd class="col-sm-7"><?= contentH($product[$field] ?? '—') ?></dd>
                <?php endforeach; ?>
            </dl>
            <div class="source-notes border rounded p-3" tabindex="0" role="region" aria-label="Original source description"><?= contentH($product['description'] ?? '') ?></div>
            <?php if ($warnings): ?><h3 class="h6 mt-4">Review reminders</h3><ul class="small ps-3"><?php foreach ($warnings as $warning): ?><li class="mb-2"><strong><?= contentH($warning['label']) ?>:</strong> <?= contentH($warning['note']) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <a class="btn btn-outline-primary btn-sm mt-3" href="products.php?action=edit&amp;id=<?= $id ?>">Edit source product facts</a>
            <a class="btn btn-outline-secondary btn-sm mt-3" href="<?= contentH(Seo::productUrl($product)) ?>" target="_blank" rel="noopener">View current storefront</a>
        </div></div></section>
        <section class="col-xl-7" aria-labelledby="review-heading"><div class="card border-0 shadow-sm"><div class="card-body p-4">
            <h2 class="h5" id="review-heading" tabindex="-1">Storefront description and search text</h2>
            <p class="small text-muted">Describe the actual part, condition, included pieces and verified compatibility. Keep marketplace instructions and unrelated item copy out of the description. Product names, URLs, stock and prices remain controlled by the source listing.</p>
            <form method="post">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="revision" value="<?= $revision ?>">
                <input type="hidden" name="source_hash" value="<?= contentH($sourceHash) ?>">
                <fieldset <?= $storageReady?'':'disabled' ?>>
                    <label for="review-description" class="form-label fw-semibold">Reviewed product description</label>
                    <textarea id="review-description" name="description" maxlength="5000" rows="10" class="form-control" aria-describedby="description-help"><?= contentH($draft['description'] ?? '') ?></textarea>
                    <p id="description-help" class="form-text">Plain text, up to 5,000 characters. Published text is used on the product page, in its structured data and in the shopping feed.</p>
                    <label for="review-title" class="form-label fw-semibold mt-2">Search title (optional)</label>
                    <input id="review-title" name="seo_title" maxlength="110" class="form-control" value="<?= contentH($draft['seo_title'] ?? '') ?>">
                    <p class="form-text">Leave blank to use the product title. Include any desired store name; this field replaces the complete page title. The 110-character limit is an editorial setting, not a Google requirement.</p>
                    <label for="review-snippet" class="form-label fw-semibold mt-2">Search description (optional)</label>
                    <textarea id="review-snippet" name="seo_description" maxlength="160" rows="3" class="form-control"><?= contentH($draft['seo_description'] ?? '') ?></textarea>
                    <p class="form-text">Leave blank to use the opening of the reviewed description. Up to 160 characters for this site's snippet field; search engines may choose different text.</p>
                    <div class="form-check my-3">
                        <input type="checkbox" class="form-check-input" id="review-verified" name="verified" value="1">
                        <label for="review-verified" class="form-check-label">I checked the part identity, condition, included pieces and any fitment claims against this item and its photos.</label>
                    </div>
                    <?php if ((int)($_SESSION['security_reauth_at'] ?? 0)<time()-600): ?>
                        <label for="review-password" class="form-label">Current password to publish or withdraw</label>
                        <input id="review-password" name="password" type="password" autocomplete="current-password" class="form-control mb-3">
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary" name="action" value="draft" type="submit">Save draft</button>
                        <button class="btn btn-danger" name="action" value="publish" type="submit">Publish reviewed content</button>
                    </div>
                    <?php if ($published): ?><details class="mt-4"><summary>Return to the source description</summary>
                        <p class="small mt-2">This removes the published description and search overrides. The original listing text becomes visible again; your saved draft remains available.</p>
                        <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="confirm-withdraw" name="confirm_withdraw" value="1"><label class="form-check-label" for="confirm-withdraw">Restore the current source description and automatic search text.</label></div>
                        <button class="btn btn-outline-secondary" name="action" value="withdraw" type="submit">Withdraw published overrides</button>
                    </details><?php endif; ?>
                </fieldset>
            </form>
        </div></div>
        <div class="card border-0 shadow-sm mt-4"><div class="card-body p-4">
            <h2 class="h5">Currently published</h2>
            <?php if (!$published): ?><p class="text-muted mb-0">No reviewed override is published. The storefront uses the source listing.</p>
            <?php else: ?><p class="small text-muted">Reviewed by administrator #<?= (int)$review['reviewed_by'] ?> on <?= contentH(Timezone::toUserDateTime($review['reviewed_at'])) ?>.</p>
                <p class="source-notes"><?= contentH($published['description']) ?></p>
                <p class="small"><strong>Search title:</strong> <?= contentH($published['seo_title'] ?: 'Automatic') ?><br><strong>Search description:</strong> <?= contentH($published['seo_description'] ?: 'Description opening') ?></p>
            <?php endif; ?>
            <?php if ($history): ?><h3 class="h6 mt-4">Recent review activity</h3><ul class="small ps-3"><?php foreach ($history as $event): ?><li>Revision <?= (int)$event['revision'] ?> · <?= contentH($event['action']) ?> · Admin #<?= (int)$event['actor_id'] ?> · <?= contentH(Timezone::toUserDateTime($event['created_at'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div></div></section>
    </div>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
