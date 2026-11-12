import pathlib,tempfile,subprocess,json,shutil,sys,os
ROOT=pathlib.Path(__file__).resolve().parents[1]; source=pathlib.Path(sys.argv[1]) if len(sys.argv)>1 else ROOT
php=os.environ.get('PHP_BIN','php'); util=ROOT/'src/utils/Timezone.php'
now=1794463200 # fixed synthetic instant
base={'enabled':True,'type':'percentage','value':20,'label':'TEST','starts_at':None,'ends_at':None}
def run(sale,post=None,zone='America/Los_Angeles'):
 with tempfile.TemporaryDirectory() as t:
  p=pathlib.Path(t)
  for d in ['admin','includes','src/config','src/utils']: (p/d).mkdir(parents=True,exist_ok=True)
  shutil.copy(util,p/'src/utils/Timezone.php')
  (p/'src/config/config.php').write_text('<?php return '+"json_decode("+json.dumps(json.dumps({'site':{'timezone':zone},'sale':sale,'sentinel':'unchanged'}))+',true);')
  (p/'admin/auth.php').write_text('<?php class AdminAuth {function requireLogin(){}}')
  (p/'src/utils/CSRF.php').write_text('<?php namespace FAS\\Utils; class CSRF {static function validateToken($x){return true;}}')
  code=(source/'admin/sale.php').read_text().split('?>',1)[0].replace('time()',str(now))
  code+='\necho json_encode(["error"=>$error,"success"=>$success,"live"=>$saleIsLive,"config"=>require $configFile]);'
  (p/'admin/sale.php').write_text(code)
  helper=(source/'includes/sale-helper.php').read_text().replace('time()',str(now))
  (p/'includes/sale-helper.php').write_text(helper)
  script=r'<?php require __DIR__."/src/utils/Timezone.php"; \FAS\Utils\Timezone::apply(); $_SERVER["REQUEST_METHOD"]='+json.dumps('POST' if post is not None else 'GET')+'; $_POST=json_decode('+json.dumps(json.dumps(post or {}))+',true); require __DIR__."/admin/sale.php";'
  (p/'run.php').write_text(script)
  a=subprocess.run([php,"-d","display_errors=0",str(p/'run.php')],capture_output=True,text=True); assert a.returncode==0,a.stderr
  (p/'get.php').write_text('<?php require __DIR__."/includes/sale-helper.php"; echo json_encode(getSaleConfig());')
  b=subprocess.run([php,"-d","display_errors=0",str(p/'get.php')],capture_output=True,text=True); assert b.returncode==0,b.stderr
  return json.loads(a.stdout),json.loads(b.stdout)
checks=[]
def ck(name,test):
 try: test(); checks.append({'name':name,'pass':True})
 except AssertionError as e: checks.append({'name':name,'pass':False,'error':str(e)})
def invalid(field,value):
 before=dict(base,starts_at='2027-01-01 00:00:00')
 r,_=run(before,{'enabled':'1','type':'percentage','value':'20',field:value}); assert r['error'],r; assert r['config']['sale']==before,r
for field in ['starts_at','ends_at']:
 for v in ['not-a-date','0',['invalid']]: ck('reject '+field+' '+repr(v),lambda f=field,v=v:invalid(f,v))
def schedule(field,date,live,zone):
 r,h=run(dict(base,**{field:date}),zone=zone); assert r['live']==live,(r,live); assert (h is not None)==live,(h,live)
import datetime
for zone in ['America/Los_Angeles','America/Chicago','UTC','Asia/Tokyo']:
 for field in ['starts_at','ends_at']:
  for delta in [-1,0,1]:
   date=datetime.datetime.fromtimestamp(now+delta,datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
   live=delta<=0 if field=='starts_at' else delta>=0
   ck(f'{zone} {field} {delta}',lambda f=field,d=date,l=live,z=zone:schedule(f,d,l,z))
for field in ['starts_at','ends_at']:
 ck('invalid stored '+field,lambda f=field:schedule(f,'not-a-date',False,'UTC'))
for v in [None,'']:
 ck('open schedule '+repr(v),lambda v=v:schedule('starts_at',v,True,'UTC'))
def validpost():
 r,h=run(base,{'enabled':'1','type':'percentage','value':'20','starts_at':'2026-11-12T00:00','ends_at':''}); assert not r['error'],r; assert r['config']['sale']['starts_at']=='2026-11-12 08:00:00',r; assert r['config']['sentinel']=='unchanged'; assert h is None
ck('valid user-local date writes UTC, other config preserved',validpost)
for zone in ['America/Los_Angeles','UTC','Asia/Tokyo']:
 for value in ['2026-11-12T06:00:00Z','2026-11-11T22:00:00-08:00','2026-11-12 06:00:00+0000']:
  ck('legacy explicit offset '+zone+' '+value,lambda z=zone,v=value:schedule('starts_at',v,True,z))
for value in ['', '   ']:
 def blank(v=value):
  r,h=run(base,{'enabled':'1','type':'percentage','value':'20','starts_at':v,'ends_at':v})
  assert not r['error']; assert r['config']['sale']['starts_at'] is None; assert r['config']['sale']['ends_at'] is None; assert h is not None
 ck('blank supplied schedule '+repr(value),blank)
def interval(start,end,rejected):
 before=dict(base,starts_at='2027-01-01 00:00:00')
 r,h=run(before,{'enabled':'1','type':'percentage','value':'20','starts_at':start,'ends_at':end})
 assert bool(r['error'])==rejected,r
 if rejected: assert r['config']['sale']==before,r
 else: assert r['success'],r
for start,end,bad in [('2027-01-02T00:00','2027-01-01T00:00',True),('2027-01-01T00:00:01','2027-01-01T00:00:00',True),('2027-01-01T00:00','2027-01-01T00:00',False),('2027-01-01T00:00','2027-01-02T00:00',False),('','2027-01-01T00:00',False),('2027-01-01T00:00','',False),('','',False)]:
 ck('interval order '+repr((start,end)),lambda s=start,e=end,b=bad:interval(s,e,b))
print(json.dumps({'passed':sum(x['pass'] for x in checks),'total':len(checks),'checks':checks},indent=2));sys.exit(0 if all(x['pass'] for x in checks) else 1)
