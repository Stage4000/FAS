"""Actual shipping controllers + helper, inert services only. No HTTP server or external sends.

The test copies only the two source controllers and ShippingRules into a temporary
fixture. Database, security, quote-session and provider dependencies are explicit
inert adapters, so this is controller branching coverage, not provider/auth acceptance.
"""
from pathlib import Path
import json, os, shutil, subprocess, tempfile

ROOT = Path(os.environ.get('FAS_SOURCE_ROOT', Path(__file__).resolve().parents[1])).resolve()
RULES = Path(os.environ.get('FAS_SHIPPING_RULES_SOURCE', ROOT / 'src/utils/ShippingRules.php')).resolve()
PHP = os.environ.get('PHP_BIN', 'php')
DISABLED = 'mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,socket_connect,exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec'
checks, failures = 0, []
def check(ok, label):
    global checks
    checks += 1
    if not ok: failures.append(label)

stubs = {
 'src/config/Database.php': """<?php namespace FAS\\Config;
class Database { static function getInstance(){ return new self; } function getConnection(){ return new \\stdClass; } }
""",
 'src/models/Product.php': """<?php namespace FAS\\Models;
class Product { function __construct($db){} function getById($id){ return $GLOBALS['fixture']['products'][(string)$id] ?? null; } }
""",
 'src/models/Warehouse.php': """<?php namespace FAS\\Models;
class Warehouse { function __construct($db){} function getForCartItems($items){ $GLOBALS['trace']['warehouse_calls']++; return ['id'=>1]; } }
""",
 'src/shipping/ShippingRateService.php': """<?php namespace FAS\\Shipping;
class ShippingConfig { static function load(){ return ['mode'=>'easyship','request_budget_seconds'=>10]; } }
class ShippingRateService {
 static function forDatabase($db,$config,$deadline=null){return new self;}
 function getShippingRates($items,$address,$warehouse,$limit=null){
  $GLOBALS['trace']['provider_calls'][]=['ids'=>array_column($items,'id'),'quantity'=>array_sum(array_column($items,'quantity')),'state'=>$address['state']];
  if ($GLOBALS['fixture']['provider_failure']) return null;
  return [['courier_id'=>'synthetic_paid','courier_name'=>'Fixture only','service_name'=>'Standard','total_charge'=>17.0,'currency'=>'USD','provider'=>'easyship']];
 }
 function shipmentSnapshot(){return null;} function diagnostics(){return [];}
}
""",
 'src/shipping/ShippingOrder.php': """<?php namespace FAS\\Shipping; class ShippingOrder {static function directReady($db){return true;}}
""",
 'src/utils/ErrorMonitor.php': """<?php namespace FAS\\Utils;
class ErrorMonitor {const AREA_SHIPPING='shipping'; function __construct($db){} function record(...$args){$GLOBALS['trace']['errors']++;} function recordThrowable(...$args){$GLOBALS['trace']['errors']++;}}
""",
 'src/payments/ApplePayContext.php': """<?php namespace FAS\\Payments;
class ApplePayContext { static function rememberShipping($input,$rates,$snapshot){$GLOBALS['trace']['quotes']++; return 'synthetic-quote';} }
""",
 'includes/security.php': """<?php
function fas_security_guard(...$args){$GLOBALS['trace']['security_calls']++;}
function fas_security_body($limit){return json_encode($GLOBALS['fixture']['request'],JSON_THROW_ON_ERROR);}
""",
 'src/config/config.php': """<?php return ['shipping'=>['free_shipping'=>$GLOBALS['fixture']['settings']]];""",
}
manual = dict(enabled=True, product_flags_enabled=True, auto_rules_enabled=False)
automatic = dict(enabled=True, product_flags_enabled=False, auto_rules_enabled=True, max_weight=5)
products = {
 '1': dict(id=1,name='Synthetic eligible item',price=20,quantity=10,is_active=1,show_on_website=1,free_shipping=1,weight=1,length=10,width=8,height=6),
 '2': dict(id=2,name='Synthetic rated item',price=30,quantity=10,is_active=1,show_on_website=1,free_shipping=0,weight=10,length=10,width=8,height=6),
}
cases = []
for state, eligible in [('OR',True),('Oregon',True),('DC',True),('District of Columbia',True),('AK',False),('HI',False),('PR',False),('PW',False),('FM',False),('MH',False),('ZZ',False),('Ontario',False),('Armed Forces Europe',False),('OR1',False)]:
    for mode, settings in [('manual',manual),('automatic',automatic)]:
        for mixed in [False, True]:
            cases.append((f'{mode}-{state}-mixed{mixed}',state,'US',eligible,settings,mixed,False))
for country in ['CA','MX','US1','United States of America']:
    cases.append((f'country-{country}','OR',country,country=='United States of America',manual,False,False))
for state in ['PW','ZZ','AK']:
    cases.append((f'provider-unavailable-{state}',state,'US',False,manual,False,True))
cases.append(('disabled','OR','US',True,dict(manual,enabled=False),False,False))
with tempfile.TemporaryDirectory(prefix='fas-destination-api-') as tmp:
    site=Path(tmp)
    for rel in ['api/shipping-rates.php','api/shipping-estimate.php']:
        target=site/rel; target.parent.mkdir(parents=True,exist_ok=True); shutil.copyfile(ROOT/rel,target)
    for rel, content in stubs.items():
        target=site/rel; target.parent.mkdir(parents=True,exist_ok=True); target.write_text(content)
    shutil.copyfile(RULES,site/'src/utils/ShippingRules.php')
    (site/'run.php').write_text('''<?php
$GLOBALS['fixture']=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['trace']=['provider_calls'=>[],'warehouse_calls'=>0,'errors'=>0,'quotes'=>0,'security_calls'=>0];
register_shutdown_function(static function(){file_put_contents(__DIR__.'/trace.json',json_encode(['status'=>http_response_code()?:200,'trace'=>$GLOBALS['trace']]));});
$_SERVER['REQUEST_METHOD']='POST';
require __DIR__.'/api/'.$argv[1].'.php';
''')
    env={k:v for k,v in os.environ.items() if not k.startswith('FAS_')}
    for endpoint in ['shipping-rates','shipping-estimate']:
        for name,state,country,eligible,settings,mixed,provider_failure in cases:
            label=f'{endpoint}: {name}'
            items=[dict(id=1,quantity=2)] + ([dict(id=2,quantity=1)] if mixed else [])
            address=dict(address1='100 Synthetic Road',city='Fixture City',state=state,zip='00000',country=country)
            (site/'case.json').write_text(json.dumps(dict(request=dict(items=items,address=address),products=products,settings=settings,provider_failure=provider_failure)))
            result=subprocess.run([PHP,'-d','allow_url_fopen=0','-d','display_errors=stderr','-d','disable_functions='+DISABLED,str(site/'run.php'),endpoint],capture_output=True,text=True,env=env,timeout=10)
            check(result.returncode==0,label+' clean process'); check(not result.stderr,label+' no warning/error output')
            try:
                body=json.loads(result.stdout); meta=json.loads((site/'trace.json').read_text())
            except (ValueError,FileNotFoundError):
                check(False,label+' valid JSON result'); continue
            trace=meta['trace']; check(trace['security_calls']==1,label+' controller retains security guard call')
            grant=eligible and settings['enabled']; expect_provider=not grant or mixed
            check(len(trace['provider_calls'])==int(expect_provider),label+' free-only bypass versus rated branch')
            if expect_provider:
                expected_ids=[2] if grant else ([1,2] if mixed else [1])
                check((trace['provider_calls'][0]['ids'] if trace['provider_calls'] else None)==expected_ids,label+' correct rated product identities')
                check((trace['provider_calls'][0]['quantity'] if trace['provider_calls'] else None)==(1 if grant else (3 if mixed else 2)),label+' correct rated quantity')
            if provider_failure:
                check(body.get('success') is False,label+' failed provider cannot become free shipping')
                check(not any(r.get('total_charge')==0 for r in body.get('rates',[])),label+' no zero fallback')
                check(meta['status']==(500 if endpoint=='shipping-rates' else 200),label+' existing unavailable status preserved')
                check(trace['quotes']==0,label+' no remembered failed quote')
                continue
            summary=body.get('free_shipping',{})
            check(body.get('success') is True,label+' successful branch')
            check(summary.get('destination_eligible') is eligible,label+' explicit destination eligibility')
            check(summary.get('item_count' if endpoint=='shipping-rates' else 'free_items_count')==(2 if grant else 0),label+' free quantity')
            check(summary.get('rated_item_count' if endpoint=='shipping-rates' else 'rated_items_count')==((1 if mixed else 0) if grant else (3 if mixed else 2)),label+' rated quantity')
            rates=body.get('rates',[])
            check(len(rates)==1,label+' single expected rate')
            check(rates[0].get('total_charge')==(17 if expect_provider else 0),label+' paid versus free total')
            check(trace['quotes']==(1 if endpoint=='shipping-rates' else 0),label+' quote lifecycle unchanged')
            check(meta['status']==200,label+' success status')
print(json.dumps(dict(cases=len(cases)*2,checks=checks,failures=len(failures),failed=failures),indent=2))
raise SystemExit(bool(failures))
