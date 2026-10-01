"""Disposable storefront fixture. No production database, emails, payments or sync calls."""
from pathlib import Path
import json, os, re, shutil, socket, sqlite3, subprocess, sys, tempfile, time
from datetime import datetime, timezone
import urllib.request, urllib.error

ROOT = Path(__file__).resolve().parents[1]
with socket.socket() as listener:
    listener.bind(("127.0.0.1",0))
    PORT=listener.getsockname()[1]
ORIGIN="http://127.0.0.1:"+str(PORT)
BASE = Path(tempfile.mkdtemp(prefix="fas-seo-presentation-"))
SITE = BASE / "site"
SITE.mkdir()
for folder in ["src", "includes", "public", "api", "scripts"]:
    shutil.copytree(ROOT / folder, SITE / folder, ignore=shutil.ignore_patterns(
        "config.php", "config.local.php", "config.production.php", "uploads", "*.log", "*.db", "*.sqlite"))
for name in ["product.php", "products.php", "index.php", "cart.php", "checkout.php", "google-merchant-feed.php"]:
    shutil.copy2(ROOT / name, SITE / name)
shutil.copy2(ROOT / "src/config/config.example.php", SITE / "src/config/config.php")
(SITE / "src/integrations/EbayAPI.php").write_text("""<?php namespace FAS\\Integrations;
class EbayAPI {
    public function __construct($config) {}
    public function getStoreCategoriesHierarchical(){return [];}
    public function getStoreCategories(){return [];}
}
""")
(SITE / "database").mkdir()
(SITE / "gallery").mkdir()
for name in ["logo.png", "FLIPANDSTRIP.COM_d00a_018a.jpg"]:
    if (ROOT / "gallery" / name).is_file():
        shutil.copy2(ROOT / "gallery" / name, SITE / "gallery" / name)
if (ROOT / "gallery/favicons").is_dir():
    shutil.copytree(ROOT / "gallery/favicons", SITE / "gallery/favicons")
shutil.copy2(ROOT / "gallery/FLIPANDSTRIP.COM_d00a_018a.jpg", SITE / "gallery/default.jpg")
source="/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg"
with sqlite3.connect(SITE / "database/flipandstrip.db") as db:
    db.executescript((ROOT / "tests/fixtures/security-catalog.sql").read_text())
    notes="Synthetic QA description, not real product information.\n" + "\n".join(
        "Long fixture note %d. Check visible product details, photos, price, condition and availability." % i for i in range(40))
    db.execute("UPDATE products SET description=?,image_url=?,images=?,model='N/A',condition_name='New with tags'",
               [notes, source, json.dumps([source, "/gallery/default.jpg"])])
    row=dict(zip([r[1] for r in db.execute("PRAGMA table_info(products)")], db.execute("SELECT * FROM products").fetchone()))
    row.update(id=2, name="Synthetic sold out part", sku="TEST-2", quantity=0, model="2203143", condition_name="Used")
    db.execute("INSERT INTO products(%s) VALUES(%s)" % (",".join(row),",".join("?"*len(row))), list(row.values()))
env=os.environ.copy()
env["FAS_SECURITY_DB_PATH"]=str(BASE / "private/security.sqlite")
env["FAS_GROWTH_DB_PATH"]=str(BASE / "private/growth.sqlite")
env.pop("FAS_TRUSTED_PROXY_CIDRS",None)
build=subprocess.check_output(["php",str(SITE / "scripts/build-responsive-images.php"),"--inventory","--limit=100"],env=env,text=True)
# Independent route mapping for PHP's development server, not production routing.
router=BASE/"router.php"
router.write_text("""<?php
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/product/([0-9]+)(?:/[^/]+)?$~',$p,$m)){$_GET['id']=$m[1];require $_SERVER['DOCUMENT_ROOT'].'/product.php';return true;}
if(in_array($p,['/products','/cart','/checkout'],true)){require $_SERVER['DOCUMENT_ROOT'].$p.'.php';return true;}
if($p==='/without-animation-library'){
    $_SERVER['REQUEST_URI']='/products'; ob_start();require $_SERVER['DOCUMENT_ROOT'].'/products.php';$body=ob_get_clean();
    echo preg_replace('~<script src="https://unpkg.com/aos[^"]+"></script>~','',$body);return true;
}
return false;
""")
log=open(BASE/"server.log","w")
server=subprocess.Popen(["php","-d","disable_functions=mail","-S","127.0.0.1:"+str(PORT),"-t",str(SITE),str(router)],
    env=env,stdout=log,stderr=log,creationflags=getattr(subprocess,"CREATE_NO_WINDOW",0))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
client=urllib.request.build_opener(NoRedirect)
def request(path):
    try:r=client.open(ORIGIN+path,timeout=15)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,dict(r.headers),r.read().decode()
rows=[]
def check(ok,message):
    if not ok:raise AssertionError(message)
    rows.append(message)
def product_schema(body):
    for match in re.findall(r'<script type="application/ld\+json">(.*?)</script>',body,re.S):
        value=json.loads(match)
        for node in value if isinstance(value,list) else [value]:
            if node.get("@type")=="Product":return node
    raise AssertionError("Product schema missing")
try:
    for attempt in range(40):
        if server.poll() is not None:raise RuntimeError("Fixture server did not start")
        try:request("/products");break
        except OSError:time.sleep(.1)
    status,headers,body=request("/product/1/synthetic-test-part")
    check(status==200,"Product detail renders")
    check(body.index('<h1')<body.index('class="product-gallery"')<body.index('class="product-purchase-column"')<body.index('class="product-details"'),"Mobile DOM prioritizes product identity and purchase controls")
    detail_section=body.split('class="product-details"',1)[1].split('</section>',1)[0]
    check(detail_section.count('Long fixture note ')==40,"All original listing notes retained")
    main=re.search(r'<img [^>]*id="main-product-image"[^>]*>',body,re.S)[0]
    check(all(x in main for x in ['srcset=','width=','height=','loading="eager"','fetchpriority="high"']),"Main image includes responsive options and dimensions, eager priority preserved")
    check('aria-label="Open full-size product photo"' in body and 'href="'+source+'"' in body,"Original photo remains directly accessible")
    check(body.count('class="product-thumbnail ')==2 and 'aria-pressed="true"' in body,"Gallery provides named native buttons with selection state")
    schema=product_schema(body)
    check(schema["offers"]["itemCondition"].endswith("/NewCondition") and "mpn" not in schema,"Schema normalizes explicit new condition and omits placeholder MPN")
    check(schema["image"][0].endswith(source) and "/responsive/" not in schema["image"][0],"Schema retains full quality source image")
    status,_,sold=request("/product/2/synthetic-sold-out-part")
    check(status==200 and 'Out of stock</span>' in sold and 'In Stock</span>' not in sold,"Sold out page has truthful visible stock badge")
    check(product_schema(sold)["offers"]["availability"].endswith("/OutOfStock"),"Sold out schema agrees with display")
    buy=re.search(r'<button[^>]*product-buy-now[^>]*>',sold,re.S)[0]
    add=re.search(r'<button[^>]*product-detail-add-to-cart[^>]*>',sold,re.S)[0]
    check("disabled" in buy and "disabled" in add,"Sold out detail purchase controls disabled")
    check(request("/product/999999999")[0]==404,"Missing products remain 404")
    status,_,catalog=request("/products")
    check(status==200,"Catalog renders")
    api=json.loads(request("/api/products.php")[2])
    check("html" in api,"API renders without warnings: "+str(api.get("message","OK")))
    markup=api["html"]
    for surface,b in [("server catalog",catalog),("API catalog",markup),("related products",body)]:
        check('/gallery/responsive/' in b and 'loading="lazy"' in b,surface+" includes responsive lazy images")
        buttons=re.findall(r'<button[^>]*class="[^"]*add-to-cart[^"]*"[^>]*>',b,re.S)
        target=[b for b in buttons if 'data-id="2"' in b]
        check(target and "disabled" in target[0],surface+" disables sold out purchase")
    status,_,feed=request("/google-merchant-feed.php")
    check(status==200 and "<g:condition>new</g:condition>" in feed,"Feed uses matching new condition")
    check("<g:mpn>N/A</g:mpn>" not in feed and "<g:mpn>2203143</g:mpn>" in feed,"Feed omits placeholders and retains real part number")
    check("/gallery/responsive/" not in feed and source in feed,"Feed retains original source photo")
    check("AOS.init" in request("/without-animation-library")[2],"Animation outage fixture retains guarded initialization")
    report={"date":datetime.now(timezone.utc).isoformat(),"scope":"local synthetic fixture only","origin":ORIGIN,"checks":len(rows),"passed":rows,"image_builds":[json.loads(l) for l in build.splitlines() if l],
            "fixture":str(BASE),"server_pid":server.pid,"production_verified":False}
    (ROOT/"audit/seo-presentation-local.json").write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report,indent=2))
except:
    print("Fixture diagnostics:",BASE)
    server.terminate();server.wait(timeout=10)
    raise
finally:
    if "--keep" not in sys.argv:
        server.terminate();server.wait(timeout=10);log.close()
