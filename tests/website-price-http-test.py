"""Actual admin price handler against a disposable local copy; auth/chrome stubbed.
No live login, APIs, browser, host, database, payment or network acceptance claimed.
"""
from pathlib import Path
import http.cookiejar, html, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.parse, urllib.error
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[1]
BASE=Path(tempfile.mkdtemp(prefix='fas-price-http-',dir=ROOT.parent))
SITE=BASE/'site'; SITE.mkdir()
for folder in ['src','admin','includes','public','api']:
    shutil.copytree(ROOT/folder,SITE/folder,ignore=shutil.ignore_patterns('config.php','*.db','*.sqlite','*.log','uploads','backups','exports'))
(SITE/'database').mkdir(); DB=SITE/'database/fixture.sqlite'
shutil.copy2(ROOT/'google-merchant-feed.php',SITE/'google-merchant-feed.php')
# Exercise the target PHP controller using the alias parsed from the actual Apache rule.
# PHP's development router is not Apache: host rewrite acceptance is still required.
feed_alias=re.search(r'RewriteRule \^google-merchant-feed\\\.txt\$ ([\w.-]+) \[L\]',(ROOT/'.htaccess').read_text())
assert feed_alias and feed_alias[1]=='google-merchant-feed.php'
router=SITE/'fixture-router.php'
router.write_text("<?php if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/google-merchant-feed.txt'){require __DIR__.'/"+feed_alias[1]+"';return true;} return false;")
(SITE/'src/config/config.php').write_text("<?php return ['database'=>['path'=>__DIR__.'/../../database/fixture.sqlite'],'sale'=>['enabled'=>true,'type'=>'percentage','value'=>50],'site'=>['timezone'=>'UTC']];")
(SITE/'admin/auth.php').write_text("""<?php
session_start();
class AdminAuth { public function requireLogin() {
 if (($_SERVER['HTTP_X_FIXTURE_AUTH']??'')!=='yes') {http_response_code(401);exit;}
 $_SESSION['admin_id']=9001;
}}
""")
(SITE/'admin/includes/nav.php').write_text('')
(SITE/'admin/includes/footer.php').write_text('')
(SITE/'includes/security.php').write_text('<?php function fas_security_guard(...$args) {}')
# Stub potential integration entry points, with no usable network capability.
(SITE/'src/integrations/EbayAPI.php').write_text("<?php namespace FAS\\Integrations; class EbayAPI { public function __construct(){throw new \\RuntimeException('Network integration forbidden in fixture');}}")
with sqlite3.connect(DB) as db:
    db.executescript((ROOT/'database/schema.sqlite.sql').read_text())
    db.execute("INSERT INTO products(id,ebay_item_id,name,description,sku,price,sale_price,quantity,category,weight,length,width,height,source,show_on_website) VALUES(1,'QA-1','Fixture title','Fixture text','QA-SKU',100,80,5,'motorcycle',1,2,3,4,'ebay',1)")
db.close()
bootstrap=BASE/'bootstrap.php'
bootstrap.write_text("""<?php
require $argv[1].'/src/models/Product.php';
$db=new PDO('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
\\FAS\\Utils\\ProductSyncOwnership::install($db);
\\FAS\\Utils\\WebsitePrice::install($db);
$p=new \\FAS\\Models\\Product($db);
$p->syncFromEbay(['id'=>'QA-1','price'=>'110.00','currency'=>'USD','title'=>'Imported title','quantity'=>6,'weight'=>1,'length'=>2,'width'=>3,'height'=>4]);
""")
subprocess.run(['php',str(bootstrap),str(SITE),str(DB)],check=True,capture_output=True)
# Small fixture endpoint exercises the actual shared checkout/feed/schema classes.
(SITE/'agreement.php').write_text("""<?php
require __DIR__.'/src/models/Product.php';
require __DIR__.'/src/utils/MerchantFeedBuilder.php';
require __DIR__.'/src/payments/CheckoutPricing.php';
$db=new PDO('sqlite:'.__DIR__.'/database/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$model=new \\FAS\\Models\\Product($db);$p=$model->getById(1);$info=getProductEffectivePrice($p);$unit=$info['effective_price'];
$input=['items'=>[['product_id'=>1,'quantity'=>1,'unit_price'=>$unit]],'subtotal'=>$unit,'tax_amount'=>0,'discount_amount'=>0,'total_amount'=>$unit+9];
$feed=(new \\FAS\\Utils\\MerchantFeedBuilder($model))->buildItem($p);
$schema=\\FAS\\Utils\\Seo::productSchema($p,[],$info,'https://example.invalid/product','Fixture');
header('Content-Type: application/json');echo json_encode(['display'=>$unit,'feed'=>$feed,'schema'=>$schema,'checkout'=>\\FAS\\Payments\\CheckoutPricing::calculate($input,[1=>1],$model,900)]);
""")
with socket.socket() as sock: sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
origin=f'http://127.0.0.1:{port}'; log=open(BASE/'server.log','w')
server=subprocess.Popen(['php','-d','allow_url_fopen=0','-d','disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client','-S',f'127.0.0.1:{port}','-t',str(SITE),str(router)],stdout=log,stderr=log)
client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks=[]
def request(path='/admin/products.php?action=edit&id=1',fields=None,auth=True):
    headers={'X-Fixture-Auth':'yes'} if auth else {}
    data=urllib.parse.urlencode(fields).encode() if fields is not None else None
    try: response=client.open(urllib.request.Request(origin+path,data=data,headers=headers),timeout=10)
    except urllib.error.HTTPError as e: response=e
    with response: return response.status,response.read().decode()
def check(ok,message):
    if not ok: raise AssertionError(message)
    checks.append(message)
def fields(body):
    return {k:html.unescape(re.search(r'name="'+k+r'" value="([^"]*)"',body)[1]) for k in ['csrf_token','price_snapshot']}
def state():
    db=sqlite3.connect(DB)
    try: return (db.execute('SELECT * FROM products').fetchall(),db.execute('SELECT * FROM website_price_history').fetchall())
    finally: db.close()
def verify_feeds(expected_price,expected_sale):
    direct_status,direct=request('/google-merchant-feed.php')
    alias_status,alias=request('/google-merchant-feed.txt')
    check(direct_status==alias_status==200 and direct==alias,'Legacy alias and dynamic PHP controller emit identical current XML')
    item=ET.fromstring(alias).find('channel/item');ns={'g':'http://base.google.com/ns/1.0'}
    check(item.findtext('g:price',namespaces=ns)==expected_price and item.findtext('g:sale_price',namespaces=ns)==expected_sale,'Actual XML feed uses expected effective override/source pricing')
try:
    for _ in range(50):
        if server.poll() is not None: raise RuntimeError('Fixture server exited')
        try: status,body=request();break
        except OSError as e: last_error=e;time.sleep(.1)
    else: raise RuntimeError('Local server unavailable: '+str(last_error))
    check(status==200 and 'Latest received eBay price' in body and '$110.00 USD' in body,'Actual editor shows observed source and both price controls')
    check('Stock follows eBay' in body and 'Product and site sales do not reduce it' in body,'Editor explains ownership and discount rule')
    initial=state(); token=fields(body)
    data=dict(token,action='website_price',product_id='1',price_action='override',website_amount='60.25',website_currency='USD',confirm_website_price='1')
    status,_=request(fields=data,auth=False);check(status==401 and state()==initial,'Unauthenticated handler cannot mutate')
    bad=dict(data,csrf_token='bad');status,_=request(fields=bad);check(status==403 and state()==initial,'Invalid CSRF cannot mutate')
    bad=dict(data,confirm_website_price='0');status,_=request(fields=bad);check(status==409 and state()==initial,'Missing deliberate confirmation cannot mutate')
    bad=dict(data,website_amount='1.001');status,_=request(fields=bad);check(status==409 and state()==initial,'Handler rejects excess precision')
    status,body=request(fields=data);check(status==200 and 'Price source saved' in body,'Actual handler saves override')
    saved=state();check(saved[0][0]!=initial[0][0] and len(saved[1])==1,'One explicit audit row written')
    status,_=request(fields=data);check(status==409 and state()==saved,'Repeated stale POST cannot replay')
    _,body=request(); fresh=fields(body)
    check('Website override</strong>' in body and '$60.25 USD' in body,'Editor shows active override')
    status,body=request('/agreement.php');agreement=json.loads(body)
    check(status==200 and agreement['display']==60.25 and agreement['feed']['price']=='60.25 USD' and agreement['schema']['offers']['price']=='60.25' and agreement['checkout']['items'][0]['unit_price']==60.25,'Shared display/feed/schema/checkout agree under active 50 percent global sale')
    status,body=request('/api/product-check.php?id=1');catalog=json.loads(body)
    check(status==200 and catalog['active'] and catalog['unit_price_cents']==6025,'Actual cart catalog endpoint agrees with override')
    verify_feeds('60.25 USD',None)
    status,body=request(fields=dict(data,**fresh,price_action='source'))
    check(status==200 and 'eBay price with existing sale rules' in body,'Return to eBay takes effect immediately in actual handler')
    _,body=request('/agreement.php');agreement=json.loads(body)
    check(agreement['display']==55 and agreement['checkout']['items'][0]['unit_price']==55 and agreement['feed']['sale_price']=='55.00 USD' and agreement['schema']['offers']['price']=='55.00','Return restores existing global sale consistently across consumers')
    status,body=request('/api/product-check.php?id=1');check(status==200 and json.loads(body)['unit_price_cents']==5500,'Actual cart catalog endpoint agrees after return')
    verify_feeds('110.00 USD','55.00 USD')
    status,_=request('/unmapped-legacy-feed.txt');check(status==404,'Unmapped static-feed negative control does not serve stale prices')
    print(f'PASS {len(checks)} actual price-handler HTTP assertions; fixture auth/chrome, local-only server, network functions disabled.')
finally:
    server.terminate();server.wait(timeout=10);log.close()
    (ROOT.parent/'website-price-http-server.log').write_text((BASE/'server.log').read_text())
    # Delete only this verified task-local disposable directory.
    assert BASE.resolve().parent == ROOT.parent.resolve() and BASE.name.startswith('fas-price-http-')
    shutil.rmtree(BASE)
