<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
use FAS\Utils\CSRF;
use FAS\Utils\Timezone;
use FAS\Security\ClientIp;
use FAS\Security\SecurityStore;

$auth = new AdminAuth();
$admin = $auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function secText($v): string { return is_string($v) ? $v : ''; }
function secHtml($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function secTime($v): string { return Timezone::toUserDateTime(gmdate('Y-m-d H:i:s',(int)$v)); }
$tabs = ['overview'=>'Overview','rules'=>'Rules','restrictions'=>'Restrictions','activity'=>'Activity'];
$tab = secText($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) $tab = 'overview';
$client = fas_security_ip();
$ip = $client['ip'];
$error = ''; $healthy = true; $store = null;
$success = secText($_SESSION['security_notice'] ?? '');
unset($_SESSION['security_notice']);
try { $store = fas_security_store(); } catch (Throwable $e) { $healthy = false; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    fas_security_body();
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
        }
        if (!$store) throw new RuntimeException('Storage unavailable');
        $action = secText($_POST['action'] ?? '');
        if ($action === 'reauth') {
            if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
                throw new InvalidArgumentException($auth->lastError ?: 'Password could not be verified.');
            }
            $_SESSION['security_reauth_at'] = time();
            $success = 'Password verified. Security changes are available for ten minutes.';
        } else {
            if ((int)($_SESSION['security_reauth_at'] ?? 0) < time()-600) {
                http_response_code(403);
                throw new InvalidArgumentException('Verify your password before changing security controls.');
            }
            if ($action === 'save_rule') {
                $capacity = filter_var($_POST['capacity'] ?? null,FILTER_VALIDATE_INT);
                $seconds = filter_var($_POST['seconds'] ?? null,FILTER_VALIDATE_INT);
                $store->saveRule(secText($_POST['rule'] ?? ''),(int)$capacity,(int)$seconds,secText($_POST['mode'] ?? ''),$ip,(int)$admin['id']);
                $success = 'Rule saved. Its counters were reset.';
            } elseif ($action === 'reset_rules') {
                $store->resetRules($ip,(int)$admin['id']); $success = 'Default rules restored.';
            } elseif ($action === 'block') {
                $store->block(secText($_POST['ip'] ?? ''),(int)secText($_POST['duration'] ?? ''),secText($_POST['reason'] ?? ''),$ip,(int)$admin['id']);
                $success = 'Temporary IP block added.';
            } elseif ($action === 'unblock') {
                $store->unblock(secText($_POST['ip'] ?? ''),$ip,(int)$admin['id']); $success = 'IP block and counters cleared.';
            } elseif ($action === 'clear') {
                $store->clearBucket(secText($_POST['id'] ?? ''),$ip,(int)$admin['id']); $success = 'Selected counter cleared.';
            } else { throw new InvalidArgumentException('Unknown security action.'); }
        }
        $_SESSION['security_notice'] = $success;
        header('Location: security.php?tab='.rawurlencode($tab),true,303); exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { $healthy = false; $error = 'Security storage is unavailable. Changes were not saved. Use the server maintenance command to check storage.'; }
}
$verified = (int)($_SESSION['security_reauth_at'] ?? 0) >= time()-600;
$active = false; $summary = []; $rules = []; $blocks = []; $buckets = []; $events = []; $total = 0;
$days = (int)secText($_GET['days'] ?? '1'); if (!in_array($days,[1,7,30],true)) $days = 1;
$filterIp = ClientIp::normalize($_GET['ip'] ?? '');
$filterRule = secText($_GET['rule'] ?? '');
$filterOutcome = secText($_GET['outcome'] ?? '');
$outcomes = ['login_success','login_failed','throttled','blocked','observed','reauth_success','reauth_failed',
    'password_changed','rule_changed','rules_reset','block_added','unblocked','counter_cleared','activated','deactivated'];
$page = max(1,min(2000,(int)secText($_GET['page'] ?? '1')));
if ($store && $healthy) {
    try {
        $active = $store->active(); $summary = $store->summary(); $rules = $store->rules();
        $blocks = $store->run('SELECT * FROM security_blocks WHERE expires>? ORDER BY expires LIMIT 100',[$store->now()])->fetchAll();
        if ($tab === 'restrictions') $buckets = $store->restrictions();
        if ($tab === 'activity') {
            $where = ['time>=?']; $params = [$store->now()-$days*86400];
            if ($filterIp !== '') { $where[] = 'ip=?'; $params[] = $filterIp; }
            if ($filterRule !== '' && in_array($filterRule,array_merge(array_keys($rules),['manual','system','login','reauth','password','email_settings']),true)) {
                $where[] = 'rule=?'; $params[] = $filterRule;
            }
            if (in_array($filterOutcome,$outcomes,true)) { $where[] = 'outcome=?'; $params[] = $filterOutcome; }
            $whereSql = implode(' AND ',$where);
            $total = (int)$store->run('SELECT COUNT(*) FROM security_events WHERE '.$whereSql,$params)->fetchColumn();
            $page = min($page,max(1,(int)ceil($total/50)));
            $events = $store->run('SELECT * FROM security_events WHERE '.$whereSql.' ORDER BY id DESC LIMIT 50 OFFSET '.(($page-1)*50),$params)->fetchAll();
        }
    } catch (Throwable $e) { $healthy = false; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin-style.css">
    <style>
        .security-shell {max-width:1400px;margin:auto}
        .security-shell .card {border:0;box-shadow:0 .125rem .5rem #0000000d}
        .security-shell code,.security-shell td {overflow-wrap:anywhere}
        .security-shell .table {min-width:680px}
        .security-shell .nav-link {border-radius:.7rem}
        .security-rule {display:grid;grid-template-columns:minmax(180px,2fr) repeat(3,minmax(90px,1fr)) auto;gap:1rem;align-items:end}
        .security-rule label {font-size:.85rem}
        .security-shell .form-text {margin-bottom:0}
        @media(max-width:991px){.security-rule{grid-template-columns:1fr 1fr}.security-rule-title{grid-column:1/-1}}
        @media(max-width:420px){.security-rule{grid-template-columns:1fr}.security-shell .nav{gap:.25rem}.security-shell .nav-link{padding:.5rem .65rem}}
    </style>
</head>
<body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="security-shell">
    <div class="admin-hero d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div><h1 class="display-6 fw-bold"><i class="fas fa-shield-halved me-2" aria-hidden="true"></i>Security</h1>
        <p class="mb-0">Manage access restrictions and review recent activity.</p></div>
        <span class="badge rounded-pill <?= $healthy ? ($active?'bg-success':'bg-warning text-dark'):'bg-danger' ?> p-3">
            <?= !$healthy ? 'Storage unavailable' : ($active?'Limits enforced':'Awaiting activation') ?>
        </span>
    </div>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= secHtml($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= secHtml($error) ?></div><?php endif; ?>
    <?php if (!$healthy): ?>
        <div class="alert alert-danger" role="alert">Security storage is unavailable. New protected actions may be temporarily unavailable. Existing admin access and payment recovery remain available. Check the private database on the server.</div>
    <?php elseif (!$active): ?>
        <div class="alert alert-warning">Limits are being observed. Activate enforcement on the server after verifying storage permissions, visitor IP detection, and checkout recovery.</div>
    <?php endif; ?>
    <nav class="nav nav-pills flex-wrap gap-2 mb-4" aria-label="Security sections">
        <?php foreach ($tabs as $key=>$label): ?><a class="nav-link <?= $tab===$key?'active':'' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="?tab=<?= $key ?>"><?= $label ?></a><?php endforeach; ?>
    </nav>
    <?php if ($healthy && $tab==='overview'): ?>
        <div class="row g-3 mb-4">
            <?php foreach (['login_failed'=>'Failed logins · 24h','throttled'=>'Throttled requests · 24h','observed'=>'Observed limits · 24h','active_blocks'=>'Active IP blocks'] as $key=>$label): ?>
                <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><p class="text-muted mb-2"><?= $label ?></p><p class="h2 mb-0"><?= number_format($summary[$key]) ?></p></div></div></div>
            <?php endforeach; ?>
        </div>
        <div class="row g-4">
            <div class="col-lg-7"><section class="card h-100"><div class="card-body p-4">
                <h2 class="h5 mb-3">Visitor IP detection</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-5">Your detected IP</dt><dd class="col-sm-7"><?= secHtml($ip) ?></dd>
                    <dt class="col-sm-5">Address source</dt><dd class="col-sm-7"><?= secHtml($client['source']) ?></dd>
                    <dt class="col-sm-5">Connection peer</dt><dd class="col-sm-7"><?= secHtml($client['peer']) ?></dd>
                    <dt class="col-sm-5">Storage</dt><dd class="col-sm-7">Healthy · private database</dd>
                    <dt class="col-sm-5">Activity retention</dt><dd class="col-sm-7">30 days · up to 100,000 entries</dd>
                </dl>
                <p class="text-muted mb-0">Compare the detected address with your connection before activating limits. Forwarded headers from untrusted connections are ignored.</p>
            </div></section></div>
            <div class="col-lg-5"><section class="card h-100"><div class="card-body p-4">
                <h2 class="h5">Protected actions</h2>
                <p>Sign-in, public submissions, shipping, coupons, new payment attempts, and data collection have separate budgets.</p>
                <p>Browsing and search-engine crawling remain available. Payment completion and recovery have their own limits and are excluded from manual blocks.</p>
                <a href="?tab=rules" class="btn btn-outline-primary">Review limits</a>
            </div></section></div>
        </div>
    <?php elseif ($healthy && in_array($tab,['rules','restrictions'],true)): ?>
        <section class="card mb-4"><div class="card-body p-4">
            <h2 class="h5">Confirm your identity</h2>
            <?php if ($verified): ?><p class="text-success mb-0"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>Security changes are unlocked for this session until <?= secHtml(secTime((int)$_SESSION['security_reauth_at']+600)) ?>.</p>
            <?php else: ?>
                <form method="post" class="row g-3 align-items-end">
                    <?= CSRF::tokenField() ?><input type="hidden" name="action" value="reauth">
                    <div class="col-md-8"><label class="form-label" for="security-password">Current password</label><input id="security-password" type="password" name="password" class="form-control" autocomplete="current-password" maxlength="4096" required></div>
                    <div class="col-md-4"><button class="btn btn-primary" type="submit">Unlock changes</button></div>
                </form>
            <?php endif; ?>
        </div></section>
        <fieldset <?= $verified?'':'disabled' ?>>
        <?php if ($tab==='rules'): ?>
            <p class="text-muted">Capacity is the allowed burst. Tokens refill continuously over the interval. Saving a rule clears its counters.</p>
            <?php foreach ($rules as $id=>$r): ?>
                <form method="post" class="card card-body p-4 mb-3 security-rule">
                    <?= CSRF::tokenField() ?><input type="hidden" name="action" value="save_rule"><input type="hidden" name="rule" value="<?= secHtml($id) ?>">
                    <div class="security-rule-title"><h2 class="h6 mb-1"><?= secHtml($r['label']) ?></h2><small class="text-muted"><?= secHtml($id) ?></small></div>
                    <div><label for="<?= $id ?>-capacity" class="form-label">Capacity</label><input id="<?= $id ?>-capacity" type="number" name="capacity" min="1" max="10000" required class="form-control" value="<?= (int)$r['capacity'] ?>"></div>
                    <div><label for="<?= $id ?>-seconds" class="form-label">Interval (seconds)</label><input id="<?= $id ?>-seconds" type="number" name="seconds" min="10" max="86400" required class="form-control" value="<?= (int)$r['seconds'] ?>"></div>
                    <div><label for="<?= $id ?>-mode" class="form-label">Mode</label><select id="<?= $id ?>-mode" name="mode" class="form-select"><option value="enforce" <?= $r['mode']==='enforce'?'selected':'' ?>>Enforce</option><option value="observe" <?= $r['mode']==='observe'?'selected':'' ?>>Observe</option></select></div>
                    <button class="btn btn-outline-primary" type="submit" aria-label="Save <?= secHtml($r['label']) ?>">Save</button>
                </form>
            <?php endforeach; ?>
            <form method="post" class="my-4"><?= CSRF::tokenField() ?><input type="hidden" name="action" value="reset_rules"><button type="submit" class="btn btn-outline-danger">Restore all default rules and clear counters</button></form>
        <?php else: ?>
            <section class="card mb-4"><div class="card-body p-4"><h2 class="h5">Add a temporary block</h2>
                <p class="text-muted">Blocks apply to protected submissions and sign-in. Your current address and trusted proxy addresses cannot be blocked.</p>
                <form method="post" class="row g-3">
                    <?= CSRF::tokenField() ?><input type="hidden" name="action" value="block">
                    <div class="col-md-5"><label for="block-ip" class="form-label">Exact IPv4 or IPv6 address</label><input id="block-ip" name="ip" maxlength="45" required class="form-control"></div>
                    <div class="col-md-3"><label for="block-duration" class="form-label">Duration</label><select id="block-duration" name="duration" class="form-select"><option value="900">15 minutes</option><option value="3600">1 hour</option><option value="86400">24 hours</option></select></div>
                    <div class="col-md-8"><label for="block-reason" class="form-label">Reason</label><input id="block-reason" name="reason" maxlength="200" required class="form-control"><p class="form-text">Describe the abuse. Do not include customer details or credentials.</p></div>
                    <div class="col-12"><button class="btn btn-danger" type="submit">Add temporary block</button></div>
                </form>
            </div></section>
            <section class="card mb-4"><div class="card-body p-4"><h2 class="h5">Active blocks</h2>
                <?php if (!$blocks): ?><p class="text-muted mb-0">No active IP blocks.</p><?php endif; ?>
                <?php foreach ($blocks as $b): ?><div class="border-bottom py-3 d-flex flex-wrap justify-content-between gap-3">
                    <div><strong><?= secHtml($b['ip']) ?></strong><p class="mb-1"><?= secHtml($b['reason']) ?></p><small class="text-muted">Expires <?= secHtml(secTime($b['expires'])) ?></small></div>
                    <form method="post"><?= CSRF::tokenField() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?= secHtml($b['ip']) ?>"><button class="btn btn-outline-secondary" type="submit">Unblock <?= secHtml($b['ip']) ?></button></form>
                </div><?php endforeach; ?>
            </div></section>
            <section class="card mb-4"><div class="card-body p-4"><h2 class="h5">Exhausted budgets</h2><p class="text-muted">Up to 100 current restrictions, including observation counters. Clearing a counter preserves its activity history.</p>
                <?php if (!$buckets): ?><p class="mb-0">No exhausted budgets.</p><?php endif; ?>
                <?php foreach ($buckets as $b): ?><div class="border-bottom py-3 d-flex flex-wrap justify-content-between gap-3">
                    <div><strong><?= secHtml($rules[$b['rule']]['label']) ?></strong><p class="mb-1"><?= secHtml($b['ip']) ?></p><small class="text-muted">Next token <?= secHtml(secTime(ceil($b['available']))) ?></small></div>
                    <form method="post"><?= CSRF::tokenField() ?><input type="hidden" name="action" value="clear"><input type="hidden" name="id" value="<?= secHtml($b['id']) ?>"><button class="btn btn-outline-secondary" type="submit">Clear counter</button></form>
                </div><?php endforeach; ?>
            </div></section>
        <?php endif; ?>
        </fieldset>
    <?php elseif ($healthy && $tab==='activity'): ?>
        <form method="get" class="card card-body p-4 mb-4"><input type="hidden" name="tab" value="activity">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-xl-2"><label for="days" class="form-label">Period</label><select class="form-select" name="days" id="days"><?php foreach([1,7,30] as $d): ?><option value="<?= $d ?>" <?= $days===$d?'selected':'' ?>>Last <?= $d ?> day<?= $d===1?'':'s' ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6 col-xl-3"><label for="filter-ip" class="form-label">IP address</label><input id="filter-ip" name="ip" maxlength="45" class="form-control" value="<?= secHtml($filterIp) ?>"></div>
                <div class="col-sm-6 col-xl-3"><label for="filter-rule" class="form-label">Rule</label><select id="filter-rule" name="rule" class="form-select"><option value="">All rules</option><?php foreach(array_merge(array_keys($rules),['manual','system','login','reauth','password','email_settings']) as $r): ?><option <?= $filterRule===$r?'selected':'' ?>><?= secHtml($r) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6 col-xl-3"><label for="outcome" class="form-label">Outcome</label><select id="outcome" name="outcome" class="form-select"><option value="">All outcomes</option><?php foreach($outcomes as $o): ?><option value="<?= $o ?>" <?= $filterOutcome===$o?'selected':'' ?>><?= secHtml(str_replace('_',' ',$o)) ?></option><?php endforeach; ?></select></div>
                <div class="col-xl-1"><button type="submit" class="btn btn-primary">Filter</button></div>
            </div>
        </form>
        <section class="card"><div class="card-body p-4"><h2 class="h5">Security activity <span class="text-muted fw-normal">(<?= number_format($total) ?> entries)</span></h2>
            <p class="text-muted">Times shown in <?= secHtml(Timezone::userTimezone()) ?>. Repeated denials are grouped by minute.</p>
            <div class="table-responsive" tabindex="0" role="region" aria-label="Security activity">
                <table class="table align-middle"><thead><tr><th>Time</th><th>IP / route</th><th>Rule / outcome</th><th>Count</th><th>Details / actor</th></tr></thead><tbody>
                    <?php if (!$events): ?><tr><td colspan="5" class="text-muted py-4">No activity matches these filters.</td></tr><?php endif; ?>
                    <?php foreach($events as $e): ?><tr><td><?= secHtml(secTime($e['time'])) ?></td><td><?= secHtml($e['ip']) ?><br><small class="text-muted"><?= secHtml($e['path']) ?></small></td><td><?= secHtml($e['rule']) ?><br><strong><?= secHtml(str_replace('_',' ',$e['outcome'])) ?></strong></td><td><?= (int)$e['count'] ?></td><td><?= secHtml($e['detail']) ?><br><small class="text-muted"><?= $e['actor']?'Admin #'.(int)$e['actor']:'System / visitor' ?></small></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
            <nav class="d-flex flex-wrap gap-3 align-items-center mt-3" aria-label="Activity pages">
                <?php foreach ([$page-1=>'Previous',$page+1=>'Next'] as $p=>$label): if ($p>=1 && $p<=ceil($total/50)): ?>
                    <a class="btn btn-outline-secondary" href="?<?= secHtml(http_build_query(['tab'=>'activity','days'=>$days,'ip'=>$filterIp,'rule'=>$filterRule,'outcome'=>$filterOutcome,'page'=>$p])) ?>"><?= $label ?></a>
                <?php endif; endforeach; ?><span class="text-muted">Page <?= $page ?> of <?= max(1,(int)ceil($total/50)) ?></span>
            </nav>
        </div></section>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
