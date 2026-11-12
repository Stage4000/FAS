"""Actual contact controller + security guards, with inert mail/verification functions.
No real config, provider requests or email. All source and security storage are disposable.
PHP_BIN and FAS_AUDIT_SOURCE select installed runtime/full source; FAS_CONTACT_SOURCE
can overlay a local candidate. CONTACT_EXPECT_BASELINE=1 reproduces old false success.
"""
from pathlib import Path
import base64,hashlib,json,os,shutil,subprocess,tempfile
ROOT=Path(os.environ.get('FAS_AUDIT_SOURCE',Path(__file__).resolve().parents[1])).resolve()
PHP=os.environ.get('PHP_BIN','php')
BASELINE=os.environ.get('CONTACT_EXPECT_BASELINE')=='1'
FILES=['api/contact-form.php','includes/security.php','src/security/SecurityStore.php','src/security/ClientIp.php']
RUNNER=r'''<?php
$case=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['fixture_case']=$case;$GLOBALS['fixture_mail']=[];$GLOBALS['fixture_verify']=[];
foreach(['CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLINFO_HTTP_CODE'] as $i=>$name)if(!defined($name))define($name,$i+1);
function mail($to,$subject,$body,$headers,$params){
 $GLOBALS['fixture_mail'][]=compact('to','subject','body','headers','params');
 if(($GLOBALS['fixture_case']['mail']??'true')==='throw')throw new RuntimeException('Synthetic transport exception');
 return ($GLOBALS['fixture_case']['mail']??'true')==='true';
}
function curl_init($url){$GLOBALS['fixture_verify'][]=['url'=>$url,'options'=>[]];return count($GLOBALS['fixture_verify'])-1;}
function curl_setopt($ch,$key,$value){$GLOBALS['fixture_verify'][$ch]['options'][$key]=$value;return true;}
function curl_exec($ch){return json_encode(['success'=>($GLOBALS['fixture_case']['verify_success']??true)]);}
function curl_getinfo($ch,$key){return $GLOBALS['fixture_case']['verify_http']??200;}
function curl_close($ch){}
$_SERVER=['REQUEST_METHOD'=>$case['method']??'POST','REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'fixture.invalid','DOCUMENT_ROOT'=>__DIR__.'/site','REQUEST_URI'=>'/api/contact-form.php'];
if(isset($case['content_length']))$_SERVER['CONTENT_LENGTH']=$case['content_length'];
$_POST=$case['post'];$_GET=[];$_FILES=[];
if($case['blocked']??false){require __DIR__.'/site/includes/security.php';$db=fas_security_store()->db;$db->exec("UPDATE security_meta SET value='1' WHERE name='active'");$stmt=$db->prepare('INSERT INTO security_blocks(ip,expires,reason,actor) VALUES(?,?,?,?)');$stmt->execute(['127.0.0.1',time()+600,'Synthetic test',0]);}
ob_start();register_shutdown_function(function(){
 $body=ob_get_clean();$error=error_get_last();
 echo json_encode(['status'=>http_response_code()?:200,'body'=>$body,'mail'=>$GLOBALS['fixture_mail'],'verify'=>$GLOBALS['fixture_verify'],'error'=>$error]);
});
require __DIR__.'/site/api/contact-form.php';
'''
POST={'name':'Fixture sender','email':'sender@example.invalid','subject':'Synthetic inquiry','message':'Synthetic private text','cf-turnstile-response':'synthetic-single-use-token'}
cases=[]
for enabled in [False,True]:
 for mail in ['true','false','throw']:
  cases.append((f'mail {mail}, verification {enabled}',dict(mail=mail,enabled=enabled),503 if mail!='true' else 200,mail=='true',1,int(enabled)))
for field in ['name','email','subject','message']:
 for value in ['',[],{}]:cases.append((f'invalid {field} {type(value).__name__}',{'post':dict(POST,**{field:value})},400,False,0,0))
cases += [('invalid email',{'post':dict(POST,email='not-an-email')},400,False,0,0),('missing token',{'enabled':True,'post':{k:v for k,v in POST.items() if k!='cf-turnstile-response'}},400,False,0,0),('verification rejected',{'enabled':True,'verify_success':False},400,False,0,1),('verification unavailable',{'enabled':True,'verify_http':503},500,False,0,1),('GET rejected',{'method':'GET'},405,False,0,0),('oversized body',{'content_length':65537},413,False,0,0),('security block',{'blocked':True},429,False,0,0)]
checks=[]
with tempfile.TemporaryDirectory(prefix='fas-contact-delivery-') as td:
 base=Path(td);site=base/'site'
 for rel in FILES:
  src=Path(os.environ['FAS_CONTACT_SOURCE']) if rel=='api/contact-form.php' and os.environ.get('FAS_CONTACT_SOURCE') else ROOT/rel
  dest=site/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dest)
 (site/'src/config').mkdir(parents=True)
 (base/'runner.php').write_text(RUNNER)
 for i,(name,case,status,success,mail_count,verify_count) in enumerate(cases):
  case=dict(case,post=case.get('post',POST))
  config={'site':{'email':'admin@example.invalid','url':'https://fixture.invalid','name':'Synthetic Fixture','from_email':'noreply@fixture.invalid'},'turnstile':{'enabled':case.get('enabled',False),'secret_key':'synthetic-test-only'}}
  (site/'src/config/config.php').write_text('<?php return json_decode('+repr(json.dumps(config))+',true);')
  env={'PATH':os.environ.get('PATH',''),'FAS_SECURITY_DB_PATH':str(base/'private'/str(i)/'security.sqlite')}
  disabled='mail,curl_init,curl_setopt,curl_exec,curl_getinfo,curl_close,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,passthru,shell_exec,system,proc_open,popen'
  args=[PHP,'-d','disable_functions='+disabled,'-d','allow_url_fopen=0','-d','display_errors=0',str(base/'runner.php'),base64.b64encode(json.dumps(case).encode()).decode()]
  out=subprocess.run(args,env=env,text=True,capture_output=True,timeout=15)
  row=json.loads(out.stdout);fatal=case.get('mail')=='throw' and BASELINE
  result=json.loads(row['body']) if not fatal else None
  target_status,target_success=status,success
  if BASELINE and case.get('mail')=='false':target_status,target_success=200,True
  ok=(out.returncode!=0 and row['error'] is not None) if fatal else (out.returncode==0 and row['status']==target_status and result.get('success') is target_success and row['error'] is None)
  ok=ok and len(row['mail'])==mail_count and len(row['verify'])==verify_count
  checks.append({'name':name,'passed':ok,'status':row['status'],'success':result.get('success') if result else None,'mail_calls':len(row['mail']),'verification_calls':len(row['verify']),'fatal':fatal})
  if not fatal:
   checks.append({'name':name+' response contains no submitted private text','passed':'Synthetic private text' not in row['body'] and 'sender@example.invalid' not in row['body']})
  if row['mail']:
   m=row['mail'][0];checks.append({'name':name+' sender and recipient contract preserved','passed':m['to']=='admin@example.invalid' and m['params']=='-fnoreply@fixture.invalid' and 'Reply-To: sender@example.invalid' in m['headers'] and 'Synthetic private text' in m['body']})
report={'scope':'CLI actual handler/security with inert mail/cURL stubs and disposable private security SQLite; no sends or provider access','baseline_expected_defect':BASELINE,'checks':len(checks),'passed':sum(x['passed'] for x in checks),'failed':[x for x in checks if not x['passed']],'results':checks}
print(json.dumps(report,indent=2))
raise SystemExit(int(bool(report['failed'])))
