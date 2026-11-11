"""Forward-compatible emergency withdrawal against synthetic local PHP/SQLite only.

Current-main regression baseline: c947aea8dedf69edfeac07de754ebe1f4adebe74.
This pinned source is not a production preimage or deployment authorization.

Restores exactly the pinned current-main admin/products.php in a disposable copy,
retaining current price/sync/cart/wallet/feed code and current data. This is not
a host/OPcache/worker/Apache/provider acceptance test or a polished rollback UI.
Usage: python tests/website-price-rollback-http-test.py [pinned-main-admin-preimage] [pinned-main-model-preimage]
The default preimage is bundled pinned-main-baseline/admin/products.php, or
the sibling ../pinned-main-baseline/admin/products.php in the task workspace. It must
match the pinned current-main SHA256; a live/customer backup must never be used.
"""
from pathlib import Path
from contextlib import closing
import hashlib
import html
import http.cookiejar
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
BUNDLED_PREIMAGE = ROOT / 'pinned-main-baseline/admin/products.php'
PREIMAGE = Path(sys.argv[1]) if len(sys.argv) > 1 else (BUNDLED_PREIMAGE if BUNDLED_PREIMAGE.exists() else ROOT.parent / 'pinned-main-baseline/admin/products.php')
MODEL_PREIMAGE = Path(sys.argv[2]) if len(sys.argv) > 2 else PREIMAGE.parents[1] / 'src/models/Product.php'
PINNED_MAIN_ADMIN_SHA256 = '953e876b663518046ad347dff44d145ca939cae9e8b71f0280c6adf82c7b85e3'
PINNED_MAIN_MODEL_SHA256 = '5f1ce6c1e5119e53db7158ca3b92bfa4378c57c1bb8e9da5bf74f79e5e69ae9c'
if not PREIMAGE.is_file() or hashlib.sha256(PREIMAGE.read_bytes()).hexdigest() != PINNED_MAIN_ADMIN_SHA256:
    raise RuntimeError('Supply the exact pinned current-main admin preimage; no live backup is accepted.')

BASE = Path(tempfile.mkdtemp(prefix='fas-price-rollback-', dir=ROOT.parent))
SITE = BASE / 'site'
SITE.mkdir()
DB = SITE / 'database/fixture.sqlite'
SESSIONS = BASE / 'sessions'
SESSIONS.mkdir()
PHP_OPTIONS = ['-d', 'allow_url_fopen=0', '-d', 'opcache.enable=0', '-d',
               'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
               '-d', 'session.save_path=' + str(SESSIONS)]
checks = []
server = None
log = None


def check(ok, message):
    if not ok:
        raise AssertionError(message)
    checks.append(message)


def php(script, *args):
    result = subprocess.run(['php', *PHP_OPTIONS, str(script), *map(str, args)],
                            capture_output=True, text=True, timeout=30, env=env)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result.stdout


def rows(table):
    with closing(sqlite3.connect(DB)) as db:
        db.row_factory = sqlite3.Row
        return [dict(row) for row in db.execute('SELECT * FROM ' + table + ' ORDER BY 1')]


def state():
    return {table: rows(table) for table in ['products', 'website_price_source',
            'website_price_history', 'website_price_meta', 'product_sync_ownership',
            'product_sync_ownership_history', 'orders', 'order_items', 'applepay_attempts', 'order_shipping']}


env = {key: value for key, value in os.environ.items() if not key.startswith('FAS_')}
env.update(FAS_SECURITY_DB_PATH=str(BASE / 'private/security.sqlite'),
           FAS_GROWTH_DB_PATH=str(BASE / 'private/growth.sqlite'), ANALYTICS_IP_GEO_ENABLED='0')

try:
    for folder in ['src', 'admin', 'includes', 'public', 'api']:
        shutil.copytree(ROOT / folder, SITE / folder, ignore=shutil.ignore_patterns(
            'config.php', 'config.local.php', 'config.production.php', 'shipping.php',
            'applepay.php', 'googlepay.php', '*.db', '*.sqlite', '*.log', 'uploads', 'backups', 'exports'))
    (SITE / 'database').mkdir()
    # The config-name exclusion also matches this public controller; restore it.
    shutil.copy2(ROOT / 'api/googlepay.php', SITE / 'api/googlepay.php')
    shutil.copy2(ROOT / 'google-merchant-feed.php', SITE / 'google-merchant-feed.php')
    shutil.copy2(ROOT / '.htaccess', SITE / '.htaccess')
    alias = re.search(r'RewriteRule \^google-merchant-feed\\\.txt\$ ([\w.-]+) \[L\]',
                      (SITE / '.htaccess').read_text())
    check(alias and alias[1] == 'google-merchant-feed.php', 'Retained alias names the dynamic feed controller')
    router = BASE / 'router.php'
    router.write_text("<?php if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/google-merchant-feed.txt'){"
                      "require $_SERVER['DOCUMENT_ROOT'].'/google-merchant-feed.php';return true;}return false;")
    (SITE / 'src/config/config.php').write_text("""<?php
return ['database'=>['path'=>__DIR__.'/../../database/fixture.sqlite'],
 'sale'=>['enabled'=>true,'type'=>'percentage','value'=>50],
 'site'=>['timezone'=>'UTC'],'paypal'=>['mode'=>'live']];
""")  # 'live' only exercises the public-wallet gate; transport below is always a file-backed fake.
    (SITE / 'src/config/googlepay.php').write_text("<?php return ['enabled'=>true,'admin_only'=>false];")
    (SITE / 'admin/auth.php').write_text("""<?php
session_start();
class AdminAuth {public function requireLogin(){
 if(($_SERVER['HTTP_X_FIXTURE_AUTH']??'')!=='yes'){http_response_code(401);exit;}
 $_SESSION['admin_id']=9001;
}}
""")
    (SITE / 'admin/includes/nav.php').write_text('')
    (SITE / 'admin/includes/footer.php').write_text('')
    (SITE / 'src/integrations/EbayAPI.php').write_text(r"""<?php namespace FAS\Integrations;
class EbayAPI {public function __construct(){throw new \RuntimeException('External integration forbidden in fixture');}}
""")
    # The actual endpoint/context/service remain; only provider factory/transport is replaced.
    (SITE / 'src/payments/ApplePayFactory.php').write_text(r'''<?php
namespace FAS\Payments;
require_once __DIR__.'/ApplePayContext.php';
require_once __DIR__.'/WalletPayPalClient.php';
require_once __DIR__.'/ApplePayService.php';
require_once __DIR__.'/../models/Product.php';
require_once __DIR__.'/../config/Database.php';
require_once __DIR__.'/../../includes/sale-helper.php';
class RollbackFixtureGateway implements WalletPayPalGateway {
 private function path($id){return __DIR__.'/../../'.$id.'.json';}
 private function record($method){file_put_contents(__DIR__.'/../../mock-gateway.log',$method."\n",FILE_APPEND);}
 public function create(array $p,string $key):array {
  $this->record('create');$id='ORDER'.strtoupper(substr(hash('sha256',$key),0,12));
  if(is_file($this->path($id)))return $this->get($id);
  $p['id']=$id;$p['status']='CREATED';$p['purchase_units'][0]['payee']=['merchant_id'=>'FIXTURE'];
  file_put_contents($this->path($id),json_encode($p));return $p;
 }
 public function get(string $id):array {$this->record('get');return json_decode(file_get_contents($this->path($id)),true);}
 public function capture(string $id,string $key):array {throw new \RuntimeException('Capture forbidden in rollback fixture');}
}
class ApplePayFactory {
 public static function make(string $wallet='applepay'):ApplePayService {
  return new ApplePayService(\FAS\Config\Database::getInstance()->getConnection(),new RollbackFixtureGateway(),
   fn($p)=>getProductEffectivePrice($p)['effective_price'],fn()=>0,'live',fn()=>['mode'=>'easyship'],$wallet);
 }
}
''')
    with closing(sqlite3.connect(DB)) as db:
        db.executescript((ROOT / 'database/schema.sqlite.sql').read_text())
        db.executescript((ROOT / 'database/applepay.sql').read_text())
        db.execute("""INSERT INTO products(id,ebay_item_id,name,description,sku,price,sale_price,quantity,
                    category,manufacturer,model,condition_name,weight,length,width,height,source,show_on_website,images)
                    VALUES(1,'QA-ROLLBACK','Fixture title','Fixture text','QA-SKU',100,80,20,
                    'motorcycle','Fixture brand','Fixture model','Used',1,2,3,4,'ebay',1,'[]')""")
        db.execute("""INSERT INTO orders(id,order_number,customer_email,subtotal,total_amount,payment_status)
                    VALUES(1,'SYNTHETIC-EXISTING','existing@example.invalid',75,84,'completed')""")
        db.execute("""INSERT INTO order_items(id,order_id,product_id,product_name,quantity,unit_price,total_price)
                    VALUES(1,1,1,'Historical fixture',1,75,75)""")
        db.commit()
    driver = BASE / 'fixture-cli.php'
    driver.write_text(r'''<?php
require $argv[1].'/src/models/Product.php';
$db=new PDO('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($argv[3]==='init'){
 \FAS\Utils\ProductSyncOwnership::install($db);\FAS\Utils\WebsitePrice::install($db);
}
$model=new \FAS\Models\Product($db);
$model->syncFromEbay(['id'=>'QA-ROLLBACK','price'=>$argv[4],'currency'=>'USD',
 'title'=>'Imported title '.$argv[4],'description'=>'Imported description '.$argv[4],
 'quantity'=>(int)$argv[5],'weight'=>1,'length'=>2,'width'=>3,'height'=>4]);
''')
    php(driver, SITE, DB, 'init', '110.00', '21')
    (SITE / 'wallet-seed.php').write_text(r'''<?php
require __DIR__.'/src/payments/ApplePayContext.php';
$token=\FAS\Payments\ApplePayContext::csrf();
$_SESSION['fas_applepay_quotes'][str_repeat('c',32)]=['cart'=>[1=>1],
 'address'=>['address1'=>'100 Test Road','address2'=>'','city'=>'Test City','state'=>'CA','zip'=>'90001','country'=>'US'],
 'rates'=>[['total_charge'=>5,'courier_id'=>123,'courier_name'=>'Fixture','service_name'=>'Ground','provider'=>'easyship']],
 'expires'=>time()+600];
header('Content-Type: application/json');echo json_encode(['csrf'=>$token]);
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    log = (BASE / 'server.log').open('w')
    server = subprocess.Popen(['php', *PHP_OPTIONS, '-S', f'127.0.0.1:{port}', '-t', str(SITE), str(router)],
                              stdout=log, stderr=log, env=env)
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args):
            raise AssertionError('Fixture must not redirect or contact another origin')

    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect,
              urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(path='/admin/products.php?action=edit&id=1', fields=None, json_body=None):
        assert path.startswith('/') and not path.startswith('//') and '\\' not in path
        headers = {'X-Fixture-Auth': 'yes'}
        data = None
        if fields is not None:
            data = urllib.parse.urlencode(fields, doseq=True).encode()
        if json_body is not None:
            data = json.dumps(json_body).encode()
            headers.update({'Content-Type': 'application/json', 'X-CSRF-Token': wallet_csrf})
        try:
            response = client.open(urllib.request.Request(origin + path, data=data, headers=headers), timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode()

    def field(body, name):
        match = re.search(r'name="' + name + r'" value="([^"]*)"', body)
        if not match:
            raise AssertionError('Missing editor field ' + name)
        return html.unescape(match[1])

    def product_form(**extra):
        code, editor = request()
        check(code == 200, 'Restored editor loads for a protected synthetic product')
        p = rows('products')[0]
        data = {key: p[key] or '' for key in ['name', 'description', 'sku', 'price', 'sale_price',
                'quantity', 'category', 'manufacturer', 'model', 'condition_name', 'weight',
                'length', 'width', 'height', 'image_url', 'source']}
        data.update(action='update', product_id='1', csrf_token=field(editor, 'csrf_token'),
                    edit_snapshot=field(editor, 'edit_snapshot'), show_on_website='1')
        data.update(extra)
        return data

    def verify_consumers():
        code, body = request('/api/product-check.php?id=1')
        check(code == 200 and json.loads(body)['unit_price_cents'] == 6025,
              'Retained cart endpoint still serves the active override')
        code, direct = request('/google-merchant-feed.php')
        alias_code, alias_body = request('/google-merchant-feed.txt')
        check(code == alias_code == 200 and direct == alias_body,
              'Retained dynamic feed and fixture alias agree after admin withdrawal')
        item = ET.fromstring(direct).find('channel/item')
        ns = {'g': 'http://base.google.com/ns/1.0'}
        check(item.findtext('g:price', namespaces=ns) == '60.25 USD'
              and item.findtext('g:sale_price', namespaces=ns) is None,
              'Feed bypasses retained product/site sales for the active override')

    for _ in range(50):
        if server.poll() is not None:
            raise RuntimeError('Fixture PHP server exited')
        try:
            code, body = request()
            break
        except OSError as error:
            last_error = error
            time.sleep(.1)
    else:
        raise RuntimeError('Fixture PHP server unavailable: ' + str(last_error))
    check(code == 200 and 'price_snapshot' in body, 'Candidate override controls available before withdrawal')
    price_post = dict(action='website_price', product_id='1', csrf_token=field(body, 'csrf_token'),
                      price_snapshot=field(body, 'price_snapshot'), price_action='override',
                      website_amount='60.25', website_currency='USD', confirm_website_price='1')
    code, body = request(fields=price_post)
    check(code == 200 and 'Price source saved' in body, 'Actual candidate handler enables a synthetic override')
    code, body = request('/wallet-seed.php')
    wallet_csrf = json.loads(body)['csrf']
    wallet = {'action': 'create', 'attempt_id': 'a' * 32,
              'items': [{'product_id': '1', 'quantity': 1, 'unit_price': '60.25'}],
              'email': 'test@example.invalid', 'first_name': 'Test', 'last_name': 'Buyer',
              'address': {'address1': '100 Test Road', 'address2': '', 'city': 'Test City',
                          'state': 'CA', 'zip': '90001', 'country': 'US'},
              'shipping_quote': 'c' * 32, 'shipping_index': 0, 'expected_total': '65.25'}
    code, body = request('/api/googlepay.php', json_body=wallet)
    created = json.loads(body)
    check(code == 200 and created['state'] == 'created' and created['amount'] == '65.25',
          'New-format wallet attempt created through actual API with file-backed fake provider: ' + body)
    before = state()
    protected = {key: before[key] for key in ['website_price_source', 'website_price_history',
                  'website_price_meta', 'orders', 'order_items', 'applepay_attempts', 'order_shipping']}
    check(len(before['website_price_history']) == 1 and len(before['orders']) == 2,
          'Rehearsal starts after explicit audited price edit and new immutable wallet order')

    # Keep paired cart files and persisted accepted overlay through withdrawal.
    cart_storage = BASE / 'cart-storage.json'
    cart_driver = BASE / 'cart-fixture.cjs'
    cart_driver.write_text(r'''
const fs=require('fs'),vm=require('vm'),path=require('path'),assert=require('assert/strict');
const site=process.argv[2],file=process.argv[3],phase=process.argv[4];
const pricing=require(path.join(site,'public/js/cart-pricing.js'));
const base=JSON.stringify([{id:'1',name:'Synthetic',quantity:1,price:100,stock:21}]);
const values=phase==='before'?{'flipandstrip_cart':base}:JSON.parse(fs.readFileSync(file,'utf8'));
const storage={getItem:key=>values[key]??null,setItem:(key,value)=>values[key]=String(value)};
const context={localStorage:storage,document:{getElementById:()=>null,dispatchEvent(){}},CustomEvent:class{},window:{}};
vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(site,'public/js/main.js'),'utf8').split('// Initialize cart')[0]+'\nglobalThis.TestCart=ShoppingCart;',context);
(async()=>{const cart=new context.TestCart();if(phase==='before'){
 const changes=await pricing.changes(cart.cart,async()=>({exists:true,active:true,unit_price_cents:6025}));
 pricing.accept(cart,changes,pricing.capture(cart,storage),storage);fs.writeFileSync(file,JSON.stringify(values));
}assert.equal(new context.TestCart().cart[0].price,60.25);assert.equal(new context.TestCart().cart[0].quantity,1);
assert.equal(values.flipandstrip_cart,base);assert.ok(values.flipandstrip_cart_price_acceptance_v1);console.log('accepted overlay retained');})();
''')

    def cart_check(phase):
        result = subprocess.run(['node', str(cart_driver), str(SITE), str(cart_storage), phase],
                                capture_output=True, text=True, timeout=15)
        check(result.returncode == 0, 'Current cart reader retains accepted overlay ' + phase + ' admin withdrawal: ' + result.stderr)

    cart_check('before')
    retained_files = ['src/models/Product.php', 'src/utils/WebsitePrice.php', 'src/utils/ProductSyncOwnership.php',
                      'src/payments/ApplePayService.php', 'src/payments/ApplePayContext.php',
                      'includes/sale-helper.php', 'public/js/main.js', 'public/js/cart-pricing.js',
                      'cart.php', 'checkout.php', 'api/product-check.php', '.htaccess']
    # cart.php/checkout.php are copied too, without rendering checkout or creating any real payment.
    for name in ['cart.php', 'checkout.php']:
        shutil.copy2(ROOT / name, SITE / name)
    runtime_hashes = {name: hashlib.sha256((SITE / name).read_bytes()).hexdigest() for name in retained_files}
    shutil.copyfile(PREIMAGE, SITE / 'admin/products.php')
    check(hashlib.sha256((SITE / 'admin/products.php').read_bytes()).hexdigest() == PINNED_MAIN_ADMIN_SHA256,
          'Only admin/products.php restored to its exact pinned current-main bytes')
    check(state() == before, 'File-only emergency withdrawal preserves all synthetic DB rows exactly')
    check(all(hashlib.sha256((SITE / name).read_bytes()).hexdigest() == digest
              for name, digest in runtime_hashes.items()), 'Price/sync/cart/wallet/feed dependency files remain byte-identical')
    code, body = request()
    check(code == 200 and 'name="price_snapshot"' not in body and 'name="price_action"' not in body,
          'Separate override controls withdrawn from restored pinned current-main admin: status=' + str(code)
          + ', price_snapshot=' + str('name="price_snapshot"' in body)
          + ', price_action=' + str('name="price_action"' in body))
    check('Price and stock still follow eBay.' in body and 'name="price"' in body,
          'Known emergency UI limitation captured: legacy price input and eBay wording return')
    code, _ = request(fields=dict(price_post, csrf_token=field(body, 'csrf_token'), website_amount='1.00'))
    check(code == 200 and state() == before, 'Restored legacy switch ignores old override POST without changing rows')
    for changed in [dict(price='1.00'), dict(sale_price='1.00')]:
        code, _ = request(fields=product_form(name='Must roll back', **changed))
        check(code == 409 and state() == before,
              'Legacy general-save cannot change imported price/sale or partially save text/ownership')

    code, body = request(fields=product_form(name='Website protected after withdrawal'))
    check(code == 200 and 'Product updated successfully' in body,
          'Normal text save through exact pinned current-main admin remains available')
    check(rows('products')[0]['name'] == 'Website protected after withdrawal'
          and rows('products')[0]['price'] == 60.25, 'Normal text save preserves active price override')
    php(driver, SITE, DB, 'sync', '120.00', '22')
    php(driver, SITE, DB, 'sync', '120.00', '23')
    php(driver, SITE, DB, 'sync', '130.00', '24')
    p = rows('products')[0]
    check(p['price'] == 60.25 and p['website_override_cents'] == 6025 and p['quantity'] == 24,
          'Repeated source changes after withdrawal retain override and advance inventory')
    check(p['name'] == 'Website protected after withdrawal' and p['description'] == 'Fixture text',
          'Pinned current-main manual title and description protection survives repeated source sync')
    check(rows('website_price_source')[0]['amount_cents'] == 13000
          and rows('website_price_source')[0]['revision'] == 4,
          'Separate latest source observation advances instead of rolling back')
    check(rows('website_price_history') == protected['website_price_history'],
          'Price audit history remains exact and sync creates no inferred override audit')
    code, body = request(fields=product_form(**{'release_ebay_fields[]': ['name'], 'confirm_release_ebay': '1'}))
    check(code == 200 and 'Product updated successfully' in body,
          'Pinned current-main text-return action still accepted by restored admin')
    check(rows('products')[0]['name'] == 'Website protected after withdrawal',
          'Text return applies on next sync rather than changing text retroactively')
    php(driver, SITE, DB, 'sync', '140.00', '25')
    p = rows('products')[0]
    check(p['name'] == 'Imported title 140.00' and p['description'] == 'Fixture text'
          and p['price'] == 60.25 and p['quantity'] == 25,
          'Next source sync releases only selected title and preserves override/description/quantity rules')
    verify_consumers()
    cart_check('after')

    code, body = request('/api/googlepay.php', json_body=wallet)
    retried = json.loads(body)
    check(code == 200 and retried['paypal_order_id'] == created['paypal_order_id']
          and retried['amount'] == '65.25', 'New-format wallet create retry recovers exact immutable attempt after withdrawal/sync')
    code, body = request('/api/googlepay.php', json_body={'action': 'status', 'attempt_id': 'a' * 32})
    check(code == 200 and json.loads(body)['paypal_order_id'] == created['paypal_order_id'],
          'New-format wallet status recovery remains available')
    missing_price = dict(wallet, items=[{'product_id': '1', 'quantity': 1}])
    code, body = request('/api/googlepay.php', json_body=missing_price)
    check(code == 409 and json.loads(body)['code'] == 'changed_checkout',
          'New-format attempt still binds accepted per-item price; legacy-shaped retry cannot replace hash')
    after = state()
    check(after['orders'] == protected['orders'] and after['order_items'] == protected['order_items']
          and after['order_shipping'] == protected['order_shipping'],
          'Historical and newly created order amounts/shipping snapshots never roll back or reprice')
    check(after['applepay_attempts'][0]['request_hash'] == protected['applepay_attempts'][0]['request_hash']
          and after['applepay_attempts'][0]['payload'] == protected['applepay_attempts'][0]['payload']
          and len(after['applepay_attempts']) == 1,
          'Wallet request hash and immutable payload retained without duplicate attempts')
    gateway_calls = (SITE / 'mock-gateway.log').read_text().splitlines()
    check(gateway_calls.count('create') == 1 and set(gateway_calls) <= {'create', 'get'},
          'Only one fake provider create; recovery uses fake get and no capture/provider network')
    check(rows('website_price_source')[0]['amount_cents'] == 14000
          and rows('website_price_source')[0]['revision'] == 5
          and rows('website_price_history') == protected['website_price_history'],
          'Source advancement and original price audit preserved through all rollback checks')

    # Negative control is a different disposable site/DB, never the active fixture.
    old_model = MODEL_PREIMAGE
    if not old_model.is_file() or hashlib.sha256(old_model.read_bytes()).hexdigest() != PINNED_MAIN_MODEL_SHA256:
        raise RuntimeError('Exact pinned current-main Product preimage required for disposable negative control')
    negative = BASE / 'old-model-negative-control'
    shutil.copytree(SITE, negative)
    shutil.copyfile(old_model, negative / 'src/models/Product.php')
    negative_db = negative / 'database/fixture.sqlite'
    php(driver, negative, negative_db, 'sync', '199.00', '26')
    with closing(sqlite3.connect(negative_db)) as db:
        broken = db.execute('SELECT price,website_override_cents FROM products WHERE id=1').fetchone()
        stale_source = db.execute('SELECT amount_cents,revision FROM website_price_source WHERE product_id=1').fetchone()
    check(broken == (199.0, 6025) and stale_source == (14000, 5),
          'Disposable old-model negative control loses override materialization and leaves source stale')
    check(rows('products')[0]['price'] == 60.25, 'Negative-control damage cannot affect retained-runtime fixture')
    print(json.dumps({'passed': len(checks), 'checks': checks,
        'scope': 'synthetic task-local SQLite; actual localhost admin/cart-feed/wallet HTTP; fake auth/chrome/provider',
        'not_covered': ['live host state, ownership/permissions, backups or restore',
                        'in-flight requests, cron drain, OPcache, atomic deployment or database lock latency',
                        'Apache/nginx/CDN rewrite and cache behavior (router only maps parsed alias)',
                        'real authentication, browser UI, customer data, shipping provider or payment provider/capture',
                        'wallet recovery after an effective catalog-price change (source changes retain the same override here)',
                        'whole runtime rollback (negative control replaces only exact pinned current-main Product model)',
                        'fully coherent emergency admin wording (exact preimage has known misleading legacy text)']}, indent=2))
finally:
    if server is not None:
        server.terminate()
        server.wait(timeout=10)
    if log is not None:
        log.close()
    if (BASE / 'server.log').exists():
        (ROOT.parent / 'website-price-rollback-http-server.log').write_text((BASE / 'server.log').read_text())
    # Delete only this resolved, task-local synthetic fixture. No source/preimage is touched.
    assert BASE.resolve().parent == ROOT.parent.resolve() and BASE.name.startswith('fas-price-rollback-')
    shutil.rmtree(BASE)
