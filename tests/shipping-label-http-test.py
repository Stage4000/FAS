"""Isolated administrator label checks. Carrier purchase/cancellation stays disabled."""
from pathlib import Path
import hashlib, http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, sys, tempfile, time
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
    db.execute("INSERT INTO admin_users(id,username,email,full_name,password_hash) VALUES(2,'cancellation-fixture','cancellation@example.invalid','Cancellation Fixture',?)",[hashed])
    db.execute("INSERT INTO admin_users(id,username,email,full_name,password_hash) VALUES(3,'browser-fixture','browser@example.invalid','Browser Fixture',?)",[hashed])
    db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,customer_phone,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status,paypal_transaction_id) VALUES(10,'FAS-10','buyer@example.invalid','Alex Buyer','5555551234',?,20,9,29,'completed','processing','CAPTURE123456')",[address])
    db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,customer_phone,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status,paypal_transaction_id) VALUES(11,'FAS-11','buyer@example.invalid','Alex Buyer','5555551234',?,20,9,29,'completed','processing','CAPTURE123457')",[address])
    db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,customer_phone,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status,paypal_transaction_id) VALUES(12,'FAS-12','buyer@example.invalid','Alex Buyer','5555551234',?,20,9,29,'completed','processing','CAPTURE123458')",[address])
    db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,customer_phone,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status,paypal_transaction_id) VALUES(13,'FAS-13','buyer@example.invalid','Alex Buyer','5555551234',?,20,9,29,'completed','processing','CAPTURE123459')",[address])
    db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) VALUES(10,'usps','direct_usps_USPS_GROUND_ADVANTAGE','USPS_GROUND_ADVANTAGE','USPS','Ground Advantage',900,'USD','fixture-hash',?,?,?,?)",
        [int(time.time())+600,origin,packages,options])
    db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) VALUES(11,'ups','direct_ups_03','03','UPS','Ground',900,'USD','fixture-ups',?,?,?,NULL)",
        [int(time.time())+600,origin,packages])
    db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) VALUES(12,'ups','direct_ups_03','03','UPS','Ground',900,'USD','fixture-ups-12',?,?,?,NULL)",
        [int(time.time())+600,origin,packages])
    db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) VALUES(13,'ups','direct_ups_03','03','UPS','Ground',900,'USD','fixture-ups-13',?,?,?,NULL)",
        [int(time.time())+600,origin,packages])
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
def upload(path,fields,filename,content,who=owner):
    boundary='fas-synthetic-label-boundary'
    data=bytearray()
    for key,value in fields.items():
        data.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    data.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="labels[0]"; filename="{filename}"\r\nContent-Type: image/gif\r\n\r\n'.encode())
    data.extend(content)
    data.extend(f'\r\n--{boundary}--\r\n'.encode())
    req=urllib.request.Request(ORIGIN+path,data=bytes(data),
        headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try: r=who.open(req,timeout=15)
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
    check(request('/admin/shipping-operations.php',who=anonymous)[0]==302,
          'Anonymous shipment review queue redirects to sign-in')
    check(request('/admin/shipping-readiness.php',who=anonymous)[0]==302,
          'Anonymous carrier readiness page redirects to sign-in')
    loginPage=request('/admin/login.php')[2]
    csrf=re.search(r'name="csrf_token" value="([^"]+)"',loginPage)[1]
    login=request('/admin/login.php',{'csrf_token':csrf,'username':'shipping-fixture','password':PASSWORD})
    check(login[0]==302,'Synthetic active administrator signs in')
    warehousePath='/admin/warehouses.php'
    check(request(warehousePath,who=anonymous)[0]==302,
          'Anonymous visitors cannot open warehouse origins')
    warehousePage=request(warehousePath+'?action=create')
    check(warehousePage[0]==200 and 'name="csrf_token"' in warehousePage[2]
          and 'one active default ship-from warehouse' in warehousePage[2],
          'Origin form has CSRF protection and direct-carrier guidance')
    firstWarehouse={'action':'create','name':'First fixture origin','code':'FIXTURE-1',
        'address_line1':'100 Fixture Road','city':'Test City','state':'KS',
        'postal_code':'66614','country_code':'US','is_active':'1','is_default':'1'}
    check(request(warehousePath,firstWarehouse)[0]==403,
          'Creating a ship-from origin requires CSRF')
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT COUNT(*) FROM warehouses').fetchone()[0]==0,
              'Rejected origin request cannot change the warehouse table')
    check('Warehouse created successfully' in request(warehousePath,{'csrf_token':csrf,**firstWarehouse})[2],
          'Administrator can create the first default origin')
    secondWarehouse={**firstWarehouse,'name':'Second fixture origin','code':'FIXTURE-2',
        'address_line1':'200 Fixture Road'}
    check('Warehouse created successfully' in request(warehousePath,{'csrf_token':csrf,**secondWarehouse})[2],
          'Administrator can replace the default origin while creating a warehouse')
    with sqlite3.connect(DB) as db:
        defaults=db.execute('SELECT code FROM warehouses WHERE is_default=1').fetchall()
        check(defaults==[('FIXTURE-2',)],
              'Creating a new default leaves exactly one ship-from origin')
    duplicate={**secondWarehouse,'name':'Duplicate code origin'}
    check('Error:' in request(warehousePath,{'csrf_token':csrf,**duplicate})[2],
          'Failed default creation reports its database error')
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT code FROM warehouses WHERE is_default=1').fetchall()==[('FIXTURE-2',)],
              'Failed origin creation rolls back the previous default')
    check('Failed to set default warehouse' in request(warehousePath,
          {'csrf_token':csrf,'action':'set_default','warehouse_id':'99999'})[2],
          'Unknown warehouse cannot be selected as the ship-from origin')
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT code FROM warehouses WHERE is_default=1').fetchall()==[('FIXTURE-2',)],
              'Unknown default selection preserves the existing origin')
    check('Default warehouse updated successfully' in request(warehousePath,
          {'csrf_token':csrf,'action':'set_default','warehouse_id':'1'})[2],
          'Administrator can switch to an existing default origin')
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT code FROM warehouses WHERE is_default=1').fetchall()==[('FIXTURE-1',)],
              'Switching origin keeps one active default')
    updateWarehouse={**firstWarehouse,'action':'update','warehouse_id':'1'}
    check('A default warehouse must be active' in request(warehousePath,
          {'csrf_token':csrf,**{k:v for k,v in updateWarehouse.items() if k!='is_active'}})[2],
          'Default origin cannot be deactivated through its edit form')
    check('Choose another default warehouse' in request(warehousePath,
          {'csrf_token':csrf,**{k:v for k,v in updateWarehouse.items() if k!='is_default'}})[2],
          'Default origin cannot be silently unset through its edit form')
    with sqlite3.connect(DB) as db:
        check(db.execute('SELECT code,is_active FROM warehouses WHERE is_default=1').fetchall()==[('FIXTURE-1',1)],
              'Rejected origin edits preserve the active default')
    check(request(warehousePath,{'action':'delete','warehouse_id':'2'})[0]==403,
          'Deleting a ship-from origin requires CSRF')
    status,headers,page=request(path)
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and 'Shipping Labels' in page,
          'Private label page renders for active administrator')
    check('Ground Advantage' in page and '$9.00' in page and 'Purchase label' not in page,
          'Disabled carrier configuration shows saved quote without purchase control')
    status,headers,queue=request('/admin/shipping-operations.php')
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and 'No carrier label outcomes need review' in queue,
          'Active administrator sees an empty private shipment review queue')
    status,headers,readiness=request('/admin/shipping-readiness.php')
    check(status==200 and 'no-store' in headers.get('Cache-Control','')
          and 'Shipping Readiness' in readiness and 'Saleable products scanned' in readiness
          and 'Easyship' in readiness and 'separate account tests' in readiness
          and 'Over the USPS 130 in length and girth limit' in readiness
          and 'Manage products' in readiness and 'Manage warehouses' in readiness,
          'Private readiness page reports local mode and catalog state without claiming carrier acceptance')
    check('Fulfillment storage' in readiness and 'Label operations' in readiness
          and 'Cancellation review' in readiness and 'Tracking status' in readiness
          and 'Tracking emails' in readiness and readiness.count('Installed')>=5,
          'Readiness distinguishes installed fulfillment records from carrier activation')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE shipping_cache RENAME TO hidden_shipping_cache')
    unavailable=request('/admin/shipping-readiness.php')[2]
    check('Private shipping storage is unavailable' in unavailable and 'Needs setup' in unavailable,
          'Readiness reports missing private storage instead of a healthy zero')
    check('Fulfillment storage' in unavailable and 'Installed' not in unavailable,
          'Unavailable private database cannot report installed fulfillment records')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_shipping_cache RENAME TO shipping_cache')
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
    shippingConfig=SITE/'src/config/shipping.php'
    shippingConfig.write_text("<?php return ['shipper_name'=>'Fixture Sender','carriers'=>['usps'=>"
        "['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',"
        "'client_id'=>'synthetic','client_secret'=>'synthetic']]];",encoding='utf-8')
    check('synthetic' not in request('/admin/shipping-readiness.php')[2],
          'Carrier readiness never renders configured credential values')
    check('Purchase label' in request(path)[2],
          'Matching saved USPS pricing tier offers an administrator purchase form')
    shippingConfig.write_text("<?php return ['shipper_name'=>'Fixture Sender','carriers'=>['usps'=>"
        "['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',"
        "'client_id'=>'synthetic','client_secret'=>'synthetic','price_type'=>'COMMERCIAL']]];",
        encoding='utf-8')
    changedTierPage=request(path)[2]
    check('USPS pricing settings changed since checkout' in changedTierPage
          and 'Purchase label' not in changedTierPage,
          'A changed USPS pricing tier disables the purchase form and explains the hold')
    request(path,{'csrf_token':csrf,**post})
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM shipping_label_operations').fetchone()[0]==0,
              'A forged purchase POST after a pricing change creates no carrier operation')
    shippingConfig.write_text("<?php return ['shipper_name'=>'Fixture Sender','carriers'=>['usps'=>"
        "['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',"
        "'client_id'=>'synthetic','client_secret'=>'synthetic']]];",encoding='utf-8')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        now=int(time.time())
        db.execute("INSERT INTO shipping_label_operations(order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,operator_id,created_at,updated_at) VALUES(10,0,'usps','USPS_GROUND_ADVANTAGE',1,'synthetic-reserved','9b71aa67-f7e8-4c72-9c75-3c79f4630506','reserved',2,?,?)",[now,now])
    recent=request(path)[2]
    check('Another administrator is preparing this shipment' in recent
          and 'Take over and purchase label' not in recent,
          'Recent reservation stays with the original administrator')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('UPDATE shipping_label_operations SET updated_at=? WHERE order_id=10',
                   [int(time.time())-901])
    aged=request(path)[2]
    check('Take over and purchase label' in aged
          and 'No carrier purchase has been sent' not in aged,
          'Aged unsent reservation offers an explicit takeover control')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE shipping_label_handoffs RENAME TO hidden_handoffs')
    missing=request(path)[2]
    check('Carrier label purchasing is unavailable' in missing
          and 'Take over and purchase label' not in missing,
          'Missing handoff migration keeps the label purchase form disabled')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_handoffs RENAME TO shipping_label_handoffs')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('DELETE FROM shipping_label_operations WHERE order_id=10')
    shippingConfig.unlink()
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        now=int(time.time())
        db.execute("INSERT INTO shipping_label_operations(order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,operator_id,created_at,submitted_at,updated_at) VALUES(10,0,'usps','USPS_GROUND_ADVANTAGE',1,'synthetic-fingerprint','9b71aa67-f7e8-4c72-9c75-3c79f4630506','review',1,?,?,?)",[now,now,now])
        tracking='1Z1234567890123456'
        image=b'GIF89asynthetic'
        op=db.execute("INSERT INTO shipping_label_operations(order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,shipment_id,tracking_number,billed_cents,operator_id,created_at,submitted_at,updated_at) VALUES(11,0,'ups','03',1,'synthetic-ups-fingerprint','5da882d6-c20e-47c5-87be-167ad86823b5','ready',?,?,900,1,?,?,?)",[tracking,tracking,now,now,now]).lastrowid
        db.execute("INSERT INTO shipping_label_packages(operation_id,shipment_package_index,tracking_number,label_format,label_sha256,label_image) VALUES(?,0,?,'gif',?,?)",[op,tracking,hashlib.sha256(image).hexdigest(),image])
        db.execute("INSERT INTO shipping_tracking(tracking_number,provider,status_code,status_text,checked_at,attempted_at,next_attempt_at,last_result) VALUES(?,'ups','IT','On the way',?,?,?,'ok')",
            [tracking,now,now,now+1800])
        tracking2='1Z1234567890123458'
        op2=db.execute("INSERT INTO shipping_label_operations(order_id,package_index,provider,service_code,expected_packages,fingerprint,idempotency_key,state,shipment_id,tracking_number,billed_cents,operator_id,created_at,submitted_at,updated_at) VALUES(12,0,'ups','03',1,'synthetic-ups-fingerprint-12','5da882d6-c20e-47c5-87be-167ad86823b6','ready',?,?,900,1,?,?,?)",[tracking2,tracking2,now,now,now]).lastrowid
        db.execute("INSERT INTO shipping_label_packages(operation_id,shipment_package_index,tracking_number,label_format,label_sha256,label_image) VALUES(?,0,?,'gif',?,?)",[op2,tracking2,hashlib.sha256(image).hexdigest(),image])
        db.execute("INSERT INTO shipping_tracking(tracking_number,provider,status_code,status_text,checked_at,attempted_at,next_attempt_at,last_result) VALUES(?,'ups','IT','On the way',?,?,?,'ok')",
            [tracking2,now,now,now+1800])
        db.execute("UPDATE shipping_label_operations SET submitted_at=?,mailing_date=?,carrier_environment='sandbox' WHERE order_id=10",
            [now-1800,time.strftime('%Y-%m-%d',time.gmtime(now+86400))])
    shippingConfig.write_text("<?php return ['carriers'=>['usps'=>"
        "['enabled'=>true,'label_reprint_enabled'=>true,'environment'=>'sandbox',"
        "'client_id'=>'synthetic','client_secret'=>'synthetic']]];",encoding='utf-8')
    reprintPage=request(path)[2]
    check('Request original USPS label' in reprintPage
          and 'This does not submit another label purchase' in reprintPage
          and 'New label purchases are disabled' in reprintPage
          and 'Carrier label purchasing is unavailable' not in reprintPage,
          'Aged uncertain USPS purchase exposes the guarded original-label reprint form')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        opId=db.execute('SELECT id FROM shipping_label_operations WHERE order_id=10').fetchone()[0]
        now=int(time.time())
        db.execute("INSERT INTO shipping_label_cancellations"
                   "(operation_id,state,operator_id,created_at,updated_at)"
                   " VALUES (?,'reserved',1,?,?)",[opId,now,now])
    check('Request original USPS label' not in request(path)[2],
          'Canceled uncertain label does not offer the reprint action')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('DELETE FROM shipping_label_cancellations WHERE operation_id=?',[opId])
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE shipping_label_reprints RENAME TO hidden_reprints')
    check('Request original USPS label' not in request(path)[2],
          'Missing private reprint migration suppresses the carrier recovery form')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_reprints RENAME TO shipping_label_reprints')
    reprintPost={'action':'reprint','package_index':'0','confirm_reprint':'yes','password':PASSWORD}
    check(request(path,reprintPost)[0]==403,'USPS reprint requires CSRF')
    wrong=request(path,{'csrf_token':csrf,**reprintPost,'password':'wrong'})
    check(wrong[0]==200 and 'password' in wrong[2].lower(),
          'USPS reprint requires current administrator password')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM shipping_label_reprints').fetchone()[0]==0,
              'Denied reprints create no one-attempt record or carrier call')
    shippingConfig.unlink()
    queue=request('/admin/shipping-operations.php')[2]
    check('FAS-10' in queue and 'Review order' in queue and 'Label needs reconciliation' in queue,
          'Uncertain shipment is visible in the administrator review queue')
    check('purchase label' not in request(path)[2].lower(),
          'Uncertain shipment has no repeat purchase control')
    cancelPath='/admin/shipping-label-cancel.php?id=11&package=0'
    check(request(cancelPath,who=anonymous)[0]==302,
          'Anonymous label cancellation page redirects to sign-in')
    status,headers,cancelPage=request(cancelPath)
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and tracking in cancelPage
          and 'Submit carrier request' not in cancelPage,
          'Disabled cancellation page shows confirmed shipment without a carrier action')
    check(request(cancelPath,{'confirm_unused':'yes','confirm_carrier_action':'yes','password':PASSWORD})[0]==403,
          'Carrier cancellation requires CSRF')
    status,_,body=request(cancelPath,{'csrf_token':csrf,'confirm_unused':'yes',
        'confirm_carrier_action':'yes','password':PASSWORD})
    check(status==200 and 'could not be prepared' in body.lower(),
          'Valid CSRF cannot cancel while the carrier switch is disabled')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        count=db.execute('SELECT COUNT(*) FROM shipping_label_cancellations').fetchone()[0]
    check(count==0,'Disabled cancellation page creates no carrier action')
    check(request('/admin/shipping-label-download.php?id=11&package=0&piece=0')[0]==200,
          'Confirmed label can be downloaded before cancellation')
    labelPage=request('/admin/shipping-label.php?id=11')[2]
    check('Carrier tracking' in labelPage and 'On the way' in labelPage,
          'Administrator label view shows the saved package status')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute("INSERT INTO shipping_label_cancellations(operation_id,state,operator_id,created_at,submitted_at,updated_at) VALUES(?,'review',1,?,?,?)",[op,now,now,now])
    check(request('/admin/shipping-label-download.php?id=11&package=0&piece=0')[0]==404,
          'Label with uncertain cancellation cannot be downloaded for use')
    status,_,cancelPage=request(cancelPath)
    check(status==200 and 'needs carrier reconciliation' in cancelPage
          and 'Submit carrier request' not in cancelPage,
          'Uncertain cancellation shows reconciliation state without another action')
    labelPage=request('/admin/shipping-label.php?id=11')[2]
    check('This label is unavailable after a carrier cancellation request' in labelPage
          and 'Download label 1' not in labelPage and 'Carrier tracking' not in labelPage,
          'Order label view hides the saved label after a cancellation request')
    queue=request('/admin/shipping-operations.php')[2]
    check('Cancellation and refund follow-ups' in queue and tracking in queue
          and 'Review request' in queue,
          'Uncertain carrier cancellation appears in administrator review queue')
    check(request('/admin/shipping-label-download.php?id=10&package=0&piece=0')[0]==404,
          'Unconfirmed label cannot be downloaded')
    check(request('/admin/order-details.php?id=10',{'action':'update_tracking','tracking_number':'INJECTED'})[0]==403,
          'Order tracking mutation now requires CSRF')
    with sqlite3.connect(DB) as db:
        tracking=db.execute('SELECT tracking_number FROM orders WHERE id=10').fetchone()[0]
    check(tracking is None,'Rejected tracking mutation leaves order unchanged')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute("UPDATE shipping_tracking SET last_result='error' WHERE tracking_number=?",[tracking2])
        reviewId=db.execute("INSERT INTO shipping_notifications(order_id,digest,state,created_at,attempted_at) VALUES(11,'review-fixture','review',?,?)",[now-3600,now-1800]).lastrowid
        recentId=db.execute("INSERT INTO shipping_notifications(order_id,digest,state,created_at,attempted_at) VALUES(12,'recent-fixture','submitted',?,?)",[now,now]).lastrowid
        staleId=db.execute("INSERT INTO shipping_notifications(order_id,digest,state,created_at,attempted_at) VALUES(12,'stale-fixture','submitted',?,?)",[now-3600,now-1800]).lastrowid
    queue=request('/admin/shipping-operations.php')[2]
    check('Last check failed' in queue and tracking2 in queue and 'Tracking follow-ups' in queue,
          'Failed uncancelled package appears in the tracking follow-up table')
    check('Customer tracking emails' in queue and 'Tracking emails off' in queue
          and 'Transport outcome uncertain' in queue and 'Send did not finish' in queue,
          'Email monitoring distinguishes disabled sending and uncertain or stalled outcomes')
    reviewPath=f'/admin/shipping-notification.php?id={reviewId}'
    check(request(reviewPath,who=anonymous)[0]==302,'Anonymous email review redirects to sign-in')
    status,headers,reviewPage=request(reviewPath)
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and 'Record verified outcome' in reviewPage
          and 'buyer@example.invalid' not in reviewPage,
          'Private email review displays operational references without recipient details')
    check(request('/admin/shipping-notification.php?id=999999')[0]==404
          and request('/admin/shipping-notification.php?id[]=1')[0]==404,
          'Missing and malformed notification references return not found')
    valid={'csrf_token':csrf,'action':'resolve','outcome':'accepted','confirm_logs':'yes','password':PASSWORD}
    check(request(reviewPath,{k:v for k,v in valid.items() if k!='csrf_token'})[0]==403,
          'Email reconciliation rejects missing CSRF')
    check(request(reviewPath,{**valid,'password':'wrong'})[0]==403,'Email reconciliation verifies the current password')
    check(request(reviewPath,{**valid,'confirm_logs':''})[0]==422,'Email reconciliation requires transport-log confirmation')
    check(request(reviewPath,{**valid,'outcome':'resend'})[0]==422,'Email reconciliation cannot request a resend')
    check(request(f'/admin/shipping-notification.php?id={recentId}',valid)[0]==409,
          'A recent in-flight send cannot be reconciled by a stale POST')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM shipping_notification_resolutions').fetchone()[0]==0,
              'Rejected reconciliation requests create no audit or outcome changes')
    result=request(reviewPath,valid)
    check(result[0]==303 and result[1]['Location']==f'shipping-notification.php?id={reviewId}',
          'Verified reconciliation redirects to the private recorded outcome')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        outcome=db.execute('SELECT state FROM shipping_notifications WHERE id=?',[reviewId]).fetchone()[0]
        record=db.execute('SELECT previous_state,outcome,actor_id,source FROM shipping_notification_resolutions WHERE notification_id=?',[reviewId]).fetchone()
    check(outcome=='accepted' and record==('review','accepted',1,'admin'),
          'Outcome and named administrator audit are saved together')
    check('Outcome recorded. No email was resent.' in request(reviewPath)[2]
          and 'Record outcome</button>' not in request(reviewPath)[2],
          'Resolved notification shows history instead of another mutation control')
    history=request('/admin/shipping-operations.php')[2]
    check('Recent email reviews' in history and f'shipping-notification.php?id={reviewId}' in history,
          'Resolved email remains discoverable through recent review history')
    check(request(reviewPath,{**valid,'outcome':'suppressed'})[0]==409,
          'Repeated or competing resolutions cannot rewrite a completed outcome')
    with sqlite3.connect(DB) as db: db.execute("UPDATE admin_users SET role='viewer' WHERE id=1")
    check(request(f'/admin/shipping-notification.php?id={staleId}',valid)[0] in (302,403),
          'Demoted administrator cannot reconcile a notification')
    with sqlite3.connect(DB) as db: db.execute("UPDATE admin_users SET role='admin' WHERE id=1")
    # Refresh the session after the role-change access test if the auth layer expired it.
    loginPage=request('/admin/login.php')[2]
    match=re.search(r'name="csrf_token" value="([^"]+)"',loginPage)
    if match: request('/admin/login.php',{'csrf_token':match[1],'username':'shipping-fixture','password':PASSWORD})
    # Missing optional storage must be shown as unavailable, never as a zero count.
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db: db.execute('ALTER TABLE shipping_tracking RENAME TO hidden_tracking_fixture')
    missing=request('/admin/shipping-operations.php')[2]
    check('Tracking status is unavailable' in missing and 'No tracking follow-ups are currently due' not in missing,
          'Unavailable tracking storage does not masquerade as a healthy empty queue')
    readinessMissing=request('/admin/shipping-readiness.php')[2]
    check(re.search(r'Tracking status</span>\s*<span[^>]*>Needs setup',readinessMissing) is not None,
          'Readiness page identifies the missing tracking table')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db: db.execute('ALTER TABLE hidden_tracking_fixture RENAME TO shipping_tracking')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db: db.execute('ALTER TABLE shipping_notification_resolutions RENAME TO hidden_resolution_fixture')
    missing=request('/admin/shipping-operations.php')[2]
    check('Tracking email status is unavailable' in missing and 'No tracking email outcomes need review' not in missing,
          'Missing notification migration is reported instead of an empty healthy queue')
    readinessMissing=request('/admin/shipping-readiness.php')[2]
    check(re.search(r'Tracking emails</span>\s*<span[^>]*>Needs setup',readinessMissing) is not None,
          'Readiness page identifies incomplete notification storage')
    blocked=request(f'/admin/shipping-notification.php?id={staleId}')
    check(blocked[0]==503 and 'Record outcome</button>' not in blocked[2],
          'Uninitialized reconciliation storage suppresses the mutation form')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db: db.execute('ALTER TABLE hidden_resolution_fixture RENAME TO shipping_notification_resolutions')
    with sqlite3.connect(DB) as db: db.execute('UPDATE admin_users SET is_active=0 WHERE id=1')
    check(request('/admin/warehouses.php')[0] in (302,403),
          'Deactivated administrator cannot manage ship-from origins')
    check(request('/admin/shipping-readiness.php')[0] in (302,403),
          'Deactivated administrator cannot inspect carrier readiness')
    check(request(f'/admin/shipping-notification.php?id={staleId}',valid)[0] in (302,403),
          'Deactivated administrator cannot use an existing session to resolve email')
    with sqlite3.connect(DB) as db: db.execute('UPDATE admin_users SET is_active=1 WHERE id=1')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT state FROM shipping_notifications WHERE id=?',[staleId]).fetchone()[0]=='submitted',
              'Denied account access preserves the unresolved email state')
    # Separate synthetic operator keeps the independent flow inside the real per-account rate limit.
    loginPage=request('/admin/login.php')[2]
    match=re.search(r'name="csrf_token" value="([^"]+)"',loginPage)
    if match: request('/admin/login.php',{'csrf_token':match[1],'username':'cancellation-fixture','password':PASSWORD})
    cancelPage=request(cancelPath)[2]
    csrf=re.search(r'name="csrf_token" value="([^"]+)"',cancelPage)[1]
    check('Record carrier outcome</button>' in cancelPage,
          'Uncertain cancellation offers evidence review while carrier sending remains disabled')
    cancelReview={'csrf_token':csrf,'action':'reconcile','expected_state':'review','outcome':'cancelled',
        'verified_tracking':'1Z1234567890123456','evidence_reference':'CASE-HTTP-123',
        'confirm_evidence':'yes','password':PASSWORD}
    def reviewRequest(data):
        return request(cancelPath,data)
    check(request(cancelPath,{**cancelReview,'csrf_token':''})[0]==403,'Carrier evidence review requires CSRF')
    check(request(cancelPath,{**cancelReview,'confirm_evidence':''})[0]==422,'Carrier review requires explicit evidence confirmation')
    check(reviewRequest({**cancelReview,'password':'wrong'})[0]==403,'Carrier review requires current administrator password')
    check(reviewRequest({**cancelReview,'verified_tracking':'1Z1234567890123459'})[0]==422,
          'Carrier review rejects evidence for a different tracking number')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM shipping_cancellation_resolutions').fetchone()[0]==0,
              'Rejected carrier reviews leave no history')
        db.execute('ALTER TABLE shipping_cancellation_resolutions RENAME TO hidden_cancellation_reviews')
    missing=request(cancelPath)
    check(missing[0]==503 and 'Record carrier outcome</button>' not in missing[2],
          'Missing carrier review migration suppresses the form')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_cancellation_reviews RENAME TO shipping_cancellation_resolutions')
    resolved=reviewRequest(cancelReview)
    check(resolved[0]==303,'Verified carrier outcome redirects after saving')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT state FROM shipping_label_cancellations WHERE operation_id=?',[op]).fetchone()[0]=='cancelled'
              and db.execute('SELECT actor_id,evidence_reference FROM shipping_cancellation_resolutions WHERE operation_id=?',[op]).fetchone()==(2,'CASE-HTTP-123'),
              'Carrier outcome and reviewer evidence are saved together')
    saved=request(cancelPath)[2]
    check('Carrier review history' in saved and 'CASE-HTTP-123' in saved and 'Record carrier outcome</button>' not in saved,
          'Confirmed cancellation displays permanent history without another action')
    check(reviewRequest(cancelReview)[0]==409,'Repeated carrier review cannot overwrite outcome')
    check(request('/admin/shipping-label-download.php?id=11&package=0&piece=0')[0]==404,
          'Reconciled cancellation keeps label downloads blocked')
    check('Review request' not in request('/admin/shipping-operations.php')[2],
          'Confirmed cancellation leaves the follow-up queue')
    # Leave another synthetic uncertain cancellation for optional browser interaction.
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute("INSERT INTO shipping_label_cancellations(operation_id,state,operator_id,created_at,submitted_at,updated_at) VALUES(?,'review',1,?,?,?)",[op2,now,now-1800,now])
    fixtureCode=("require $argv[1].'/src/shipping/ShippingCache.php';"
        "require $argv[1].'/src/shipping/ShippingLabelOperations.php';"
        "$db=new PDO('sqlite:'.$argv[2]);"
        "$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);"
        "$cache=new FAS\\Shipping\\ShippingCache($argv[3]);"
        "$ledger=new FAS\\Shipping\\ShippingLabelOperations($cache->database());"
        "$ledger->reserve($db,13,0,1);"
        "$ledger->markSubmitted($db,13,0,1,'sandbox');"
        "$ledger->markReview(13,0);"
        "$cache->database()->exec('UPDATE shipping_label_operations SET submitted_at='.(time()-1800).' WHERE order_id=13');")
    subprocess.check_call(['php','-r',fixtureCode,str(SITE),str(DB),str(BASE/'private/shipping.sqlite')],
        env=env,stdout=subprocess.DEVNULL)
    loginPage=request('/admin/login.php')[2]
    reviewerCsrf=re.search(r'name="csrf_token" value="([^"]+)"',loginPage)[1]
    check(request('/admin/login.php',{'csrf_token':reviewerCsrf,'username':'browser-fixture',
          'password':PASSWORD})[0]==302,'Fresh synthetic administrator opens label review')
    labelReview='/admin/shipping-label-reconcile.php?id=13&package=0'
    check(request(labelReview,who=anonymous)[0]==302,
          'Anonymous original-label reconciliation redirects to sign-in')
    reviewPage=request(labelReview)
    check(reviewPage[0]==200 and 'Record original carrier labels' in reviewPage[2]
          and 'original carrier transaction' in reviewPage[2].lower()
          and '200 Synthetic Street' in reviewPage[2]
          and '100 Fixture Road' in reviewPage[2],
          'Aged uncertain UPS shipment offers a guarded original-label review')
    check('Record verified carrier label' in request('/admin/shipping-label.php?id=13')[2],
          'Uncertain shipment links to carrier-evidence review')
    reviewFields={'csrf_token':reviewerCsrf,'expected_state':'review','evidence_reference':'UPS-ORIGINAL-13',
        'billed_amount':'10.25','tracking[0]':'1Z1234567890123460',
        'password':PASSWORD,'confirm_evidence':'yes'}
    check(request(labelReview,{**reviewFields,'csrf_token':''})[0]==403,
          'Original-label review requires CSRF')
    check(request(labelReview,{**reviewFields,'password':'wrong'})[0]==403,
          'Original-label review requires the current administrator password')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM shipping_label_resolutions').fetchone()[0]==0,
              'Denied original-label reviews leave no audit or carrier label')
        db.execute('ALTER TABLE shipping_label_resolutions RENAME TO hidden_label_resolutions')
    missing=request(labelReview)
    check('Record original carrier labels' not in missing[2],
          'Missing original-label review migration suppresses the upload form')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        db.execute('ALTER TABLE hidden_label_resolutions RENAME TO shipping_label_resolutions')
    invalid=upload(labelReview,reviewFields,'not-a-label.gif',b'<html>invalid</html>')
    check(invalid[0]==422,'Uploaded carrier label must pass original image validation')
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM shipping_label_resolutions').fetchone()[0]==0
              and db.execute("SELECT state FROM shipping_label_operations WHERE order_id=13").fetchone()[0]=='review',
              'Rejected original-label upload leaves the shipment and audit unchanged')
    original=b'GIF89asynthetic-original-carrier-label'
    saved=upload(labelReview,reviewFields,'original.gif',original)
    check(saved[0]==303,'Verified original carrier label redirects after saving'
          + (f' (HTTP {saved[0]}: {saved[2][:200]})' if saved[0]!=303 else ''))
    with sqlite3.connect(BASE/'private/shipping.sqlite') as db:
        op13=db.execute('SELECT id,state,billed_cents FROM shipping_label_operations WHERE order_id=13').fetchone()
        audit=db.execute('SELECT actor_id,evidence_reference FROM shipping_label_resolutions WHERE operation_id=?',[op13[0]]).fetchone()
        label=db.execute('SELECT tracking_number,label_image FROM shipping_label_packages WHERE operation_id=?',[op13[0]]).fetchone()
        check(op13[1:] == ('ready',1025) and audit==(3,'UPS-ORIGINAL-13')
              and label==('1Z1234567890123460',original),
              'Verified original label, charge and reviewer evidence save together')
    check('UPS-ORIGINAL-13' in request(labelReview)[2]
          and 'Record original carrier labels' not in request(labelReview)[2],
          'Completed label review shows immutable evidence without a second form')
    check(upload(labelReview,reviewFields,'original.gif',original)[0]==422,
          'Repeated original-label review cannot overwrite the saved shipment')
    report={'date':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
        'scope':'isolated synthetic administrator and paid orders; purchase and cancellation disabled',
        'checks':len(checks),'passed':checks,'live_carrier_calls':0,'label_purchases':0,
        'production_verified':False}
    (ROOT/'audit/shipping-label-http-local.json').write_text(json.dumps(report,indent=2)+'\n')
    print(f'PASS {len(checks)} shipping label HTTP checks. No carrier calls, purchases or cancellations.',flush=True)
    if '--serve' in sys.argv:
        shutil.copy2(ROOT/'checkout.php',SITE/'checkout.php')
        shutil.copytree(ROOT/'api',SITE/'api',ignore=shutil.ignore_patterns('*.db','*.sqlite','*.log'))
        # This mock exists only in the disposable browser fixture; PHP cannot reach PayPal or carriers.
        (SITE/'src/integrations/PayPalAPI.php').write_text('''<?php
namespace FAS\\Integrations;
class PayPalAPI {
    public function getOrderDetails($id) {
        $path=__DIR__.'/../../../paypal-response.json';
        return is_file($path) ? json_decode(file_get_contents($path),true) : ['error'=>'fixture unavailable'];
    }
}
''',encoding='utf-8')
        with sqlite3.connect(DB) as db:
            db.execute("INSERT INTO products(id,name,sku,price,quantity,is_active,show_on_website,image_url) "
                       "VALUES(1,'Synthetic checkout part','CHECKOUT-1',20,10,1,1,'/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg')")
            db.execute("INSERT INTO orders(id,order_number,customer_email,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status) "
                       "VALUES(42,'FAS-42','buyer@example.invalid',?,20,9,29,'pending','pending')",[address])
            db.execute("INSERT INTO order_items(order_id,product_id,product_name,product_sku,quantity,unit_price,total_price) "
                       "VALUES(42,1,'Synthetic checkout part','CHECKOUT-1',1,20,20)")
            db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) "
                       "VALUES(42,'usps','direct_usps_USPS_GROUND_ADVANTAGE','USPS_GROUND_ADVANTAGE','USPS','Ground Advantage',900,'USD','fixture-browser',?,?,?,?)",
                       [int(time.time())+600,origin,packages,options])
        provider={'id':'PAYPAL424242','status':'COMPLETED','intent':'CAPTURE','purchase_units':[{
            'invoice_id':'FAS-42','custom_id':'FAS-CHECKOUT-42',
            'payee':{'merchant_id':'FIXTUREMERCHANT'},'amount':{'currency_code':'USD','value':'29.00'},
            'shipping':{'address':{'address_line_1':'200 Synthetic Street','address_line_2':'',
                'admin_area_2':'Test City','admin_area_1':'CA','postal_code':'90210','country_code':'US'}},
            'payments':{'captures':[{'id':'CAPTURE424242','status':'COMPLETED',
                'amount':{'currency_code':'USD','value':'29.00'},'final_capture':True}]}
        }]}
        (BASE/'paypal-response.json').write_text(json.dumps({'success':True,'data':provider}),encoding='utf-8')
        (SITE/'checkout-seed.html').write_text('''<!doctype html><title>Checkout fixture</title>
<script>
localStorage.setItem('flipandstrip_cart', JSON.stringify([{
    id:1,name:'Synthetic checkout part',sku:'CHECKOUT-1',price:20,quantity:1,
    image:'/gallery/FLIPANDSTRIP.COM_d00a_018a.jpg'
}]));
if (new URLSearchParams(location.search).has('recovery')) {
    sessionStorage.setItem('fas_paypal_recovery_v1', JSON.stringify({
        paypal_order_id:'PAYPAL424242',paypal_transaction_id:'CAPTURE424242',
        order_id:42,source:JSON.stringify(['cart',[['1',1]]]),
        retry_at:new URLSearchParams(location.search).has('ready') ? Date.now()-1 : Date.now()+12000
    }));
} else sessionStorage.removeItem('fas_paypal_recovery_v1');
location.replace('/checkout.php');
</script>''',encoding='utf-8')
        # A separate paid order lets browser QA inspect the pricing-tier hold without
        # disturbing the uncertain label and cancellation fixtures above.
        with sqlite3.connect(DB) as db:
            db.execute("INSERT INTO orders(id,order_number,customer_email,customer_name,customer_phone,shipping_address,subtotal,shipping_cost,total_amount,payment_status,order_status,paypal_transaction_id) "
                       "VALUES(14,'FAS-14','buyer@example.invalid','Alex Buyer','5555551234',?,20,9,29,'completed','processing','CAPTURE123460')",[address])
            db.execute("INSERT INTO order_shipping(order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,quote_hash,quote_expires_at,origin_json,packages_json,fulfillment_json) "
                       "VALUES(14,'usps','direct_usps_USPS_GROUND_ADVANTAGE','USPS_GROUND_ADVANTAGE','USPS','Ground Advantage',900,'USD','fixture-tier',?,?,?,?)",
                       [int(time.time())+600,origin,packages,options])
        shippingConfig.write_text("<?php return ['shipper_name'=>'Fixture Sender','carriers'=>['usps'=>"
            "['enabled'=>true,'label_purchasing_enabled'=>true,'environment'=>'sandbox',"
            "'client_id'=>'synthetic','client_secret'=>'synthetic','price_type'=>'COMMERCIAL']]];",
            encoding='utf-8')
        print(json.dumps({'origin':ORIGIN,'fixture':str(BASE),'username':'browser-fixture',
            'password':PASSWORD}),flush=True)
        while True: time.sleep(1)
finally:
    server.terminate(); server.wait(timeout=10); log.close()
