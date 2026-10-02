<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingNotifications.php';
use FAS\Config\Database;
use FAS\Utils\CSRF;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingNotifications};

$auth=new AdminAuth(); $admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function notificationHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404); exit('Notification not found.'); }
$db=Database::getInstance()->getConnection();
$error=''; $notification=null; $resolution=null; $notifications=null; $orderNumber=null; $storageReady=false;
$outcome=is_string($_POST['outcome'] ?? null) ? $_POST['outcome'] : '';
$confirmed=($_POST['confirm_logs'] ?? '')==='yes';
try {
    $config=ShippingConfig::load(); $cache=new ShippingCache($config['cache_path']);
    $notifications=new ShippingNotifications($cache->database(),$db,$config);
    $notification=$notifications->find((int)$id);
    $resolution=$notifications->resolution((int)$id);
    $storageReady=true;
} catch (Throwable $e) {
    http_response_code(503);
    $error='Email review is unavailable. Check notification storage before making changes.';
}
if (!$error && !$notification) { http_response_code(404); exit('Notification not found.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    fas_security_body();
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403); throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
        }
        if ($error || !$notification || !$notifications) throw new RuntimeException('Notification storage is unavailable.');
        if (($_POST['action'] ?? '')!=='resolve' || !in_array($outcome,['accepted','suppressed'],true)) {
            throw new InvalidArgumentException('Choose the verified email outcome.');
        }
        if (!$confirmed) throw new InvalidArgumentException('Confirm the transport logs and worker status before recording an outcome.');
        if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
            if (http_response_code()<400) http_response_code(403);
            throw new InvalidArgumentException($auth->lastError ?: 'Password could not be verified.');
        }
        $notifications->resolve((int)$id,$outcome,true,(int)$admin['id']);
        header('Location: shipping-notification.php?id='.(int)$id,true,303); exit;
    } catch (DomainException $e) {
        http_response_code(409); $error='This email is no longer eligible for review. Reload to see its current outcome.';
    } catch (InvalidArgumentException $e) {
        if (http_response_code()<400) http_response_code(422);
        $error=$e->getMessage();
    } catch (Throwable $e) {
        http_response_code(503); $error='The outcome could not be recorded. Refresh and check the review history before trying again.';
    }
}
if ($notification) {
    $lookup=$db->prepare('SELECT order_number FROM orders WHERE id=?');
    $lookup->execute([(int)$notification['order_id']]); $orderNumber=$lookup->fetchColumn() ?: null;
}
$canResolve=$storageReady && $notification && !$resolution && ($notification['state']==='review'
    || ($notification['state']==='submitted' && (int)$notification['attempted_at']<=time()-900));
$stateLabels=['queued'=>'Queued','submitted'=>'Send in progress or awaiting outcome',
    'accepted'=>'Accepted by mail transport','review'=>'Transport outcome uncertain','suppressed'=>'Closed without another send'];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tracking Email Review - Flip and Strip Admin</title>
<link rel="shortcut icon" href="../gallery/favicons/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-style.css">
<link rel="stylesheet" href="css/shipping-review.css?v=<?= filemtime(__DIR__.'/css/shipping-review.css') ?>">
</head><body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="notification-review container-fluid px-3 px-lg-4 pb-5">
    <div class="admin-hero">
        <a class="btn btn-light btn-sm mb-3" href="shipping-operations.php">Back to Shipping Review</a>
        <h1 class="h3 mb-1">Tracking email review</h1>
        <p class="mb-0">Email reference #<?= (int)$id ?><?php if ($notification): ?> · Order <?= notificationHtml($orderNumber ?: '#'.$notification['order_id']) ?><?php endif; ?></p>
    </div>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= notificationHtml($error) ?></div><?php endif; ?>
    <?php if ($notification): ?>
        <div class="row g-4">
            <section class="col-lg-5" aria-labelledby="email-status"><div class="card border-0 shadow-sm"><div class="card-body p-4">
                <h2 class="h5" id="email-status">Recorded status</h2>
                <p class="fw-semibold"><?= notificationHtml($stateLabels[$notification['state']]) ?></p>
                <dl class="small mb-3">
                    <dt>Queued (UTC)</dt><dd><?= notificationHtml(gmdate('M j, Y H:i',(int)$notification['created_at'])) ?></dd>
                    <dt>Transport attempt (UTC)</dt><dd><?= $notification['attempted_at'] ? notificationHtml(gmdate('M j, Y H:i',(int)$notification['attempted_at'])) : 'No attempt recorded' ?></dd>
                </dl>
                <p class="text-muted small">Mail transport acceptance does not confirm inbox delivery. Recording an outcome never sends the original email again.</p>
                <?php if ($orderNumber): ?><a class="btn btn-outline-primary btn-sm" href="order-details.php?id=<?= (int)$notification['order_id'] ?>">View order</a><?php endif; ?>
            </div></div></section>
            <section class="col-lg-7" aria-labelledby="email-resolution"><div class="card border-0 shadow-sm"><div class="card-body p-4">
                <h2 class="h5" id="email-resolution"><?= $resolution?'Review history':'Record verified outcome' ?></h2>
                <?php if ($resolution): ?>
                    <div class="alert alert-success" role="status">Outcome recorded. No email was resent.</div>
                    <p><?= notificationHtml($stateLabels[$resolution['outcome']]) ?></p>
                    <p class="small text-muted mb-0"><?= notificationHtml(gmdate('M j, Y H:i',(int)$resolution['resolved_at'])) ?> UTC ·
                        <?= $resolution['source']==='admin'?'Administrator #'.(int)$resolution['actor_id']:'Server operator' ?></p>
                <?php elseif ($canResolve): ?>
                    <p class="small text-muted">Check the transport logs for this order and attempt time. Stop or confirm completion of any worker still handling the send before recording its outcome.</p>
                    <form method="post" action="shipping-notification.php?id=<?= (int)$id ?>">
                        <input type="hidden" name="csrf_token" value="<?= notificationHtml(CSRF::getToken()) ?>"><input type="hidden" name="action" value="resolve">
                        <fieldset class="mb-3"><legend class="fs-6">Verified outcome</legend>
                            <div class="form-check mb-2"><input class="form-check-input" type="radio" name="outcome" id="mail-accepted" value="accepted" required <?= $outcome==='accepted'?'checked':'' ?>>
                                <label class="form-check-label" for="mail-accepted">The mail transport accepted this email</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="outcome" id="mail-suppressed" value="suppressed" required <?= $outcome==='suppressed'?'checked':'' ?>>
                                <label class="form-check-label" for="mail-suppressed">Close this update without sending it</label></div>
                        </fieldset>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="mail-confirmed" name="confirm_logs" value="yes" required <?= $confirmed?'checked':'' ?>>
                            <label class="form-check-label" for="mail-confirmed">I checked the mail transport logs and confirmed no worker is still sending this email.</label></div>
                        <div class="mb-3"><label class="form-label" for="mail-password">Your current password</label>
                            <input type="password" class="form-control" id="mail-password" name="password" autocomplete="current-password" required></div>
                        <button type="submit" class="btn btn-primary">Record outcome</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">This email is not eligible for reconciliation. Recent sends remain protected for at least 15 minutes; completed outcomes cannot be changed here.</p>
                <?php endif; ?>
            </div></div></section>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
