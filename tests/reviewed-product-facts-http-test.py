"""Local synthetic storefront/feed fixtures; external PHP network and mail disabled."""
from pathlib import Path
import json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
BASE=Path(tempfile.mkdtemp(prefix='fas-reviewed-facts-')); SITE=BASE/'site'; SITE.mkdir()
for folder in ['src','includes','public','api']:
    shutil.copytree(ROOT/folder,SITE/folder,ignore=shutil.ignore_patterns('config.php','config.local.php','config.production.php','uploads','*.db','*.sqlite','*.log'))
for file in ['product.php','products.php','index.php','google-merchant-feed.php']:
    shutil.copy2(ROOT/file,SITE/file)
shutil.copy2(ROOT/'src/config/config.example.php',SITE/'src/config/config.php')
(SITE/'src/integrations/EbayAPI.php').write_text('''<?php namespace FAS\\Integrations;
class EbayAPI { public function __construct($config) {} public function getStoreCategoriesHierarchical(){return [];} public function getStoreCategories(){return [];} }
''')
(SITE/'database').mkdir(); (SITE/'gallery').mkdir()
DB=SITE/'database/flipandstrip.db'
identities={6305:'10171 FAS',6419:'FW103 FAS',6223:'fw107b tw1',6221:'(fw107c tw1)',6306:'SYNTHETIC-CONTROL'}
with sqlite3.connect(DB) as db:
    db.executescript((ROOT/'tests/fixtures/security-catalog.sql').read_text()); db.execute('DELETE FROM products')
    for id,sku in identities.items():
        db.execute('INSERT INTO products(id,name,description,sku,price,category,manufacturer,model,condition_name,quantity,is_active,show_on_website,free_shipping,image_url,images,source,ebay_item_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [id,f'Synthetic reviewed {id}','Synthetic complete source description. NO RETURNS.' if id in [6419,6223,6221] else 'Synthetic complete source description.',sku,123.45,'motorcycle','EPI' if id==6305 else 'Control maker','WE437724' if id==6305 else 'CONTROL-42','Used',2,1,1,0,'/gallery/default.jpg','[]','ebay',f'SYNTHETIC-EBAY-{id}','2026-01-01','2026-01-01'])
    original=db.execute('SELECT * FROM products ORDER BY id').fetchall()
router=BASE/'router.php'; router.write_text('''<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/product/([0-9]+)(?:/[^/]+)?$~',$p,$m)){$_GET['id']=$m[1];require $_SERVER['DOCUMENT_ROOT'].'/product.php';return true;}
if($p==='/fixture-growth-catalog'){
    require $_SERVER['DOCUMENT_ROOT'].'/includes/growth.php';
    header('Content-Type: application/json');
    echo json_encode(fas_growth()->catalog([['id'=>6305,'quantity'=>1],['id'=>6306,'quantity'=>1]])); return true;
}
if($p==='/products'){require $_SERVER['DOCUMENT_ROOT'].'/products.php';return true;} return false;''')
with socket.socket() as s:s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
env=os.environ.copy(); env['FAS_SECURITY_DB_PATH']=str(BASE/'private/security.sqlite'); env['FAS_GROWTH_DB_PATH']=str(BASE/'private/growth.sqlite')
log=open(BASE/'server.log','w')
server=subprocess.Popen(['php','-d','disable_functions=mail,curl_exec,curl_multi_exec','-d','allow_url_fopen=0','-S',f'127.0.0.1:{port}','-t',str(SITE),str(router)],env=env,stdout=log,stderr=log)
def request(path):
    with urllib.request.urlopen(f'http://127.0.0.1:{port}'+path,timeout=15) as r:
        assert r.status==200
        return r.read().decode()
def product(id):return request(f'/product/{id}/synthetic-reviewed-{id}')
def schema(body):
    for text in re.findall(r'<script type="application/ld\+json">(.*?)</script>',body,re.S):
        value=json.loads(text)
        for v in value if isinstance(value,list) else [value]:
            if v.get('@type')=='Product':return v
    raise AssertionError('Product JSON-LD missing')
def feed():
    xml=ET.fromstring(request('/google-merchant-feed.php')); ns='{http://base.google.com/ns/1.0}'
    return {item.findtext(ns+'id'):{c.tag:c.text for c in item} for item in xml.findall('./channel/item')}
checks=[]
def check(ok,message):
    if not ok:raise AssertionError(message)
    checks.append(message)
try:
    for _ in range(100):
        if server.poll() is not None:raise RuntimeError('Fixture server exited')
        try:body=product(6305);break
        except OSError:time.sleep(.1)
    check('>Wiseco</td>' in body and '>PWR128-101</td>' in body,'Product specifications use owner-confirmed identifiers')
    check('>EPI</td>' not in body and 'WE437724' not in body,'Wrong identifiers are not presented in detail HTML')
    check('data-manufacturer="Wiseco"' in body and 'manufacturer: "Wiseco"' in body,'Detail purchase/analytics metadata agrees')
    s=schema(body); check(s['brand']['name']=='Wiseco' and s['mpn']=='PWR128-101','Detail schema agrees')
    restored_catalog=json.loads(request('/fixture-growth-catalog'))
    check(restored_catalog[0]['manufacturer']=='Wiseco' and 'EPI' not in restored_catalog[0]['image_alt'],'Saved-cart catalog uses matching reviewed purchase/analytics metadata')
    check(restored_catalog[1]['manufacturer']=='Control maker','Unrelated saved-cart manufacturer is unchanged')
    initialfeed=feed(); ns='{http://base.google.com/ns/1.0}'
    check(initialfeed['10171 FAS'][ns+'brand']=='Wiseco' and initialfeed['10171 FAS'][ns+'mpn']=='PWR128-101','Native XMLWriter RSS contains confirmed identifiers')
    check(initialfeed['10171 FAS'][ns+'identifier_exists']=='yes','Feed retains assigned-identifier status')
    for route in ['/products','/api/products.php','/index.php']:
        output=request(route); output=json.loads(output)['html'] if route.startswith('/api/') else output
        check('data-manufacturer="Wiseco"' in output and '<strong>Model / part number:</strong> WE437724' not in output and '<strong>Mfg:</strong> EPI' not in output,route+' rendered cards agree with detail/feed/schema')
    for query in ['manufacturer=Wiseco','model=PWR128-101','search=PWR128-101']:
        output=json.loads(request('/api/products.php?'+query))
        check('data-id="6305"' in output['html'] and 'data-manufacturer="Wiseco"' in output['html'],'Catalog API reviewed filter/search: '+query)
    catalog=request('/products')
    check('value="Wiseco"' in catalog and 'value="PWR128-101"' in catalog and 'WE437724' not in catalog,'Catalog dropdowns agree with reviewed identifiers')
    for id in [6419,6223,6221]:
        body=product(id); policy=schema(body)['offers']['hasMerchantReturnPolicy']
        check(policy=={'@type':'MerchantReturnPolicy','applicableCountry':'US','returnPolicyCategory':'https://schema.org/MerchantReturnNotPermitted'},str(id)+' schema has final sale with no return days, methods, fees')
        check('Final Sale' in body and 'Returns are not accepted for this item.' in body and '30-Day Return Policy' not in body,str(id)+' visible badge agrees with existing NO RETURNS description')
        check('NO RETURNS' in initialfeed[identities[id]]['description'],str(id)+' feed preserves final-sale source description')
    control=product(6306); check('30-Day Return Policy' in control and schema(control)['offers']['hasMerchantReturnPolicy']['merchantReturnDays']==30,'Unrelated standard policy remains unchanged')
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT * FROM products ORDER BY id').fetchall()==original,'Rendering preserves every raw product column including import provenance')
        db.execute("UPDATE products SET manufacturer='',model='',description='Synthetic refreshed import notes' WHERE id=6305")
        db.execute("UPDATE products SET description='Synthetic refreshed import notes' WHERE id IN (6419,6223,6221)")
        refreshed=db.execute('SELECT * FROM products ORDER BY id').fetchall()
    check('>Wiseco</td>' in product(6305) and feed()['10171 FAS'][ns+'identifier_exists']=='yes','Identity correction survives import refresh with blank raw identifiers')
    for id in [6419,6223,6221]:check('Final Sale' in product(id),'Confirmed policy survives description refresh for '+str(id))
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT * FROM products ORDER BY id').fetchall()==refreshed,'Import-refreshed source records remain untouched')
        db.execute("UPDATE products SET sku=sku || '-CHANGED' WHERE id<>6306")
        changed=db.execute('SELECT * FROM products ORDER BY id').fetchall()
    changed_catalog=json.loads(request('/fixture-growth-catalog'))
    check(changed_catalog[0]['manufacturer']=='','Saved-cart catalog withholds identifiers on changed SKU')
    check(changed_catalog[1]==restored_catalog[1],'Unrelated saved-cart payload is unchanged after target refreshes')
    wrong=product(6305); check('>Wiseco</td>' not in wrong and 'brand' not in schema(wrong) and 'mpn' not in schema(wrong),'Changed SKU cannot inherit confirmed identifiers')
    for id in [6419,6223,6221]:
        body=product(id); check('hasMerchantReturnPolicy' not in schema(body)['offers'] and 'Final Sale' not in body and '30-Day Return Policy' not in body,'Changed SKU safely withholds unverified return policy for '+str(id))
    check(schema(product(6306))==schema(control),'Unrelated detail schema is identical through target import and SKU refreshes')
    check(feed()['SYNTHETIC-CONTROL']==initialfeed['SYNTHETIC-CONTROL'],'Unrelated RSS item is identical')
    with sqlite3.connect(DB) as db:check(db.execute('SELECT * FROM products ORDER BY id').fetchall()==changed,'No runtime path mutates changed-identity records')
    print(json.dumps({'checks':len(checks),'passed':checks,'fixture':str(BASE),'scope':'local synthetic fixtures only','production_verified':False},indent=2))
finally:
    server.terminate();server.wait(timeout=10);log.close()
