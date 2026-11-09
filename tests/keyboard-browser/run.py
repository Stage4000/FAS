"""Actual storefront PHP and Chromium against disposable synthetic inventory.

Run in a loopback-only namespace, as configured by keyboard-browser.yml.
No runtime configuration, uploads, customer data or provider clients are used.
"""
from pathlib import Path
import argparse, hashlib, json, os, shutil, socket, sqlite3, subprocess, tempfile, time

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--assets', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    from heading_runner import verify_checkout
    checkout_identity = verify_checkout(ROOT)
    subprocess.run(['python3', str(ROOT/'tests/keyboard-browser/catalog-scroll-guard-test.py')], check=True)
    subprocess.run(['node', str(ROOT/'tests/keyboard-browser/catalog-scroll-policy-test.cjs')], check=True)
    subprocess.run(['node', str(ROOT/'tests/catalog-scroll-offset-test.cjs')], check=True)
    subprocess.run(['python3', str(ROOT/'tests/keyboard-browser/email-restore-guard-test.py')], check=True)
    subprocess.run(['node', str(ROOT/'tests/keyboard-browser/email-restore-policy-test.cjs')], check=True)
    subprocess.run(['node', str(ROOT/'tests/email-restore-preservation-test.cjs')], check=True)
    subprocess.run(['node', str(ROOT/'tests/email-action-lifecycle-test.cjs')], check=True)
    subprocess.run(['node', str(ROOT/'tests/cart-malformed-hydration-test.cjs')], check=True)
    subprocess.run(['python3', str(ROOT/'tests/keyboard-browser/malformed-identity-test.py')], check=True)
    subprocess.run(['node', str(ROOT/'tests/keyboard-browser/malformed-identity-test.cjs')], check=True)
    subprocess.run(['python3', str(ROOT/'tests/keyboard-browser/storage-identity-test.py')], check=True)
    subprocess.run(['node', str(ROOT/'tests/keyboard-browser/storage-quantity-identity-test.cjs')], check=True)
    subprocess.run(['python3', str(ROOT/'tests/keyboard-browser/heading-unit-test.py')], check=True)
    subprocess.run(['node', str(ROOT/'tests/keyboard-browser/heading-policy-test.cjs')], check=True)
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='fas-keyboard-') as temporary:
        base = Path(temporary)
        site = base / 'site'
        site.mkdir()
        for folder in ['src', 'includes', 'public']:
            shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns(
                'config*.php', 'uploads', '*.log', '*.db', '*.sqlite'))
        for name in ['index.php', 'products.php', 'product.php', '404.php', 'cart.php', 'recover-cart.php']:
            shutil.copy2(ROOT / name, site / name)
        (site / 'api').mkdir()
        shutil.copy2(ROOT / 'api/products.php', site / 'api/products.php')
        sources = {str(p.relative_to(site)): hashlib.sha256(p.read_bytes()).hexdigest()
                   for p in sorted(site.rglob('*')) if p.is_file()}
        (site / 'database').mkdir()
        (site / 'gallery/favicons').mkdir(parents=True)
        # Approved branding is checked out explicitly; inventory photos are generated.
        shutil.copy2(ROOT / 'gallery/hero-image.png', site / 'gallery/hero-image.png')
        subprocess.run(['php', '-r',
            '$im=imagecreatetruecolor(1280,960); imagefill($im,0,0,imagecolorallocate($im,60,90,120)); imagejpeg($im,$argv[1],90); imagedestroy($im);',
            str(site / 'gallery/default.jpg')], check=True)
        for name in ['FLIPANDSTRIP.COM_d00a_018a.jpg', 'aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg']:
            shutil.copy2(site / 'gallery/default.jpg', site / 'gallery' / name)
        shutil.copy2(ROOT / 'gallery/favicons/favicon.png', site / 'gallery/favicons/favicon.png')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        origin = f'http://127.0.0.1:{port}'
        (site / 'src/config/config.php').write_text('''<?php
return [
 'database' => ['path' => __DIR__ . '/../../database/flipandstrip.db'],
 'site' => ['url' => '%s', 'timezone' => 'UTC'],
 'ebay' => ['store_name' => 'synthetic-heading-store'],
 'google_analytics' => ['enabled' => false],
 'tawk' => ['enabled' => false],
 'turnstile' => ['enabled' => false],
];
''' % origin)
        (site / 'src/integrations/EbayAPI.php').write_text(r'''<?php namespace FAS\Integrations;
class EbayAPI {
 public function __construct($config) {}
 public function getStoreCategoriesHierarchical(){return [];}
 public function getStoreCategories(){return [];}
}
''')
        with sqlite3.connect(site / 'database/flipandstrip.db') as db:
            db.executescript((ROOT / 'tests/fixtures/security-catalog.sql').read_text())
        from heading_runner import seed_seller
        seed_seller(site)
        # Router only models clean URLs for the PHP development server.
        router = base / 'router.php'
        router.write_text('''<?php
// This check executes in the actual fixture server, not a separate CLI process.
$_fasSapi = PHP_SAPI;
$_fasCacheLoaded = extension_loaded('Zend OPcache');
$_fasCacheSetting = ini_get('opcache.enable');
$_fasCacheApi = function_exists('opcache_get_status');
$_fasCacheStatus = $_fasCacheApi ? opcache_get_status(false) : false;
// BEGIN pure fixture opcode-cache decision
$_fasCacheDisabled = !$_fasCacheLoaded ||
    ($_fasCacheSetting === '0' && $_fasCacheApi && $_fasCacheStatus === false);
$_fasCacheReceipt = [
    'sapi' => $_fasSapi,
    'pid' => getmypid(),
    'opcache_extension_loaded' => $_fasCacheLoaded,
    'opcache_enable' => $_fasCacheSetting === false ? null : $_fasCacheSetting,
    'opcache_status_available' => $_fasCacheApi,
    'opcache_status_disabled' => $_fasCacheStatus === false,
    'passed' => $_fasSapi === 'cli-server' && $_fasCacheDisabled,
];
// END pure fixture opcode-cache decision
$_fasCacheWritten = file_put_contents(__DIR__.'/php-runtime.json', json_encode($_fasCacheReceipt)."\\n", LOCK_EX);
$_fasCacheHistory = file_put_contents(__DIR__.'/php-runtime.jsonl', json_encode($_fasCacheReceipt)."\\n", FILE_APPEND | LOCK_EX);
if (!$_fasCacheReceipt['passed'] || $_fasCacheWritten === false || $_fasCacheHistory === false) {
    http_response_code(500); echo 'Fixture opcode-cache verification failed'; return true;
}
unset($_fasSapi, $_fasCacheLoaded, $_fasCacheSetting, $_fasCacheApi, $_fasCacheStatus,
      $_fasCacheDisabled, $_fasCacheReceipt, $_fasCacheWritten);
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($p==='/') {require $_SERVER['DOCUMENT_ROOT'].'/index.php'; return true;}
if ($p==='/cart') {require $_SERVER['DOCUMENT_ROOT'].'/cart.php'; return true;}
if ($p==='/recover-cart.php') { if ($_SERVER['REQUEST_METHOD']!=='GET' || ($_SERVER['QUERY_STRING'] ?? '')!=='') { http_response_code(404); echo 'Fixture route not found'; return true; } require $_SERVER['DOCUMENT_ROOT'].'/recover-cart.php'; return true; }
if ($p==='/products') {require $_SERVER['DOCUMENT_ROOT'].'/products.php'; return true;}
if (preg_match('~^/product/([0-9]+)(?:/[^/]+)?$~',$p,$m)) {
 $_GET['id']=$m[1]; require $_SERVER['DOCUMENT_ROOT'].'/product.php'; return true;
}
if (is_file($_SERVER['DOCUMENT_ROOT'].$p)) return false;
require $_SERVER['DOCUMENT_ROOT'].'/404.php';
''')
        env = {'PATH': os.environ['PATH'], 'ANALYTICS_IP_GEO_ENABLED': '0',
               'FAS_SECURITY_DB_PATH': str(base / 'security.sqlite'),
               'FAS_GROWTH_DB_PATH': str(base / 'growth.sqlite')}
        (output / 'fixture.json').write_text(json.dumps({
            'heading_checkout_identity': checkout_identity,
            'candidate_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'scope': 'actual PHP with synthetic SQLite; simulated clean URL routing',
            'production_verified': False, 'source_sha256': sources,
            'fixture_changes': ['generated config and inventory photos', 'empty eBay category client'],
        }, indent=2) + '\n')
        (base / 'sessions').mkdir()
        with (output / 'php.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'session.save_path='+str(base/'sessions'), '-d', 'allow_url_fopen=0', '-d', 'display_errors=1',
                '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open',
                '-S', f'127.0.0.1:{port}', '-t', str(site), str(router)], env=env, stdout=log, stderr=log)
            try:
                for attempt in range(100):
                    if server.poll() is not None:
                        raise RuntimeError('PHP fixture server exited; inspect php.log')
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.2):
                            break
                    except OSError:
                        time.sleep(.1)
                else:
                    raise RuntimeError('PHP fixture did not start')
                # Keep the original 20-view suite intact; collect both reports even
                # if one fails, and fail the job if either acceptance suite fails.
                results = [subprocess.run(['node', str(ROOT / 'tests/keyboard-browser' / script),
                            origin, str(args.assets.resolve()), str(output)]).returncode
                           for script in ['navbar-unit-test.cjs', 'quantity-rendering-unit-test.cjs', 'check.cjs', 'navbar.cjs', 'quantity.cjs']]
                # This suite alone needs stock for native recommendation-add/quantity edits.
                # Save and restore the existing disposable synthetic row exactly; all
                # original suites above and historical heading variants below are unchanged.
                with sqlite3.connect(site/'database/flipandstrip.db') as db:
                    original_quantity = db.execute('SELECT quantity FROM products WHERE id=1').fetchone()
                    if original_quantity is None:
                        raise RuntimeError('Missing synthetic id1 inventory for storage suite')
                    if db.execute('UPDATE products SET quantity=5 WHERE id=1').rowcount != 1:
                        raise RuntimeError('Unexpected synthetic storage inventory update')
                try:
                    results.append(subprocess.run(['node', str(ROOT/'tests/keyboard-browser/storage-unavailable.cjs'),
                        origin, str(args.assets.resolve()), str(output)]).returncode)
                finally:
                    with sqlite3.connect(site/'database/flipandstrip.db') as db:
                        if db.execute('UPDATE products SET quantity=? WHERE id=1', original_quantity).rowcount != 1:
                            raise RuntimeError('Synthetic storage inventory restoration failed')
                with sqlite3.connect(site/'database/flipandstrip.db') as db:
                    db.execute('DELETE FROM ebay_seller_rating_cache')
                # Malformed suite alone uses stock5; preserve prior suite and heading seeds.
                with sqlite3.connect(site/'database/flipandstrip.db') as db:
                    malformed_original_quantity = db.execute('SELECT quantity FROM products WHERE id=1').fetchone()
                    if malformed_original_quantity is None:
                        raise RuntimeError('Missing synthetic id1 inventory for malformed suite')
                    if db.execute('UPDATE products SET quantity=5 WHERE id=1').rowcount != 1:
                        raise RuntimeError('Unexpected synthetic malformed inventory update')
                try:
                    results.append(subprocess.run(['node', str(ROOT/'tests/keyboard-browser/malformed-hydration.cjs'),
                        origin, str(args.assets.resolve()), str(output)]).returncode)
                finally:
                    with sqlite3.connect(site/'database/flipandstrip.db') as db:
                        if db.execute('UPDATE products SET quantity=? WHERE id=1', malformed_original_quantity).rowcount != 1:
                            raise RuntimeError('Synthetic malformed inventory restoration failed')

                from heading_runner import run_heading_variants
                results.append(run_heading_variants(ROOT, site, origin, args.assets.resolve(), output))
                results.append(subprocess.run(['node', str(ROOT/'tests/keyboard-browser/email-restore.cjs'),
                    origin, str(args.assets.resolve()), str(output)]).returncode)
                results.append(subprocess.run(['node', str(ROOT/'tests/keyboard-browser/catalog-scroll.cjs'),
                    origin, str(args.assets.resolve()), str(output)]).returncode)
                if any(results):
                    raise RuntimeError(f'Keyboard acceptance failed: {results}; inspect both reports')
            finally:
                server.terminate()
                server.wait(timeout=10)
                receipt = base / 'php-runtime.json'
                if not receipt.is_file():
                    raise RuntimeError('Missing same-server opcode-cache evidence')
                shutil.copy2(receipt, output / 'php-runtime.json')
                shutil.copy2(base/'php-runtime.jsonl', output/'php-runtime.jsonl')
                receipts = [json.loads(line) for line in (base/'php-runtime.jsonl').read_text().splitlines()]
                if not receipts or any(not r['passed'] or r['pid'] != server.pid for r in receipts):
                    raise RuntimeError('Per-request same-process cache evidence failed')
                if not json.loads(receipt.read_text()).get('passed'):
                    raise RuntimeError('Same-server opcode-cache verification failed')


if __name__ == '__main__':
    main()
