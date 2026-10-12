"""Manufacturer case grouping through full-page and AJAX catalog rendering; synthetic stock only."""
from pathlib import Path
import json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix='fas-manufacturer-test-'))
SITE = BASE / 'site'
SITE.mkdir()
for folder in ['src', 'includes', 'public', 'api']:
    shutil.copytree(ROOT / folder, SITE / folder, ignore=shutil.ignore_patterns(
        'config.php', 'config.local.php', 'config.production.php', '*.db', '*.log', 'uploads'))
shutil.copy2(ROOT / 'products.php', SITE / 'products.php')
shutil.copy2(ROOT / 'src/config/config.example.php', SITE / 'src/config/config.php')
(SITE / 'database').mkdir()
(SITE / 'src/integrations/EbayAPI.php').write_text('''<?php namespace FAS\\Integrations;
class EbayAPI {
 public function __construct($config) {}
 public function getStoreCategoriesHierarchical(){return [];}
 public function getStoreCategories(){return [];}
}
''')
with sqlite3.connect(SITE / 'database/flipandstrip.db') as db:
    db.executescript((ROOT / 'tests/fixtures/security-catalog.sql').read_text())
    db.execute('DELETE FROM products')
    for i in range(1, 31):
        db.execute('''INSERT INTO products(id,name,description,sku,price,category,manufacturer,model,
            quantity,is_active,show_on_website,free_shipping,images,source,created_at,updated_at)
            VALUES(?,?,?,?,100,'motorcycle',?,?,1,1,1,0,'[]','manual','2026-01-01','2026-01-01')''',
            [i, 'Fixture rotor '+str(i), 'Synthetic rotor', 'TEST-'+str(i),
             ['KAWASAKI','Kawasaki','kawasaki'][i % 3], 'XR'])
    db.execute("UPDATE products SET model='KX' WHERE id=1")
    db.execute("INSERT INTO products(id,name,price,category,manufacturer,model,is_active,show_on_website,created_at) VALUES(31,'Other rotor',100,'boat','Honda','XR',1,1,'2026-01-01')")
router = BASE / 'router.php'
router.write_text('''<?php
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/products(?:/(motorcycle))?(?:/make/([^/]+))?$~',$p,$m)) {
 if(!empty($m[1])) $_GET['category']=$m[1];
 if(!empty($m[2])) $_GET['manufacturer_slug']=$m[2];
 require $_SERVER['DOCUMENT_ROOT'].'/products.php';return true;
}
return false;
''')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
origin = 'http://127.0.0.1:'+str(port)
env = os.environ.copy()
env['FAS_SECURITY_DB_PATH'] = str(BASE / 'security.sqlite')
env['FAS_GROWTH_DB_PATH'] = str(BASE / 'growth.sqlite')
log = (BASE / 'server.log').open('w')
server = subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(SITE),str(router)],env=env,stdout=log,stderr=log)
checks = 0
def get(path):
    with urllib.request.urlopen(origin+path,timeout=10) as response:
        return response.read().decode()
def check(ok, message):
    global checks
    assert ok, message
    checks += 1
try:
    for attempt in range(50):
        try:
            get('/products'); break
        except OSError:
            time.sleep(.1)
    for variant in ['KAWASAKI','Kawasaki','kawasaki']:
        for prefix in ['/products/motorcycle?', '/api/products.php?category=motorcycle&']:
            body = get(prefix+'manufacturer='+variant+'&search=rotor')
            html = json.loads(body)['html'] if prefix.startswith('/api') else body
            check('30 products found' in html, 'Full-page/AJAX combined count')
            options = re.findall(r'<option value="([^"]*)"([^>]*)>',html)
            makes = [(value, attrs) for value,attrs in options if value.lower()=='kawasaki']
            check(len(makes)==1 and 'selected' in makes[0][1], 'One make remains selected for every legacy spelling')
            check('value="KX"' in html, 'Models from another make spelling available')
            check('manufacturer='+variant in html, 'Pagination retains legacy make filter')
            next_body=get(prefix+'manufacturer='+variant+'&search=rotor&page=2')
            next_html=json.loads(next_body)['html'] if prefix.startswith('/api') else next_body
            check('30 products found' in next_html, 'Page two retains count and filters')
    check('30 products found' in get('/products/make/kawasaki'), 'Canonical make slug spans capitalization variants')
    check('30 products found' in get('/products/motorcycle'), 'Clearing make keeps homepage category')
    check('31 products found' in get('/products'), 'Clearing all filters restores all visible products')
    print('PASS',checks,'manufacturer HTTP assertions; synthetic inventory only')
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
