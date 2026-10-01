<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../includes/growth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
use FAS\Utils\CSRF;
use FAS\Utils\Timezone;
$auth=new AdminAuth();$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function growthH($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function growthTime($v): string {return $v?Timezone::toUserDateTime(gmdate('Y-m-d H:i:s',(int)$v)):'—';}
$error='';$notice='';$growth=null;
try {$growth=fas_growth();}catch(Throwable $e){$error='Email storage is unavailable. Check the private database on the server.';}
if(($_SERVER['REQUEST_METHOD']??'')==='POST' && $growth) {
    fas_security_body();
    try {
        if(!CSRF::validateToken($_POST['csrf_token']??null)){http_response_code(403);throw new InvalidArgumentException('Refresh this page and try again.');}
        if((int)($_SESSION['security_reauth_at']??0)<time()-600) {
            if(!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password']??null))throw new InvalidArgumentException($auth->lastError?:'Verify your current password to save email settings.');
            $_SESSION['security_reauth_at']=time();
        }
        $growth->saveSettings([
            'from_email'=>$_POST['from_email']??'','reply_to'=>$_POST['reply_to']??'',
            'postal_address'=>$_POST['postal_address']??'','mail_enabled'=>isset($_POST['mail_enabled'])?'1':'0',
        ]);
        fas_security_event('email_settings','rule_changed',(int)$admin['id']);
        $notice='Email settings saved.';
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){$error='Email settings could not be saved.';}
}
try {
    $settings=$growth?$growth->settings():[];
    $summary=$growth?$growth->summary():[];
    $contacts=$growth?$growth->run('SELECT email,status,requested_at,confirmed_at FROM growth_contacts ORDER BY requested_at DESC LIMIT 50')->fetchAll():[];
    $carts=$growth?$growth->run('SELECT email,status,created_at,reminder_due,restored_at,order_id FROM growth_carts ORDER BY updated_at DESC LIMIT 50')->fetchAll():[];
    $messages=$growth?$growth->run('SELECT email,kind,status,due,sent_at,error FROM growth_messages ORDER BY id DESC LIMIT 50')->fetchAll():[];
    $sendingReady=$growth && $growth->ready();
}catch(Throwable $e){$growth=null;$error='Email storage is unavailable. Check the private database on the server.';}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sales &amp; Email - Flip and Strip Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-style.css">
<style>.growth-card{border:0}.growth-table{min-width:650px}.growth-table td{overflow-wrap:anywhere}.growth-number{font-variant-numeric:tabular-nums}</style>
</head><body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main>
    <div class="admin-hero"><h1 class="display-6 fw-bold"><i class="fas fa-envelope-open-text me-2" aria-hidden="true"></i>Sales &amp; Email</h1><p class="mb-0">Build your email audience and help interested shoppers return to their carts.</p></div>
    <?php if($error): ?><div class="alert alert-danger" role="alert"><?= growthH($error) ?></div><?php endif; ?>
    <?php if($notice): ?><div class="alert alert-success" role="status"><?= growthH($notice) ?></div><?php endif; ?>
    <?php if($growth): ?>
    <div class="alert <?= $sendingReady?'alert-success':'alert-warning' ?>">
        <?= $sendingReady?'Email delivery is enabled. The scheduled worker sends eligible queued messages.':'Email delivery is paused. Signups and cart requests are saved; configure the mailing address and sender, then enable delivery when ready.' ?>
    </div>
    <div class="row g-3 mb-4">
        <?php foreach(['subscribers'=>'Confirmed subscribers','pending'=>'Awaiting confirmation','saved_carts'=>'Open saved carts','restored'=>'Carts returned to','converted'=>'Orders after a cart return','queued'=>'Queued emails'] as $key=>$label): ?>
        <div class="col-6 col-xl-4"><div class="card growth-card h-100"><div class="card-body"><p class="small text-muted mb-2"><?= $label ?></p><p class="h2 growth-number mb-0"><?= number_format($summary[$key]) ?></p></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="row g-4 mb-4">
        <section class="col-lg-7"><div class="card growth-card h-100"><div class="card-body p-4">
            <h2 class="h5">Email delivery settings</h2>
            <form method="post">
                <?= CSRF::tokenField() ?>
                <div class="row g-3">
                    <div class="col-md-6"><label for="growth-from" class="form-label">Sender address</label><input id="growth-from" name="from_email" type="email" class="form-control" maxlength="254" required value="<?= growthH($settings['from_email']) ?>"></div>
                    <div class="col-md-6"><label for="growth-reply" class="form-label">Reply-to address</label><input id="growth-reply" name="reply_to" type="email" class="form-control" maxlength="254" required value="<?= growthH($settings['reply_to']) ?>"></div>
                    <div class="col-12"><label for="growth-address" class="form-label">Business mailing address</label><textarea id="growth-address" name="postal_address" class="form-control" maxlength="500" rows="3" placeholder="Street address or PO Box, city, state, ZIP"><?= growthH($settings['postal_address']) ?></textarea><p class="form-text">Shown in every optional email. Enter your business’s postal address.</p></div>
                    <div class="col-12"><div class="form-check"><input id="growth-enabled" name="mail_enabled" type="checkbox" class="form-check-input" <?= $settings['mail_enabled']==='1'?'checked':'' ?>><label for="growth-enabled" class="form-check-label">Enable scheduled signup confirmations and cart emails</label></div></div>
                    <?php if((int)($_SESSION['security_reauth_at']??0)<time()-600): ?>
                    <div class="col-12"><label for="growth-password" class="form-label">Current admin password</label><input id="growth-password" name="password" type="password" autocomplete="current-password" class="form-control" required></div>
                    <?php endif; ?>
                    <div class="col-12"><button type="submit" class="btn btn-danger">Save email settings</button></div>
                </div>
            </form>
        </div></div></section>
        <section class="col-lg-5"><div class="card growth-card h-100"><div class="card-body p-4">
            <h2 class="h5">What shoppers receive</h2>
            <ul class="ps-3">
                <li class="mb-2">Newsletter signup: an email confirmation before joining the list.</li>
                <li class="mb-2">“Email my cart”: a saved-cart link and one reminder after 24 hours of inactivity.</li>
                <li class="mb-2">Checkout reminder: one optional reminder, without newsletter enrollment.</li>
                <li class="mb-2">Every email includes an unsubscribe link. No automatic discounts.</li>
            </ul>
            <p class="small text-muted">Payments in progress and completed orders suppress reminders. Restored carts use current stock and prices. Order counts show a recorded order after a cart return, not proven incremental sales.</p>
            <a href="analytics.php" class="btn btn-outline-primary">Review the sales funnel</a>
        </div></div></section>
    </div>
    <?php foreach([
        ['Email signups',$contacts,['email'=>'Email','status'=>'Status','requested_at'=>'Requested','confirmed_at'=>'Confirmed']],
        ['Saved carts',$carts,['email'=>'Email','status'=>'Status','created_at'=>'Saved','reminder_due'=>'Reminder due','restored_at'=>'Returned','order_id'=>'Order']],
        ['Email queue',$messages,['email'=>'Recipient','kind'=>'Type','status'=>'Status','due'=>'Due','sent_at'=>'Transport accepted','error'=>'Delivery note']]
    ] as [$title,$rows,$columns]): ?>
    <section class="card growth-card mb-4"><div class="card-body p-4">
        <h2 class="h5"><?= $title ?></h2><p class="small text-muted">Latest 50 records. Transport acceptance does not confirm inbox delivery.</p>
        <div class="table-responsive" tabindex="0" role="region" aria-label="<?= $title ?>"><table class="table growth-table align-middle">
            <thead><tr><?php foreach($columns as $label): ?><th><?= $label ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php if(!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="text-muted py-3">No records yet.</td></tr><?php endif; ?>
            <?php foreach($rows as $row): ?><tr><?php foreach($columns as $key=>$label): ?><td><?= growthH(in_array($key,['requested_at','confirmed_at','created_at','reminder_due','restored_at','due','sent_at'],true)?growthTime($row[$key]):str_replace('_',' ',(string)($row[$key]??'—'))) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </div></section>
    <?php endforeach; endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
