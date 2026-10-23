"""Manufacturer case grouping through full-page and AJAX catalog rendering; synthetic stock only."""
from pathlib import Path
import json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time, sys
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix='fas-manufacturer-test-'))
SITE = BASE / 'site'
SITE.mkdir()
SYNTHETIC_DB = SITE / 'database/flipandstrip.db'
# Fail closed: every stock mutation below targets this new disposable database.
assert SYNTHETIC_DB.resolve().is_relative_to(BASE.resolve())
assert not SYNTHETIC_DB.resolve().is_relative_to(ROOT.resolve())
for folder in ['src', 'includes', 'public', 'api']:
    shutil.copytree(ROOT / folder, SITE / folder, ignore=shutil.ignore_patterns(
        'config.php', 'config.local.php', 'config.production.php', '*.db', '*.log', 'uploads'))
shutil.copy2(ROOT / 'products.php', SITE / 'products.php')
shutil.copy2(ROOT / 'src/config/config.example.php', SITE / 'src/config/config.php')
(SITE / 'database').mkdir()
(SITE / 'src/integrations/EbayAPI.php').write_text('''<?php namespace FAS\\Integrations;
class EbayAPI {
 public function __construct($config) {}
 public function getStoreCategoriesHierarchical(){return [
  ['id'=>1,'name'=>'Other','children'=>[['id'=>11,'name'=>'HONDA','children'=>[['id'=>111,'name'=>'XR'],['id'=>112,'name'=>'KX'],['id'=>113,'name'=>'part number']]]]],
  ['id'=>2,'name'=>'OTHER','children'=>[['id'=>21,'name'=>'Honda','children'=>[['id'=>211,'name'=>'xr']]]]],
  ['id'=>3,'name'=>'Motorcycle','children'=>[['id'=>31,'name'=>'Honda','children'=>[['id'=>311,'name'=>'XR']]]]],
  ['id'=>4,'name'=>'ATV','children'=>[['id'=>41,'name'=>'Honda','children'=>[['id'=>411,'name'=>'XR']]]]],
 ];}
 public function getStoreCategories(){return [1=>['name'=>'Other'],2=>['name'=>'OTHER'],11=>['name'=>'HONDA','topLevel'=>'Other'],21=>['name'=>'Honda','topLevel'=>'OTHER'],111=>['name'=>'XR','topLevel'=>'Other','parent'=>'HONDA'],211=>['name'=>'xr','topLevel'=>'OTHER','parent'=>'Honda'],3=>['name'=>'Motorcycle']];}
}
''')
with sqlite3.connect(SYNTHETIC_DB) as db:
    db.executescript((ROOT / 'tests/fixtures/security-catalog.sql').read_text())
    db.execute('DELETE FROM products')
    for i in range(1, 31):
        db.execute('''INSERT INTO products(id,name,description,sku,price,category,manufacturer,model,
            quantity,is_active,show_on_website,free_shipping,images,source,created_at,updated_at)
            VALUES(?,?,?,?,100,'motorcycle',?,?,1,1,1,0,'[]','manual','2026-01-01','2026-01-01')''',
            [i, 'Fixture rotor '+str(i), 'Synthetic rotor', 'TEST-'+str(i),
             ['KAWASAKI','Kawasaki','kawasaki'][i % 3], 'XR'])
    db.execute("UPDATE products SET model=CASE id % 3 WHEN 0 THEN 'XR' WHEN 1 THEN 'xr' ELSE 'Xr' END, ebay_store_cat1_id=CASE WHEN id<=15 THEN 1 ELSE 2 END, ebay_store_cat2_id=CASE WHEN id<=15 THEN 11 ELSE 21 END, ebay_store_cat3_id=CASE WHEN id<=15 THEN 111 ELSE 211 END")
    db.execute("UPDATE products SET model='KX',ebay_store_cat3_id=112 WHERE id=1")
    db.execute("UPDATE products SET model='49058-0120-31f',ebay_store_cat3_id=113 WHERE id=2")
    db.execute("INSERT INTO products(id,name,price,category,manufacturer,model,is_active,show_on_website,created_at) VALUES(31,'Other rotor',100,'boat','Honda','XR',1,1,'2026-01-01')")
    db.execute("UPDATE products SET sku='TEST-31',description='Synthetic rotor',quantity=1,condition_name='Used',images='[]',source='manual',free_shipping=0,ebay_store_cat1_id=3,ebay_store_cat2_id=31,ebay_store_cat3_id=311 WHERE id=31")
    db.execute("INSERT INTO products(id,name,price,category,manufacturer,model,is_active,show_on_website,ebay_store_cat1_id,created_at) VALUES(32,'Hidden fixture',100,'motorcycle','Kawasaki','XR',1,0,4,'2026-01-01')")
router = BASE / 'router.php'
router.write_text('''<?php
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/products(?:/(motorcycle))?(?:/make/([^/]+)(?:/([^/]+))?)?$~i',$p,$m)) {
 if(!empty($m[1])) $_GET['category']=$m[1];
 if(!empty($m[2])) $_GET['manufacturer_slug']=$m[2];
 if(!empty($m[3])) $_GET['model_slug']=$m[3];
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
def product_ids(html):
    return {int(value) for value in re.findall(r'href="/product/(\d+)/',html)}
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
            check(product_ids(html).isdisjoint(product_ids(next_html)) and product_ids(html)|product_ids(next_html)==set(range(1,31)),'Pagination includes each matching product exactly once')
    check('30 products found' in get('/products/make/kawasaki'), 'Canonical make slug spans capitalization variants')
    check('30 products found' in get('/products/motorcycle'), 'Clearing make keeps homepage category')
    check('31 products found' in get('/products'), 'Clearing all filters restores all visible products')
    for variant in ['XR','xr','Xr']:
        for prefix in ['/products?', '/api/products.php?']:
            body=get(prefix+'manufacturer=kawasaki&model='+variant+'&search=ROTOR')
            html=json.loads(body)['html'] if prefix.startswith('/api') else body
            check('28 products found' in html,'Case-equivalent models retain search and make')
            models=[(value,attrs) for value,attrs in re.findall(r'<option value="([^"]*)"([^>]*)>',html) if value.lower()=='xr']
            check(len(models)==1 and 'selected' in models[0][1],'One model option remains selected for every spelling')
    check('28 products found' in get('/products/make/KAWASAKI/XR'),'Uppercase canonical make/model segments resolve the entire group')
    check('1 products found' in get('/products/make/kawasaki?model=49058-0120-31F'),'Uppercase part-number query on canonical make stays available')
    for prefix in ['/products?', '/api/products.php?']:
        for alias in [1,2]:
            body=get(prefix+'cat1='+str(alias)+'&include_sidebar=1')
            response=json.loads(body) if prefix.startswith('/api') else {'html':body,'sidebar':body}
            check('30 products found' in response['html'],'Either root alias includes all equivalent sibling products')
            check(response['sidebar'].count('data-cat1="1"')>=1 and 'data-cat1="2"' not in response['sidebar'],'One canonical root group shown')
            check('data-cat1="4"' not in response['sidebar'],'Hidden-only branches remain pruned')
            second=get(prefix+'cat1='+str(alias)+'&page=2')
            second_html=json.loads(second)['html'] if prefix.startswith('/api') else second
            check('30 products found' in second_html,'Alias grouping persists over pagination')
            check(product_ids(response['html']).isdisjoint(product_ids(second_html)) and product_ids(response['html'])|product_ids(second_html)==set(range(1,31)),'Category alias pages have a complete unique product set')
        for path in ['cat1=1&cat2=11&cat3=111','cat1=2&cat2=21&cat3=211']:
            body=get(prefix+path+'&manufacturer=KAWASAKI&model=xr&search=rotor')
            html=json.loads(body)['html'] if prefix.startswith('/api') else body
            check('28 products found' in html,'Full alias hierarchy retains make, model and search')
        body=get(prefix+'cat1=3&cat2=31&cat3=311')
        check('1 products found' in (json.loads(body)['html'] if prefix.startswith('/api') else body),'Same model label under another parent stays separate')
        body=get(prefix+'cat1=999')
        check('0 products found' in (json.loads(body)['html'] if prefix.startswith('/api') else body),'Unknown ID never widens results')
    import html as html_module
    from urllib.parse import urlparse, parse_qs
    for endpoint in ['/products?', '/api/products.php?']:
        content=get(endpoint+'cat1=1&cat2=11&cat3=111&search=rotor&manufacturer=Kawasaki&model=XR&include_sidebar=1&page=2')
        sidebar=json.loads(content)['sidebar'] if endpoint.startswith('/api') else content
        links=re.findall(r'<a href="([^"]*)"[^>]*data-cat1="([^"]*)"([^>]*)>',sidebar)
        check(len(links)>=5, 'Every category level has native destinations')
        for href, cat1, attrs in links:
            href=html_module.unescape(href)
            params=parse_qs(urlparse(href).query)
            check(href.startswith('/products?') and params['cat1']==[cat1], 'Native category URL targets its category')
            check(params.get('search')==['rotor'] and params.get('manufacturer')==['Kawasaki'] and params.get('model')==['XR'], 'Native category URL preserves shopping filters')
            check('page' not in params and 'include_sidebar' not in params, 'Native category URL resets pagination and drops API transport flags')
            for level in [2,3]:
                match=re.search('data-cat%d="([^"]*)"'%level, attrs)
                check(params.get('cat'+str(level))==([match[1]] if match else None), 'Native category URL resets stale descendants')
            check('products found' in get(href), 'Native category destination renders without JavaScript')
    with sqlite3.connect(SYNTHETIC_DB) as db:
        db.execute('UPDATE products SET show_on_website=0 WHERE id<=15')
        db.execute('UPDATE products SET is_active=0 WHERE id=30')
    body=get('/products?cat1=2')
    check('14 products found' in body and 'data-cat1="1"' in body,'Canonical sibling without visible stock still exposes alias stock, excluding hidden/inactive products')
    (SITE / 'gallery').mkdir(exist_ok=True)
    for photo in ['gallery-only.jpg', 'primary.jpg', 'second.jpg', 'default.jpg']:
        (SITE / 'gallery' / photo).write_bytes(b'synthetic image-path fixture')
    # Gallery-only stock must use its supplied photo in both listing transports.
    for primary, gallery, expected in [
        ('', '["gallery/gallery-only.jpg"]', '/gallery/gallery-only.jpg'),
        ('gallery/primary.jpg', '["gallery/gallery-only.jpg"]', '/gallery/primary.jpg'),
        ('  ', '[null,"",{},"gallery/second.jpg"]', '/gallery/second.jpg'),
        ('', 'broken-json', '/gallery/default.jpg'),
        ('', '["HTTPS://example.invalid/photo.jpg"]', 'HTTPS://example.invalid/photo.jpg'),
    ]:
        with sqlite3.connect(SYNTHETIC_DB) as db:
            db.execute('UPDATE products SET image_url=?, images=? WHERE id=29', (primary, gallery))
        for endpoint in ['/products?', '/api/products.php?']:
            body=get(endpoint+'search=TEST-29')
            rendered=json.loads(body)['html'] if endpoint.startswith('/api') else body
            check('data-id="29"' in rendered and 'data-image="'+expected+'"' in rendered, 'Listing uses selected product photo for cart: '+endpoint)
            check('src="'+expected+'"' in rendered, 'Listing renders selected product photo: '+endpoint)
        with sqlite3.connect(SYNTHETIC_DB) as db:
            check(db.execute('SELECT image_url,images FROM products WHERE id=29').fetchone()==(primary,gallery), 'Listing reads do not mutate source images')
    print('PASS',checks,'catalog label HTTP assertions; synthetic inventory only')
finally:
    if '--keep' in sys.argv:
        print(json.dumps({'origin':origin,'fixture':str(BASE),'server_pid':server.pid}))
    else:
        server.terminate()
        server.wait(timeout=10)
    log.close()
