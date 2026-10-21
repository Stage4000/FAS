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
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='fas-keyboard-') as temporary:
        base = Path(temporary)
        site = base / 'site'
        site.mkdir()
        for folder in ['src', 'includes', 'public']:
            shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns(
                'config*.php', 'uploads', '*.log', '*.db', '*.sqlite'))
        for name in ['index.php', 'products.php', 'product.php', '404.php']:
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
 'ebay' => ['store_name' => ''],
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
        # Router only models clean URLs for the PHP development server.
        router = base / 'router.php'
        router.write_text('''<?php
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($p==='/') {require $_SERVER['DOCUMENT_ROOT'].'/index.php'; return true;}
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
            'candidate_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'scope': 'actual PHP with synthetic SQLite; simulated clean URL routing',
            'production_verified': False, 'source_sha256': sources,
            'fixture_changes': ['generated config and inventory photos', 'empty eBay category client'],
        }, indent=2) + '\n')
        with (output / 'php.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'allow_url_fopen=0', '-d', 'display_errors=1',
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
                subprocess.run(['node', str(ROOT / 'tests/keyboard-browser/check.cjs'), origin,
                                str(args.assets.resolve()), str(output)], check=True)
            finally:
                server.terminate()
                server.wait(timeout=10)


if __name__ == '__main__':
    main()
