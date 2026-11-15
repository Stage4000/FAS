"""Actual admin-search HTTP acceptance; synthetic config/nav/login only, no upload/provider."""
from pathlib import Path
import os,sys,tempfile,shutil,subprocess,sqlite3,json,hashlib,time,socket,urllib.request,urllib.error,urllib.parse,http.cookiejar,html,re
ROOT=Path(os.environ.get('FAS_AUDIT_SOURCE',Path(__file__).resolve().parents[2])).resolve()
PHP=os.environ.get('FAS_TEST_PHP',os.environ.get('PHP_BIN','php'))
FILES=['admin/manuals.php','admin/manuals-common.php','admin/auth.php','includes/admin-accounts.php','includes/security.php','src/utils/CSRF.php','src/utils/Timezone.php','src/config/Database.php','src/security/ClientIp.php','src/security/SecurityStore.php','src/manuals/bootstrap.php','src/manuals/ManualLibrary.php','src/manuals/ManualByteRange.php']
checks=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a): return None
with tempfile.TemporaryDirectory(prefix='fas-admin-search-') as temp:
    out=Path(temp);site=out/'site';site.mkdir()
    for f in FILES:
        p=site/f;p.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/f,p)
    for d in ['sessions','private']:(out/d).mkdir()
    (site/'admin/includes').mkdir();(site/'admin/includes/nav.php').write_text('<nav>Synthetic admin navigation</nav>');(site/'admin/includes/footer.php').write_text('')
    (site/'src/config/config.php').write_text("<?php return ['database'=>['path'=>__DIR__.'/../../fixture.sqlite'],'manuals'=>['public_enabled'=>false,'storage_path'=>"+repr(str(out/'private'))+"]];")
    # Same login adapter as the existing upload-storage fixture, actual AdminAuth login.
    (site/'fixture-login.php').write_text("<?php require __DIR__.'/admin/auth.php';$a=new AdminAuth();if(!$a->login('fixture','fixture-only')){http_response_code(403);exit($a->lastError);}echo 'Synthetic fixture login';")
    dbpath=site/'fixture.sqlite'
    password=subprocess.check_output([PHP,'-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],text=True)
    with sqlite3.connect(dbpath) as db:
        db.execute('CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,email TEXT,role TEXT,is_active INTEGER,last_login TEXT)')
        db.execute("INSERT INTO admin_users VALUES(1,'fixture',?,'fixture@example.invalid','admin',1,NULL)",[password])
    seed=out/'seed.php';seed.write_text("<?php require "+repr(str(site/'src/manuals/bootstrap.php'))+";FAS\\Manuals\\ManualLibrary::install(manualsDatabase());")
    env={'PATH':os.environ.get('PATH',''),'FAS_SECURITY_DB_PATH':str(out/'security.sqlite')}
    subprocess.run([PHP,str(seed)],env=env,check=True,capture_output=True)
    with sqlite3.connect(dbpath) as db:
        for i in range(1,66):
            draft={'title':f'Synthetic Alpha {i:02}','manufacturer':'Example & Motors','model':'ZX','years':'2026','rights':'unverified','rights_note':'PRIVATE EVIDENCE NOT SEARCHABLE','description':''}
            if i==65:draft.update(title='<img src=x onerror=alert(1)>',manufacturer='Other <brand>',model='<script>example</script>')
            db.execute('INSERT INTO manuals(revision,request_key,draft_json,published_json,file_json,created_at,updated_at) VALUES(1,?,?,NULL,?,?,?)',[f'synthetic-{i}',json.dumps(draft),json.dumps({'key':'synthetic.pdf'}),'2026-01-01','2026-01-01'])
    def snapshot():
        with sqlite3.connect(dbpath) as db:return '\n'.join(db.iterdump())
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}'
    def client():return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
    anon=client();auth=client()
    def request(c,path):
        try:r=c.open(base+path,timeout=10)
        except urllib.error.HTTPError as e:r=e
        with r:return r.status,dict(r.headers),r.read().decode('utf-8',errors='replace')
    cmd=[PHP,'-d','open_basedir='+str(out),'-d','allow_url_fopen=0','-d','display_errors=1','-d','html_errors=0','-d','session.save_path='+str(out/'sessions'),'-d','disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client','-S',f'127.0.0.1:{port}','-t',str(site)]
    with (out/'server.log').open('w') as log:
        server=subprocess.Popen(cmd,env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                assert server.poll() is None,'Server exited'
                try:r=request(anon,'/admin/manuals.php');break
                except OSError:time.sleep(.05)
            else:raise AssertionError('Server not ready')
            check(r[0]==302 and 'login.php' in r[1].get('Location','') and 'Synthetic Alpha' not in r[2],'Anonymous gate rejects before draft rendering')
            r=request(anon,'/admin/manuals.php?q[]=invalid');check(r[0]==302 and 'role="alert"' not in r[2],'Authentication gate precedes malformed-filter validation')
            r=request(auth,'/fixture-login.php');check(r[0]==200 and r[2]=='Synthetic fixture login','Actual AdminAuth login succeeds')
            before=snapshot()
            def get(q=''):return request(auth,'/admin/manuals.php'+('?' + q if q else ''))
            r=get();check(r[0]==200 and '65 manuals found.' in r[2],'Authorized unfiltered draft discovery')
            check('private, no-store' in r[1].get('Cache-Control','') and 'noindex' in r[1].get('X-Robots-Tag',''),'Private response headers retained')
            check('PRIVATE EVIDENCE NOT SEARCHABLE' not in r[2],'Private rights evidence never rendered in discovery')
            check('Pre-launch mode' in r[2],'Admin discovery works while public release is off')
            query=urllib.parse.urlencode({'q':'Synthetic Alpha','manufacturer':'Example & Motors'})
            r=get(query);check(r[0]==200 and '64 manuals found.' in r[2],'Combined metadata and exact manufacturer filter')
            urls=[html.unescape(x) for x in re.findall(r'href="([^"]+)"',r[2])];n=next(x for x in urls if 'page=2' in x);params=urllib.parse.parse_qs(urllib.parse.urlsplit(n).query)
            check(params=={'q':['Synthetic Alpha'],'manufacturer':['Example & Motors'],'page':['2']},'Next-page link preserves both encoded filters')
            r=get(query+'&page=2');check('Page 2 of 3' in r[2] and '64 manuals found.' in r[2],'Second filtered page renders')
            check('q=Synthetic%20Alpha&amp;manufacturer=Example%20%26%20Motors&amp;page=1' in r[2],'Previous-page link preserves encoded filters')
            r=get(query+'&page=33334');check(r[0]==200 and 'Page 3 of 3' in r[2] and 'Synthetic Alpha 01' in r[2],'Out-of-range page clamps and fetches final page')
            for q in ['q=absent','q=PRIVATE+EVIDENCE+NOT+SEARCHABLE','manufacturer=Absent','manufacturer=example+%26+motors','q=%25','q=%27+OR+1%3D1+--']:
                r=get(q);check(r[0]==200 and 'No manuals match these filters.' in r[2] and '0 manuals found.' in r[2],'Empty filtered state '+q)
            r=get(urllib.parse.urlencode({'q':'é'*160}));check(r[0]==200 and '0 manuals found.' in r[2],'Unicode character-length boundary accepted')
            r=get(urllib.parse.urlencode({'q':'é'*161}));check(r[0]==400 and 'role="alert"' in r[2],'Unicode overlength rejected')
            for q in ['q[]=x','manufacturer[]=x','q=%FF','manufacturer=%FF','q='+('a'*161),'manufacturer='+('a'*81),'q='+('+'.join(['x']*13))]:
                r=get(q);check(r[0]==400 and 'role="alert"' in r[2] and 'Warning:' not in r[2] and 'Fatal error' not in r[2] and 'Synthetic Alpha' not in r[2],'Controlled invalid filter '+q[:40])
            r=get(urllib.parse.urlencode({'q':'<img src=x onerror=alert(1)>','manufacturer':'Other <brand>'}));check(r[0]==200 and '1 manual found.' in r[2],'Special-character metadata remains searchable')
            check('<img src=x' not in r[2] and '<script>example' not in r[2] and '&lt;img src=x' in r[2] and '&lt;script&gt;example' in r[2],'Draft title/model and filter values HTML escaped')
            check('value="Other &lt;brand&gt;"' in r[2] and 'value="Example &amp; Motors"' in r[2],'Manufacturer input and facets escaped')
            for q in ['page[]=2','page=-1','page=notanumber','page=33335']:
                r=get(q);check(r[0]==200 and 'Page 1 of 3' in r[2] and 'Warning:' not in r[2],'Malformed page safely defaults '+q)
            check(snapshot()==before,'All search GETs leave entire application DB unchanged after login')
            with sqlite3.connect(dbpath) as db:db.execute('UPDATE admin_users SET is_active=0 WHERE id=1')
            before=snapshot();r=get('q=Synthetic');check(r[0] in (302,403) and 'Synthetic Alpha' not in r[2] and 'manual-table-wrap' not in r[2],'Existing session cannot read after active-admin revocation')
            check(snapshot()==before,'Revoked search request does not mutate application DB')
            # Separate fixture arrangement, not a request-side mutation.
            with sqlite3.connect(dbpath) as db:
                db.execute('DELETE FROM manuals');db.execute('UPDATE admin_users SET is_active=1 WHERE id=1')
            empty_before=snapshot();r=get();check(r[0]==200 and 'No manuals yet. Upload a PDF to begin.' in r[2] and '0 manuals found.' in r[2],'Empty library distinguishes initial upload state')
            check(snapshot()==empty_before,'Empty-library GET leaves application DB unchanged')
            check(not list((out/'private').iterdir()),'No PDF or import files created')
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill();server.wait()
    result={'passed':len(checks),'checks':checks,'sources':{f:hashlib.sha256((ROOT/f).read_bytes()).hexdigest() for f in FILES},'scope':'Actual PHP HTTP, unchanged active AdminAuth/common/bootstrap/CSRF, synthetic DB/config/nav/login; no browser or production','no_uploads_or_external_requests':True}
    print(json.dumps(result,indent=2))
