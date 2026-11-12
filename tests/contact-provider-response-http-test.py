"""Actual loopback HTTP with inert stateful transport/verification doubles. No provider calls."""
from pathlib import Path
import hashlib,json,os,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.parse,urllib.error,sys
ROOT=Path(os.environ.get('FAS_AUDIT_SOURCE',Path(__file__).resolve().parents[1])).resolve()
PHP=os.environ.get('PHP_BIN','php');checks=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
PREPEND=r'''<?php
foreach(['CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLINFO_HTTP_CODE'] as $i=>$n)if(!defined($n))define($n,$i+1);
function fixture_state(){return json_decode(file_get_contents(getenv('FIXTURE_STATE')),true);}
function fixture_save($s){file_put_contents(getenv('FIXTURE_STATE'),json_encode($s));}
function curl_init($url){$GLOBALS['fixture_url']=$url;$GLOBALS['fixture_opts']=[];return 1;}
function curl_setopt($c,$k,$v){$GLOBALS['fixture_opts'][$k]=$v;return true;}
function curl_exec($c){$s=fixture_state();parse_str($GLOBALS['fixture_opts'][CURLOPT_POSTFIELDS],$p);$token=$p['response']??'';$ok=is_string($token)&&!in_array($token,$s['used'],true);$s['verify'][]=['token'=>$token,'success'=>$ok,'url'=>$GLOBALS['fixture_url']];if($ok)$s['used'][]=$token;fixture_save($s);return array_key_exists('verify_raw',$s)?$s['verify_raw']:json_encode(['success'=>$ok]);}
function curl_getinfo($c,$k){return 200;}function curl_close($c){}
function mail($to,$subject,$body,$headers,$params){$s=fixture_state();$s['mail'][]=compact('to','subject','body','headers','params');$mode=$s['mode'];fixture_save($s);if($mode==='throw')throw new RuntimeException('DO_NOT_LEAK_SYNTHETIC_TRANSPORT');return $mode==='success';}
'''
files=['api/contact-form.php','includes/security.php','src/security/SecurityStore.php','src/security/ClientIp.php']
identities={f:hashlib.sha256((ROOT/f).read_bytes()).hexdigest() for f in files}
with tempfile.TemporaryDirectory(prefix='fas-contact-provider-') as tmp:
    base=Path(tmp);site=base/'site'
    for f in files:
        dest=site/f;dest.parent.mkdir(parents=True,exist_ok=True)
        source=Path(os.environ.get('FAS_CONTACT_SOURCE',str(ROOT/f))) if f=='api/contact-form.php' else ROOT/f
        shutil.copyfile(source,dest)
    (site/'src/config').mkdir(exist_ok=True)
    (site/'src/config/config.php').write_text("<?php return ['site'=>['email'=>'admin@example.invalid','url'=>'https://fixture.invalid','name'=>'Synthetic Fixture','from_email'=>'noreply@fixture.invalid'],'turnstile'=>['enabled'=>true,'secret_key'=>'synthetic-only']];")
    (base/'prepend.php').write_text(PREPEND);state=base/'state.json'
    state.write_text(json.dumps({'mode':'false','mail':[],'verify':[],'used':[]}));(base/'sessions').mkdir()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env={'PATH':os.environ.get('PATH',''),'FAS_SECURITY_DB_PATH':str(base/'security.sqlite'),'FIXTURE_STATE':str(state)}
    disabled='mail,curl_init,curl_setopt,curl_exec,curl_getinfo,curl_close,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,passthru,shell_exec,system,proc_open,popen'
    log=open(base/'server.log','w')
    server=subprocess.Popen([PHP,'-d','disable_functions='+disabled,'-d','allow_url_fopen=0','-d','display_errors=0','-d','auto_prepend_file='+str(base/'prepend.php'),'-d','session.save_path='+str(base/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(site)],env=env,stdout=log,stderr=log)
    client=urllib.request.build_opener(urllib.request.ProxyHandler({}));url='http://127.0.0.1:'+str(port)+'/api/contact-form.php'
    def send(data=None):
        try:r=client.open(urllib.request.Request(url,data=urllib.parse.urlencode(data).encode() if data is not None else None),timeout=5)
        except urllib.error.HTTPError as e:r=e
        with r:return r.status,dict(r.headers),r.read().decode()
    def stored():return json.loads(state.read_text())
    def mode(value):
        s=stored();s['mode']=value;state.write_text(json.dumps(s))
    def response(row,status,success,label):
        code,headers,body=row
        check(code==status,label+' status')
        check(headers.get('Content-Type','').startswith('application/json'),label+' JSON content type')
        j=json.loads(body);check(j['success'] is success,label+' truthful success')
        check('DO_NOT_LEAK' not in body and str(base) not in body,label+' no internal exception/path')
        return j
    payload={'name':'Synthetic Sender','email':'sender@example.invalid','subject':'First fixture inquiry','message':'Original draft content & <literal>','cf-turnstile-response':'token-one'}
    try:
        for _ in range(50):
            try:response(send(),405,False,'GET control');break
            except (OSError,urllib.error.URLError):time.sleep(.05)
        else:raise RuntimeError('Loopback server unavailable')
        before=dict(payload);response(send(payload),503,False,'False transport');check(payload==before,'Caller draft bytes unchanged')
        s=stored();check(len(s['mail'])==1 and len(s['verify'])==1,'Failure attempts exactly one verification and one mail')
        check('Original draft content & <literal>' in s['mail'][0]['body'],'Failure transport received original message')
        check(s['mail'][0]['to']=='admin@example.invalid','Only synthetic recipient')
        mode('success');response(send(payload),400,False,'Reused token');s=stored()
        check(len(s['mail'])==1 and len(s['verify'])==2,'Consumed token adds no send attempt')
        check(s['verify'][-1]['success'] is False,'Single-use double rejected consumed token')
        payload=dict(payload,subject='Edited retry inquiry',message='Edited draft is preserved',**{'cf-turnstile-response':'token-two'})
        response(send(payload),200,True,'Fresh explicit retry');s=stored()
        check(len(s['mail'])==2 and len(s['verify'])==3,'One explicit fresh retry causes exactly one additional mail')
        check('Edited draft is preserved' in s['mail'][-1]['body'] and 'Original draft content' not in s['mail'][-1]['body'],'Retry uses only updated draft')
        check(s['verify'][-1]['token']=='token-two','Fresh retry passes replacement verification token')
        mode('throw');payload['cf-turnstile-response']='token-three';response(send(payload),503,False,'Throwing transport');s=stored()
        check(len(s['mail'])==3 and len(s['verify'])==4,'Exception makes exactly one transport attempt')
        mode('success');payload['cf-turnstile-response']='token-four';response(send(payload),200,True,'Recovery after exception');s=stored()
        check(len(s['mail'])==4 and len(s['verify'])==5,'Later explicit recovery has no hidden retries')
        check(all(x['url']=='https://challenges.cloudflare.com/turnstile/v0/siteverify' for x in s['verify']),'Only intended verification URL reaches inert double')
        matrix=[]
        for i,raw in enumerate(['{}','[]','null','true','"scalar"','{broken','{"success":null}','{"success":"false"}','{"success":[]}','{"success":1}','{"success":false}','{"success":true}']):
            s=stored();s['verify_raw']=raw;state.write_text(json.dumps(s));n=len(s['mail']);payload['cf-turnstile-response']='matrix-'+str(i)
            status,headers,body=send(payload)
            try:parsed=json.loads(body);valid_json=True
            except json.JSONDecodeError:parsed=None;valid_json=False
            row={'raw':raw,'status':status,'content_type':headers.get('Content-Type'),'valid_json':valid_json,'response':parsed,'mail_attempts':len(stored()['mail'])-n,'body_length':len(body)};matrix.append(row)
            valid=raw in ['{"success":false}','{"success":true}'];accepted=raw=='{"success":true}'
            check(status==(200 if accepted else 400 if valid else 500),'Strict schema status '+raw)
            check(valid_json and parsed['success'] is accepted,'Strict schema truthful JSON '+raw)
            check(row['mail_attempts']==int(accepted),'Strict schema no unintended mail '+raw)
    finally:
        server.terminate();server.wait(timeout=5);log.close()
    check(all(hashlib.sha256((ROOT/f).read_bytes()).hexdigest()==v for f,v in identities.items()),'Published source unchanged')
report={'passed':True,'checks':len(checks),'details':checks,'source_hashes':identities,'provider_shape_observations':matrix,'scope':'Actual isolated HTTP. Stateful verification and mail are inert doubles; provider single-use is a fixture assumption. No real browser/DOM preservation or delivery guarantee.'}
if os.environ.get('FAS_PROVIDER_TEST_CHILD')!='1':
    source=Path(os.environ.get('FAS_CONTACT_SOURCE',str(ROOT/'api/contact-form.php'))).read_text();controls=[]
    with tempfile.TemporaryDirectory(prefix='contact-provider-controls-') as td:
        for name,old,new,expected in [
            ('missing-shape-check','!is_array($verifyResult)','false','Strict schema truthful JSON null'),
            ('missing-bool-check',"|| !is_bool($verifyResult['success'])",'|| false','Strict schema status {"success":null}')]:
            if source.count(old)!=1:raise AssertionError('Exact mutation anchor required: '+name)
            mutation=Path(td)/(name+'.php');mutation.write_text(source.replace(old,new))
            env={**os.environ,'FAS_CONTACT_SOURCE':str(mutation),'FAS_PROVIDER_TEST_CHILD':'1','FAS_AUDIT_SOURCE':str(ROOT),'PHP_BIN':PHP}
            result=subprocess.run([sys.executable,str(Path(__file__).resolve())],env=env,capture_output=True,text=True,timeout=45)
            if result.returncode==0 or expected not in result.stderr:raise AssertionError('Mutation did not fail intended assertion: '+name+' '+result.stderr[-1000:])
            controls.append({'name':name,'expected_failure':expected,'rejected':True})
    report['mutation_controls']=controls
print(json.dumps(report,indent=2))
