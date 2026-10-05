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
if (is_file(__DIR__.'/../../../invalid-shipping-config')) throw new RuntimeException('Fixture config outage');
$c=require __DIR__.'/shipping.example.php';
$c['mode']=is_file(__DIR__.'/../../../legacy-mode')?'easyship':
    (is_file(__DIR__.'/../../../fallback')?'direct_with_fallback':'direct');
$c['parcel_data_verified']=true;
foreach($c['carriers'] as &$p) {
    $p['enabled']=true; $p['environment']='production'; $p['production_verified']=true;
    $p['client_id']='fixture-client'; $p['client_secret']='fixture-secret'; $p['account_number']='TEST01';
}
$c['carriers']['usps']['enabled']=!is_file(__DIR__.'/../../../disable-usps');
return $c;
""")
# Change only the construction seam in the isolated copy; production has no mock switch.
factory=SITE/"src/shipping/ShippingRateService.php"
factory.write_text(factory.read_text().replace(
    "return new self($config,null,null,", "return new self($config,'shippingFixtureLegacy','shippingFixtureTransport',"))
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
trackingRefresh=json.loads(subprocess.check_output(
    ["php",str(SITE/"scripts/shipping-maintenance.php"),"refresh-tracking"],env=env,text=True))
notificationPrepare=json.loads(subprocess.check_output(
    ["php","-d","disable_functions=mail,curl_exec,curl_multi_exec",str(SITE/"scripts/shipping-maintenance.php"),"prepare-notifications"],env=env,text=True))
notificationSend=json.loads(subprocess.check_output(
    ["php","-d","disable_functions=mail,curl_exec,curl_multi_exec",str(SITE/"scripts/shipping-maintenance.php"),"send-notifications","--deliver"],env=env,text=True))
notificationGuard=subprocess.run(
    ["php","-d","disable_functions=mail,curl_exec,curl_multi_exec",str(SITE/"scripts/shipping-maintenance.php"),"send-notifications"],env=env,text=True,capture_output=True)
shippingReadiness=json.loads(subprocess.check_output(
    ["php","-d","disable_functions=mail,curl_exec,curl_multi_exec",str(SITE/"scripts/shipping-maintenance.php"),"readiness"],env=env,text=True))
subprocess.check_call(["php",str(ROOT/"scripts/security-maintenance.php"),"init"],env=env,stdout=subprocess.DEVNULL)
subprocess.check_call(["php",str(ROOT/"scripts/security-maintenance.php"),"activate","--verified"],env=env,stdout=subprocess.DEVNULL)
# The fixture makes more synthetic order-creation attempts than one shopper should;
# its payment budget is raised only here so later shipping assertions stay reachable.
with sqlite3.connect(BASE/'security.sqlite') as db:
    db.execute("INSERT INTO security_rules(rule,capacity,seconds,mode) VALUES('payment_create',20,600,'enforce')")
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
    check(shippingHealth['notifications']['initialized'] and not shippingHealth['notifications']['enabled'],
          'CLI initialization installs the disabled notification queue')
    check(notificationPrepare['notification_preparation']['queued']==0
          and notificationSend['notification_delivery']['accepted']==0,
          'Disabled notification commands work with mail and carrier transports unavailable')
    check(notificationGuard.returncode==1 and '--deliver' in notificationGuard.stderr,
          'Notification delivery requires an explicit command flag before accessing storage')
    check(not shippingReadiness['readiness']['external_services_checked']
          and not shippingReadiness['readiness']['production_acceptance_verified'],
          'Readiness reports configuration without claiming external carrier acceptance')
    check(shippingReadiness['catalog']['schema_initialized']
          and shippingReadiness['catalog']['saleable_products']==2
          and shippingReadiness['readiness']['catalog']['data_complete_for_direct_quotes'],
          'Readiness scans saleable packed products and their configured origin')
    check(shippingHealth["label_operations"]=={"reserved":0,"submitted":0,"ready":0,"review":0},
          "CLI initialization and health include the private fulfillment operation ledger")
    check(shippingHealth["label_storage"]=={"initialized":True,"packages":0},
          "CLI health confirms private per-package label storage without revealing label content")
    check(shippingHealth['reservation_handoff_storage']['initialized']
          and shippingReadiness['readiness']['fulfillment_storage_configured'],
          'Readiness confirms audited reserved-label handoffs and fulfillment storage')
    with sqlite3.connect(BASE/'shipping.sqlite') as db:
        db.execute('ALTER TABLE shipping_label_handoffs RENAME TO hidden_handoffs')
    incomplete=json.loads(subprocess.check_output(
        ['php','-d','disable_functions=mail,curl_exec,curl_multi_exec',
         str(SITE/'scripts/shipping-maintenance.php'),'readiness'],env=env,text=True))
    check(not incomplete['reservation_handoff_storage']['initialized']
          and not incomplete['readiness']['fulfillment_storage_configured'],
          'Readiness flags missing handoff migration before label purchases')
    with sqlite3.connect(BASE/'shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_handoffs RENAME TO shipping_label_handoffs')
    check(shippingHealth['label_reprint_storage']['initialized']
          and shippingReadiness['readiness']['reprint_storage_configured'],
          'Readiness confirms private USPS reprint recovery storage')
    with sqlite3.connect(BASE/'shipping.sqlite') as db:
        db.execute('ALTER TABLE shipping_label_reprints RENAME TO hidden_reprints')
    incomplete=json.loads(subprocess.check_output(
        ['php','-d','disable_functions=mail,curl_exec,curl_multi_exec',
         str(SITE/'scripts/shipping-maintenance.php'),'readiness'],env=env,text=True))
    check(not incomplete['label_reprint_storage']['initialized']
          and not incomplete['readiness']['reprint_storage_configured'],
          'Readiness flags a missing USPS reprint migration')
    with sqlite3.connect(BASE/'shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_reprints RENAME TO shipping_label_reprints')
    check(shippingHealth['label_resolution_storage']['initialized']
          and shippingReadiness['readiness']['label_resolution_storage_configured'],
          'Readiness confirms private carrier-evidence label review storage')
    with sqlite3.connect(BASE/'shipping.sqlite') as db:
        db.execute('ALTER TABLE shipping_label_resolutions RENAME TO hidden_label_resolutions')
    incomplete=json.loads(subprocess.check_output(
        ['php','-d','disable_functions=mail,curl_exec,curl_multi_exec',
         str(SITE/'scripts/shipping-maintenance.php'),'readiness'],env=env,text=True))
    check(not incomplete['label_resolution_storage']['initialized']
          and not incomplete['readiness']['label_resolution_storage_configured']
          and not incomplete['readiness']['fulfillment_storage_configured'],
          'Readiness flags a missing original-label review migration')
    with sqlite3.connect(BASE/'shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_label_resolutions RENAME TO shipping_label_resolutions')
    check(shippingHealth["tracking"]=={"initialized":True,"packages":0,"with_status":0,"last_attempt_failed":0},
          "CLI initialization and health include private tracking status storage")
    check(shippingReadiness['readiness']['cancellation_storage_configured']
          and shippingReadiness['readiness']['tracking_storage_configured']
          and shippingReadiness['readiness']['notifications']['storage_initialized'],
          'Readiness separates cancellation, tracking and email storage from carrier activation')
    check(trackingRefresh["tracking_refresh"]["enabled_carriers"]==[]
          and trackingRefresh["tracking_refresh"]["selected"]==0,
          "Disabled tracking refresh makes no carrier request")
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
    publicStored=[{k:v for k,v in rate.items() if k not in ("carrier_quote_cents","parcel_services")}
                  for rate in stored["rates"]]
    check(publicStored==body["rates"] and stored["cart"]=={"1":1},
          "Wallet quote keeps private label prices and options while returning the selected public rates")
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
    (BASE/'legacy-mode').touch()
    staleMode=request('/api/process-order.php',order,cookie=cookie)
    (BASE/'legacy-mode').unlink()
    check(staleMode[0]==409 and staleMode[2].get('code')=='shipping_changed'
          and 'Calculate shipping again' in staleMode[2]['error'],
          'A direct quote cannot start a new order after rollback to Easyship')
    with sqlite3.connect(dbfile) as db:
        check(db.execute('SELECT COUNT(*) FROM orders').fetchone()[0]==0,
              'Mode-change rejection leaves no pending order')
    (BASE/'disable-usps').touch()
    disabledCarrier=request('/api/process-order.php',order,cookie=cookie)
    (BASE/'disable-usps').unlink()
    check(disabledCarrier[0]==409 and disabledCarrier[2].get('code')=='shipping_changed'
          and 'Calculate shipping again' in disabledCarrier[2]['error'],
          'Disabling USPS invalidates its saved quote before a new order starts')
    with sqlite3.connect(dbfile) as db:
        check(db.execute('SELECT COUNT(*) FROM orders').fetchone()[0]==0,
              'Carrier-disable rejection leaves no pending order')
    (BASE/'invalid-shipping-config').touch()
    missingConfig=request('/api/process-order.php',order,cookie=cookie)
    (BASE/'invalid-shipping-config').unlink()
    check(missingConfig[0]==503 and missingConfig[2].get('code')=='shipping_unavailable'
          and 'temporarily unavailable' in missingConfig[2]['error'],
          'A shipping configuration outage refuses new PayPal orders clearly')
    with sqlite3.connect(dbfile) as db:
        check(db.execute('SELECT COUNT(*) FROM orders').fetchone()[0]==0,
              'Shipping configuration outage leaves no pending order')
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
    check(json.loads(row[7])==stored["rates"][0]["parcel_services"]
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
    free={"items":[{"id":2,"quantity":1}],"address":address}
    freeResponse=request("/api/shipping-rates.php",free)
    freeResult=freeResponse[2]
    check(freeResult["success"] and len(freeResult["rates"])==2
          and all(rate["total_charge"]==0 for rate in freeResult["rates"]),
          "All-free direct carts retain a zero customer charge while obtaining a carrier service")
    freeCookie=freeResponse[1]["Set-Cookie"].split(";",1)[0]
    freeStored=request("/quote-inspect.php?key="+freeResult["shipping_quote"],cookie=freeCookie)[2]
    check(freeStored["rates"][0]["carrier_quote_cents"]==900
          and len(freeStored["shipment"]["packages"])==1
          and "carrier_quote_cents" not in freeResult["rates"][0],
          "Free direct quote keeps its private carrier cost and packed parcel in the server session")
    freeOrder={**order,"items":[{"product_id":2,"quantity":1,"unit_price":20}],"subtotal":20,
               "shipping_cost":0,"total_amount":20,"shipping_quote":freeResult["shipping_quote"]}
    freeCreated=request("/api/process-order.php",freeOrder,cookie=freeCookie)
    check(freeCreated[0]==200 and freeCreated[2]["success"],
          "A free-shipping direct service creates a pending order without a customer shipping charge")
    with sqlite3.connect(dbfile) as db:
        freeRow=db.execute("SELECT provider,quoted_cents,carrier_quote_cents,packages_json,fulfillment_json "
                           "FROM order_shipping WHERE order_id=?",(freeCreated[2]["order_id"],)).fetchone()
    check(freeRow[0]=="usps" and freeRow[1:3]==(0,900)
          and len(json.loads(freeRow[3]))==len(json.loads(freeRow[4]))==1,
          "Free order saves an actionable direct label quote separately from the customer charge")
    freeEstimate=request("/api/shipping-estimate.php",
                         {"items":free["items"],"address":{"city":"Test City","state":"CA","zip":"90210"}})[2]
    check(freeEstimate["success"] and freeEstimate["lowest_rate"]["total_charge"]==0,
          "Free pre-checkout estimate is offered only after a direct carrier can rate the parcel")
    (BASE/"legacy-mode").touch()
    legacyBefore=len(calls())
    legacyFree=request("/api/shipping-rates.php",free)[2]
    check(legacyFree["success"] and legacyFree["rates"][0]["courier_id"]=="free_shipping"
          and len(calls())==legacyBefore,
          "Easyship mode preserves the existing all-free checkout without direct carrier calls")
    (BASE/"legacy-mode").unlink()
    mixedCart={"items":[{"id":1,"quantity":1},{"id":2,"quantity":2}],"address":address}
    mixedResponse=request("/api/shipping-rates.php",mixedCart)
    mixed=mixedResponse[2]
    check(mixed["success"] and mixed["rates"][0]["total_charge"]==9
          and mixed["free_shipping"]["item_count"]==2,
          "Mixed carts charge only paid-shipping items")
    mixedCookie=mixedResponse[1]["Set-Cookie"].split(";",1)[0]
    mixedStored=request("/quote-inspect.php?key="+mixed["shipping_quote"],cookie=mixedCookie)[2]
    check(mixedStored["rates"][0]["carrier_quote_cents"]==2700
          and len(mixedStored["shipment"]["packages"])==3
          and len(mixedStored["rates"][0]["parcel_services"])==3,
          "Mixed direct quote includes every packed item in its carrier estimate and label options")
    mixedOrder={**order,"items":[{"product_id":1,"quantity":1,"unit_price":100},
                                 {"product_id":2,"quantity":2,"unit_price":20}],
                "subtotal":140,"shipping_cost":9,"total_amount":149,"shipping_quote":mixed["shipping_quote"]}
    mixedCreated=request("/api/process-order.php",mixedOrder,cookie=mixedCookie)
    check(mixedCreated[0]==200 and mixedCreated[2]["success"],
          "Mixed direct cart creates a pending order with the customer shipping charge intact")
    with sqlite3.connect(dbfile) as db:
        mixedRow=db.execute("SELECT provider,quoted_cents,carrier_quote_cents,packages_json,fulfillment_json "
                            "FROM order_shipping WHERE order_id=?",(mixedCreated[2]["order_id"],)).fetchone()
    check(mixedRow[0]=="usps" and mixedRow[1:3]==(900,2700)
          and len(json.loads(mixedRow[3]))==len(json.loads(mixedRow[4]))==3,
          "Mixed order stores a full-cart label scope without charging the customer for free items")
    mixedEstimate=request("/api/shipping-estimate.php",
                          {"items":mixedCart["items"],"address":{"city":"Test City","state":"CA","zip":"90210"}})[2]
    check(mixedEstimate["success"] and mixedEstimate["lowest_rate"]["total_charge"]==9,
          "Mixed pre-checkout estimate checks that a full-cart direct service exists")
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
    check(legacyCreated[0]==200 and legacyCreated[2]["success"],
          "Legacy fallback still creates a pending order")
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
    unavailableFree=request("/api/shipping-rates.php",{**free,"address":changed["address"]})
    check(unavailableFree[0]==503 and unavailableFree[2]["success"] is False,
          "Direct-only carrier outage does not create an unfulfillable free-shipping quote")
    with sqlite3.connect(dbfile) as db:
        pending=db.execute("SELECT COUNT(*) FROM orders WHERE payment_status='pending'").fetchone()[0]
        captured=db.execute("SELECT COUNT(*) FROM orders WHERE payment_status='completed'").fetchone()[0]
    check(pending==4 and captured==0,"Quote and order tests do not capture payments or buy labels")
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
    with sqlite3.connect(dbfile) as db:
        db.execute("ALTER TABLE order_shipping RENAME COLUMN carrier_quote_cents TO old_carrier_quote_cents")
    unmigrated=json.loads(subprocess.check_output(
        ["php","-d","disable_functions=mail,curl_exec,curl_multi_exec",
         str(SITE/"scripts/shipping-maintenance.php"),"readiness"],env=env,text=True))
    check(unmigrated['order_shipping_storage'] and not unmigrated['direct_order_storage']
          and not unmigrated['readiness']['storage_configured'],
          "Readiness distinguishes a historical order table from migrated direct-order storage")
    check(request("/api/shipping-rates.php",cart)[0]==503,
          "Direct checkout refuses rates before the additive order migration")
    (BASE/"fallback").touch()
    unmigratedFallback=request("/api/shipping-rates.php",cart)[2]
    check(unmigratedFallback['success'] and unmigratedFallback['rates'][0]['provider']=='easyship',
          "Fallback mode keeps Easyship checkout available before the order migration")
    (BASE/"fallback").unlink()
    with sqlite3.connect(dbfile) as db:
        db.execute("ALTER TABLE order_shipping RENAME COLUMN old_carrier_quote_cents TO carrier_quote_cents")
    # Existing shared shipping rate-limit contract remains enforced.
    code="require $argv[1]; $s=\\FAS\\Security\\SecurityStore::open(); $s->saveRule('shipping',1,3600,'enforce','127.0.0.1',1);"
    subprocess.check_call(["php","-r",code,str(ROOT/"src/security/SecurityStore.php")],env=env)
    request("/api/shipping-estimate.php",{})
    limited=request("/api/shipping-rates.php",{})
    check(limited[0]==429 and int(limited[1]["Retry-After"])>0,"Shipping endpoints retain their shared rate limit and Retry-After")
    # Readiness must not bootstrap missing deployment databases or print credential values.
    (SITE/"src/config/config.php").write_text("""<?php
$c=require __DIR__.'/config.example.php';
$c['database']['path']=dirname(__DIR__,2).'/missing-inventory/no.sqlite';
return $c;
""")
    readinessEnv={**env,"FAS_SHIPPING_CACHE_PATH":str(BASE/"missing-cache.sqlite")}
    missingRaw=subprocess.check_output(
        ["php","-d","disable_functions=mail,curl_exec,curl_multi_exec",str(SITE/"scripts/shipping-maintenance.php"),"readiness"],
        env=readinessEnv,text=True)
    missing=json.loads(missingRaw)
    check(not missing['order_shipping_storage'] and not missing['cache']['healthy']
          and not (SITE/'missing-inventory').exists() and not (BASE/'missing-cache.sqlite').exists(),
          'Readiness reports missing storage without creating database files or directories')
    check('fixture-secret' not in missingRaw and 'fixture-client' not in missingRaw,
          'Readiness output contains no carrier credential values')
    report={"date":datetime.now(timezone.utc).isoformat(),"scope":"local synthetic inventory; mocked carrier transport",
        "checks":len(checks),"passed":checks,"live_carrier_calls":0,"production_verified":False}
    (ROOT/"audit/shipping-http-local.json").write_text(json.dumps(report,indent=2)+"\n")
    print(f"PASS {len(checks)} shipping HTTP checks. No live carrier calls or label purchases.")
finally:
    server.terminate();server.wait(timeout=10);log.close()
