"""Actual storefront PHP and Chromium against disposable synthetic inventory.

Run in a loopback-only namespace, as configured by keyboard-browser.yml.
No runtime configuration, uploads, customer data or provider clients are used.
"""
from pathlib import Path
import argparse, hashlib, json, os, re, shutil, signal, socket, sqlite3, subprocess, tempfile, time

ROOT = Path(__file__).resolve().parents[2]


def process_table() -> dict[int, tuple[int, str, str]]:
    """PID -> (parent PID, start identity, state); never read cmdline/env secrets."""
    result = {}
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        try:
            fields = (entry / 'stat').read_text().rsplit(') ', 1)[1].split()
            result[int(entry.name)] = (int(fields[1]), fields[19], fields[0])
        except (OSError, ValueError, IndexError):
            continue
    return result


def track_children(roots: list[int], tracked: dict[int, str]) -> None:
    table = process_table()
    descendants = {pid for pid in roots if pid in table and (pid not in tracked or tracked[pid] == table[pid][1])} | {pid for pid, identity in tracked.items()
                                if pid in table and table[pid][1] == identity}
    changed = True
    while changed:
        new = {pid for pid, (parent, _, _) in table.items() if parent in descendants}
        changed = bool(new - descendants)
        descendants |= new
    for pid in descendants:
        if pid in table:
            tracked[pid] = table[pid][1]


def tracked_running(tracked: dict[int, str]) -> list[int]:
    table = process_table()
    return [pid for pid, identity in tracked.items()
            if pid in table and table[pid][1] == identity and table[pid][2] != 'Z']


def stop_tracked(tracked: dict[int, str]) -> bool:
    for sig in (signal.SIGTERM, signal.SIGKILL):
        for pid in tracked_running(tracked):
            try:
                os.kill(pid, sig)
            except ProcessLookupError:
                pass
        deadline = time.monotonic() + 5
        while tracked_running(tracked) and time.monotonic() < deadline:
            time.sleep(.1)
    return not tracked_running(tracked)



def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--assets', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--variant', choices=['baseline','candidate'], required=True)
    args = parser.parse_args()
    from guard import verify_candidate, assert_isolation, verify_assets
    isolation = assert_isolation()
    checkout_identity = verify_candidate()
    verify_assets(args.assets.resolve(strict=True))
    output = args.output.resolve()
    if output.is_relative_to(ROOT): raise RuntimeError('Evidence must be outside checkout')
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()): raise RuntimeError('Evidence directory must be empty')
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
        if args.variant == 'baseline':
            from guard import BASELINE, EXPECTED_RUNTIME
            for rel in EXPECTED_RUNTIME:
                (site/rel).write_bytes(subprocess.check_output(['git','show',BASELINE+':'+rel],cwd=ROOT))
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
        from fixture_seed import seed_seller
        seed_seller(site)
        with sqlite3.connect(site/'database/flipandstrip.db') as db:
            db.executescript("CREATE TABLE banners(id INTEGER PRIMARY KEY,message TEXT,bg_color TEXT,text_color TEXT,link_url TEXT,link_text TEXT,is_dismissible INTEGER,is_active INTEGER,sort_order INTEGER,show_countdown INTEGER,countdown_end TEXT,starts_at TEXT,ends_at TEXT); INSERT INTO banners VALUES(1,'Synthetic zoom acceptance message with wrapped lines and a real dismiss control. Synthetic zoom acceptance message with wrapped lines and a real dismiss control. Synthetic zoom acceptance message with wrapped lines and a real dismiss control. Synthetic zoom acceptance message with wrapped lines and a real dismiss control. Synthetic zoom acceptance message with wrapped lines and a real dismiss control. Synthetic zoom acceptance message with wrapped lines and a real dismiss control. Synthetic zoom acceptance message with wrapped lines and a real dismiss control.','danger','white',NULL,NULL,1,1,1,0,NULL,NULL,NULL);")
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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); return true; }
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($p==='/api/products.php' && ($_SERVER['QUERY_STRING'] ?? '')==='include_sidebar=1') { require $_SERVER['DOCUMENT_ROOT'].'/api/products.php'; return true; }
if ($p==='/') {require $_SERVER['DOCUMENT_ROOT'].'/index.php'; return true;}
if ($p==='/cart') {require $_SERVER['DOCUMENT_ROOT'].'/cart.php'; return true;}
if ($p==='/recover-cart.php') { if ($_SERVER['REQUEST_METHOD']!=='GET' || ($_SERVER['QUERY_STRING'] ?? '')!=='') { http_response_code(404); echo 'Fixture route not found'; return true; } require $_SERVER['DOCUMENT_ROOT'].'/recover-cart.php'; return true; }
if ($p==='/products') {require $_SERVER['DOCUMENT_ROOT'].'/products.php'; return true;}
if (preg_match('~^/product/([0-9]+)(?:/[^/]+)?$~',$p,$m)) {
 $_GET['id']=$m[1]; require $_SERVER['DOCUMENT_ROOT'].'/product.php'; return true;
}
if (preg_match('~^/(public|gallery)/[a-zA-Z0-9_./-]+$~D',$p) && !str_contains($p,'..') && !preg_match('~\\.php$~i',$p) && is_file($_SERVER['DOCUMENT_ROOT'].$p)) return false;
http_response_code(404); return true;
require $_SERVER['DOCUMENT_ROOT'].'/404.php';
''')
        env = {'PATH': os.environ['PATH'], 'ANALYTICS_IP_GEO_ENABLED': '0',
               'FAS_SECURITY_DB_PATH': str(base / 'security.sqlite'),
               'FAS_GROWTH_DB_PATH': str(base / 'growth.sqlite')}
        env.update({k:os.environ[k] for k in ['DISPLAY','XAUTHORITY','HOME','NODE_PATH','PLAYWRIGHT_BROWSERS_PATH'] if k in os.environ})
        (output / 'fixture.json').write_text(json.dumps({
            'zoom_checkout_identity': checkout_identity, 'variant': args.variant, 'isolation': isolation,
            'candidate_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'scope': 'actual PHP with synthetic SQLite; simulated clean URL routing',
            'production_verified': False, 'source_sha256': sources,
            'fixture_changes': ['generated config and inventory photos', 'empty eBay category client', 'synthetic inventory/seller/banner rows'],
        }, indent=2) + '\n')
        (base / 'sessions').mkdir()
        with (output / 'php.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'session.save_path='+str(base/'sessions'), '-d', 'allow_url_fopen=0', '-d', 'display_errors=1',
                '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client',
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
                worker = subprocess.Popen(['node', str(ROOT/'tests/zoom-browser/check.cjs'),
                    origin, str(args.assets.resolve()), str(output), args.variant], start_new_session=True)
                tracked = {}
                deadline = time.monotonic()+600
                try:
                    while worker.poll() is None:
                        track_children([worker.pid], tracked)
                        if time.monotonic()>deadline: raise RuntimeError('Browser deadline exceeded')
                        time.sleep(.1)
                    if worker.returncode: raise RuntimeError('Browser acceptance failed; inspect failure.json')
                finally:
                    track_children([worker.pid], tracked)
                    stopped = stop_tracked(tracked)
                    worker.wait(timeout=10)
                    (output/'browser-cleanup.json').write_text(json.dumps({'tracked_descendants_stopped':stopped})+'\n')
                    if not stopped: raise RuntimeError('Browser descendant cleanup failed')
                excluded = {'src/integrations/EbayAPI.php'}
                for rel, digest in sources.items():
                    if rel not in excluded and hashlib.sha256((site/rel).read_bytes()).hexdigest()!=digest:
                        raise RuntimeError('Runtime source mutated: '+rel)
            finally:
                server.terminate()
                try: server.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    server.kill(); server.wait(timeout=5)
                receipt = base / 'php-runtime.json'
                if not receipt.is_file():
                    raise RuntimeError('Missing same-server opcode-cache evidence')
                shutil.copy2(receipt, output / 'php-runtime.json')
                shutil.copy2(base/'php-runtime.jsonl', output/'php-runtime.jsonl')
                receipts = [json.loads(line) for line in (base/'php-runtime.jsonl').read_text().splitlines()]
                if not receipts or any(not r['passed'] or r['pid'] != server.pid for r in receipts):
                    raise RuntimeError('Per-request same-process cache evidence failed')
                if re.search(r'PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught |SQLSTATE\[', (output/'php.log').read_text()):
                    raise RuntimeError('PHP fixture diagnostics failed')
                if not json.loads(receipt.read_text()).get('passed'):
                    raise RuntimeError('Same-server opcode-cache verification failed')


if __name__ == '__main__':
    main()
