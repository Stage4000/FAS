"""Isolated HTTP fixture. No production credentials, mail, sync runs, or payment calls."""
from pathlib import Path
import concurrent.futures, datetime, http.cookiejar, json, os, re, shutil, sqlite3, subprocess, sys, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix="fas-security-http-"))
SITE = BASE / "site"
SITE.mkdir()
for folder in ["src", "admin", "includes", "public", "api"]:
    shutil.copytree(ROOT / folder, SITE / folder,
        ignore=shutil.ignore_patterns("config.php", "config.local.php", "config.production.php", "applepay.php" if folder == "src" else "__none__", "uploads", "*.log", "*.db", "*.sqlite"))
# Keep concurrent payment checks local: the fixture reads a synthetic PayPal response.
(SITE / "src/integrations/PayPalAPI.php").write_text("""<?php
namespace FAS\\Integrations;
class PayPalAPI {
    public function getOrderDetails($id) {
        return json_decode(file_get_contents(__DIR__.'/../../../paypal-mock.json'),true);
    }
}
""",encoding="utf-8")
for name in ["checkout.php", "cart.php", "products.php", "product.php", "index.php", "contact.php", "recover-cart.php", "email-preferences.php"]:
    shutil.copy2(ROOT / name, SITE / name)
shutil.copy2(ROOT / "src/config/config.example.php", SITE / "src/config/config.php")
dbfile = SITE / "database/flipandstrip.db"
dbfile.parent.mkdir()
with sqlite3.connect(dbfile) as db:
    db.executescript((ROOT / "tests/fixtures/security-catalog.sql").read_text(encoding="utf-8"))
    db.execute("CREATE TABLE IF NOT EXISTS admin_users(id INTEGER PRIMARY KEY,username TEXT UNIQUE,password_hash TEXT,email TEXT,role TEXT,is_active INTEGER,last_login TEXT)")
    password = subprocess.check_output(["php", "-r", 'echo password_hash("Local-test-only", PASSWORD_DEFAULT);'], text=True)
    db.execute("INSERT OR REPLACE INTO admin_users(id,username,password_hash,email,role,is_active) VALUES(9001,'security-fixture',?,'fixture@example.invalid','admin',1)", [password])
    db.execute("CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY,customer_email TEXT,payment_status TEXT,updated_at TEXT)")
    db.execute("CREATE TABLE IF NOT EXISTS ebay_sync_log(id INTEGER PRIMARY KEY,status TEXT,last_sync_timestamp TEXT)")
# Use the real analytics schema so admin-side session marking and Security traffic queries run normally.
analytics_code = "require_once "+json.dumps(str(SITE / "src/utils/Analytics.php").replace("\\","/"))+"; $db=new PDO('sqlite:' . "+json.dumps(str(dbfile).replace("\\","/"))+"); (new \\FAS\\Utils\\Analytics($db))->ensureTables();"
subprocess.check_call(["php","-r",analytics_code])
traffic_now = datetime.datetime.now(datetime.timezone.utc)
def traffic_stamp(offset):
    return (traffic_now+datetime.timedelta(seconds=offset)).strftime("%Y-%m-%d %H:%M:%S")
with sqlite3.connect(dbfile) as db:
    for i in range(8):
        session_id = f"traffic-sg-{i}"
        db.execute("INSERT INTO analytics_sessions (session_id,visitor_id,started_at,last_seen_at,cf_country,ip_hash,is_potential_bot,is_admin_session) VALUES (?,?,?,?,?,?,?,?)",
            (session_id,session_id,traffic_stamp(-100),traffic_stamp(-100),"SG",f"traffic-addr-{i%4}",i<2,0))
        db.execute("INSERT INTO analytics_events (session_id,visitor_id,event_type,created_at) VALUES (?,?,?,?)",
            (session_id,session_id,"tawk_widget_loaded",traffic_stamp(-90)))
    db.execute("INSERT INTO analytics_sessions (session_id,visitor_id,started_at,last_seen_at,cf_country,ip_hash,is_admin_session) VALUES (?,?,?,?,?,?,?)",
        ("traffic-sg-prior","traffic-sg-prior",traffic_stamp(-1000),traffic_stamp(-1000),"SG","traffic-addr-prior",0))
gallery = SITE / "gallery"
gallery.mkdir()
for name in ["logo.png","default.jpg","FLIPANDSTRIP.COM_d00a_018a.jpg"]:
    if (ROOT / "gallery" / name).is_file(): shutil.copy2(ROOT / "gallery" / name, gallery / name)
if (ROOT / "gallery/favicons").is_dir(): shutil.copytree(ROOT / "gallery/favicons",gallery / "favicons")
env = os.environ.copy()
env["FAS_SECURITY_DB_PATH"] = str(BASE / "private/security.sqlite")
env.pop("FAS_TRUSTED_PROXY_CIDRS", None)
def maintenance(*args):
    return subprocess.check_output(["php",str(ROOT/"scripts/security-maintenance.php"),*args],env=env,text=True)
maintenance("init")
maintenance("activate","--verified")  # isolated fixture; production activation is separate
def rule(name,capacity=1,seconds=3600):
    code = "require "+json.dumps(str(ROOT/"src/security/SecurityStore.php").replace("\\","/"))+"; $s=\\FAS\\Security\\SecurityStore::open(); $s->saveRule($argv[1],(int)$argv[2],(int)$argv[3],'enforce','127.0.0.1',9001);"
    subprocess.check_call(["php","-r",code,name,str(capacity),str(seconds)],env=env)
log = open(BASE/"server.log","w")
# Only the known storefront routes needed by this disposable browser fixture.
router=BASE/"router.php"
router.write_text("<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if(in_array($p,['/cart','/checkout','/products'],true)){require $_SERVER['DOCUMENT_ROOT'].$p.'.php';return true;}return false;",encoding="utf-8")
server = subprocess.Popen(["php","-d","disable_functions=mail","-S","127.0.0.1:8788","-t",str(SITE),str(router)],env=env,stdout=log,stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
c = client()
rows = []
def request(path, data=None, headers=None, who=c, method=None):
    if isinstance(data,dict):
        data=urllib.parse.urlencode(data).encode()
    req=urllib.request.Request("http://127.0.0.1:8788"+path,data=data,headers=headers or {},method=method)
    try: result=who.open(req,timeout=10)
    except urllib.error.HTTPError as e: result=e
    with result: return result.status,dict(result.headers),result.read().decode()
def check(ok,message):
    if not ok: raise AssertionError(message)
    rows.append(message)
def token(body):
    return re.search(r'name="csrf_token" value="([^"]+)"',body)[1]
for _ in range(30):
    try: request("/admin/login.php");break
    except OSError: time.sleep(.1)
if "--prepare-only" in sys.argv:
    print(json.dumps({"base":str(BASE),"site":str(SITE),"pid":server.pid,"security_db":env["FAS_SECURITY_DB_PATH"]}))
    sys.exit(0)
try:
    status,h,b=request("/admin/security.php")
    check(status==302 and h["Location"]=="login.php","Anonymous security access redirects to sign-in")
    status,h,b=request("/admin/login.php")
    csrf=token(b)
    status,h,b=request("/admin/login.php",{"username":"security-fixture","password":"Local-test-only"})
    check(status==403,"Login rejects missing CSRF")
    status,h,b=request("/admin/login.php",{"username":"security-fixture","password":"Local-test-only","csrf_token":csrf})
    check(status==302,"Valid credentials and CSRF authenticate")
    status,h,b=request("/admin/security.php?tab=overview")
    check(status==200 and "Traffic watch" in b and "Review burst" in b and "Recent pattern" in b,
        "Security overview renders burst context and recent traffic history")
    status,h,b=request("/admin/security.php?tab=rules")
    csrf=token(b)
    check(status==200 and "no-store" in h["Cache-Control"] and '<fieldset disabled' in b,"Rules require reauthentication; responses are private")
    status,h,b=request("/admin/security.php?tab=rules",{"action":"save_rule","rule":"contact","capacity":"2","seconds":"60","mode":"enforce","csrf_token":csrf})
    check(status==403,"Security mutation blocked without recent password verification")
    status,h,b=request("/admin/security.php?tab=rules",{"action":"reauth","password":"Local-test-only","csrf_token":csrf})
    check(status==303,"Reauthentication succeeds")
    status,h,b=request("/admin/security.php?tab=rules",{"action":"save_rule","rule":"contact","capacity":"2","seconds":"60","mode":"enforce"})
    check(status==403,"Security mutation rejects missing CSRF")
    status,h,b=request("/admin/security.php?tab=rules",{"action":"save_rule","rule":"contact","capacity":"2","seconds":"60","mode":"enforce","csrf_token":csrf})
    check(status==303,"Rule save succeeds after authorization")
    with sqlite3.connect(env["FAS_SECURITY_DB_PATH"]) as db:
        check(db.execute("SELECT capacity,seconds FROM security_rules WHERE rule='contact'").fetchone()==(2,60),"Rule persisted independently of site settings")
    for role,active in [("viewer",1),("admin",0)]:
        with sqlite3.connect(dbfile) as db: db.execute("UPDATE admin_users SET role=?,is_active=? WHERE id=9001",[role,active])
        check(request("/admin/security.php")[0]==403,"Inactive or non-admin identity rejected: "+role+"/"+str(active))
    with sqlite3.connect(dbfile) as db: db.execute("UPDATE admin_users SET role='admin',is_active=1 WHERE id=9001")
    for role,active in [("viewer",1),("admin",0)]:
        with sqlite3.connect(dbfile) as db: db.execute("UPDATE admin_users SET role=?,is_active=? WHERE id=9001",[role,active])
        check(request("/admin/settings.php")[0]==403,"Site settings reject inactive or non-admin identity: "+role+"/"+str(active))
    with sqlite3.connect(dbfile) as db: db.execute("UPDATE admin_users SET role='admin',is_active=1 WHERE id=9001")
    request("/admin/security.php?tab=rules",{"action":"reauth","password":"Local-test-only","csrf_token":csrf})
    status,h,b=request("/admin/security.php?tab=restrictions",{"action":"block","ip":"127.0.0.1","reason":"Self","duration":"900","csrf_token":csrf})
    check(status==200 and "cannot be blocked" in b,"Self-block prevented")
    status,h,b=request("/admin/security.php?tab=restrictions",{"action":"block","ip":"203.0.113.4","reason":"<script>fixture</script>","duration":"900","csrf_token":csrf})
    check(status==303,"Temporary exact IP block saved")
    check("&lt;script&gt;fixture&lt;/script&gt;" in request("/admin/security.php?tab=restrictions")[2],"Block reason HTML escaped")
    status,h,b=request("/admin/security.php?tab=restrictions",{"action":"unblock","ip":"203.0.113.4","csrf_token":csrf})
    check(status==303,"Temporary block removed through UI handler")
    # Newsletter and saved-cart capture use synthetic recipients and never send mail.
    def growth_request(action,fields=None,csrf_override=None):
        payload={"action":action,"csrf":growth_csrf,**(fields or {})}
        if csrf_override is not None: payload["csrf"]=csrf_override
        return request("/api/growth.php",json.dumps(payload).encode(),{"Content-Type":"application/json"})
    growth_csrf=json.loads(request("/api/growth.php")[2])["csrf"]
    check(growth_request("signup",{"email":"news@example.invalid","consent":True},"wrong")[0]==403,"Email signup requires CSRF")
    check(growth_request("signup",{"email":"news@example.invalid","consent":False})[0]==422,"Email signup requires explicit consent")
    check(growth_request("signup",{"email":"news@example.invalid","consent":True})[0]==200,"Email signup captured")
    growthdb=BASE/"private/growth.sqlite"
    with sqlite3.connect(growthdb) as db:
        check(db.execute("SELECT status FROM growth_contacts WHERE email='news@example.invalid'").fetchone()[0]=="pending","Signup waits for email confirmation")
        payload=json.loads(db.execute("SELECT payload FROM growth_messages WHERE kind='confirmation'").fetchone()[0])
    check(request("/email-preferences.php")[0]==200,"Email action page renders without acting on a link")
    check(growth_request("confirm",{"token":payload["confirm"]})[0]==200,"Confirmation endpoint activates subscriber")
    fields={"email":"buyer@example.invalid","consent":True,"send_now":True,"items":[{"id":1,"quantity":99,"price":0.01}]}
    check(growth_request("save_cart",fields)[0]==200,"Email-my-cart request captured")
    with sqlite3.connect(growthdb) as db:
        cartlink=json.loads(db.execute("SELECT payload FROM growth_messages WHERE kind='cart_link'").fetchone()[0])
    status,h,b=growth_request("restore",{"token":cartlink["restore"]})
    restored=json.loads(b)
    check(status==200 and restored["items"][0]["price"]==100 and restored["items"][0]["quantity"]==1,"Restore endpoint uses current server price and stock")
    check(growth_request("restore",{"token":"f"*64})[0]==422,"Forged cart link rejected")
    check(growth_request("unsubscribe",{"token":cartlink["unsubscribe"]})[0]==200,"Cart recipient can unsubscribe")
    with sqlite3.connect(growthdb) as db:
        check(db.execute("SELECT COUNT(*) FROM growth_messages WHERE email='buyer@example.invalid' AND status='queued'").fetchone()[0]==0,"Unsubscribe cancels queued cart emails")
    check(request("/admin/growth.php",who=client())[0]==302,"Sales and Email dashboard requires login")
    check(request("/admin/growth.php")[0]==200,"Sales and Email dashboard renders for active admin")
    status,h,b=request("/admin/growth.php",{"csrf_token":csrf,"from_email":"noreply@flipandstrip.com","reply_to":"reply@example.invalid","mail_enabled":"1","postal_address":""})
    check(status==200 and "Enter a business mailing address" in b,"Delivery cannot be enabled without a mailing address")
    # Site and email configuration share budgets, before any settings are written.
    rule("settings_account")
    config_before=(SITE/"src/config/config.php").read_bytes()
    check(request("/admin/settings.php",{"site_name":"Must not save"})[0]==403,"Settings reject missing CSRF")
    with sqlite3.connect(env["FAS_SECURITY_DB_PATH"]) as db:
        check(db.execute("SELECT COUNT(*) FROM security_buckets WHERE rule='settings_account'").fetchone()[0]==0,"Invalid CSRF does not consume account budget")
    request("/admin/growth.php",{"csrf_token":csrf,"from_email":"invalid"})
    status,h,b=request("/admin/settings.php",{"csrf_token":csrf,"site_name":"Must not save"})
    check(status==429 and int(h.get("Retry-After","0"))>0 and "Too many attempts" in b,"Site settings share email settings budget with HTML retry guidance")
    check((SITE/"src/config/config.php").read_bytes()==config_before,"Throttled settings do not write configuration")
    with sqlite3.connect(growthdb) as db:
        settings_before=db.execute("SELECT * FROM growth_settings ORDER BY name").fetchall()
    status,h,b=request("/admin/growth.php",{"csrf_token":csrf,"from_email":"changed@example.invalid","reply_to":"reply@example.invalid","postal_address":"Fixture only"})
    check(status==429 and "Retry-After" in h,"Email settings return rate limit status and retry header")
    with sqlite3.connect(growthdb) as db:
        check(db.execute("SELECT * FROM growth_settings ORDER BY name").fetchall()==settings_before,"Throttled email settings preserve stored values")
    check(request("/admin/settings.php")[0]==200 and request("/admin/security.php")[0]==200,"Settings throttle preserves read access and security recovery")
    rule("settings_account",10,300)
    # Password verification has one budget across the Security and Password pages.
    rule("reauth_account")
    request("/admin/security.php?tab=rules",{"csrf_token":csrf,"action":"reauth","password":"Wrong-fixture"})
    status,h,b=request("/admin/password.php",{"csrf_token":csrf,"current_password":"Local-test-only","new_password":"Must-not-change","confirm_password":"Must-not-change"})
    check(status==429 and "Retry-After" in h,"Password changes share the reauthentication budget")
    with sqlite3.connect(dbfile) as db:
        check(db.execute("SELECT password_hash FROM admin_users WHERE id=9001").fetchone()[0]==password,"Throttled password change preserves password hash")
    rule("reauth_account",5,900)
    check("Admin account protection" in request("/admin/security.php")[2],"Security overview exposes admin protection limits")
    with sqlite3.connect(growthdb) as db:
        check(db.execute("SELECT COUNT(*) FROM growth_messages WHERE status='sent'").fetchone()[0]==0,"No mail was sent during HTTP tests")
    # Multiple PHP origins here simulate workers against ONE isolated SQLite store.
    # Force all workers to read the pending order before releasing the writer lock.
    extra_servers=[]
    try:
        for port in [8789,8790]:
            extra_servers.append(subprocess.Popen(["php","-d","disable_functions=mail","-S","127.0.0.1:"+str(port),"-t",str(SITE),str(router)],env=env,stdout=log,stderr=log))
        with sqlite3.connect(dbfile) as db:
            db.execute('UPDATE products SET quantity=10 WHERE id=1')
            shipping=json.dumps({"address1":"200 Synthetic Street","address2":"","city":"Test City",
                "state":"CA","zip":"90210","country":"US"})
            db.execute("INSERT INTO orders(id,order_number,customer_email,payment_method,payment_status,order_status,total_amount,shipping_address,updated_at) VALUES(10,'TEST-RETRY','payment@example.invalid','paypal','pending','pending',100,?,'2026-09-30')",[shipping])
            db.execute("INSERT INTO order_items(id,order_id,product_id,product_name,quantity,unit_price,total_price) VALUES(1,10,1,'Synthetic test part',1,100,100)")
        provider={"id":"MOCKORDER123","intent":"CAPTURE","status":"COMPLETED","purchase_units":[{
            "invoice_id":"TEST-RETRY","custom_id":"FAS-CHECKOUT-10","payee":{"merchant_id":"FIXTUREMERCHANT"},
            "amount":{"currency_code":"USD","value":"100.00"},
            "shipping":{"address":{"address_line_1":"200 Synthetic Street","address_line_2":"",
                "admin_area_2":"Test City","admin_area_1":"CA","postal_code":"90210","country_code":"US"}},
            "payments":{"captures":[{"id":"MOCKCAPTURE123","status":"COMPLETED",
                "amount":{"currency_code":"USD","value":"100.00"},"final_capture":True}]}
        }]}
        (BASE/"paypal-mock.json").write_text(json.dumps({"success":True,"data":provider}),encoding="utf-8")
        for port in [8789,8790]:
            for attempt in range(30):
                try: urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/api/growth.php',timeout=2).close();break
                except OSError: time.sleep(.1)
        def complete(port):
            body=json.dumps(dict(action='complete_order',order_id=10,paypal_order_id='MOCKORDER123',paypal_transaction_id='MOCKCAPTURE123')).encode()
            req=urllib.request.Request('http://127.0.0.1:'+str(port)+'/api/process-order.php',data=body,headers={'Content-Type':'application/json'})
            with urllib.request.urlopen(req,timeout=10) as response: return json.load(response)
        lock=sqlite3.connect(dbfile)
        try:
            lock.execute('BEGIN IMMEDIATE')
            with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
                futures=[pool.submit(complete,port) for port in [8788,8789,8790]]
                time.sleep(.7)
                lock.commit()
                responses=[f.result() for f in futures]
        finally: lock.close()
        check(all(r['success'] for r in responses),'Concurrent completion retries all return success for the same order')
        with sqlite3.connect(dbfile) as db:
            check(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()[0]==9,'Concurrent completion deducts inventory only once')
        check(complete(8788)['message']=='Order already completed','Already-completed legacy order is idempotent')
    finally:
        for worker in extra_servers: worker.terminate();worker.wait(timeout=10)
    endpoints=[("contact-form.php","contact","POST"),("saved-search.php","saved_search","POST"),
        ("shipping-estimate.php","shipping","POST"),("shipping-rates.php","shipping","POST"),
        ("validate-coupon.php","coupon","POST"),("address-autofill.php","address","GET"),
        ("product-check.php","product_check","GET"),("track-event.php","analytics","POST"),
        ("log-client-error.php","client_error","POST"),("ebay-sync.php?key=invalid-fixture-key","sync_auth","GET")]
    for endpoint,r,method in endpoints:
        rule(r)
        data=b"[]" if method=="POST" else None
        headers={"Content-Type":"application/json","CF-Connecting-IP":"203.0.113.99","X-Forwarded-For":"203.0.113.100"}
        request("/api/"+endpoint,data,headers,method=method)
        status,h,b=request("/api/"+endpoint,data,headers,method=method)
        check(status==429 and int(h.get("Retry-After","0"))>0 and json.loads(b)["code"]=="rate_limited","429 + Retry-After before effects: "+endpoint)
    rule("shipping")
    request("/api/shipping-estimate.php",b"{}",{"Content-Type":"application/json"})
    check(request("/api/shipping-rates.php",b"{}",{"Content-Type":"application/json"})[0]==429,"Shipping endpoints share their budget")
    check(request("/api/track-event.php",method="OPTIONS")[0]==204,"Telemetry preflight exempt")
    rule("client_error",100)
    check(request("/api/log-client-error.php",b" "*65537,{"Content-Type":"application/json"})[0]==413,"Actual oversized body rejected")
    rule("login_pair",1)
    anonymous=client()
    csrf2=token(request("/admin/login.php",who=anonymous)[2])
    request("/admin/login.php",{"username":"unknown-fixture","password":"NEVER_RECORD","csrf_token":csrf2},who=anonymous)
    status,h,b=request("/admin/login.php",{"username":"unknown-fixture","password":"NEVER_RECORD","csrf_token":csrf2},who=anonymous)
    check(status==429 and "Retry-After" in h,"Login throttle preserves HTML response")
    check(request("/admin/security.php")[0]==200,"Established admin session survives login throttle")
    check(request("/admin/password.php",{"current_password":"Local-test-only","new_password":"Another-fixture","confirm_password":"Another-fixture"})[0]==403,"Password change rejects missing CSRF")
    with sqlite3.connect(env["FAS_SECURITY_DB_PATH"]) as db:
        events=db.execute("SELECT ip,rule,outcome,path,detail FROM security_events").fetchall()
        check("NEVER_RECORD" not in json.dumps(events) and "invalid-fixture-key" not in json.dumps(events),"Activity excludes credentials and query strings")
        check(not any(e[0]=="203.0.113.99" for e in events),"Spoofed Cloudflare header ignored at HTTP boundary")
    maintenance("unblock","127.0.0.1")
    check(request("/admin/login.php")[0]==200,"CLI emergency recovery available")
    status,h,b=request("/admin/settings.php",{"csrf_token":csrf,"site_name":"Local fixture updated"})
    check(status==200 and "Settings saved successfully" in b,"Authorized settings save succeeds within budget")
    with sqlite3.connect(env["FAS_SECURITY_DB_PATH"]) as db:
        check(db.execute("SELECT COUNT(*) FROM security_events WHERE outcome='settings_changed' AND actor=9001").fetchone()[0]==1,"Successful settings save records its admin actor")
    result={"date":time.strftime("%Y-%m-%d"),"scope":"Isolated localhost fixture; no production requests or real payments","assertions":len(rows),"passed":rows}
    (ROOT/"audit/security-local-http.json").write_text(json.dumps(result,indent=2)+"\n",encoding="utf-8")
    print("PASS",len(rows),"isolated HTTP assertions.")
finally:
    server.terminate();server.wait(timeout=10);log.close()
    # Leave the isolated fixture for diagnosis; no customer data or credentials are present.
    print("Fixture:",BASE)
