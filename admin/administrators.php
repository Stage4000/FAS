<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
use FAS\Utils\CSRF;
use FAS\Utils\Timezone;

$auth = new AdminAuth();
$admin = $auth->requireActiveAdmin();
$db = \FAS\Config\Database::getInstance()->getConnection();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
function accountH($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function accountText($value): string { return is_string($value) ? $value : ''; }
$error = '';
$success = accountText($_SESSION['admin_accounts_notice'] ?? '');
unset($_SESSION['admin_accounts_notice']);
$editId = max(0, (int)accountText($_GET['edit'] ?? ''));
$creating = isset($_GET['create']);
$posted = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    fas_security_body();
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');
        }
        $action = accountText($_POST['action'] ?? '');
        if ($action === 'reauth') {
            if (!$auth->verifyCurrentPassword((int)$admin['id'], $_POST['password'] ?? null)) {
                throw new InvalidArgumentException($auth->lastError ?: 'Current password is incorrect.');
            }
            $_SESSION['security_reauth_at'] = time();
            $success = 'Identity confirmed. Account changes are unlocked for ten minutes.';
        } else {
            if ((int)($_SESSION['security_reauth_at'] ?? 0) < time()-600) {
                http_response_code(403);
                throw new InvalidArgumentException('Confirm your current password before managing administrators.');
            }
            $limit = fas_security_check(['admin_accounts'=>(string)$admin['id']], false, true);
            if (!$limit['allowed']) {
                fas_security_headers($limit);
                throw new InvalidArgumentException(fas_security_message($limit));
            }
            $targetId = fas_admin_account_change($db, (int)$admin['id'], $_SESSION['admin_session_token'], $action, $_POST);
            $labels = ['create'=>'Administrator added. Share their credentials privately.', 'update'=>'Account details saved.',
                'reset_password'=>'Password reset. Existing sessions for this account have been revoked.',
                'deactivate'=>'Administrator deactivated. Their existing sessions no longer have access.',
                'activate'=>'Administrator reactivated. They can sign in again.'];
            $success = $labels[$action];
            fas_security_event('admin_accounts', 'admin_'.$action, (int)$admin['id']);
            $editId = $targetId;
            $creating = false;
        }
        $_SESSION['admin_accounts_notice'] = $success;
        header('Location: administrators.php'.($creating?'?create=1':($editId?'?edit='.$editId:'')), true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        // Retain profile fields only; passwords are never returned in HTML.
        foreach (['username','email','full_name'] as $key) if (isset($_POST[$key])) $posted[$key] = accountText($_POST[$key]);
    } catch (Throwable $e) {
        http_response_code(503);
        $error = 'Account changes could not be saved. Try again shortly.';
    }
}
$verified = (int)($_SESSION['security_reauth_at'] ?? 0) >= time()-600;
$editing = $editId ? fas_admin_account($db, $editId) : null;
if ($editing && $editing['role'] !== 'admin') $editing = null;
if ($editId && !$editing) { http_response_code(404); $error = 'Administrator not found.'; }
$values = array_merge($editing ?: ['username'=>'','email'=>'','full_name'=>''], $posted);
$counts = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_active=1),0) AS active FROM admin_users WHERE role='admin'")->fetch();
$page = max(1,min(max(1,(int)ceil($counts['total']/25)),(int)accountText($_GET['page'] ?? '1')));
$accounts = $db->query("SELECT id,username,email,full_name,is_active,last_login FROM admin_users WHERE role='admin'
    ORDER BY is_active DESC,username COLLATE NOCASE LIMIT 25 OFFSET ".(($page-1)*25))->fetchAll();
$events = $db->query('SELECT e.*, a.username AS actor, t.username AS target FROM admin_account_events e
    LEFT JOIN admin_users a ON a.id=e.actor_id LEFT JOIN admin_users t ON t.id=e.target_id ORDER BY e.id DESC LIMIT 20')->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administrators - Flip and Strip Admin</title>
<link rel="shortcut icon" href="../gallery/favicons/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-style.css?v=<?= filemtime(__DIR__.'/css/admin-style.css') ?>">
<style>.accounts-shell{max-width:1400px;margin:auto}.accounts-shell td{overflow-wrap:anywhere}.accounts-table{min-width:700px}.account-form{max-width:760px}.accounts-shell .card{border:0}.accounts-shell summary{cursor:pointer}[data-theme="dark"] .accounts-shell a:not(.btn){color:#9ec5fe}[data-theme="dark"] .accounts-shell .text-success{color:#75b798!important}[data-theme="dark"] .accounts-shell .btn-outline-danger{color:#ff8fa3;border-color:#ff8fa3}</style>
</head><body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="accounts-shell">
    <div class="admin-hero d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div><h1 class="display-6 fw-bold"><i class="fas fa-user-shield me-2" aria-hidden="true"></i>Administrators</h1><p class="mb-0">Individual accounts for everyone who manages your store.</p></div>
        <a class="btn btn-light" href="?create=1">Add administrator</a>
    </div>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= accountH($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= accountH($error) ?></div><?php endif; ?>
    <section class="card mb-4"><div class="card-body p-4">
        <h2 class="h5">Account access</h2>
        <p><strong><?= (int)$counts['active'] ?> active</strong> of <?= (int)$counts['total'] ?> administrators. Every administrator has full access, including account management.</p>
        <?php if ($verified): ?><p class="text-success mb-0">Identity confirmed. Account changes are unlocked for this session.</p>
        <?php else: ?>
            <p class="text-muted">Confirm your password to add administrators or change their access.</p>
            <form method="post" data-no-ajax class="row g-3 align-items-end account-form">
                <?= CSRF::tokenField() ?><input type="hidden" name="action" value="reauth">
                <div class="col-md-8"><label class="form-label" for="account-current-password">Your current password</label><input class="form-control" id="account-current-password" name="password" type="password" autocomplete="current-password" required maxlength="4096"></div>
                <div class="col-md-4"><button class="btn btn-danger" type="submit">Unlock account changes</button></div>
            </form>
        <?php endif; ?>
    </div></section>
    <?php if ($creating || $editing): ?>
    <section class="card mb-4"><div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between gap-2"><h2 class="h5"><?= $creating?'Add administrator':'Edit '.accountH($editing['username']) ?></h2><a href="administrators.php">Back to accounts</a></div>
        <form method="post" data-no-ajax class="account-form">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="<?= $creating?'create':'update' ?>">
            <?php if (!$creating): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
            <fieldset <?= $verified?'':'disabled' ?>><div class="row g-3 mt-1">
                <div class="col-md-6"><label for="account-name" class="form-label">Full name</label><input id="account-name" class="form-control" name="full_name" maxlength="100" required value="<?= accountH($values['full_name']) ?>" autocomplete="off"></div>
                <div class="col-md-6"><label for="account-username" class="form-label">Username</label><input id="account-username" class="form-control" name="username" minlength="3" maxlength="64" required value="<?= accountH($values['username']) ?>" autocomplete="off"><p class="form-text">Usernames are case-sensitive when signing in.</p></div>
                <div class="col-12"><label for="account-email" class="form-label">Email address</label><input id="account-email" class="form-control" name="email" type="email" maxlength="254" required value="<?= accountH($values['email']) ?>" autocomplete="off"></div>
                <?php if ($creating): ?>
                <div class="col-md-6"><label for="account-new-password" class="form-label">Initial password</label><input id="account-new-password" class="form-control" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
                <div class="col-md-6"><label for="account-confirm-password" class="form-label">Confirm initial password</label><input id="account-confirm-password" class="form-control" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
                <div class="col-12"><p class="form-text">Use 12–72 characters; non-ASCII characters may use more than one byte. Share credentials privately. No invitation email is sent.</p></div>
                <?php endif; ?>
                <div class="col-12"><button type="submit" class="btn btn-danger"><?= $creating?'Create administrator':'Save account details' ?></button></div>
            </div></fieldset>
        </form>
        <?php if ($editing && !$creating): ?>
            <?php if ((int)$editing['id'] === (int)$admin['id']): ?>
                <p class="mt-4 mb-0">This is your account. <a href="password.php">Change your password</a>. You cannot deactivate yourself.</p>
            <?php else: ?>
                <hr class="my-4">
                <details class="account-form mb-4"><summary class="fw-semibold">Reset password</summary>
                    <p class="text-muted mt-3">A password reset signs this administrator out of all existing sessions.</p>
                    <form method="post" data-no-ajax><?= CSRF::tokenField() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                        <fieldset <?= $verified?'':'disabled' ?>><div class="row g-3">
                            <div class="col-md-6"><label for="reset-password" class="form-label">New password</label><input id="reset-password" name="new_password" type="password" class="form-control" autocomplete="new-password" minlength="12" maxlength="72" required></div>
                            <div class="col-md-6"><label for="reset-confirm" class="form-label">Confirm new password</label><input id="reset-confirm" name="confirm_password" type="password" class="form-control" autocomplete="new-password" minlength="12" maxlength="72" required></div>
                            <div class="col-12"><button class="btn btn-danger" type="submit">Reset password and revoke sessions</button></div>
                        </div></fieldset>
                    </form>
                </details>
                <h3 class="h6"><?= $editing['is_active']?'Deactivate account':'Reactivate account' ?></h3>
                <p class="text-muted"><?= $editing['is_active']?'Deactivation removes access from existing sessions and prevents new sign-ins. Account history is retained.':'Reactivation allows this administrator to sign in with their current password. Old sessions remain revoked.' ?></p>
                <form method="post" data-no-ajax><?= CSRF::tokenField() ?><input type="hidden" name="action" value="<?= $editing['is_active']?'deactivate':'activate' ?>"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><button class="btn btn-outline-danger" type="submit" <?= $verified?'':'disabled' ?>><?= $editing['is_active']?'Deactivate administrator':'Reactivate administrator' ?></button></form>
            <?php endif; ?>
        <?php endif; ?>
    </div></section>
    <?php endif; ?>
    <section class="card mb-4"><div class="card-body p-4">
        <h2 class="h5 mb-3">Administrator accounts</h2>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Administrator accounts"><table class="table accounts-table align-middle">
            <thead><tr><th scope="col">Administrator</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col">Manage</th></tr></thead>
            <tbody><?php foreach ($accounts as $account): ?><tr>
                <td><strong><?= accountH($account['full_name'] ?: $account['username']) ?></strong><div class="small text-muted"><?= accountH($account['username']) ?><?= (int)$account['id']===(int)$admin['id']?' · You':'' ?></div></td>
                <td><?= accountH($account['email']) ?></td><td><span class="badge <?= $account['is_active']?'bg-success':'bg-secondary' ?>"><?= $account['is_active']?'Active':'Inactive' ?></span></td>
                <td><?= $account['last_login']?accountH(Timezone::toUserDateTime($account['last_login'])):'Never signed in' ?></td>
                <td><a class="btn btn-outline-secondary btn-sm" href="?edit=<?= (int)$account['id'] ?>" aria-label="Edit <?= accountH($account['username']) ?>">Edit</a></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="d-flex justify-content-between align-items-center gap-3 mt-3"><span class="small text-muted">Page <?= $page ?> of <?= max(1,(int)ceil($counts['total']/25)) ?></span><nav aria-label="Account pages"><?php if ($page>1): ?><a class="btn btn-outline-secondary btn-sm me-2" href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if ($page*25<$counts['total']): ?><a class="btn btn-outline-secondary btn-sm" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav></div>
    </div></section>
    <section class="card mb-4"><div class="card-body p-4"><h2 class="h5">Recent account changes</h2>
        <p class="text-muted small">Latest 20 changes. Passwords and previous credentials are never shown.</p>
        <?php if (!$events): ?><p class="mb-0 text-muted">No account changes yet.</p><?php endif; ?>
        <?php foreach ($events as $event): ?><div class="border-bottom py-3"><strong><?= accountH($event['actor'] ?: 'Admin #'.$event['actor_id']) ?></strong> · <?= accountH(['create'=>'Added','update'=>'Updated','reset_password'=>'Reset password for','deactivate'=>'Deactivated','activate'=>'Reactivated'][$event['action']] ?? $event['action']) ?> <?= accountH($event['target'] ?: 'Admin #'.$event['target_id']) ?><div class="small text-muted"><?= accountH(Timezone::toUserDateTime($event['occurred_at'])) ?></div></div><?php endforeach; ?>
    </div></section>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
