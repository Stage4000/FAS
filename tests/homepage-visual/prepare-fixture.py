"""Render the exact PHP homepage against fresh synthetic SQLite data, offline.

Only source code, approved public branding assets, and generated inventory are used.
No runtime configuration, customer database, API endpoint or production directory is copied.
"""
from pathlib import Path
import argparse, hashlib, json, os, re, shutil, sqlite3, subprocess, tempfile

ROOT = Path(__file__).resolve().parents[2]
BASELINE = '28018e345f5b35c731536b65dd88f015ca75a626'
APPROVED_LOGO = '209532a773f807801d63dcc1036b7bc8272b3c85fa0c0d6952cc61340b9cbb36'
BASELINE_FILES = ['index.php', 'includes/header.php', 'src/utils/Seo.php', 'gallery/hero-image.png']
GALLERY = ['gallery/hero-image.png', 'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg',
           'gallery/aaron-huber-KxeFuXta4SE-unsplash-ts1669126250.jpg',
           'gallery/favicons/favicon.png']


def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT)


def materialize(cache):
    """Resolve promisor blobs before the workflow removes all external networking."""
    assert not cache.is_relative_to(ROOT), 'Source cache must be outside the repository'
    cache.mkdir(parents=True)
    current = cache / 'current'
    current.mkdir()
    source_paths = git('ls-files', '-z', 'src', 'includes', 'public').decode().split('\0')
    source_paths.append('index.php')
    for relative in source_paths:
        if not relative:
            continue
        path = Path(relative)
        if path.suffix not in {'.php', '.css', '.js'}:
            continue
        if 'uploads' in path.parts or path.name.startswith('config'):
            continue
        source = ROOT / path
        assert not source.is_symlink(), 'Source symlinks are not allowed'
        target = current / path
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
    for relative in GALLERY:
        target = current / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(git('show', 'HEAD:' + relative))
    approved = (current / 'gallery/hero-image.png').read_bytes()
    assert hashlib.sha256(approved).hexdigest() == APPROVED_LOGO, 'Unexpected hero image'
    assert approved == (ROOT / 'gallery/hero-image.png').read_bytes(), 'Uncommitted hero image differs'
    for relative in BASELINE_FILES:
        target = cache / 'baseline' / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(git('show', BASELINE + ':' + relative))
    shutil.copy2(ROOT / 'tests/fixtures/security-catalog.sql', cache / 'security-catalog.sql')
    manifest = {
        'baseline_commit': BASELINE,
        'candidate_commit': git('rev-parse', 'HEAD').decode().strip(),
        'files': {str(path.relative_to(cache)): hashlib.sha256(path.read_bytes()).hexdigest()
                  for path in sorted(cache.rglob('*')) if path.is_file()},
    }
    (cache / 'source-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(f"Materialized {len(manifest['files'])} source/assets files before network isolation.")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source-cache', type=Path, required=True)
    parser.add_argument('--materialize-only', action='store_true')
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    cache = args.source_cache.resolve()
    if args.materialize_only:
        assert args.output is None, '--output is only used for offline rendering'
        materialize(cache)
        return
    assert args.output is not None, '--output is required for offline rendering'
    output = args.output.resolve()
    assert not output.is_relative_to(ROOT), 'Fixture must be outside the repository'
    output.mkdir(parents=True, exist_ok=True)
    manifest = json.loads((cache / 'source-manifest.json').read_text())
    assert manifest['baseline_commit'] == BASELINE, 'Unexpected baseline'
    for relative, expected_hash in manifest['files'].items():
        source = cache / relative
        assert source.resolve().is_relative_to(cache), 'Invalid cached source path'
        assert not source.is_symlink(), 'Cached symlinks are not allowed'
        assert hashlib.sha256(source.read_bytes()).hexdigest() == expected_hash, relative
    approved = (cache / 'current/gallery/hero-image.png').read_bytes()
    assert hashlib.sha256(approved).hexdigest() == APPROVED_LOGO, 'Unexpected cached hero image'
    report = {'baseline_commit': BASELINE, 'candidate_commit': manifest['candidate_commit'],
              'approved_logo_sha256': APPROVED_LOGO, 'scope': 'offline synthetic PHP fixture only',
              'production_verified': False, 'versions': {}}
    with tempfile.TemporaryDirectory(prefix='fas-homepage-php-') as temporary:
        private = Path(temporary)
        for version in ['before', 'after']:
            site = private / version
            shutil.copytree(cache / 'current', site)
            if version == 'before':
                for relative in BASELINE_FILES:
                    shutil.copy2(cache / 'baseline' / relative, site / relative)
            index = (site / 'index.php').read_bytes()
            (site / 'database').mkdir()
            (site / 'src/config/config.php').write_text('''<?php
return [
 'database' => ['path' => __DIR__ . '/../../database/flipandstrip.db'],
 'site' => ['url' => 'http://127.0.0.1:43187', 'timezone' => 'UTC'],
 'ebay' => ['store_name' => ''],
 'google_analytics' => ['enabled' => false],
 'tawk' => ['enabled' => false],
 'turnstile' => ['enabled' => false],
];
''')
            with sqlite3.connect(site / 'database/flipandstrip.db') as db:
                db.executescript((cache / 'security-catalog.sql').read_text())
                db.execute('DELETE FROM products')
                for i in range(1, 25):
                    db.execute('''INSERT INTO products(id,name,description,sku,price,category,manufacturer,model,
                        condition_name,quantity,is_active,show_on_website,free_shipping,image_url,images,source,
                        created_at,updated_at) VALUES(?,?,?,?,100,'motorcycle','Honda','QA','Used',1,1,1,0,?,'[]',
                        'manual','2026-01-01','2026-01-01')''',
                        [i, 'Synthetic QA part ' + str(i), 'Generated visual-test inventory only',
                         'QA-' + str(i), '/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg'])
            bootstrap = "$_SERVER['REQUEST_URI']='/'; $_SERVER['HTTP_HOST']='127.0.0.1:43187'; require $argv[1];"
            command = ['php', '-d', 'allow_url_fopen=0', '-d', 'display_errors=1',
                       '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open',
                       '-r', bootstrap, str(site / 'index.php')]
            run = subprocess.run(command, cwd=site, env={'PATH': os.environ['PATH'], 'ANALYTICS_IP_GEO_ENABLED': '0'},
                                 capture_output=True, timeout=60)
            assert run.returncode == 0, run.stderr.decode()
            html = run.stdout.decode()
            assert '<!DOCTYPE html>' in html and '</html>' in html, 'Incomplete PHP render'
            assert not re.search(r'(?:Fatal error|Warning|Notice|Deprecated):', html), 'PHP diagnostics in rendered page'
            assert 'googletagmanager.com/gtag' not in html and 'embed.tawk.to/' not in html
            destination = output / version
            destination.mkdir()
            (destination / 'index.html').write_text(html)
            shutil.copytree(site / 'public', destination / 'public')
            shutil.copytree(site / 'gallery', destination / 'gallery')
            report['versions'][version] = {
                'index_sha256': hashlib.sha256(index).hexdigest(),
                'css_sha256': hashlib.sha256((site / 'public/css/style.css').read_bytes()).hexdigest(),
                'header_sha256': hashlib.sha256((site / 'includes/header.php').read_bytes()).hexdigest(),
                'seo_sha256': hashlib.sha256((site / 'src/utils/Seo.php').read_bytes()).hexdigest(),
                'php_render_warnings': run.stderr.decode().splitlines(),
            }
    (output / 'fixture.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
