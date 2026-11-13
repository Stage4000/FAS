"""Synthetic fixed-target reload negative controls; loopback only, no browser."""
from pathlib import Path
import contextlib,io,hashlib,json,os,secrets,subprocess,sys,tempfile,time,urllib.request,urllib.error,urllib.parse
sys.dont_write_bytecode=True
from config_reload import FixtureConfigReload
ROOT=Path(__file__).resolve().parents[2];PHP=os.environ.get('FAS_TEST_PHP','php');OUT=Path(tempfile.mkdtemp(prefix='fas-config-reload-')).resolve()
ENV={**os.environ,'FAS_TEST_PHP':PHP,'FAS_SECURITY_DB_PATH':str(OUT/'security.sqlite')}
for command in [[PHP,'tests/manuals/config-reload-unit.php'],[sys.executable,'tests/manuals/prepare-fixture.py',str(OUT)]]:
    result=subprocess.run(command,cwd=ROOT,env=ENV,text=True,capture_output=True);print(result.stdout,end='',flush=True);print(result.stderr,end='',file=sys.stderr,flush=True);assert result.returncode==0,'Fixture reload setup/unit failure'
port=20000+secrets.randbelow(30000);base=f'http://127.0.0.1:{port}';errors=OUT/'php-errors.log';checks=0
command=[PHP,'-d','open_basedir='+str(OUT),'-d','display_errors=1','-d','html_errors=0','-d','error_reporting=-1','-d','log_errors=1','-d','error_log='+str(errors),'-d','allow_url_fopen=0','-d','session.save_path='+str(OUT/'sessions'),'-d','disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client','-S',f'127.0.0.1:{port}','-t',str(OUT/'site')]
def request(path,data=None):
    try:response=urllib.request.urlopen(urllib.request.Request(base+path,data=None if data is None else urllib.parse.urlencode(data).encode()),timeout=10)
    except urllib.error.HTTPError as error:response=error
    with response:return response.status,dict(response.headers),response.read()
def check(value,label):
    global checks
    assert value,label;checks+=1;print('PASS '+label,flush=True)
with (OUT/'server.log').open('w') as log:
    server=subprocess.Popen(command,env=ENV,stdout=log,stderr=log)
    try:
        for _ in range(100):
            assert server.poll() is None,'Reload fixture server unavailable'
            try:
                if request('/manuals/')[0]==200:break
            except OSError:pass
            time.sleep(.03)
        else:raise AssertionError('Reload fixture not ready')
        reload=FixtureConfigReload(OUT,base);config=reload.config;original=config.read_text();token=(OUT/'config-reload-key').read_text();digest=hashlib.sha256(config.read_bytes()).hexdigest();fields={'token':token,'expected_sha256':digest}
        for path,data,status,label in [('/fixture-config-reload.php',None,405,'GET is rejected'),('/fixture-config-reload.php',{},400,'Missing fields rejected'),('/fixture-config-reload.php',{**fields,'token':'wrong'},403,'Incorrect fixture token rejected'),('/fixture-config-reload.php',{**fields,'expected_sha256':'0'*64},409,'Wrong expected disk bytes rejected'),('/fixture-config-reload.php',{**fields,'path':'/etc/passwd'},400,'Arbitrary target field rejected'),('/fixture-config-reload.php',{**fields,'code':'arbitrary'},400,'Executable-content field rejected'),('/fixture-config-reload.php?path=other',fields,400,'Query-selected target rejected'),('/fixture-config-reload.php',{**fields,'expected_sha256':'invalid'},400,'Malformed expected digest rejected')]:
            result=request(path,data);check(result[0]==status and result[2]==b'{"ok":false}',label)
        alias=OUT/'config-alias.php';alias.write_text(original);config.unlink();config.symlink_to(alias)
        try:
            check(request('/fixture-config-reload.php',fields)[0]==409,'Symlink config target rejected')
            unchanged=alias.read_bytes();failed=False
            try:reload.apply(original,'string_release')
            except AssertionError:failed=True
            check(failed and alias.read_bytes()==unchanged,'Client rejects symlink before any config write')
        finally:config.unlink();config.write_text(original)
        try:
            for case,spelling,flag,status in [('string_release',"'true'",'string_true',404),('boolean_release','false','boolean_false',404),('string_restore','true','boolean_true',200)]:
                expected=original.replace("'public_enabled'=>true","'public_enabled'=>"+spelling)
                reload.apply(expected,case);reload.before_request();result=request('/manuals/');reload.observe(result,flag,OUT/'private','catalog');check(result[0]==status,'Explicit reload applies exact '+case)
            marker=reload.marker;valid=json.loads(marker.read_text())
            for field,wrong,label in [('loaded_flag','boolean_false','Stale compiled flag cannot pass on status alone'),('storage_sha256','0'*64,'Stale loaded storage cannot pass on status alone'),('disk_sha256','0'*64,'Wrong disk fingerprint fails'),('pid',reload.pid+1,'Different serving process fails')]:
                marker.write_text(json.dumps({**valid,field:wrong}));failed=False
                try:
                    with contextlib.redirect_stdout(io.StringIO()):reload.observe(result,'boolean_true',OUT/'private','catalog')
                except AssertionError:failed=True
                check(failed,label)
            marker.unlink();failed=False
            try:
                with contextlib.redirect_stdout(io.StringIO()):reload.observe(result,'boolean_true',OUT/'private','catalog')
            except AssertionError:failed=True
            check(failed,'Missing observation cannot pass on status alone')
        finally:
            reload.apply(original,'string_restore');reload.before_request();result=request('/manuals/');reload.observe(result,'boolean_true',OUT/'private','catalog');assert result[0]==200
        check(not errors.exists() or errors.read_text()=='','Reload helper and denials leave strict PHP log empty')
    finally:
        server.terminate()
        try:server.wait(timeout=10)
        except subprocess.TimeoutExpired:server.kill();server.wait()
# When OPcache is really active (hosted CLI-server), prove API restriction is
# a closed failure in a separate disposable process, not a disabled-cache pass.
if reload.method=='forced_invalidation':
    restricted=command.copy();restricted[1:1]=['-d','opcache.restrict_api='+str(OUT/'not-the-helper-path')]
    with (OUT/'restricted-server.log').open('w') as log:
        server=subprocess.Popen(restricted,env=ENV,stdout=log,stderr=log)
        try:
            for _ in range(100):
                assert server.poll() is None,'Restricted-API fixture exited'
                try:
                    if request('/public/css/manuals.css')[0]==200:break
                except OSError:pass
                time.sleep(.03)
            else:raise AssertionError('Restricted-API fixture not ready')
            fields['expected_sha256']=hashlib.sha256(config.read_bytes()).hexdigest()
            result=request('/fixture-config-reload.php',fields)
            check(result[0]==503 and result[2]==b'{"ok":false}','Actual restricted OPcache API fails closed without fallback')
            check(not errors.exists() or errors.read_text()=='','Actual API restriction is scoped and leaves no leaked PHP warning')
        finally:
            server.terminate()
            try:server.wait(timeout=10)
            except subprocess.TimeoutExpired:server.kill();server.wait()
else:print('NOT RUN actual OPcache API restriction: local runtime has no OPcache; injected restriction unit check passed.',flush=True)
print(f'{checks} fixture reload HTTP/observation checks passed; no browser launched.')
