"""Product output and mutation regressions using disposable synthetic fixtures only."""
from pathlib import Path
from html.parser import HTMLParser
import base64, html, http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix='fas-product-security-'))
SITE = BASE / 'site'
SITE.mkdir()
for folder in ['src', 'admin', 'includes', 'public', 'api', 'scripts']:
    shutil.copytree(ROOT / folder, SITE / folder, ignore=shutil.ignore_patterns(
        'config.php', 'config.local.php', 'config.production.php', 'shipping.php', 'applepay.php',
        'uploads', 'backups', 'exports', '*.db', '*.sqlite', '*.log'))
for name in ['products.php', 'product.php', 'google-merchant-feed.php']:
    shutil.copy2(ROOT / name, SITE / name)
shutil.copy2(ROOT / 'src/config/config.example.php', SITE / 'src/config/config.php')
(SITE / 'src/integrations/EbayAPI.php').write_text('''<?php namespace FAS\\Integrations;
class EbayAPI {
    public function __construct($config) {}
    public function getStoreCategoriesHierarchical(){return [];}
    public function getStoreCategories(){return [];}
}
''')
(SITE / 'database').mkdir()
(SITE / 'gallery/uploads').mkdir(parents=True)
(BASE / 'sessions').mkdir()
DB = SITE / 'database/flipandstrip.db'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMfkAAAAASUVORK5CYII=')
with sqlite3.connect(DB) as db:
    db.executescript((ROOT / 'database/schema.sqlite.sql').read_text())
    password = subprocess.check_output(['php', '-r', 'echo password_hash("Local-test-only", PASSWORD_DEFAULT);'], text=True)
    db.execute("INSERT INTO admin_users(id,username,password_hash,email,role,is_active) VALUES(9001,'product-fixture',?,'fixture@example.invalid','admin',1)", [password])
    db.execute("INSERT INTO products(id,name,description,sku,price,quantity,category,manufacturer,model,weight,length,width,height,ebay_store_cat1_id,ebay_store_cat2_id,ebay_store_cat3_id,images) VALUES(9001,'Synthetic part','Original synthetic notes','SYNTHETIC-9001',25,1,'motorcycle','Fixture brand','Fixture model',1,2,3,4,1,2,3,'[]')")
env = os.environ.copy()
for key in list(env):
    if key.startswith('FAS_'): env.pop(key)
env.update(FAS_SECURITY_DB_PATH=str(BASE / 'private/security.sqlite'),
           FAS_GROWTH_DB_PATH=str(BASE / 'private/growth.sqlite'), ANALYTICS_IP_GEO_ENABLED='0')
subprocess.run(['php', str(SITE / 'scripts/security-maintenance.php'), 'init'], env=env, check=True, stdout=subprocess.DEVNULL)
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
ORIGIN = 'http://127.0.0.1:' + str(port)
router = BASE / 'router.php'
router.write_text('''<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/fixture-schema') {
    $structuredData=[['@type'=>'Thing','name'=>'</ScRiPt ><div id=fixture-boundary>'],
        '{"@type":"Thing","name":"</SCRIPT><div id=fixture-boundary>","empty":{},"large":9007199254740993}',
        '{"invalid":', '</script><div id=fixture-boundary>'];
    require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; return true;
}
if ($path==='/products') {require $_SERVER['DOCUMENT_ROOT'].'/products.php';return true;}
if (preg_match('~^/product/([0-9]+)~',$path,$m)) {$_GET['id']=$m[1];require $_SERVER['DOCUMENT_ROOT'].'/product.php';return true;}
return false;
''')
log = open(BASE / 'server.log', 'w')
server = subprocess.Popen(['php', '-d', 'disable_functions=mail', '-d', 'session.save_path='+str(BASE / 'sessions'),
    '-S', '127.0.0.1:'+str(port), '-t', str(SITE), str(router)], env=env, stdout=log, stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
def request(path, data=None, headers=None):
    if isinstance(data, dict): data = urllib.parse.urlencode(data, doseq=True).encode()
    try: response = client.open(urllib.request.Request(ORIGIN+path, data=data, headers=headers or {}), timeout=15)
    except urllib.error.HTTPError as error: response = error
    with response: return response.status, dict(response.headers), response.read().decode()
def token(body): return html.unescape(re.search(r'name="csrf_token" value="([^"]+)"', body)[1])
def snapshot():
    with sqlite3.connect(DB) as db: return db.execute('SELECT * FROM products ORDER BY id').fetchall()
def uploads(): return sorted((p.name, p.read_bytes()) for p in (SITE / 'gallery/uploads').iterdir() if p.is_file() and not p.is_symlink())
checks, failures = [], []
def check(condition, message):
    if condition: checks.append(message)
    else: failures.append(message)
class Markup(HTMLParser):
    def __init__(self, body):
        super().__init__(); self.inputs=[]; self.boundary=False; self.feed(body)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='input': self.inputs.append(attrs)
        if attrs.get('id')=='fixture-boundary': self.boundary=True
def schemas(body): return [json.loads(s) for s in re.findall(r'<script type="application/ld\+json">(.*?)</script>', body, re.S)]
def multipart(fields):
    boundary='FASFixtureBoundary'
    parts=[]
    for name,value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    for name in ['image_file','additional_images[]']:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+PNG+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return b''.join(parts), {'Content-Type':'multipart/form-data; boundary='+boundary}
try:
    for _ in range(50):
        if server.poll() is not None: raise RuntimeError('Fixture PHP server exited')
        try: request('/admin/login.php'); break
        except OSError: time.sleep(.1)
    # Exercise the actual header with arrays, raw JSON and invalid raw schemas.
    status, _, body=request('/fixture-schema')
    try:
        parsed=schemas(body)
        check(status==200 and len(parsed)==3 and parsed[2]['large']==9007199254740993 and parsed[2]['empty']=={}, 'Header emits safe valid array/raw schema and omits malformed strings')
    except (ValueError, IndexError): check(False, 'Header emits safe valid array/raw schema and omits malformed strings')
    check(not Markup(body).boundary, 'Schema cannot create markup outside its script element')
    for value, cleaned in [('</script>',''), ('</ScRiPt >',''), ('</SCRIPT/>',''),
            ('&lt;/script&gt;','</script>'), ('&#60;/ScRiPt&#62;','</ScRiPt>'), ('&lt;/script&gt;<script>','</script>')]:
        with sqlite3.connect(DB) as db:
            db.execute('UPDATE products SET name=?,sku=?,image_url=?,images=? WHERE id=9001', ['Synthetic '+value,'SYNTHETIC-'+value,'/gallery/'+value,json.dumps(['/gallery/'+value])])
        before=snapshot()
        status, headers, body=request('/product/9001')
        if status == 301:
            canonical = urllib.parse.urlsplit(headers.get('Location', '')).path
            if not canonical.startswith('/product/9001/'):
                raise AssertionError('Fixture product redirect must remain scoped to its local route')
            # Follow the app's canonical path through the fixture origin only.
            # Its configured public hostname must never receive a test request.
            status, _, body = request(canonical)
        check(status == 200, 'Synthetic product reaches its canonical page locally')
        try:
            product=next(s for s in schemas(body) if s.get('@type')=='Product')
            check(product['sku']=='SYNTHETIC-'+cleaned and product['image'][0].endswith('/gallery/'+value), 'Product schema keeps existing SKU cleaning and original image data')
        except (ValueError, StopIteration, KeyError): check(False, 'Product schema safely preserves SKU and image data')
        check(snapshot()==before, 'Public rendering preserves original inventory columns')
    with sqlite3.connect(DB) as db:
        db.execute("UPDATE products SET name='Synthetic part',sku='SYNTHETIC-9001',image_url='/gallery/default.jpg',images='[]' WHERE id=9001")
    for key in ['manufacturer', 'model']:
        value='Fixture &lt;/ScRiPt&gt; &amp; " quote'
        path='/products?'+urllib.parse.urlencode({key:value})
        body=request(path)[2]
        match=re.search(r'window.FAS_PRODUCTS_PAGE_DATA\s*=\s*(.*?);\s*\n',body,re.S)
        try:
            payload=json.loads(match[1]); check(payload[key]==html.unescape(value), 'Inline catalog JSON round-trips cleaned '+key)
        except (ValueError, TypeError): check(False, 'Inline catalog JSON round-trips cleaned '+key)
        check(match is not None and '<' not in match[1], 'Inline catalog JSON has no HTML boundary')
        data=json.loads(request('/api/products.php?'+urllib.parse.urlencode({key:value}))[2])
        check('html' in data and not Markup(data['html']).boundary, 'AJAX catalog remains valid JSON with safe HTML')
    for key in ['cat1', 'cat2', 'cat3']:
        for value in ['1" data-fixture="attribute', '1</ScRiPt>', '../1', '-1', '1.5']:
            query=urllib.parse.urlencode({key:value})
            check(request('/products?'+query)[0]==404 and request('/api/products.php?'+query)[0]==404, 'Full page and AJAX reject invalid category identifiers')
        check(request('/products?'+key+'%5B%5D=1')[0]==404 and request('/api/products.php?'+key+'%5B%5D=1')[0]==404, 'Array category identifiers rejected')
    query=urllib.parse.urlencode({'cat1':'1','cat2':'2','cat3':'3'})
    full=request('/products?'+query)
    ajax=request('/api/products.php?'+query)
    for status, body in [(full[0],full[2]),(ajax[0],json.loads(ajax[2])['html'])]:
        inputs={a.get('name'):a for a in Markup(body).inputs}
        check(status==200 and all(inputs[k]['value']==str(i) and set(inputs[k])=={'type','name','value'} for i,k in enumerate(['cat1','cat2','cat3'],1)), 'Valid category inputs keep one escaped value attribute')
    csrf=token(request('/admin/login.php')[2])
    check(request('/admin/login.php',{'username':'product-fixture','password':'Local-test-only','csrf_token':csrf})[0]==302, 'Normal fixture login succeeds')
    for route in ['/admin/products.php','/admin/products.php?action=create','/admin/products.php?action=edit&id=9001']:
        body=request(route)[2]
        forms=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',body,re.S|re.I)
        check(bool(forms) and all('name="csrf_token"' in form for form in forms), 'Every product POST form carries CSRF')
    fields={'action':'create','name':'Synthetic created part','sku':'SYNTHETIC-CREATE','description':'Synthetic notes','price':'20','quantity':'1','category':'motorcycle','weight':'1','length':'2','width':'3','height':'4','show_on_website':'1'}
    mutations=[fields,dict(fields,action='update',product_id='9001'),{'action':'delete','product_id':'9001'},
        {'action':'bulk_update','bulk_action':'hide','selected_products[]':['9001']},
        {'action':'toggle_visibility','product_id':'9001'},{'action':'toggle_free_shipping','product_id':'9001'},
        {'ajax_remove_image':'1','product_id':'9001','image_path':'/gallery/uploads/member.png'}]
    (SITE/'gallery/uploads/member.png').write_bytes(PNG)
    with sqlite3.connect(DB) as db: db.execute('UPDATE products SET images=? WHERE id=9001',[json.dumps(['/gallery/uploads/member.png'])])
    for mutation in mutations:
        for extra in [{},{'csrf_token':'wrong'},{'csrf_token[]':['wrong']}]:
            before,files=snapshot(),uploads()
            status,_,body=request('/admin/products.php',dict(mutation,**extra))
            check(status==403, 'Product mutation rejects missing, invalid or array CSRF')
            check(snapshot()==before and uploads()==files, 'Rejected CSRF preserves inventory and uploads')
            if 'ajax_remove_image' in mutation or str(mutation.get('action','')).startswith('toggle_'):
                check(json.loads(body)['success'] is False, 'AJAX CSRF rejection preserves JSON response contract')
    for action in ['create','update']:
        before,files=snapshot(),uploads()
        data,headers=multipart(dict(fields,action=action,product_id='9001',csrf_token='wrong'))
        check(request('/admin/products.php',data,headers)[0]==403 and snapshot()==before and uploads()==files, 'Invalid multipart CSRF cannot upload either image field')
    for action in ['toggle_visibility','toggle_free_shipping']:
        before=snapshot()
        result=request('/admin/products.php',{'action':action,'product_id':'9001','csrf_token':csrf})
        check(result[0]==200 and json.loads(result[2])['success'] is True and snapshot()!=before, 'Valid CSRF preserves '+action)
    result=request('/admin/products.php',{'action':'bulk_update','bulk_action':'show','selected_products[]':['9001'],'csrf_token':csrf})
    check(result[0]==200 and 'Bulk action applied' in result[2], 'Valid CSRF preserves bulk action')
    before,files=snapshot(),uploads()
    result=request('/admin/products.php',{'ajax_remove_image':'1','product_id':'9001','image_path':'/gallery/uploads/nonmember.png','csrf_token':csrf})
    check(json.loads(result[2])['success'] is False and snapshot()==before and uploads()==files, 'Valid CSRF cannot remove a nonmember image')
    result=request('/admin/products.php',{'ajax_remove_image':'1','product_id':'9001','image_path':'/gallery/uploads/member.png','csrf_token':csrf})
    with sqlite3.connect(DB) as db: remaining=db.execute('SELECT images FROM products WHERE id=9001').fetchone()[0]
    check(json.loads(result[2])['success'] is True and remaining=='[]' and not (SITE/'gallery/uploads/member.png').exists(), 'Valid CSRF preserves legitimate immediate image removal')
    data,headers=multipart(dict(fields,csrf_token=csrf))
    result=request('/admin/products.php',data,headers)
    with sqlite3.connect(DB) as db:
        created=db.execute("SELECT id,image_url,images FROM products WHERE sku='SYNTHETIC-CREATE'").fetchone()
    check(result[0]==200 and created is not None and len(uploads())==2, 'Valid CSRF creates a product with both upload fields')
    if created:
        result=request('/admin/products.php',dict(fields,action='update',product_id=str(created[0]),name='Synthetic updated part',csrf_token=csrf))
        with sqlite3.connect(DB) as db: name=db.execute('SELECT name FROM products WHERE id=?',[created[0]]).fetchone()[0]
        check(result[0]==200 and name=='Synthetic updated part', 'Valid CSRF preserves product update')
        result=request('/admin/products.php',{'action':'delete','product_id':str(created[0]),'csrf_token':csrf})
        with sqlite3.connect(DB) as db: active=db.execute('SELECT is_active FROM products WHERE id=?',[created[0]]).fetchone()[0]
        check(result[0]==200 and active==0, 'Valid CSRF preserves product deletion')
    print(json.dumps({'passed':len(checks),'failed':failures,'scope':'disposable synthetic cloud fixtures only'},indent=2))
    if failures: raise AssertionError(str(len(failures))+' product security checks failed')
finally:
    server.terminate(); server.wait(timeout=10); log.close()
    shutil.rmtree(BASE)
