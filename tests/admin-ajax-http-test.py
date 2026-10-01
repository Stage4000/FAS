"""Disposable admin AJAX integration fixture; no production data or external writes.
Run with --keep to retain a local server for browser verification.
"""
from pathlib import Path
import html, http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, sys, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix="fas-admin-ajax-"))
SITE = BASE / "site"
SITE.mkdir()
for folder in ["src", "admin", "includes", "public", "api", "scripts"]:
    shutil.copytree(ROOT / folder, SITE / folder, ignore=shutil.ignore_patterns(
        "config.php", "config.local.php", "config.production.php", "shipping.php", "applepay.php",
        "config.json", "settings.json", "uploads", "backups", "exports", "*.db", "*.sqlite", "*.log"))
shutil.copy2(ROOT / "src/config/config.example.php", SITE / "src/config/config.php")
(SITE / "database").mkdir()
dbfile = SITE / "database/flipandstrip.db"
with sqlite3.connect(dbfile) as db:
    db.executescript((ROOT / "database/schema.sqlite.sql").read_text(encoding="utf-8"))
    password = subprocess.check_output(["php", "-r", 'echo password_hash("Local-test-only", PASSWORD_DEFAULT);'], text=True)
    db.execute("INSERT INTO admin_users(id,username,password_hash,email,role,is_active) VALUES(9001,'ajax-fixture',?,'fixture@example.invalid','admin',1)", [password])
    db.execute("INSERT INTO products(id,name,price,category,weight,length,width,height,description) VALUES(9001,'AJAX fixture part',25,'Fixture parts',1,2,3,4,'Synthetic fixture part')")
    db.execute("INSERT INTO categories(name,slug) VALUES('Fixture parts','fixture-parts')")
    for i, area in enumerate(['checkout','shipping','paypal'], 9001):
        db.execute("INSERT INTO error_monitor_events(id,area,severity,message,metadata) VALUES(?,?,'critical',?,'{}')",[i,area,'AJAX fixture '+area])
    db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,subtotal,total_amount,shipping_address,billing_address) VALUES(9001,'AJAX-9001','fixture@example.invalid','Fixture Customer',25,25,'{}','{}')")
(SITE / "gallery").mkdir()
shutil.copytree(ROOT / "gallery/favicons", SITE / "gallery/favicons")
env = os.environ.copy()
for key in list(env):
    if key.startswith('FAS_'): env.pop(key)
env['ANALYTICS_IP_GEO_ENABLED'] = '0'
env['FAS_SECURITY_DB_PATH'] = str(BASE / 'private/security.sqlite')
# All optional subsystem stores derive from this disposable site path.
for script in ['security-maintenance.php','product-content-maintenance.php']:
    if (SITE / 'scripts' / script).is_file():
        subprocess.run(['php',str(SITE / 'scripts' / script),'init'],env=env,stdout=subprocess.DEVNULL,check=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
ORIGIN = 'http://127.0.0.1:'+str(port)
(BASE / 'sessions').mkdir()
router = BASE / 'router.php'
router.write_text("<?php file_put_contents(__DIR__.'/requests.jsonl',json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'uri'=>$_SERVER['REQUEST_URI'],'ajax'=>$_SERVER['HTTP_X_REQUESTED_WITH']??'']) . PHP_EOL, FILE_APPEND); return false;")
log = open(BASE / 'server.log','w')
server = subprocess.Popen(['php','-d','disable_functions=mail','-d','session.save_path='+str(BASE / 'sessions'),
    '-d','session.name=FASAJAX'+str(port),'-S','127.0.0.1:'+str(port),'-t',str(SITE),str(router)],env=env,stdout=log,stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
def request(path,data=None,ajax=True):
    if isinstance(data,dict): data=urllib.parse.urlencode(data).encode()
    headers={'X-Requested-With':'XMLHttpRequest'} if ajax else {}
    try: result=client.open(urllib.request.Request(ORIGIN+path,data=data,headers=headers),timeout=15)
    except urllib.error.HTTPError as e: result=e
    with result: return result.status,dict(result.headers),result.read().decode()
def token(body): return html.unescape(re.search(r'name="csrf_token" value="([^"]+)"',body)[1])
checks=[]
passed=False
def check(condition,message):
    if not condition: raise AssertionError(message)
    checks.append(message)
try:
    for _ in range(50):
        try: request('/admin/login.php');break
        except OSError: time.sleep(.1)
    check(request('/admin/error-monitor.php')[0]==302,'Anonymous error monitor still requires sign-in')
    check(request('/admin/dashboard-summary.php')[0]==401,'Anonymous dashboard summary is denied')
    check(request('/api/ebay-sync.php',{'csrf_token':'invalid'})[0]==401,'Anonymous admin sync POST is denied')
    csrf=token(request('/admin/login.php')[2])
    check(request('/admin/login.php',{'username':'ajax-fixture','password':'Local-test-only','csrf_token':csrf})[0]==302,'Fixture signs in through normal CSRF-protected login')
    routes=['error-monitor.php','products.php','orders.php','order-details.php?id=9001','warehouses.php','coupons.php','banners.php','sale.php','free-shipping.php','homepage-categories.php','settings.php','password.php','security.php','growth.php','product-content.php?id=9001','product-quality.php','stale-inventory.php','ebay-sync-health.php','analytics.php','administrators.php','shipping-operations.php','merchant-feed-health.php']
    for route in routes:
        status,headers,body=request('/admin/'+route)
        check(status==200 and body.count('id="admin-content"')==1 and 'js/admin-ajax.js?' in body and 'Fatal error' not in body and '<b>Warning</b>' not in body,'Enhanced page renders with one AJAX root: '+route)
        if route=='shipping-operations.php':
            check('data-admin-error=""' in body,'Shipping storage warning remains refreshable')
    status,headers,body=request('/admin/dashboard-summary.php')
    summary=json.loads(body)
    check(status==200 and summary['active_products']>=1 and summary['visible_products']>=1,'Signed-in dashboard summary returns current counts')
    check(request('/api/ebay-sync.php',{'csrf_token':'invalid'})[0]==403,'Admin sync POST rejects invalid CSRF')
    check(request('/api/ebay-sync.php',{'csrf_token':csrf,'start_date':'bad','end_date':'2026-10-01'})[0]==400,'Admin sync POST validates dates before starting work')
    check(request('/api/ebay-sync.php?key=invalid-fixture-key')[0]==401,'Legacy key route remains protected')
    check('sync_api_key' not in request('/admin/index.php')[2],'Dashboard does not embed the sync key')
    poll=json.loads(request('/admin/error-monitor.php?poll=1')[2])
    check(poll['latest_id']>=9003 and poll['open_count']>=3,'Monitor polling reports current activity')
    path='/admin/error-monitor.php?status=open&area=checkout&days=7'
    body=request(path)[2];csrf=token(body)
    body=request(path,{'action':'resolve_event','event_id':9001,'csrf_token':'invalid'})[2]
    check('data-admin-error="Invalid security token.' in body,'Invalid CSRF returns explicit fragment error')
    with sqlite3.connect(dbfile) as db:
        check(db.execute('SELECT status FROM error_monitor_events WHERE id=9001').fetchone()[0]=='open','Invalid CSRF does not resolve an event')
    body=request(path,{'action':'resolve_event','event_id':9001,'csrf_token':csrf})[2]
    check('data-admin-notice="Error marked resolved.' in body and 'No matching errors recorded.' in body,'Resolve refreshes filtered empty state and returns server notice')
    with sqlite3.connect(dbfile) as db:
        check(db.execute('SELECT status FROM error_monitor_events WHERE id=9001').fetchone()[0]=='resolved','Resolution persists')
    check('No open error was updated.' in request(path,{'action':'resolve_event','event_id':9001,'csrf_token':csrf})[2],'Repeated resolution is idempotent')
    body=request('/admin/error-monitor.php',{'action':'resolve_event','event_id':9002,'csrf_token':csrf},ajax=False)[2]
    check('Error marked resolved.' in body,'Native non-JavaScript submission still works')
    code,headers,body=request('/admin/security.php?tab=rules',{'action':'reauth','password':'Local-test-only','csrf_token':csrf})
    check(code==303 and headers['Location']=='security.php?tab=rules','Existing POST/redirect/GET semantics preserved')
    body=request('/admin/security.php?tab=rules')[2]
    check('data-admin-notice="Password verified.' in body,'Redirected fragment carries flash notice')
    body=request('/admin/order-details.php?id=9001',{'action':'update_tracking','tracking_number':'AJAX-TRACK','csrf_token':csrf})[2]
    check('data-admin-notice="Tracking information updated successfully' in body and 'AJAX-TRACK' in body,'Order tracking action returns refreshed state')
    body=request('/admin/warehouses.php?action=create',{
        'action':'create','name':'Fixture warehouse','code':'AJAX-QA','address_line1':'1 Test Street',
        'city':'Test City','state':'CA','postal_code':'90001','country_code':'US','is_active':'1'
    })[2]
    check('data-admin-notice="Warehouse created successfully' in body,'Warehouse create returns updated list')
    check('data-admin-url="warehouses.php?action=list"' in body,'Editor save provides canonical list URL for history')
    # Restore disposable events for browser flows.
    with sqlite3.connect(dbfile) as db: db.execute("UPDATE error_monitor_events SET status='open',resolved_at=NULL")
    passed=True
    print(json.dumps({'passed':len(checks),'checks':checks,'origin':ORIGIN,'base':str(BASE),'site':str(SITE),'pid':server.pid},indent=2))
finally:
    if '--keep' not in sys.argv or not passed:
        server.terminate();server.wait(timeout=10);log.close()

