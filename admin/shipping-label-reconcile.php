<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/utils/Timezone.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';

use FAS\Config\Database;
use FAS\Utils\{CSRF,Timezone};
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingOrder,ShippingLabelOperations,ShippingLabelCancellations};

$auth=new AdminAuth();
$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function labelReviewHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$orderId=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$packageIndex=filter_var($_GET['package'] ?? null,FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0,'max_range'=>9]]);
if (!$orderId || $packageIndex===false) { http_response_code(400); exit('Choose a shipment.'); }
$db=Database::getInstance()->getConnection();
$stmt=$db->prepare('SELECT * FROM orders WHERE id=?');
$stmt->execute([$orderId]);
$order=$stmt->fetch(PDO::FETCH_ASSOC);
$shipping=$order ? ShippingOrder::find($db,(int)$orderId) : null;
if (!$order || !$shipping || !in_array($shipping['provider'],['usps','ups'],true)) {
    http_response_code(404); exit('Direct carrier order not found.');
}
$error=''; $operations=null; $operation=null; $resolution=null; $reprint=null; $cancel=null;
try {
    $config=ShippingConfig::load();
    if ($config['cache_path']==='') throw new RuntimeException('Missing private shipping storage.');
    $cache=new ShippingCache($config['cache_path']);
    $operations=new ShippingLabelOperations($cache->database());
    $table=$cache->database()->query("SELECT 1 FROM sqlite_master
        WHERE type='table' AND name='shipping_label_resolutions'")->fetchColumn();
    if (!$table) throw new RuntimeException('Missing label reconciliation migration.');
    $operation=$operations->find((int)$orderId,$packageIndex);
    if (!$operation || $operation['provider']!==$shipping['provider']) {
        http_response_code(404); exit('Shipment operation not found.');
    }
    $resolution=$operations->resolution((int)$orderId,$packageIndex);
    $reprintTable=$cache->database()->query("SELECT 1 FROM sqlite_master
        WHERE type='table' AND name='shipping_label_reprints'")->fetchColumn();
    if ($reprintTable) $reprint=$operations->reprint((int)$orderId,$packageIndex);
    $cancel=(new ShippingLabelCancellations($cache->database()))->find((int)$orderId,$packageIndex);
} catch (Throwable $e) {
    $error='Private shipping storage is unavailable. Run the shipping health check before reviewing this label.';
}
$pieces=$operation ? (int)$operation['expected_packages'] : 0;
$canReview=$operation && !$resolution && !$cancel
    && in_array($operation['state'],['submitted','review'],true)
    && (int)($operation['submitted_at'] ?? 0)>0
    && (int)$operation['submitted_at']<=time()-ShippingLabelOperations::HANDOFF_DELAY_SECONDS
    && $pieces>=1 && $pieces<=10
    && (!$reprint || $reprint['state']!=='submitted'
        || (int)$reprint['created_at']<=time()-ShippingLabelOperations::HANDOFF_DELAY_SECONDS)
    && $order['payment_status']==='completed' && $order['order_status']==='processing';

if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $length=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length>7340032 || $length<1) {
        http_response_code(413);
        $error='The label files are too large. Keep the combined upload below 7 MB.';
    } else {
        try {
            if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
            }
            if (!$canReview || ($_POST['confirm_evidence'] ?? '')!=='yes') {
                throw new DomainException('This shipment is not ready for carrier reconciliation.');
            }
            if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
                throw new InvalidArgumentException($auth->lastError ?: 'Password could not be verified.');
            }
            $amount=$_POST['billed_amount'] ?? null;
            if (!is_string($amount)
                || !preg_match('/\A(?:0|[1-9][0-9]{0,5})\.[0-9]{2}\z/D',$amount)
                || (float)$amount<=0) {
                throw new InvalidArgumentException('Enter the confirmed carrier charge in dollars and cents.');
            }
            $parts=explode('.',$amount);
            $cents=(int)$parts[0]*100+(int)$parts[1];
            $tracking=$_POST['tracking'] ?? null;
            $uploads=$_FILES['labels'] ?? null;
            if (!is_array($tracking) || !is_array($uploads)
                || !isset($uploads['tmp_name'],$uploads['error'],$uploads['size'])
                || count($tracking)!==$pieces || count($uploads['tmp_name'])!==$pieces) {
                throw new InvalidArgumentException('Provide a tracking number and original label file for every package.');
            }
            $packages=[]; $total=0;
            for ($i=0;$i<$pieces;$i++) {
                $name=$tracking[$i] ?? null;
                $tmp=$uploads['tmp_name'][$i] ?? null;
                $size=$uploads['size'][$i] ?? null;
                if (!is_string($name) || !is_string($tmp) || !is_int($size)
                    || ($uploads['error'][$i] ?? null)!==UPLOAD_ERR_OK
                    || $size<6 || $size>6291456 || !is_uploaded_file($tmp)) {
                    throw new InvalidArgumentException('Upload each original carrier label as a valid file.');
                }
                $total+=$size;
                if ($total>7000000) throw new InvalidArgumentException('The combined label files exceed 7 MB.');
                $bytes=file_get_contents($tmp,false,null,0,6291457);
                if (!is_string($bytes) || strlen($bytes)!==$size) {
                    throw new RuntimeException('A carrier label file could not be read.');
                }
                $packages[]=['tracking_number'=>$name,'label'=>$bytes,
                    'format'=>$operation['provider']==='usps' ? 'pdf' : 'gif'];
            }
            $confirmation=['shipment_id'=>$packages[0]['tracking_number'],
                'billed_cents'=>$cents,'packages'=>$packages];
            $operations->reconcileReady($db,(int)$orderId,$packageIndex,(int)$admin['id'],
                is_string($_POST['expected_state'] ?? null) ? $_POST['expected_state'] : '',
                $confirmation,is_string($_POST['evidence_reference'] ?? null)
                    ? trim($_POST['evidence_reference']) : '',true);
            $_SESSION['shipping_label_notice']='Original carrier label recorded. Verify every saved label before shipping.';
            header('Location: shipping-label.php?id='.(int)$orderId,true,303); exit;
        } catch (InvalidArgumentException|DomainException $e) {
            if (http_response_code()<400) http_response_code(422);
            $error=$e->getMessage();
        } catch (Throwable $e) {
            http_response_code(503);
            $error='The carrier label could not be recorded. Check the original carrier transaction and private storage before trying again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reconcile Shipping Label - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="container-fluid px-3 px-lg-4 pb-5" style="max-width:1100px">
    <div class="admin-hero d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-file-circle-check me-2" aria-hidden="true"></i>Reconcile Shipping Label</h1>
            <p class="mb-0 opacity-75">Order <?= labelReviewHtml($order['order_number']) ?></p></div>
        <a class="btn btn-outline-secondary" href="shipping-label.php?id=<?= (int)$orderId ?>">Back to labels</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= labelReviewHtml($error) ?></div><?php endif; ?>
    <?php if ($operation): ?>
        <section class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h2 class="h5">Original shipment</h2>
            <dl class="row mb-0">
                <dt class="col-sm-4">Carrier and service</dt><dd class="col-sm-8"><?= labelReviewHtml(strtoupper($operation['provider']).' · '.$shipping['service_name']) ?></dd>
                <dt class="col-sm-4">Scope</dt><dd class="col-sm-8"><?= $operation['provider']==='usps'?'Parcel '.($packageIndex+1):'UPS shipment' ?> · <?= $pieces ?> <?= $pieces===1?'package':'packages' ?></dd>
                <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= labelReviewHtml(ucfirst($operation['state'])) ?></dd>
                <dt class="col-sm-4">Carrier environment</dt><dd class="col-sm-8"><?= labelReviewHtml($operation['carrier_environment'] ?: 'Unknown') ?></dd>
                <?php if ($operation['submitted_at']): ?><dt class="col-sm-4">Original request</dt><dd class="col-sm-8"><?= Timezone::timestampElement(new DateTimeImmutable('@'.(int)$operation['submitted_at'])) ?></dd><?php endif; ?>
                <?php if ($operation['provider']==='usps'): ?><dt class="col-sm-4">USPS request reference</dt><dd class="col-sm-8 text-break"><code><?= labelReviewHtml($operation['idempotency_key']) ?></code></dd><?php endif; ?>
            </dl>
        </div></section>
        <?php if ($resolution): ?>
            <div class="alert alert-success" role="status">This original carrier label was recorded from verified carrier evidence by administrator #<?= (int)$resolution['actor_id'] ?> on <?= Timezone::timestampElement(new DateTimeImmutable('@'.(int)$resolution['resolved_at'])) ?>. Reference: <?= labelReviewHtml($resolution['evidence_reference']) ?>.</div>
        <?php elseif ($canReview): ?>
            <div class="alert alert-warning">Confirm the original transaction in the carrier account before recording labels. Match the destination, service, each tracking number and the final charge. This page does not buy another label.</div>
            <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="expected_state" value="<?= labelReviewHtml($operation['state']) ?>">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="evidence-reference">Carrier confirmation or case reference</label>
                        <input class="form-control" id="evidence-reference" name="evidence_reference" maxlength="100" required value="<?= labelReviewHtml($_POST['evidence_reference'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="billed-amount">Final carrier charge (USD)</label>
                        <input class="form-control" id="billed-amount" name="billed_amount" inputmode="decimal" pattern="[0-9]+\.[0-9]{2}" placeholder="9.75" required value="<?= labelReviewHtml($_POST['billed_amount'] ?? '') ?>"></div>
                    <?php for ($i=0;$i<$pieces;$i++): ?>
                        <div class="col-12"><div class="border rounded p-3">
                            <h2 class="h6">Package <?= $i+1 ?></h2>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label" for="tracking-<?= $i ?>">Carrier tracking number</label>
                                    <input class="form-control" id="tracking-<?= $i ?>" name="tracking[<?= $i ?>]" maxlength="34" required value="<?= labelReviewHtml($_POST['tracking'][$i] ?? '') ?>"></div>
                                <div class="col-md-6"><label class="form-label" for="label-<?= $i ?>">Original <?= strtoupper($operation['provider']) ?> label (<?= $operation['provider']==='usps'?'PDF':'GIF' ?>)</label>
                                    <input class="form-control" type="file" id="label-<?= $i ?>" name="labels[<?= $i ?>]" accept="<?= $operation['provider']==='usps'?'application/pdf':'image/gif' ?>" required></div>
                            </div>
                        </div></div>
                    <?php endfor; ?>
                    <div class="col-12"><label class="form-label" for="review-password">Current password</label>
                        <input class="form-control" type="password" id="review-password" name="password" autocomplete="current-password" required></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="confirm-evidence" name="confirm_evidence" value="yes" required>
                        <label class="form-check-label" for="confirm-evidence">I verified these labels belong to the original carrier transaction and its request is no longer running.</label></div></div>
                </div>
                <button class="btn btn-primary mt-4" type="submit">Record original carrier labels</button>
                <p class="small text-muted mt-2 mb-0">The combined label upload must be below 7 MB. A failed upload leaves the shipment in review.</p>
            </div></form>
        <?php else: ?>
            <div class="alert alert-info" role="status">This shipment is not eligible for manual label reconciliation yet. Check its status, cancellation or reprint request, and the carrier account.</div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body>
</html>
