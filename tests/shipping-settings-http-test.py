"""Isolated admin credential form; no carrier calls or production credentials."""
from pathlib import Path
from datetime import datetime, timezone
import http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, sys, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT=Path(__file__).resolve().parents[1]
BASE=Path(tempfile.mkdtemp(prefix='fas-shipping-settings-'))
SITE=BASE/'site'; SITE.mkdir()
for folder in ['src','admin','includes','public','scripts']:
    shutil.copytree(ROOT/folder,SITE/folder,ignore=shutil.ignore_patterns(
        'config.php','config.local.php','config.production.php','shipping.php','applepay.php',
        'config.json','settings.json','uploads','backups','exports','*.db','*.sqlite','*.log'))
shutil.copy2(ROOT/'src/config/config.example.php',SITE/'src/config/config.php')
(SITE/'database').mkdir(); (SITE/'gallery').mkdir()
shutil.copytree(ROOT/'gallery/favicons',SITE/'gallery/favicons')
shutil.copy2(ROOT/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg',SITE/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg')
private=BASE/'private'; private.mkdir(mode=0o700)
sessions=BASE/'sessions'; sessions.mkdir()
setting=private/'shipping.php'
password='Fixture-shipping-admin-123'
hashed=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password],text=True)
dbfile=SITE/'database/flipandstrip.db'
with sqlite3.connect(dbfile) as db:
    db.executescript((ROOT/'database/schema.sqlite.sql').read_text(encoding='utf-8'))
    db.execute("INSERT INTO admin_users(id,username,email,full_name,password_hash) VALUES(1,'shipping-admin','shipping@example.invalid','Shipping Admin',?)",[hashed])
    db.execute("INSERT INTO warehouses(id,name,address_line1,city,state,postal_code,country_code,is_default,is_active) VALUES(1,'Fixture origin','1 Test Way','Los Angeles','CA','90001','US',1,1)")
    db.execute("INSERT INTO products(name,price,quantity,weight,length,width,height,warehouse_id,is_active,show_on_website) VALUES('Fixture parcel',10,1,2,8,6,4,1,1,1)")
env={k:v for k,v in os.environ.items() if not k.startswith('FAS_')}
env['FAS_SECURITY_DB_PATH']=str(private/'security.sqlite')
env['FAS_SHIPPING_CONFIG_PATH']=str(setting)
env['FAS_SHIPPING_CACHE_PATH']=str(private/'shipping.sqlite')
subprocess.check_call(['php',str(SITE/'scripts/security-maintenance.php'),'init'],env=env,stdout=subprocess.DEVNULL)
subprocess.check_call(['php',str(SITE/'scripts/security-maintenance.php'),'activate','--verified'],env=env,stdout=subprocess.DEVNULL)
for rule in ['login_pair','login_account','login_ip','reauth_account','reauth_ip','settings_account','settings_ip']:
    code='require '+repr(str(SITE/'src/security/SecurityStore.php').replace('\\','/'))+'; $s=\\FAS\\Security\\SecurityStore::open();$s->saveRule($argv[1],100,3600,"enforce","127.0.0.1",1);'
    subprocess.check_call(['php','-r',code,rule],env=env,stdout=subprocess.DEVNULL)
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
origin=f'http://127.0.0.1:{port}'
log=open(BASE/'server.log','w')
server=subprocess.Popen(['php','-d','disable_functions=mail,curl_exec,curl_multi_exec',
    '-d','session.save_path='+str(sessions),'-S',f'127.0.0.1:{port}','-t',str(SITE)],
    env=env,stdout=log,stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
admin=client(); anonymous=client()
def request(path,data=None,who=admin):
    if isinstance(data,dict): data=urllib.parse.urlencode(data).encode()
    try: response=who.open(urllib.request.Request(origin+path,data=data),timeout=15)
    except urllib.error.HTTPError as exc: response=exc
    with response: return response.status,dict(response.headers),response.read().decode()
def csrf(page):
    match=re.search(r'name="csrf_token" value="([^"]+)"',page)
    assert match
    return match[1]
checks=[]
def check(ok,message):
    if not ok: raise AssertionError(message)
    checks.append(message)
path='/admin/shipping-settings.php'
passed=False
try:
    for _ in range(50):
        try: request('/admin/login.php'); break
        except OSError: time.sleep(.1)
    check(request(path,who=anonymous)[0]==302,'Anonymous credential page redirects')
    login_page=request('/admin/login.php')[2]
    check(request('/admin/login.php',{'csrf_token':csrf(login_page),'username':'shipping-admin','password':password})[0]==302,'Active administrator signs in')
    status,headers,page=request(path)
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and 'no-referrer' in headers.get('Referrer-Policy',''),'Credential form has private response headers')
    check('USPS' in page and 'UPS' in page and 'Current admin password' in page,'Both carriers have separate password forms')
    check('id="shipping-provider-form"' in page and 'id="easyship-settings-form"' in page and
          'Shipping Readiness</' not in page,'Provider and Easyship controls share Shipping Settings; readiness leaves sidebar')
    token=csrf(page)
    siteconfig=SITE/'src/config/config.php'
    easyship={'csrf_token':token,'action':'save_easyship','easyship_api_key':'fixture-easyship-secret',
              'easyship_platform_name':'Fixture Store','easyship_prefix':'FIX','password':password}
    check(request(path,{**easyship,'password':'wrong'})[0]==200 and
          'fixture-easyship-secret' not in siteconfig.read_text(),'Easyship changes require current password')
    check(request(path,easyship)[0]==303 and 'fixture-easyship-secret' in siteconfig.read_text(),
          'Easyship settings save from Shipping Settings')
    page=request(path)[2]
    check('fixture-easyship-secret' not in page and 'Fixture Store' in page and 'API key saved' in page,
          'Easyship key is never echoed to admin HTML')
    check(request('/admin/settings.php')[0]==200 and 'name="easyship_api_key"' not in request('/admin/settings.php')[2],
          'General Settings links to Shipping Settings without duplicate Easyship inputs')
    check(request('/admin/settings.php',{'csrf_token':token,'site_name':'Fixture Store'})[0]==200 and
          'fixture-easyship-secret' in siteconfig.read_text(),
          'Ordinary site settings save preserves the Easyship API key')
    details={'carrier':'usps','action':'save','client_id':'fixture-usps-id',
        'client_secret':'fixture-usps-secret','crid':'fixture-crid','gateway':'apis',
        'price_type':'RETAIL','password':password}
    check(request(path,details)[0]==403 and not setting.exists(),'CSRF is required before credential writes')
    check(request(path,{**details,'csrf_token':token,'password':'wrong'})[0]==200 and not setting.exists(),'Current password is required')
    check(request(path,{**details,'csrf_token':token})[0]==303 and setting.is_file(),'USPS credentials save to private storage')
    saved=setting.read_text()
    check('fixture-usps-secret' in saved and str(setting).startswith(str(private)) and
          not str(setting).startswith(str(SITE)),'Credentials stay outside the web root')
    late=private/'shipping-late-cache.php'
    late.write_text(re.sub(r"'cache_path' => '.*?',", "'cache_path' => '',", saved, count=1))
    late_env={**env,'FAS_SHIPPING_CONFIG_PATH':str(late)}
    source_config=str(SITE/'src/shipping/ShippingConfig.php').replace('\\','/')
    late_code='require '+repr(source_config)+'; echo \\FAS\\Shipping\\ShippingConfig::load()["cache_path"];'
    loaded=subprocess.check_output(['php','-r',late_code],env=late_env,text=True)
    check(loaded==env['FAS_SHIPPING_CACHE_PATH'],'Later private-cache deployment setting remains effective')
    status,headers,page=request(path)
    check('fixture-usps-secret' not in page and 'fixture-usps-id' not in page and
          'fixture-crid' not in page and '3 of 6 details entered' in page,'Saved values are status-only in HTML')
    check(request(path,{'csrf_token':token,**{**details,'client_id':'','client_secret':'','crid':''}})[0]==303 and
          'fixture-usps-secret' in setting.read_text(),'Blank fields retain saved values')
    check(request(path,{'csrf_token':token,**{**details,'client_id[]':'bad'}})[0]==200 and
          'fixture-usps-secret' in setting.read_text(),'Array-shaped credential input is rejected')
    check(request(path,{'csrf_token':token,**{**details,'gateway':'unknown'}})[0]==200 and
          'fixture-usps-secret' in setting.read_text(),'Unknown USPS gateway is rejected')
    check(request(path,{'csrf_token':token,'carrier':'ups','action':'save','client_id':'fixture-ups-id',
          'client_secret':'fixture-ups-secret','account_number':'UPS123','password':password})[0]==303,
          'UPS details save independently')
    saved=setting.read_text()
    check('fixture-usps-secret' in saved and 'fixture-ups-secret' in saved and
          "'mode' => 'easyship'" in saved and "'enabled' => false" in saved,
          'Both carriers remain stored with Easyship selected and activation disabled')
    switch={'csrf_token':token,'action':'switch_mode','mode':'direct','password':password,
            'confirm_packing':'1','confirm_rates':'1'}
    check(request(path,switch)[0]==200 and "'mode' => 'easyship'" in setting.read_text(),
          'Direct switch refuses unapproved accounts and uninitialized storage')
    check(request(path,{'csrf_token':token,'action':'approve_carrier','carrier':'ups','password':password})[0]==200 and
          "'production_verified' => false" in setting.read_text(),
          'Carrier approval requires explicit production-test confirmation')
    check(request(path,{'csrf_token':token,'action':'approve_carrier','carrier':'ups','password':password,
                        'confirm_account':'1'})[0]==303 and "'production_verified' => true" in setting.read_text(),
          'Confirmed production account can be approved for rates')
    check(request(path,{'csrf_token':token,'action':'switch_mode','mode':'direct','password':password})[0]==200 and
          "'mode' => 'easyship'" in setting.read_text(),
          'Direct switch requires packed-parcel and rate confirmations')
    subprocess.check_call(['php',str(SITE/'scripts/shipping-maintenance.php'),'init'],env=env,stdout=subprocess.DEVNULL)
    check(request(path,switch)[0]==303 and "'mode' => 'direct'" in setting.read_text() and
          "'parcel_data_verified' => true" in setting.read_text(),
          'Direct switch activates only after storage, catalog, account and confirmations pass')
    siteconfig.write_text(siteconfig.read_text().replace('fixture-easyship-secret','YOUR_EASYSHIP_API_KEY'))
    time.sleep(3)  # The fixture edits config outside the admin writer; let PHP's stat cache refresh.
    check(request(path,{'csrf_token':token,'action':'switch_mode','mode':'easyship','password':password})[0]==200 and
          "'mode' => 'direct'" in setting.read_text(),
          'Rollback refuses an unconfigured Easyship account')
    check(request(path,easyship)[0]==303 and 'fixture-easyship-secret' in siteconfig.read_text(),
          'Easyship key can be restored while Direct Shipping is active')
    check(request(path,{'csrf_token':token,'action':'switch_mode','mode':'easyship','password':password})[0]==303 and
          "'mode' => 'easyship'" in setting.read_text(),
          'Administrator can switch checkout back to Easyship without losing carrier settings')
    check(request(path,{'csrf_token':token,'carrier':'usps','action':'clear','password':password})[0]==200 and
          'fixture-usps-secret' in setting.read_text(),'Clearing requires explicit confirmation')
    check(request(path,{'csrf_token':token,'carrier':'usps','action':'clear',
          'confirm_clear':'1','password':password})[0]==303 and
          'fixture-usps-secret' not in setting.read_text() and 'fixture-ups-secret' in setting.read_text(),
          'Clearing one carrier preserves the other')
    setting.write_text(setting.read_text().replace("'mode' => 'easyship'","'mode' => 'direct'"))
    check(request(path,{'csrf_token':token,**details})[0]==200 and
          'fixture-usps-secret' not in setting.read_text(),'Credential changes refuse an active direct mode')
    bad_env={**env,'FAS_SHIPPING_CONFIG_PATH':str(SITE/'src/config/unsafe-shipping.php')}
    source=str(SITE/'src/shipping/ShippingSettingsStore.php').replace('\\','/')
    code='require '+repr(source)+'; try { \\FAS\\Shipping\\ShippingSettingsStore::save("ups",["client_id"=>"fixture"]); exit(1); } catch (RuntimeException $e) { exit(0); }'
    check(subprocess.run(['php','-r',code],env=bad_env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0
          and not (SITE/'src/config/unsafe-shipping.php').exists(),
          'Web-root credential storage is refused')
    (ROOT/'audit/shipping-settings-http-local.json').write_text(json.dumps({
        'date':datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'scope':'isolated synthetic administrator and private credentials; no carrier calls',
        'checks':len(checks),'passed':checks,
    },indent=2)+'\n',encoding='utf-8')
    print('PASS',len(checks),'shipping credential admin HTTP checks. No carrier calls.')
    passed=True
finally:
    if '--keep' in sys.argv and passed:
        print(f'Fixture: {origin}  {BASE}')
    else:
        server.terminate(); server.wait(timeout=10); log.close()
