"""Disposable PHP/SQLite endpoint checks. Provider transport is replaced only in the temp copy."""
from pathlib import Path
import json, os, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix='fas-googlepay-http-'))
SITE = BASE / 'site'
SITE.mkdir()
for folder in ['src', 'includes', 'api']:
    shutil.copytree(ROOT/folder, SITE/folder, ignore=shutil.ignore_patterns(
        'config.php','config.local.php','config.production.php','shipping.php','applepay.php','googlepay.php','*.db','*.sqlite','*.log'))
shutil.copy2(ROOT/'api/googlepay.php', SITE/'api/googlepay.php')
(SITE/'database').mkdir()
with sqlite3.connect(SITE/'database/flipandstrip.db') as db:
    db.executescript((ROOT/'tests/fixtures/applepay-schema.sql').read_text())
    db.executescript((ROOT/'database/applepay.sql').read_text())
(SITE/'src/config/config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['paypal']['mode']='live';return $c;")
(SITE/'src/config/googlepay.php').write_text("<?php return ['enabled'=>!is_file(__DIR__.'/../../disabled'),'admin_only'=>false];")
(SITE/'src/payments/ApplePayFactory.php').write_text(r'''<?php
namespace FAS\Payments;
require_once __DIR__.'/ApplePayContext.php';
require_once __DIR__.'/WalletPayPalClient.php';
require_once __DIR__.'/ApplePayService.php';
require_once __DIR__.'/../config/Database.php';
class GooglePayFixtureGateway implements WalletPayPalGateway {
    private function path($id) { return __DIR__.'/../../'.$id.'.json'; }
    public function create(array $p,string $key):array {
        $id='ORDER'.strtoupper(substr(hash('sha256',$key),0,12));
        if(is_file($this->path($id)))return $this->get($id);
        $p['id']=$id;$p['status']='CREATED';$p['purchase_units'][0]['payee']=['merchant_id'=>'FIXTURE'];
        file_put_contents($this->path($id),json_encode($p));return $p;
    }
    public function get(string $id):array { return json_decode(file_get_contents($this->path($id)),true); }
    public function capture(string $id,string $key):array {
        $p=$this->get($id);$p['status']='COMPLETED';
        $p['purchase_units'][0]['payments']['captures']=[['id'=>'CAPTURE'.$id,'status'=>'COMPLETED','final_capture'=>true,
            'amount'=>array_intersect_key($p['purchase_units'][0]['amount'],['value'=>1,'currency_code'=>1])]];
        file_put_contents($this->path($id),json_encode($p));
        file_put_contents(__DIR__.'/../../captures.txt',$key."\n",FILE_APPEND);return $p;
    }
}
class ApplePayFactory {
    public static function make(string $wallet='applepay'):ApplePayService {
        $db=\FAS\Config\Database::getInstance()->getConnection();
        return new ApplePayService($db,new GooglePayFixtureGateway(),fn($p)=>$p['price'],fn()=>0,'live',
            fn()=>['mode'=>'easyship'],$wallet);
    }
}
''')
(SITE/'seed.php').write_text(r'''<?php
require __DIR__.'/src/payments/ApplePayContext.php';
require __DIR__.'/src/config/Database.php';
require __DIR__.'/src/shipping/ShippingOrder.php';
\FAS\Shipping\ShippingOrder::install(\FAS\Config\Database::getInstance()->getConnection());
$token=\FAS\Payments\ApplePayContext::csrf();
$_SESSION['fas_applepay_quotes'][str_repeat('c',32)]=['cart'=>[1=>2],
    'address'=>['address1'=>'100 Test Road','address2'=>'','city'=>'Test City','state'=>'CA','zip'=>'90001','country'=>'US'],
    'rates'=>[['total_charge'=>5,'courier_id'=>123,'courier_name'=>'Fixture','service_name'=>'Ground','provider'=>'easyship']],
    'expires'=>time()+600];
header('Content-Type: application/json');echo json_encode(['csrf'=>$token]);
''')
env=dict(os.environ, FAS_SECURITY_DB_PATH=str(BASE/'security.sqlite'))
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
base=f'http://127.0.0.1:{port}'
log=(BASE/'server.log').open('w')
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(SITE)],stdout=log,stderr=log,env=env)
count=0
def request(path, data=None, headers=None, method=None):
    raw=None if data is None else json.dumps(data).encode()
    req=urllib.request.Request(base+path,data=raw,headers=headers or {},method=method)
    try: res=urllib.request.urlopen(req,timeout=20)
    except urllib.error.HTTPError as error: res=error
    return res.status,json.loads(res.read()),res.headers
def check(ok, message):
    global count
    if not ok: raise AssertionError(message)
    count+=1
try:
    for _ in range(50):
        try: status, seed, headers=request('/seed.php');break
        except urllib.error.URLError: time.sleep(.1)
    cookie=headers.get('Set-Cookie').split(';')[0]
    auth={'Cookie':cookie,'Content-Type':'application/json','X-CSRF-Token':seed['csrf']}
    check(request('/api/googlepay.php')[0]==405,'GET rejected')
    check(request('/api/googlepay.php',{}, {'Content-Type':'text/plain'})[0]==415,'wrong content type rejected')
    check(request('/api/googlepay.php',{}, {'Content-Type':'application/json'})[0]==403,'CSRF required')
    check(request('/api/googlepay.php',{},dict(auth,Origin='https://example.invalid'))[0]==403,'cross-origin rejected')
    check(request('/api/googlepay.php',{'action':'invalid'},auth)[0]==400,'invalid action rejected')
    check(request('/api/paypal-webhook.php',{'id':'WH-FAKE','event_type':'PAYMENT.CAPTURE.COMPLETED'},
        {'Content-Type':'application/json'})[0]==401,'unsigned webhook cannot complete orders')
    data={'action':'create','attempt_id':'a'*32,'items':[{'product_id':'1','quantity':2}],
        'email':'test@example.invalid','first_name':'Test','last_name':'Buyer',
        'address':{'address1':'100 Test Road','address2':'','city':'Test City','state':'CA','zip':'90001','country':'US'},
        'shipping_quote':'c'*32,'shipping_index':0,'expected_total':'25.00'}
    code,bad,_=request('/api/googlepay.php',dict(data,expected_total='0.01'),auth)
    check(code==409 and bad['code']=='total_changed','tampered total rejected before create')
    code,created,_=request('/api/googlepay.php',data,auth)
    check(code==200 and created['state']=='created' and created['amount']=='25.00','server creates priced order')
    code,same,_=request('/api/googlepay.php',data,auth)
    check(same['paypal_order_id']==created['paypal_order_id'],'duplicate create reuses provider order')
    code,other_seed,other_headers=request('/seed.php')
    other_auth={'Cookie':other_headers.get('Set-Cookie').split(';')[0],'Content-Type':'application/json','X-CSRF-Token':other_seed['csrf']}
    ref={'action':'status','attempt_id':'a'*32}
    check(request('/api/googlepay.php',ref,other_auth)[0]==404,'other session cannot recover reference')
    (SITE/'disabled').touch()
    check(request('/api/googlepay.php',dict(ref,action='capture'),auth)[0]==403,'kill switch blocks capture')
    check(request('/api/googlepay.php',ref,auth)[0]==200,'kill switch allows status recovery')
    (SITE/'disabled').unlink()
    provider=SITE/(created['paypal_order_id']+'.json')
    p=json.loads(provider.read_text());p['status']='APPROVED';p['payment_source']={'google_pay':{'name':'Fixture'}}
    provider.write_text(json.dumps(p))
    code,paid,_=request('/api/googlepay.php',dict(ref,action='capture'),auth)
    check(code==200 and paid['state']=='paid','capture verified and finalized')
    check(request('/api/googlepay.php',dict(ref,action='capture'),auth)[1]['state']=='paid','duplicate capture returns existing paid result')
    check(len((SITE/'captures.txt').read_text().splitlines())==1,'only one capture request')
    with sqlite3.connect(SITE/'database/flipandstrip.db') as db:
        check(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()[0]==8,'stock deducted once')
        check(db.execute('SELECT payment_method FROM orders').fetchone()[0]=='googlepay','Google Pay recorded')
    print(f'PASS {count} Google Pay HTTP assertions; isolated database and mocked provider; no live calls.')
finally:
    server.terminate();server.wait(timeout=10);log.close()
