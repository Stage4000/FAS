"""Isolated administrator label page checks. Purchasing stays disabled; no live carrier calls."""
from pathlib import Path
import http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, sys, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT=Path(__file__).resolve().parents[1]
BASE=Path(tempfile.mkdtemp(prefix='fas-shipping-label-http-'))
SITE=BASE/'site'; SITE.mkdir()
for folder in ['src','admin','includes','public','scripts']:
    shutil.copytree(ROOT/folder,SITE/folder,ignore=shutil.ignore_patterns(
        'config.php','config.local.php','config.production.php','shipping.php','applepay.php',
        'config.json','settings.json','uploads','backups','exports','*.db','*.sqlite','*.log'))
shutil.copy2(ROOT/'src/config/config.example.php',SITE/'src/config/config.php')
(SITE/'database').mkdir()
(SITE/'gallery').mkdir()
shutil.copytree(ROOT/'gallery/favicons',SITE/'gallery/favicons')
shutil.copy2(ROOT/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg',SITE/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg')
(BASE/'private').mkdir()
(BASE/'sessions').mkdir()
DB=SITE/'database/flipandstrip.db'
PASSWORD='Synthetic-shipping-admin-123'
hashed=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True)
address=json.dumps({'address1':'200 Synthetic Street','address2':'','city':'Test City',
    'state':'CA','zip':'90210','country':'US'})
origin=json.dumps({'address1':'100 Fixture Road','address2':'','city':'Test City',
    'state':'KS','zip':'66614','country':'US'})
packages=json.dumps([{'weight':1,'length':10,'width':8,'height':6}])
options=json.dumps([{'rate_indicator':'SP','processing_category':'MACHINABLE',
    'destination_entry_facility_type':'NONE','price_type':'RETAIL','quoted_cents':900}])
with sqlite3.connect(DB) as db:
    db.executescript((ROOT/'database/schema.sqlite.sql').read_text(encoding='utf-8'))
    db.execute("INSERT INTO admin_users(id,username,email,full_name,password_hash) VALUES(1,'shipping-fixture','shipping@example.invalid','Shipping Fixture',?)",[hashed])
    db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,customer_phone,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status,paypal_transaction_id) VALUES(10,'FAS-10','buyer@example.invalid','Alex Buyer','5555551234',?,20,9,29,'completed','processing','CAPTURE123456')",[address])
    db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) VALUES(10,'usps','direct_usps_USPS_GROUND_ADVANTAGE','USPS_GROUND_ADVANTAGE','USPS','Ground Advantage',900,'USD','fixture-hash',?,?,?,?)",
        [int(time.time())+600,origin,packages,options])
env={k:v for k,v in os.environ.items() if not k.startswith('FAS_')}
env['FAS_SECURITY_DB_PATH']=str(BASE/'private/security.sqlite')
env['FAS_SHIPPING_CACHE_PATH']=str(BASE/'private/shipping.sqlite')
subprocess.check_call(['php',str(SITE/'scripts/security-maintenance.php'),'init'],env=env,stdout=subprocess.DEVNULL)
subprocess.check_call(['php',str(SITE/'scripts/security-maintenance.php'),'activate','--verified'],env=env,stdout=subprocess.DEVNULL)
subprocess.check_call(['php',str(SITE/'scripts/shipping-maintenance.php'),'init'],env=env,stdout=subprocess.DEVNULL)
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
ORIGIN=f'http://127.0.0.1:{port}'
log=open(BASE/'server.log','w')
server=subprocess.Popen(['php','-d','disable_functions=mail,curl_exec,curl_multi_exec',
    '-d','session.save_path='+str(BASE/'sessions'),'-S',f'127.0.0.1:{port}','-t',str(SITE)],
    env=env,stdout=log,stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
owner=client(); anonymous=client()
def request(path,data=None,who=owner):
    if isinstance(data,dict): data=urllib.parse.urlencode(data).encode()
    try: r=who.open(urllib.request.Request(ORIGIN+path,data=data),timeout=15)
    except urllib.error.HTTPError as e: r=e
    with r: return r.status,dict(r.headers),r.read().decode()
def check(ok,message):
    if not ok: raise AssertionError(message)
    checks.append(message)
checks=[]
try:
    for _ in range(50):
        try: request('/admin/login.php'); break
        except OSError: time.sleep(.1)
    path='/admin/shipping-label.php?id=10'
    check(request(path,who=anonymous)[0]==302,'Anonymous label page redirects to sign-in')
    loginPage=request('/admin/login.php')[2]
    csrf=re.search(r'name="csrf_token" value="([^"]+)"',loginPage)[1]
    login=request('/admin/login.php',{'csrf_token':csrf,'username':'shipping-fixture','password':PASSWORD})
    check(login[0]==302,'Synthetic active administrator signs in')
    status,headers,page=request(path)
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and 'Shipping Labels' in page,
          'Private label page renders for active administrator')
    check('Ground Advantage' in page and '$9.00' in page and 'Purchase label' not in page,
          'Disabled carrier configuration shows saved quote without purchase control')
    check('Review shipping labels' in request('/admin/order-details.php?id=10')[2],
          'Order page links to carrier label review')
    post={'package_index':'0','confirmed_cents':'900','confirm_charge':'yes',
          'password':PASSWORD,'mailing_date':time.strftime('%Y-%m-%d')}
    check(request(path,post)[0]==403,'Label purchase requires CSRF')
    status,_,body=request(path,{'csrf_token':csrf,**post})
    check(status==200 and 'label could not be prepared' in body.lower(),
          'Valid CSRF cannot purchase while carrier configuration is disabled')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        operations=db.execute('SELECT COUNT(*) FROM shipping_label_operations').fetchone()[0]
    check(operations==0,'Disabled label page creates no shipment operation')
    check(request('/admin/shipping-label-download.php?id=10&package=0&piece=0')[0]==404,
          'Unconfirmed label cannot be downloaded')
    check(request('/admin/order-details.php?id=10',{'action':'update_tracking','tracking_number':'INJECTED'})[0]==403,
          'Order tracking mutation now requires CSRF')
    with sqlite3.connect(DB) as db:
        tracking=db.execute('SELECT tracking_number FROM orders WHERE id=10').fetchone()[0]
    check(tracking is None,'Rejected tracking mutation leaves order unchanged')
    report={'date':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
        'scope':'isolated synthetic administrator and paid order; purchasing disabled',
        'checks':len(checks),'passed':checks,'live_carrier_calls':0,'label_purchases':0,
        'production_verified':False}
    (ROOT/'audit/shipping-label-http-local.json').write_text(json.dumps(report,indent=2)+'\n')
    print(f'PASS {len(checks)} shipping label HTTP checks. No carrier calls or purchases.',flush=True)
    if '--serve' in sys.argv:
        print(json.dumps({'origin':ORIGIN,'fixture':str(BASE),'username':'shipping-fixture',
            'password':PASSWORD}),flush=True)
        while True: time.sleep(1)
finally:
    server.terminate(); server.wait(timeout=10); log.close()
