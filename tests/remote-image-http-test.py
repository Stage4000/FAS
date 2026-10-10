"""Offline synthetic import and real PHP renderers. No remote image or account access."""
from pathlib import Path
import hashlib, html, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
from datetime import datetime, timedelta, timezone
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
base = Path(tempfile.mkdtemp(prefix='fas-remote-http-'))
site = base / 'site'
site.mkdir()
server = None
checks = []
def check(ok, name):
    if not ok: raise AssertionError(name)
    checks.append(name)
try:
    for folder in ['src', 'includes', 'public', 'api', 'scripts']:
        shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns('config.php', 'config.local.php', 'config.production.php', 'uploads', '*.log', '*.db', '*.sqlite', '__pycache__'))
    for name in ['product.php', 'products.php', 'google-merchant-feed.php']:
        shutil.copy2(ROOT / name, site / name)
    shutil.copy2(ROOT / 'src/config/config.example.php', site / 'src/config/config.php')
    (site / 'src/integrations/EbayAPI.php').write_text('<?php namespace FAS\\Integrations; class EbayAPI { public function __construct($config) {} public function getStoreCategoriesHierarchical(){return [];} public function getStoreCategories(){return [];} }')
    (site / 'database').mkdir()
    (site / 'gallery').mkdir()
    (base / 'inputs').mkdir()
    image = base / 'inputs/original.png'
    subprocess.run(['php', '-r', '$i=imagecreatetruecolor(1600,1200);for($x=0;$x<1600;$x++)imageline($i,$x,0,$x,1199,imagecolorallocate($i,$x%256,($x*7)%256,($x*17)%256));imagepng($i,$argv[1],0);', str(image)], check=True)
    url = 'https://i.ebayimg.com/images/g/synthetic,a/s-l1600.png?set_id=fixture&photo=1'
    url2 = 'https://i.ebayimg.com/images/g/synthetic,a/s-l1600.png?set_id=fixture&photo=2'
    now = datetime.now(timezone.utc).replace(microsecond=0)
    record = {'product_id': '1', 'source_url': url, 'local_file': 'original.png', 'source_sha256': hashlib.sha256(image.read_bytes()).hexdigest(), 'verified_at': now.isoformat(), 'valid_until': (now + timedelta(days=1)).isoformat(), 'provenance': {'basis': 'existing_catalog', 'reference': 'synthetic-QA-only', 'optimization_authorized': True, 'excluded': False}}
    records = [record, dict(record, source_url=url2), dict(record, product_id='2')]
    manifest = base / 'inputs/manifest.json'
    manifest.write_text(json.dumps({'version': 1, 'images': records}))
    with sqlite3.connect(site / 'database/flipandstrip.db') as db:
        db.executescript((ROOT / 'tests/fixtures/security-catalog.sql').read_text())
        db.execute('UPDATE products SET image_url=?,images=?', [url, json.dumps([url, url2])])
        row=dict(zip([r[1] for r in db.execute('PRAGMA table_info(products)')], db.execute('SELECT * FROM products').fetchone()))
        row.update(id=2, name='Synthetic sold out part', sku='TEST-2', quantity=0)
        db.execute('INSERT INTO products(%s) VALUES(%s)' % (','.join(row), ','.join('?'*len(row))), list(row.values()))
    env = os.environ.copy()
    env['FAS_SECURITY_DB_PATH'] = str(base / 'private/security.sqlite')
    env['FAS_GROWTH_DB_PATH'] = str(base / 'private/growth.sqlite')
    env.pop('FAS_TRUSTED_PROXY_CIDRS', None)
    build = subprocess.run(['php', str(site / 'scripts/build-responsive-images.php'), '--remote-manifest='+str(manifest)], env=env, text=True, capture_output=True)
    check(build.returncode == 0 and '"status":"ready"' in build.stdout, 'CLI imports approved offline manifest')
    rows = [json.loads(line) for line in build.stdout.splitlines()]
    check(len(rows) == 3, 'Every requested product/photo receives a receipt')
    status = subprocess.run(['php', str(site / 'scripts/build-responsive-images.php'), '--remote-status'], env=env, text=True, capture_output=True)
    check(status.returncode == 2 and len(status.stdout.splitlines()) == 3 and all(json.loads(line)['status']=='expiring' for line in status.stdout.splitlines()), 'Status command warns seven days before explicit verification expiry')
    pending = dict(record, product_id='3', provenance=dict(record['provenance'], excluded=True))
    manifest.write_text(json.dumps({'version': 1, 'images': [pending]}))
    skipped = subprocess.run(['php', str(site / 'scripts/build-responsive-images.php'), '--remote-manifest='+str(manifest)], env=env, text=True, capture_output=True)
    check(skipped.returncode == 0 and '"status":"excluded"' in skipped.stdout, 'Explicit exclusions are recorded successfully and separately from errors')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    router = base / 'router.php'
    router.write_text("<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(preg_match('~^/product/([0-9]+)(?:/[^/]+)?$~',$p,$m)){$_GET['id']=$m[1];require $_SERVER['DOCUMENT_ROOT'].'/product.php';return true;}if($p==='/products'){require $_SERVER['DOCUMENT_ROOT'].'/products.php';return true;}return false;")
    with (base / 'server.log').open('w') as log:
        server = subprocess.Popen(['php', '-d', 'disable_functions=mail', '-S', '127.0.0.1:'+str(port), '-t', str(site), str(router)], env=env, stdout=log, stderr=log)
        origin = 'http://127.0.0.1:'+str(port)
        def request(path):
            with urllib.request.urlopen(origin+path, timeout=10) as response: return response.read().decode()
        for _ in range(40):
            try: request('/products'); break
            except OSError: time.sleep(.1)
        body=request('/product/1/synthetic-test-part')
        main=re.search(r'<img [^>]*id="main-product-image"[^>]*>', body, re.S)[0]
        check('srcset=' in main and 'width="1600" height="1200"' in main, 'Main product photo renders verified remote dimensions and srcset')
        check('src="'+html.escape(url, quote=True)+'"' in main, 'Main photo retains exact remote src fallback')
        check(html.escape(url, quote=True)+' 1600w' in main, 'Original native resolution remains available, including internal URL commas')
        check('href="'+html.escape(url, quote=True)+'"' in body, 'Full-size photo link retains exact original')
        thumbs=re.findall(r'<button[^>]*class="product-thumbnail [\s\S]*?</button>', body)
        check(len(thumbs)==2, 'Both remote gallery photos have selector buttons')
        for thumb in thumbs:
            check('data-full="'+html.escape(url if 'photo=1' in html.unescape(thumb) else url2, quote=True)+'"' in thumb, 'Selection preserves original full-size URL')
            decoded=html.unescape(thumb)
            check('srcset=' in decoded and ' 1440w' in decoded, 'Selected-photo metadata contains full responsive set')
            thumbnail=re.search(r'<img [^>]*>',thumb,re.S)[0]
            check(' 320w' in thumbnail and ' 640w' not in thumbnail, 'Thumbnail candidates remain bounded')
        catalog=request('/products')
        ajax=json.loads(request('/api/products.php'))['html']
        for name, markup in [('catalog',catalog),('AJAX catalog',ajax),('related cards',body.split('class="related-products',1)[-1])]:
            check('/gallery/responsive/remote-' in markup and 'loading="lazy"' in markup, name+' uses local derivative candidates')
        schemas=[json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>',body,re.S)]
        product=next(node for item in schemas for node in (item if isinstance(item,list) else [item]) if node.get('@type')=='Product')
        check(product['image'][0]==url and product['image'][1]==url2, 'Schema preserves exact remote originals')
        feed=request('/google-merchant-feed.php')
        check('/gallery/responsive/' not in feed and html.escape(url, quote=False) in feed, 'Merchant feed preserves remote originals')
        candidate=re.search(r'/gallery/responsive/remote-[a-f0-9]+-320.webp',main)[0]
        with urllib.request.urlopen(origin+candidate) as response:
            data=response.read();check(response.headers.get_content_type()=='image/webp' and len(data)<image.stat().st_size, 'Derivative is served as smaller image/webp')
        for receipt in (site/'gallery/responsive').glob('remote-*.json'):
            obj=json.loads(receipt.read_text());obj['valid_until']=1;receipt.write_text(json.dumps(obj))
        expired=request('/product/1/synthetic-test-part')
        expired_main=re.search(r'<img [^>]*id="main-product-image"[^>]*>', expired, re.S)[0]
        check('srcset=' not in expired_main and html.escape(url, quote=True) in expired_main, 'Expired receipt restores original on next HTTP request')
    print(json.dumps({'scope':'synthetic local HTTP, no production or remote fetch', 'checks':len(checks), 'passed':checks, 'imports':rows}, indent=2))
except Exception:
    print('Fixture diagnostics:',base)
    if (base/'server.log').exists(): print((base/'server.log').read_text()[-3000:])
    raise
finally:
    if server: server.terminate(); server.wait(timeout=10)
    # Preserve failed fixtures for diagnostics; no production data is present.
