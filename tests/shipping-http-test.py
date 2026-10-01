"""Isolated shipping endpoints with carrier transports mocked; no live accounts/labels."""
from pathlib import Path
from datetime import datetime, timezone
import json, os, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error

ROOT=Path(__file__).resolve().parents[1]
BASE=Path(tempfile.mkdtemp(prefix="fas-shipping-http-"))
SITE=BASE/"site"
SITE.mkdir()
for folder in ["src","includes","api"]:
    shutil.copytree(ROOT/folder,SITE/folder,ignore=shutil.ignore_patterns(
        "config.php","config.local.php","config.production.php","shipping.php","applepay.php","*.db","*.sqlite","*.log"))
# No provider network or charges: replace the payment lookup only inside this disposable site.
(SITE/"src/integrations/PayPalAPI.php").write_text("""<?php
namespace FAS\\Integrations;
class PayPalAPI {
    public function getOrderDetails($id) {
        $path=__DIR__.'/../../../paypal-response.json';
        if (!is_file($path)) return ['error'=>'fixture unavailable'];
        return json_decode(file_get_contents($path),true);
    }
}
""")
(SITE/"scripts").mkdir()
shutil.copy2(ROOT/"scripts/shipping-maintenance.php",SITE/"scripts/shipping-maintenance.php")
(SITE/"database").mkdir()
dbfile=SITE/"database/flipandstrip.db"
with sqlite3.connect(dbfile) as db:
    db.executescript((ROOT/"tests/fixtures/security-catalog.sql").read_text())
    db.execute("ALTER TABLE orders ADD COLUMN billing_address TEXT")
    db.execute("ALTER TABLE products ADD COLUMN warehouse_id INTEGER")
    db.execute("CREATE TABLE warehouses(id INTEGER PRIMARY KEY,name TEXT,address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country_code TEXT,is_active INTEGER,is_default INTEGER)")
    db.execute("INSERT INTO warehouses VALUES(1,'Fixture','100 Fixture Road','','Test City','KS','66614','US',1,1)")
    db.execute("INSERT INTO products(id,name,price,quantity,is_active,show_on_website,free_shipping,weight,length,width,height) VALUES(2,'Synthetic free-shipping part',20,10,1,1,1,1,10,10,10)")
    db.execute("UPDATE products SET warehouse_id=1")
(SITE/"src/config/config.php").write_text("""<?php
$c=require __DIR__.'/config.example.php';
$c['paypal']['mode']='live'; // Local quote-session fixture, never used for payments.
return $c;
""")
(SITE/"src/config/applepay.php").write_text("<?php return ['enabled'=>true,'admin_only'=>false];")
env={k:v for k,v in os.environ.items() if not k.startswith("FAS_")}
env["FAS_SECURITY_DB_PATH"]=str(BASE/"security.sqlite")
env["FAS_SHIPPING_CACHE_PATH"]=str(BASE/"shipping.sqlite")
(SITE/"src/config/shipping.php").write_text("""<?php
$c=require __DIR__.'/shipping.example.php';
$c['mode']=is_file(__DIR__.'/../../../fallback')?'direct_with_fallback':'direct';
$c['parcel_data_verified']=true;
foreach($c['carriers'] as &$p) {
    $p['enabled']=true; $p['environment']='production'; $p['production_verified']=true;
    $p['client_id']='fixture-client'; $p['client_secret']='fixture-secret'; $p['account_number']='TEST01';
}
return $c;
""")
# Change only the construction seam in the isolated copy; production has no mock switch.
factory=SITE/"src/shipping/ShippingRateService.php"
factory.write_text(factory.read_text().replace(
    "return new self(null,null,null,", "return new self(null,'shippingFixtureLegacy','shippingFixtureTransport',"))
bootstrap=BASE/"mock.php"
bootstrap.write_text("""<?php
function shippingFixtureTransport($url,$headers,$body,$timeout) {
    $base=dirname(__FILE__);
    file_put_contents($base.'/calls.jsonl', json_encode(['url'=>$url,'payload'=>json_decode($body,true)])."\\n",FILE_APPEND);
    if(is_file($base.'/fail-all')) return ['status'=>503,'data'=>[]];
    if(strpos($url,'token')!==false) return ['status'=>200,'data'=>['access_token'=>'fixture-token','expires_in'=>3600]];
    if(strpos($url,'usps')!==false) return ['status'=>200,'data'=>['rateOptions'=>[
        ['totalBasePrice'=>9,'rates'=>[['mailClass'=>'USPS_GROUND_ADVANTAGE','priceType'=>'RETAIL',
            'rateIndicator'=>'SP','processingCategory'=>'MACHINABLE','destinationEntryFacilityType'=>'NONE']]]
    ]]];
    return ['status'=>200,'data'=>['RateResponse'=>['RatedShipment'=>[
        'Service'=>['Code'=>'03'],'TotalCharges'=>['MonetaryValue'=>'11.00','CurrencyCode'=>'USD']
    ]]]];
}
function shippingFixtureLegacy($items,$address,$warehouse) {
    file_put_contents(dirname(__FILE__).'/legacy-called','yes');
    return [['courier_id'=>'original-easyship-id','courier_name'=>'Fixture legacy','service_name'=>'Standard',
        'total_charge'=>15,'currency'=>'USD','delivery_time_text'=>'Fixture only']];
}
""")
# Inspect only the server-owned quote, not any live session/customer data.
(SITE/"quote-inspect.php").write_text("""<?php
require __DIR__.'/src/payments/ApplePayContext.php';
\\FAS\\Payments\\ApplePayContext::startSession();
header('Content-Type: application/json');
echo json_encode(\\FAS\\Payments\\ApplePayContext::shipping($_GET['key'] ?? ''));
""")
subprocess.check_call(["php",str(SITE/"scripts/shipping-maintenance.php"),"init"],env=env,stdout=subprocess.DEVNULL)
shippingHealth=json.loads(subprocess.check_output(
    ["php",str(SITE/"scripts/shipping-maintenance.php"),"health"],env=env,text=True))
subprocess.check_call(["php",str(ROOT/"scripts/security-maintenance.php"),"init"],env=env,stdout=subprocess.DEVNULL)
subprocess.check_call(["php",str(ROOT/"scripts/security-maintenance.php"),"activate","--verified"],env=env,stdout=subprocess.DEVNULL)
with socket.socket() as s:
    s.bind(("127.0.0.1",0)); port=s.getsockname()[1]
ORIGIN=f"http://127.0.0.1:{port}"
log=open(BASE/"server.log","w")
server=subprocess.Popen(["php","-d","disable_functions=mail,curl_exec,curl_multi_exec","-d",f"auto_prepend_file={bootstrap}",
    "-S",f"127.0.0.1:{port}","-t",str(SITE)],env=env,stdout=log,stderr=log)
checks=[]
def check(ok,message):
    if not ok: raise AssertionError(message)
    checks.append(message)
def request(path,data=None,cookie=None):
    headers={"Content-Type":"application/json"}
    if cookie: headers["Cookie"]=cookie
    req=urllib.request.Request(ORIGIN+path,data=None if data is None else json.dumps(data).encode(),headers=headers)
    try:r=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e:r=e
    with r:
        body=r.read().decode()
        try:body=json.loads(body)
        except ValueError:pass
        return r.status,dict(r.headers),body
def calls():
    p=BASE/"calls.jsonl"
    return [json.loads(line) for line in p.read_text().splitlines()] if p.exists() else []
address={"address1":"200 Synthetic Street","address2":"","city":"Test City","state":"CA","zip":"90210","country":"US"}
cart={"items":[{"id":1,"quantity":1}],"address":address}
try:
    check(shippingHealth["label_operations"]=={"reserved":0,"submitted":0,"ready":0,"review":0},
          "CLI initialization and health include the private fulfillment operation ledger")
    check(shippingHealth["label_storage"]=={"initialized":True,"packages":0},
          "CLI health confirms private per-package label storage without revealing label content")
    for _ in range(40):
        try: request("/api/shipping-rates.php"); break
        except OSError:time.sleep(.1)
    status,headers,body=request("/api/shipping-rates.php",cart)
    check(status==200 and body["success"] and len(body["rates"])==2,"Checkout returns normalized direct USPS and UPS rates")
    check(body["rates"][0]["total_charge"]==9 and body["rates"][1]["total_charge"]==11,"Paid shipping totals remain numeric USD amounts")
    check(headers.get("Cache-Control")=="private, no-store","Checkout rates cannot be shared through public caches")
    quote=body["applepay_shipping_quote"]
    check(isinstance(quote,str) and len(quote)==32,"Direct rates obtain an Apple Pay server-owned quote")
    cookie=headers["Set-Cookie"].split(";",1)[0]
    stored=request("/quote-inspect.php?key="+quote,cookie=cookie)[2]
    check(stored["rates"]==body["rates"] and stored["cart"]=={"1":1},"Wallet quote stores the exact carrier rates and cart")
    check(stored["address"]["zip"]=="90210","Wallet quote remains bound to the destination")
    check(body["shipping_quote"]==quote and len(stored["shipment"]["packages"])==1,
          "Classic checkout shares the server-owned quote and packed parcel snapshot")
    order={"action":"create_order","customer_email":"test@example.invalid","customer_name":"Synthetic Buyer",
           "shipping_address":address,"items":[{"product_id":1,"product_name":"Forged browser name",
           "product_sku":"FORGED-SKU",
           "quantity":1,"unit_price":100}],"subtotal":100,"shipping_cost":9,"total_amount":109,
           "shipping_quote":quote,"shipping_index":0}
    for name,changes,want in [
        ("altered shipping price",{"shipping_cost":0},409),
        ("changed destination",{"shipping_address":{**address,"zip":"10001"}},409),
        ("invalid rate index",{"shipping_index":99},400)]:
        response=request("/api/process-order.php",{**order,**changes},cookie=cookie)
        check(response[0]==want and isinstance(response[2].get("error"),str),"Order rejects "+name)
    check(request("/api/process-order.php",order)[0]==409,"Quote cannot be used from another session")
    check(request("/api/process-order.php",1)[0]==400,"Non-object checkout JSON is rejected cleanly")
    changedPrice={**order,"items":[{**order["items"][0],"unit_price":0.01}]}
    check(request("/api/process-order.php",changedPrice,cookie=cookie)[0]==409,
          "Browser cannot lower the catalog unit price")
    check(request("/api/process-order.php",{**order,"total_amount":1},cookie=cookie)[0]==409,
          "Browser cannot lower the catalog total")
    created=request("/api/process-order.php",order,cookie=cookie)
    check(created[0]==200 and created[2]["success"],"Valid direct rate creates a pending local order")
    with sqlite3.connect(dbfile) as db:
        row=db.execute("SELECT provider,courier_id,service_code,quoted_cents,quote_hash,origin_json,packages_json,fulfillment_json "
                       "FROM order_shipping WHERE order_id=?",(created[2]["order_id"],)).fetchone()
        item=db.execute("SELECT product_name,product_sku,unit_price FROM order_items WHERE order_id=?",
                        (created[2]["order_id"],)).fetchone()
        count=db.execute("SELECT COUNT(*) FROM orders").fetchone()[0]
    check(row[0]=="usps" and row[1]==body["rates"][0]["courier_id"] and row[3]==900
          and row[4]!=quote and json.loads(row[6])[0]["weight"]==1 and count==1,
          "Order records selected carrier, service, price and measured parcel without storing quote secret")
    check(json.loads(row[7])==body["rates"][0]["parcel_services"]
          and json.loads(row[7])[0]["quoted_cents"]==900,
          "USPS order stores the exact quoted rate ingredients for its label")
    check(item==("Synthetic test part","TEST-1",100.0),
          "Order line names and prices come from inventory, not browser text")
    before=len(calls())
    again=request("/api/shipping-rates.php",cart)[2]
    check(again["success"] and len(calls())==before,"Repeated checkout requests reuse cached rates across HTTP sessions")
    estimate=request("/api/shipping-estimate.php",{"items":cart["items"],"address":{"city":"Test City","state":"CA","zip":"90210"}})
    check(estimate[0]==200 and estimate[2]["success"] and estimate[2]["lowest_rate"]["total_charge"]==9,"Pre-checkout estimator uses the same rate providers")
    lastUps=[c for c in calls() if "/rating/" in c["url"]][-1]
    check("AddressLine" not in lastUps["payload"]["RateRequest"]["Shipment"]["ShipTo"]["Address"],"Postal estimate omits fabricated street lines in UPS request")
    before=len(calls())
    free={"items":[{"id":2,"quantity":1}],"address":address}
    freeResult=request("/api/shipping-rates.php",free)[2]
    check(freeResult["success"] and freeResult["rates"][0]["total_charge"]==0 and len(calls())==before,"All-free carts bypass external shipping providers")
    mixed=request("/api/shipping-rates.php",{"items":[{"id":1,"quantity":1},{"id":2,"quantity":2}],"address":address})[2]
    check(mixed["success"] and mixed["rates"][0]["total_charge"]==9 and mixed["free_shipping"]["item_count"]==2,"Mixed carts rate only paid-shipping items")
    for route in ["shipping-rates.php","shipping-estimate.php"]:
        check(request("/api/"+route,{**cart,"items":[{"id":1,"quantity":0}]})[0]==400,"Invalid quantity rejected before carrier work: "+route)
        check(request("/api/"+route,{**cart,"items":cart["items"]*101})[0]==400,"Cart expansion bounded before carrier work: "+route)
        check(request("/api/"+route)[0]==405,"Shipping endpoint requires POST: "+route)
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE products SET show_on_website=0 WHERE id=1")
    check(request("/api/shipping-rates.php",cart)[0]==400,"Hidden product cannot receive a checkout quote")
    with sqlite3.connect(dbfile) as db:
        db.execute("UPDATE products SET show_on_website=1 WHERE id=1")
        db.execute("UPDATE warehouses SET is_active=0 WHERE id=1")
    check(request("/api/shipping-rates.php",cart)[2]["success"] is False,"Inactive assigned warehouse cannot be replaced with an invented origin")
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE warehouses SET is_active=1 WHERE id=1")
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE products SET show_on_website=1,weight=NULL WHERE id=1")
    check(request("/api/shipping-rates.php",cart)[2]["success"] is False,"Missing packed weight cannot silently become a direct shipping quote")
    (BASE/"fallback").touch()
    fallbackResponse=request("/api/shipping-rates.php",cart)
    fallback=fallbackResponse[2]
    check(fallback["success"] and fallback["rates"][0]["provider"]=="easyship","Explicit fallback mode preserves checkout on incomplete parcel data")
    legacyOrder={**order,"shipping_quote":fallback["shipping_quote"],"shipping_cost":15,"total_amount":115}
    legacyCookie=fallbackResponse[1]["Set-Cookie"].split(";",1)[0]
    legacyCreated=request("/api/process-order.php",legacyOrder,cookie=legacyCookie)
    check(legacyCreated[0]==200 and legacyCreated[2]["success"],"Legacy fallback still creates a pending order")
    with sqlite3.connect(dbfile) as db:
        legacyShipping=db.execute("SELECT provider,quoted_cents FROM order_shipping WHERE order_id=?",
                                  (legacyCreated[2]["order_id"],)).fetchone()
    check(legacyShipping==("easyship",1500),"Fallback order preserves Easyship selection")
    with sqlite3.connect(dbfile) as db:db.execute("UPDATE products SET weight=1 WHERE id=1")
    (BASE/"fail-all").touch()
    changed={**cart,"address":{**address,"zip":"10001"}}
    fallback=request("/api/shipping-rates.php",changed)[2]
    check(fallback["success"] and fallback["rates"][0]["courier_id"]=="original-easyship-id","Carrier outage uses Easyship only when fallback is enabled")
    (BASE/"fallback").unlink()
    unavailable=request("/api/shipping-rates.php",changed)[2]
    check(unavailable["success"] is False and "rates" not in unavailable,"Direct-only carrier outage never invents a free quote")
    with sqlite3.connect(dbfile) as db:
        pending=db.execute("SELECT COUNT(*) FROM orders WHERE payment_status='pending'").fetchone()[0]
        captured=db.execute("SELECT COUNT(*) FROM orders WHERE payment_status='completed'").fetchone()[0]
    check(pending==2 and captured==0,"Quote and order tests do not capture payments or buy labels")
    with sqlite3.connect(dbfile) as db:
        local=db.execute("SELECT order_number,total_amount FROM orders WHERE id=?",(created[2]["order_id"],)).fetchone()
        originalStock=db.execute("SELECT quantity FROM products WHERE id=1").fetchone()[0]
    provider={"id":"PAYPAL123456","status":"COMPLETED","intent":"CAPTURE","purchase_units":[{
        "invoice_id":local[0],"custom_id":"FAS-CHECKOUT-"+str(created[2]["order_id"]),
        "payee":{"merchant_id":"FIXTUREMERCHANT"},
        "amount":{"currency_code":"USD","value":f"{local[1]:.2f}"},
        "shipping":{"address":{"address_line_1":address["address1"],"address_line_2":"",
            "admin_area_2":address["city"],"admin_area_1":address["state"],
            "postal_code":address["zip"],"country_code":"US"}},
        "payments":{"captures":[{"id":"CAPTURE123456","status":"COMPLETED",
            "amount":{"currency_code":"USD","value":f"{local[1]:.2f}"},"final_capture":True}]}
    }]}
    responseFile=BASE/"paypal-response.json"
    complete={"action":"complete_order","order_id":created[2]["order_id"],
              "paypal_order_id":"PAYPAL123456","paypal_transaction_id":"CAPTURE123456"}
    check(request("/api/process-order.php",complete)[0]==503,
          "Provider lookup outage leaves payment pending and inventory untouched")
    tampered=json.loads(json.dumps(provider));tampered["purchase_units"][0]["amount"]["value"]="1.00"
    responseFile.write_text(json.dumps({"success":True,"data":tampered}))
    check(request("/api/process-order.php",complete)[0]==409,
          "Captured provider amount must match the saved local order")
    waiting=json.loads(json.dumps(provider));waiting["status"]="APPROVED"
    responseFile.write_text(json.dumps({"success":True,"data":waiting}))
    waitingResult=request("/api/process-order.php",complete)
    check(waitingResult[0]==503 and waitingResult[1].get("Retry-After")=="30",
          "Uncaptured payment can be retried without starting another charge")
    with sqlite3.connect(dbfile) as db:
        check(db.execute("SELECT quantity FROM products WHERE id=1").fetchone()[0]==originalStock,
              "Rejected and unavailable provider responses never deduct inventory")
    responseFile.write_text(json.dumps({"success":True,"data":provider}))
    paid=request("/api/process-order.php",complete)
    check(paid[0]==200 and paid[2]["success"],"Verified capture completes the selected carrier order")
    with sqlite3.connect(dbfile) as db:
        payment=db.execute("SELECT payment_status,paypal_order_id,paypal_transaction_id FROM orders WHERE id=?",
                           (created[2]["order_id"],)).fetchone()
        stock=db.execute("SELECT quantity FROM products WHERE id=1").fetchone()[0]
    check(payment==("completed","PAYPAL123456","CAPTURE123456") and stock==originalStock-1,
          "Provider capture reference is saved and inventory deducted exactly once")
    check(request("/api/process-order.php",complete)[0]==200,
          "Repeating the same completed order is idempotent")
    with sqlite3.connect(dbfile) as db:
        check(db.execute("SELECT quantity FROM products WHERE id=1").fetchone()[0]==stock,
              "Repeat completion does not deduct stock again")
    wrong={**complete,"paypal_order_id":"OTHERPAYPAL123"}
    check(request("/api/process-order.php",wrong)[0]==409,
          "Completed orders reject a different payment reference")
    with sqlite3.connect(dbfile) as db:
        legacy=db.execute("SELECT order_number,total_amount FROM orders WHERE id=?",
                          (legacyCreated[2]["order_id"],)).fetchone()
    reused=json.loads(json.dumps(provider));reused["id"]="ANOTHERPAYPAL123"
    reused["purchase_units"][0]["invoice_id"]=legacy[0]
    reused["purchase_units"][0]["custom_id"]="FAS-CHECKOUT-"+str(legacyCreated[2]["order_id"])
    reused["purchase_units"][0]["amount"]["value"]=f"{legacy[1]:.2f}"
    reused["purchase_units"][0]["payments"]["captures"][0]["amount"]["value"]=f"{legacy[1]:.2f}"
    responseFile.write_text(json.dumps({"success":True,"data":reused}))
    duplicate={"action":"complete_order","order_id":legacyCreated[2]["order_id"],
               "paypal_order_id":"ANOTHERPAYPAL123","paypal_transaction_id":"CAPTURE123456"}
    check(request("/api/process-order.php",duplicate)[0]==409,
          "A captured transaction cannot fulfill a second order")
    review=json.loads(json.dumps(reused))
    review["purchase_units"][0]["payments"]["captures"][0]["id"]="NEWCAPTURE123456"
    responseFile.write_text(json.dumps({"success":True,"data":review}))
    reviewRequest={**duplicate,"paypal_transaction_id":"NEWCAPTURE123456"}
    reviewResult=request("/api/process-order.php",reviewRequest)
    check(reviewResult[0]==409 and "needs inventory review" in reviewResult[2]["error"],
          "Captured payment with lost stock requires review instead of fulfillment")
    with sqlite3.connect(dbfile) as db:
        reviewOrder=db.execute("SELECT payment_status,order_status,paypal_transaction_id,notes FROM orders WHERE id=?",
                               (legacyCreated[2]["order_id"],)).fetchone()
        stockAfterReview=db.execute("SELECT quantity FROM products WHERE id=1").fetchone()[0]
    check(reviewOrder[0:3]==("completed","pending","NEWCAPTURE123456")
          and "[PAYPAL REVIEW REQUIRED]" in reviewOrder[3] and stockAfterReview==stock,
          "Captured funds are recorded for administrator review without a second stock deduction")
    check(request("/api/process-order.php",reviewRequest)[0]==409,
          "Review orders cannot be converted to checkout success by retrying")
    # Existing shared shipping rate-limit contract remains enforced.
    code="require $argv[1]; $s=\\FAS\\Security\\SecurityStore::open(); $s->saveRule('shipping',1,3600,'enforce','127.0.0.1',1);"
    subprocess.check_call(["php","-r",code,str(ROOT/"src/security/SecurityStore.php")],env=env)
    request("/api/shipping-estimate.php",{})
    limited=request("/api/shipping-rates.php",{})
    check(limited[0]==429 and int(limited[1]["Retry-After"])>0,"Shipping endpoints retain their shared rate limit and Retry-After")
    report={"date":datetime.now(timezone.utc).isoformat(),"scope":"local synthetic inventory; mocked carrier transport",
        "checks":len(checks),"passed":checks,"live_carrier_calls":0,"production_verified":False}
    (ROOT/"audit/shipping-http-local.json").write_text(json.dumps(report,indent=2)+"\n")
    print(f"PASS {len(checks)} shipping HTTP checks. No live carrier calls or label purchases.")
finally:
    server.terminate();server.wait(timeout=10);log.close()
