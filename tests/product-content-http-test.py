"""Editorial integration checks use disposable inventory, credentials and no external writes."""
from pathlib import Path
import contextlib, html, http.cookiejar, importlib.util, io, json, re, shutil, sqlite3, subprocess, sys
import urllib.request, urllib.error, urllib.parse
from datetime import datetime, timezone

ROOT=Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
keep="--keep" in sys.argv
# Reuse the maintained storefront fixture and its regression checks.
sys.argv.append("--keep")
spec=importlib.util.spec_from_file_location("presentation_fixture",ROOT/"tests/seo-presentation-http-test.py")
fixture=importlib.util.module_from_spec(spec)
with contextlib.redirect_stdout(io.StringIO()):
    spec.loader.exec_module(fixture)
sys.argv.pop()
SITE,BASE,ORIGIN=fixture.SITE,fixture.BASE,fixture.ORIGIN
shutil.copytree(ROOT/"admin",SITE/"admin",ignore=shutil.ignore_patterns(
    "config.php","config.json","settings.json","backups","exports","*.db","*.sqlite","*.log"))
# The dashboard's unrelated reporting tables are outside this fixture.
(SITE/"admin/index.php").write_text("<?php header('Location: product-quality.php', true, 303);")
dbfile=SITE/"database/flipandstrip.db"
password=subprocess.check_output(["php","-r",'echo password_hash("Local-test-only",PASSWORD_DEFAULT);'],text=True)
with sqlite3.connect(dbfile) as db:
    db.execute("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,email TEXT,role TEXT,is_active INTEGER,last_login TEXT)")
    db.execute("INSERT INTO admin_users VALUES(9001,'content-fixture',?,'fixture@example.invalid','admin',1,NULL)",[password])

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),fixture.NoRedirect)
admin=client()
def request(path,data=None,who=admin):
    if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
    req=urllib.request.Request(ORIGIN+path,data=data)
    try:r=who.open(req,timeout=15)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,dict(r.headers),r.read().decode()
rows=[]
def check(ok,message):
    if not ok:raise AssertionError(message)
    rows.append(message)
def field(body,name):
    return html.unescape(re.search(r'name="'+name+r'" value="([^"]*)"',body)[1])
def editor():
    status,headers,body=request("/admin/product-content.php?id=1")
    return body,{"csrf_token":field(body,"csrf_token"),"revision":field(body,"revision"),"source_hash":field(body,"source_hash")}
def post(payload):
    return request("/admin/product-content.php?id=1",payload)
path="/product/1/synthetic-test-part"
copy={"description":"Synthetic test part. "+("Verified fixture-only product details. "*8).strip(),
      "seo_title":"Reviewed fixture part | Flip and Strip",
      "seo_description":"Isolated review of the synthetic part, its included pieces and condition."}
try:
    check(request("/admin/product-content.php?id=1")[0]==302,"Anonymous editor redirects to login")
    csrf=field(request("/admin/login.php")[2],"csrf_token")
    check(request("/admin/login.php",{"username":"content-fixture","password":"Local-test-only","csrf_token":csrf})[0]==302,"Synthetic active admin signs in")
    status,headers,body=request("/admin/product-content.php?id=1")
    check(status==200 and '<fieldset disabled' in body and "private, no-store" in headers["Cache-Control"],"Uninitialized editor is read-only and private")
    check("NEW-backup.sqlite" in body,"Editor initialization hint requires a new private backup")
    body,tokens=editor()
    check(post({**tokens,**copy,"action":"draft"})[0]==503,"Direct POST cannot bypass uninitialized read-only editor")
    for payload in [None,{"command":"init"}]:
        check(request("/scripts/product-content-maintenance.php",payload)[0]==404,"Maintenance script cannot run over HTTP")
    with sqlite3.connect(dbfile) as db:
        check(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'product_content_%'").fetchone()[0]==0,"HTTP reads and rejected writes never initialize editorial storage")
    check(request(path)[0]==200 and request("/google-merchant-feed.php")[0]==200,"Uninitialized storefront and feed remain available")
    backup_dir=BASE/"editorial-backups"
    backup_dir.mkdir(mode=0o700)
    backup=backup_dir/"before-init.sqlite"
    result=subprocess.check_output(["php",str(SITE/"scripts/product-content-maintenance.php"),"preflight"],env=fixture.env,text=True)
    check(json.loads(result)["installed"] is False,"CLI preflight confirms uninitialized storage without installing it")
    subprocess.check_call(["php",str(SITE/"scripts/product-content-maintenance.php"),"init",str(backup)],env=fixture.env,stdout=subprocess.DEVNULL)
    with sqlite3.connect(backup) as db:
        check(db.execute("PRAGMA integrity_check").fetchone()[0]=="ok" and db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'product_content_%'").fetchone()[0]==0,"Verified private snapshot contains pre-initialization inventory")
    body,tokens=editor()
    check("<fieldset disabled" not in body,"Initialized editor allows drafts")
    check(post({**tokens,**copy,"action":"draft","csrf_token":"bad"})[0]==403,"Draft rejects invalid CSRF")
    check(post({**tokens,**copy,"action":"draft"})[0]==303,"Draft saves with CSRF")
    check(copy["description"] not in request(path)[2],"Draft is absent from public page")
    check("Draft only" in editor()[0],"Draft state shown")
    body,tokens=editor()
    check(post({**tokens,**copy,"action":"publish"})[0]==422,"Publication requires item verification")
    check(post({**tokens,**copy,"action":"publish","verified":"1"})[0]==403,"Publication requires password reauthentication")
    check(post({**tokens,**copy,"action":"publish","verified":"1","password":"incorrect"})[0]==403,"Wrong password cannot publish")
    check(post({**tokens,**copy,"action":"publish","verified":"1","password":"Local-test-only"})[0]==303,"Verified content publishes after reauthentication")
    body=request(path)[2]
    schema=fixture.product_schema(body)
    check(copy["description"] in body and "Long fixture note 0" not in body,"Reviewed description replaces public source notes")
    check(schema["description"]==copy["description"] and len(schema["description"])>160,"Schema carries complete reviewed description")
    check("<title>"+copy["seo_title"]+"</title>" in body and copy["seo_description"] in body,"Search overrides appear in page metadata")
    feed=request("/google-merchant-feed.php")[2]
    check(copy["description"] in feed,"Feed matches published description")
    check('https://flipandstrip.com/product/1/synthetic-test-part' in body,"Review preserves canonical URL")
    with sqlite3.connect(dbfile) as db:
        check("Long fixture note 0" in db.execute("SELECT description FROM products WHERE id=1").fetchone()[0],"Source text is preserved in inventory")
        check(db.execute("SELECT COUNT(*) FROM orders").fetchone()[0]==0,"Review does not create orders")
    body,tokens=editor()
    draft={**copy,"description":"Private replacement draft."}
    check(post({**tokens,**draft,"action":"draft"})[0]==303,"Further draft saves without withdrawing publication")
    check(copy["description"] in request(path)[2] and "Private replacement draft." not in request(path)[2],"Public page retains published revision")
    check(post({**tokens,**draft,"action":"draft"})[0]==409,"Stale revision cannot overwrite another editor")
    body,tokens=editor()
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE products SET description='Changed synthetic source after import' WHERE id=1")
    status,_,conflict=post({**tokens,**copy,"action":"publish","verified":"1"})
    check(status==409 and field(conflict,"source_hash")==tokens["source_hash"],"Changed source rejects publication and preserves stale token")
    check("Source changed" in editor()[0] and copy["description"] in request(path)[2],"Source changes flag review while preserving published corrections")
    quality=request("/admin/product-quality.php?issue=source_changed")[2]
    check("Synthetic test part" in quality and "Review content" in quality,"Quality dashboard exposes changed-source review")
    body,tokens=editor()
    check(post({**tokens,**copy,"action":"withdraw"})[0]==422,"Withdrawal requires explicit confirmation")
    check(post({**tokens,**copy,"action":"withdraw","confirm_withdraw":"1"})[0]==303,"Withdrawal returns to source on explicit request")
    check("Changed synthetic source after import" in request(path)[2] and copy["seo_title"] not in request(path)[2],"Withdrawal clears description and metadata overrides")
    body,tokens=editor()
    invalid_status,_,invalid_body=post({**tokens,"description":"<script>alert(1)</script>","action":"draft"})
    check(invalid_status==422 and "&lt;script&gt;alert(1)&lt;/script&gt;" in invalid_body,"HTML content is rejected and escaped text remains available for correction")
    check(post({**tokens,"description":"x"*5001,"action":"draft"})[0]==422,"Description size is bounded")
    for role,active in [("viewer",1),("admin",0)]:
        with sqlite3.connect(dbfile) as db:db.execute("UPDATE admin_users SET role=?,is_active=? WHERE id=9001",[role,active])
        check(request("/admin/product-content.php?id=1")[0]==403 and post({**tokens,**copy,"action":"draft"})[0]==403,"Inactive or non-admin account cannot read or write reviews")
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE admin_users SET role='admin',is_active=1 WHERE id=9001")
    body,tokens=editor()
    check(post({**tokens,**copy,"action":"publish","verified":"1"})[0]==403,"Access revocation clears previous password confirmation")
    check(post({**tokens,**copy,"action":"publish","verified":"1","password":"Local-test-only"})[0]==303,"Restored administrator can confirm password again")
    with sqlite3.connect(dbfile) as db:
        valid=db.execute("SELECT published_json FROM product_content_reviews WHERE product_id=1").fetchone()[0]
        db.execute("UPDATE product_content_reviews SET published_json='corrupt' WHERE product_id=1")
    check(request(path)[0]==503 and request("/google-merchant-feed.php")[0]==503,"Corrupt editorial storage fails retriably without exposing stale source as reviewed")
    editor_status,_,editor_body=request("/admin/product-content.php?id=1")
    check(editor_status==503 and "<fieldset disabled" in editor_body,"Corrupt review storage disables editor mutations")
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE product_content_reviews SET published_json=? WHERE product_id=1",[valid])
    check(request(path)[0]==200,"Restored storage recovers storefront")
    with sqlite3.connect(dbfile) as db:
        event_columns=[r[1] for r in db.execute("PRAGMA table_info(product_content_history)")]
        check(event_columns==["id","product_id","revision","action","actor_id","created_at"],"Review activity excludes passwords, request bodies and tokens")
    report={"date":datetime.now(timezone.utc).isoformat(),"scope":"local synthetic inventory","checks":len(rows),"passed":rows,"origin":ORIGIN,"fixture":str(BASE),"server_pid":fixture.server.pid,"production_verified":False}
    (ROOT/"audit/seo-content-local.json").write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report,indent=2))
except:
    fixture.server.terminate();fixture.server.wait(timeout=10)
    print("Fixture diagnostics:",BASE)
    raise
finally:
    if not keep:
        fixture.server.terminate();fixture.server.wait(timeout=10)
