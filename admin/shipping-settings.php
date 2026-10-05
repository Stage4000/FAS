<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/shipping/ShippingSettingsStore.php';
require_once __DIR__.'/../src/config/Database.php';

use FAS\Utils\CSRF;
use FAS\Shipping\{ShippingConfig,ShippingSettingsStore};
use FAS\Config\Database;

$auth=new AdminAuth();
$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
function shippingSettingsHtml($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$error='';
$notice=$_SESSION['shipping_settings_notice'] ?? '';
unset($_SESSION['shipping_settings_notice']);
$config=null;
try {
    $config=ShippingConfig::load();
} catch (Throwable $e) {
    // An explicit path may be configured before its first save.
    if (!is_file(ShippingConfig::managedPath()) && !is_file(__DIR__.'/../src/config/shipping.php')) {
        try { $config=ShippingConfig::validate(require __DIR__.'/../src/config/shipping.example.php'); }
        catch (Throwable $ignored) {}
    }
    if ($config===null) $error='Shipping settings could not be read. Check the private configuration on the server.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    fas_security_body();
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Refresh this page and try again.');
        }
        if ($config===null) throw new RuntimeException('Shipping settings unavailable.');
        if (!$auth->allowSettingsChange((int)$admin['id'])) {
            throw new InvalidArgumentException($auth->lastError ?: 'Wait before saving settings again.');
        }
        if (!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password'] ?? null)) {
            throw new InvalidArgumentException($auth->lastError ?: 'Current password could not be verified.');
        }
        $action=$_POST['action'] ?? '';
        if (!is_string($action)) throw new InvalidArgumentException('Choose a valid shipping action.');
        if (in_array($action,['save','clear','approve_carrier'],true)) {
            $carrier=$_POST['carrier'] ?? '';
            if (!is_string($carrier) || !in_array($carrier,['usps','ups'],true)) {
                throw new InvalidArgumentException('Choose a valid carrier.');
            }
            if ($action==='approve_carrier') {
                ShippingSettingsStore::approveCarrier($carrier,($_POST['confirm_account'] ?? null)==='1');
                $_SESSION['shipping_settings_notice']=strtoupper($carrier).' approved for direct checkout rates. Checkout provider is unchanged.';
                fas_security_event('shipping_settings','shipping_carrier_approved',(int)$admin['id']);
            } else {
                if ($action==='clear' && ($_POST['confirm_clear'] ?? null)!=='1') {
                    throw new InvalidArgumentException('Confirm that the saved carrier credentials should be removed.');
                }
                ShippingSettingsStore::save($carrier,$_POST,$action==='clear');
                $_SESSION['shipping_settings_notice']=strtoupper($carrier).' credentials '.($action==='clear'?'removed.':'saved.').' Checkout provider is unchanged.';
                fas_security_event('shipping_settings','shipping_credentials_changed',(int)$admin['id']);
            }
        } elseif ($action==='save_easyship') {
            ShippingSettingsStore::saveEasyship($_POST);
            $_SESSION['shipping_settings_notice']='Easyship settings saved. Checkout provider is unchanged.';
            fas_security_event('shipping_settings','shipping_easyship_changed',(int)$admin['id']);
        } elseif ($action==='switch_mode') {
            $mode=$_POST['mode'] ?? '';
            if (!is_string($mode)) throw new InvalidArgumentException('Choose a valid checkout provider.');
            ShippingSettingsStore::setMode($mode,Database::getInstance()->getConnection(),
                ($_POST['confirm_packing'] ?? null)==='1',($_POST['confirm_rates'] ?? null)==='1');
            $_SESSION['shipping_settings_notice']='Checkout provider changed to '.($mode==='direct'?'Direct Shipping.':'Easyship.');
            fas_security_event('shipping_settings','shipping_mode_changed',(int)$admin['id']);
        } else throw new InvalidArgumentException('Choose a valid shipping action.');
        header('Location: shipping-settings.php',true,303);
        exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { http_response_code(503); $error='Private shipping settings could not be saved. Check storage permissions on the server.'; }
}

$fields=[
    'usps'=>['client_id'=>'Client ID','client_secret'=>'Client secret','crid'=>'CRID',
        'mid'=>'MID','manifest_mid'=>'Manifest MID','eps_account_number'=>'EPS account number'],
    'ups'=>['client_id'=>'Client ID','client_secret'=>'Client secret','account_number'=>'Account number'],
];
$easyship=null;
$directBlockers=[];
if ($config) {
    try { $easyship=ShippingSettingsStore::easyshipDisplay(); }
    catch (Throwable $e) { $error='Easyship settings could not be read.'; }
    try { $directBlockers=ShippingSettingsStore::directBlockers($config,Database::getInstance()->getConnection()); }
    catch (Throwable $e) { $directBlockers=['repair the inventory database connection']; }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Shipping Settings - Flip and Strip Admin</title>
<link rel="shortcut icon" href="../gallery/favicons/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-style.css">
<link rel="stylesheet" href="css/shipping-review.css?v=<?= (int)filemtime(__DIR__.'/css/shipping-review.css') ?>">
</head><body class="bg-light">
<?php include __DIR__.'/includes/nav.php'; ?>
<main class="shipping-review container-fluid px-3 px-lg-4 pb-5" style="max-width:1200px">
    <div class="admin-hero d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div><h1 class="mb-1"><i class="fas fa-sliders me-2" style="color:var(--admin-hero-text)" aria-hidden="true"></i>Shipping Settings</h1>
            <p class="mb-0 opacity-75">Checkout provider and carrier accounts</p></div>
        <a class="btn btn-light" href="shipping-readiness.php">View readiness</a>
    </div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= shippingSettingsHtml($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= shippingSettingsHtml($error) ?></div><?php endif; ?>
    <?php if ($config): ?>
        <section class="card border-0 shadow-sm mb-3" aria-labelledby="shipping-provider-title"><div class="card-body p-3 p-sm-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h5 mb-0" id="shipping-provider-title">Checkout provider</h2>
                <span class="badge text-bg-primary"><?= $config['mode']==='easyship'?'Easyship':($config['mode']==='direct'?'Direct Shipping':'Direct with fallback') ?> active</span>
            </div>
            <p class="small text-muted">Choose which provider supplies new checkout rates. Existing orders retain their saved shipping selection.</p>
            <form method="post" autocomplete="off" id="shipping-provider-form">
                <?= CSRF::tokenField() ?><input type="hidden" name="action" value="switch_mode">
                <div class="row g-3">
                    <div class="col-md-6"><label class="d-block border rounded p-3 h-100" for="provider-easyship">
                        <input class="form-check-input me-2" type="radio" id="provider-easyship" name="mode" value="easyship" <?= $config['mode']==='easyship'?'checked':'' ?> required>
                        <strong>Easyship</strong><span class="d-block small text-muted ms-4">Use the existing Easyship checkout rates.</span>
                    </label></div>
                    <div class="col-md-6"><label class="d-block border rounded p-3 h-100" for="provider-direct">
                        <input class="form-check-input me-2" type="radio" id="provider-direct" name="mode" value="direct" <?= $config['mode']==='direct'?'checked':'' ?> required>
                        <strong>Direct Shipping</strong><span class="d-block small text-muted ms-4">Get checkout rates from approved USPS and UPS accounts.</span>
                    </label></div>
                    <?php if ($directBlockers): ?><div class="col-12"><div class="alert alert-warning mb-0" role="note"><strong>Before Direct Shipping can be selected:</strong><ul class="mb-0 mt-2"><?php foreach ($directBlockers as $blocker): ?><li><?= shippingSettingsHtml(ucfirst($blocker)) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_packing" value="1" id="confirm-packing"><label class="form-check-label" for="confirm-packing">I have checked the packed measurements and ship-from origins for products offered at checkout.</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_rates" value="1" id="confirm-rates"><label class="form-check-label" for="confirm-rates">I have tested representative production rates for the approved carrier accounts.</label></div>
                        <p class="form-text mb-0">Both confirmations are required when switching to Direct Shipping.</p></div>
                    <div class="col-sm-7 col-lg-5"><label for="provider-password" class="form-label">Current admin password</label><input id="provider-password" name="password" type="password" class="form-control" autocomplete="current-password" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Save checkout provider</button></div>
                </div>
            </form>
        </div></section>
        <?php if ($easyship): ?><section class="card border-0 shadow-sm mb-3" aria-labelledby="easyship-settings"><div class="card-body p-3 p-sm-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h2 class="h5 mb-0" id="easyship-settings">Easyship settings</h2><span class="badge text-bg-secondary"><?= $easyship['key_present']?'API key saved':'API key needed' ?></span></div>
            <p class="small text-muted">A blank API key keeps the saved key. The key is never displayed after saving.</p>
            <form method="post" autocomplete="off" id="easyship-settings-form"><div class="row g-3">
                <?= CSRF::tokenField() ?><input type="hidden" name="action" value="save_easyship">
                <div class="col-md-6"><label class="form-label" for="easyship-api-key">API key</label><input class="form-control" id="easyship-api-key" name="easyship_api_key" type="password" maxlength="4096" autocomplete="new-password" spellcheck="false" placeholder="<?= $easyship['key_present']?'Saved; leave blank to keep':'Not entered' ?>"></div>
                <div class="col-md-3"><label class="form-label" for="easyship-platform-name">Platform name</label><input class="form-control" id="easyship-platform-name" name="easyship_platform_name" value="<?= shippingSettingsHtml($easyship['platform_name']) ?>" maxlength="100" required></div>
                <div class="col-md-3"><label class="form-label" for="easyship-prefix">Order prefix</label><input class="form-control" id="easyship-prefix" name="easyship_prefix" value="<?= shippingSettingsHtml($easyship['prefix']) ?>" maxlength="20" pattern="[A-Za-z0-9_-]+" required></div>
                <div class="col-sm-7 col-lg-5"><label class="form-label" for="easyship-password">Current admin password</label><input class="form-control" id="easyship-password" name="password" type="password" autocomplete="current-password" required></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Save Easyship settings</button></div>
            </div></form>
        </div></section><?php endif; ?>
        <div class="alert alert-info" role="note">Saving carrier details does not turn on labels, tracking or customer emails. Approve production rates only after testing the account.</div>
        <div class="row g-3">
            <?php foreach (['usps'=>'USPS','ups'=>'UPS'] as $key=>$name):
                $presence=ShippingSettingsStore::credentialPresence($config,$key);
                $count=count(array_filter($presence));
            ?>
            <section class="col-xl-6" aria-labelledby="<?= $key ?>-settings"><div class="card border-0 shadow-sm h-100"><div class="card-body p-3 p-sm-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h2 class="h5 mb-0" id="<?= $key ?>-settings"><?= $name ?></h2>
                    <span class="badge <?= $config['carriers'][$key]['enabled']?'text-bg-success':'text-bg-secondary' ?>"><?= $config['carriers'][$key]['enabled']?'Approved for rates':$count.' of '.count($presence).' details entered' ?></span>
                </div>
                <p class="small text-muted">Enter only the details you need to add or replace. Blank fields keep their saved values. Values are never shown again.</p>
                <form method="post" autocomplete="off" id="<?= $key ?>-credentials">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="carrier" value="<?= $key ?>">
                    <input type="hidden" name="action" value="save">
                    <div class="row g-3">
                        <?php foreach ($fields[$key] as $field=>$label): $id=$key.'-'.$field; ?>
                        <div class="col-sm-6">
                            <label for="<?= $id ?>" class="form-label"><?= shippingSettingsHtml($label) ?></label>
                            <input id="<?= $id ?>" name="<?= $field ?>" type="password" class="form-control" maxlength="4096" autocomplete="new-password" spellcheck="false" placeholder="<?= $presence[$field]?'Saved; leave blank to keep':'Not entered' ?>">
                        </div>
                        <?php endforeach; ?>
                        <?php if ($key==='usps'): ?>
                        <div class="col-sm-6"><label for="usps-gateway" class="form-label">USPS API gateway</label>
                            <select id="usps-gateway" name="gateway" class="form-select">
                                <option value="apis" <?= $config['carriers']['usps']['gateway']==='apis'?'selected':'' ?>>apis.usps.com</option>
                                <option value="api" <?= $config['carriers']['usps']['gateway']==='api'?'selected':'' ?>>api.usps.com</option>
                            </select></div>
                        <div class="col-sm-6"><label for="usps-price" class="form-label">USPS price type</label>
                            <select id="usps-price" name="price_type" class="form-select">
                                <option value="RETAIL" <?= $config['carriers']['usps']['price_type']==='RETAIL'?'selected':'' ?>>Retail</option>
                                <option value="COMMERCIAL" <?= $config['carriers']['usps']['price_type']==='COMMERCIAL'?'selected':'' ?>>Commercial</option>
                            </select></div>
                        <?php endif; ?>
                        <div class="col-12"><label for="<?= $key ?>-password" class="form-label">Current admin password</label>
                            <input id="<?= $key ?>-password" name="password" type="password" class="form-control" autocomplete="current-password" required></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">Save <?= $name ?> details</button></div>
                    </div>
                </form>
                <?php if ($count && !$config['carriers'][$key]['enabled']): ?>
                <hr class="my-4"><h3 class="h6">Approve production rates</h3>
                <p class="small text-muted">Do this only after the account has returned correct rates for representative destinations and parcels. This approval allows rates only when Direct Shipping is selected.</p>
                <form method="post" autocomplete="off" id="<?= $key ?>-approve">
                    <?= CSRF::tokenField() ?><input type="hidden" name="carrier" value="<?= $key ?>"><input type="hidden" name="action" value="approve_carrier">
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm_account" value="1" id="<?= $key ?>-confirm-account" required><label class="form-check-label" for="<?= $key ?>-confirm-account">I tested this <?= $name ?> production account and rates.</label></div>
                    <label for="<?= $key ?>-approve-password" class="form-label">Current admin password</label><input id="<?= $key ?>-approve-password" name="password" type="password" class="form-control mb-3" autocomplete="current-password" required>
                    <button class="btn btn-outline-primary" type="submit">Approve <?= $name ?> rates</button>
                </form>
                <?php endif; ?>
                <?php if ($count): ?>
                <hr class="my-4">
                <form method="post" autocomplete="off" id="<?= $key ?>-clear">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="carrier" value="<?= $key ?>">
                    <input type="hidden" name="action" value="clear">
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm_clear" value="1" id="<?= $key ?>-confirm" required>
                        <label class="form-check-label" for="<?= $key ?>-confirm">Remove all saved <?= $name ?> account details</label></div>
                    <label for="<?= $key ?>-clear-password" class="form-label">Current admin password</label>
                    <input id="<?= $key ?>-clear-password" name="password" type="password" class="form-control mb-3" autocomplete="current-password" required>
                    <button class="btn btn-outline-danger" type="submit">Remove <?= $name ?> details</button>
                </form>
                <?php endif; ?>
            </div></div></section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>
</body></html>
