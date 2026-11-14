"""Synthetic coupon-preview HTTP regression. PHP_BIN may select a PHP CLI with PDO SQLite.
Only disposable SQLite, synthetic config and loopback traffic; no production files.
Optional COUPON_BASELINE_API verifies the original endpoint's positive bug controls.
"""
from pathlib import Path
import json, os, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error
ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
checks = []
def check(ok, name, **extra):
    checks.append(dict(name=name, passed=bool(ok), **extra))

def run(endpoint, baseline=False):
    with tempfile.TemporaryDirectory(prefix='fas-coupon-shape-') as tmp:
        base = Path(tmp); site = base/'site'
        for directory in ['api', 'includes', 'src/config', 'src/utils', 'src/models', 'src/security', 'database']:
            (site/directory).mkdir(parents=True, exist_ok=True)
        for file in ['includes/security.php','src/config/Database.php','src/utils/Timezone.php','src/models/Coupon.php']:
            shutil.copyfile(ROOT/file, site/file)
        for file in (ROOT/'src/security').glob('*.php'):
            shutil.copyfile(file, site/'src/security'/file.name)
        shutil.copyfile(endpoint, site/'api/validate-coupon.php')
        (site/'src/config/config.php').write_text("<?php return ['database'=>['path'=>__DIR__.'/../../database/test.sqlite'],'site'=>['timezone'=>'UTC']];")
        (base/'sessions').mkdir()
        dbfile=site/'database/test.sqlite'
        with sqlite3.connect(dbfile) as db:
            db.executescript('CREATE TABLE coupons(code TEXT PRIMARY KEY,description TEXT,is_active INT,expires_at TEXT,max_uses INT,times_used INT,minimum_purchase REAL,discount_type TEXT,discount_value REAL);')
            for code, kind, amount, minimum, active, expiry, maximum, used in [
                ('SAVE10','percentage',10,0,1,None,None,0), ('FIXED','fixed',20,0,1,None,None,0),
                ('MINIMUM','percentage',10,50,1,None,None,0),('INACTIVE','fixed',10,0,0,None,None,0),
                ('EXPIRED','fixed',10,0,1,'2000-01-01',None,0),('USED','fixed',10,0,1,None,1,1),
                ('Array','percentage',10,0,1,None,None,0),('1','percentage',10,0,1,None,None,0),('0','percentage',10,0,1,None,None,0),
                ('X'*1000,'fixed',1,0,1,None,None,0)]:
                db.execute('INSERT INTO coupons VALUES(?,?,?,?,?,?,?,?,?)',(code,'Synthetic fixture',active,expiry,maximum,used,minimum,kind,amount))
        def snapshot():
            with sqlite3.connect(dbfile) as db:
                return db.execute('SELECT * FROM coupons ORDER BY code').fetchall()
        before=snapshot()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        origin='http://127.0.0.1:'+str(port)
        # Deliberately do not inherit credentials, proxy, config or transport env.
        env={'PATH':os.environ.get('PATH',''), 'FAS_SECURITY_DB_PATH':str(base/'security.sqlite')}
        log=open(base/'server.log','w')
        server=subprocess.Popen([PHP,'-d','display_errors=1','-d','log_errors=1',
            '-d','allow_url_fopen=0','-d','allow_url_include=0',
            '-d','disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect',
            '-d','session.save_path="'+str(base/'sessions')+'"','-S','127.0.0.1:'+str(port),'-t',str(site)],env=env,stdout=log,stderr=log)
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        def request(payload=None, method='POST', raw=None):
            data=raw if raw is not None else json.dumps(payload).encode()
            req=urllib.request.Request(origin+'/api/validate-coupon.php',data=data if method=='POST' else None, method=method,headers={'Content-Type':'application/json'})
            try: response=opener.open(req,timeout=5)
            except urllib.error.HTTPError as e: response=e
            with response:
                body=response.read().decode(); status=response.status
                try: value=json.loads(body)
                except ValueError: value=None
                return status,value,body
        def reset_security():
            # Independent requests still execute the real guard; clear only disposable counters.
            for suffix in ['', '-wal', '-shm']:
                (base/('security.sqlite'+suffix)).unlink(missing_ok=True)
        def isolated(payload=None, **kwargs):
            reset_security(); return request(payload,**kwargs)
        try:
            for _ in range(100):
                if server.poll() is not None: raise RuntimeError('PHP server exited: '+(base/'server.log').read_text())
                try:
                    if request(method='GET')[0]==405: break
                except OSError: time.sleep(.03)
            else: raise RuntimeError('PHP did not start')
            if baseline:
                for code in [[1], {'x':1}]:
                    status,value,body=isolated({'code':code,'subtotal':100})
                    check(status==200 and value is None and 'Array to string conversion' in body and '"valid":true' in body,'Baseline array/object coercion corrupts JSON',code=code,status=status)
                for code in [True,1,1.0]:
                    status,value,body=isolated({'code':code,'subtotal':100})
                    check(status==200 and value['valid'] and value['code']=='1','Baseline non-string matches coupon 1',code=code)
                status,value,_=isolated({'code':'0','subtotal':100})
                check(status==400 and value.get('error')=='Coupon code is required','Baseline rejects valid string zero coupon')
                return
            for raw in [b'',b'{',b'null',b'false',b'true',b'0',b'1',b'"SAVE10"',b'[]',b'[1]',b'{}']:
                status,value,_=isolated(raw=raw)
                check(status==400 and isinstance(value,dict) and 'error' in value,'Invalid top-level shape rejected',raw=raw.decode())
            for code in [[],[1],{}, {'x':1},True,False,0,1,1.0,None,'']:
                status,value,_=isolated({'code':code,'subtotal':100})
                check(status==400 and isinstance(value,dict) and 'error' in value,'Invalid code rejected with JSON',code=code)
            for subtotal in [None,[],[1],{}, {'x':1},True,False,'','abc','1x','NaN','INF','1e9999',0,-1,'0','-1']:
                status,value,_=isolated({'code':'SAVE10','subtotal':subtotal})
                check(status==400 and isinstance(value,dict) and value.get('error')=='Invalid subtotal','Invalid subtotal rejected',subtotal=subtotal)
            status,value,_=isolated(raw=b'{"code":"SAVE10","subtotal":1e9999}')
            check(status==400 and value.get('error')=='Invalid subtotal','Non-finite decoded number rejected')
            for subtotal in [100,100.0,'100','100.00','1e2',' 100 ']:
                status,value,_=isolated({'code':'SAVE10','subtotal':subtotal})
                check(status==200 and value==dict(valid=True,code='SAVE10',discount_type='percentage',discount_value=10,discount_amount=10,description='Synthetic fixture'),'Numeric compatibility and full response preserved',subtotal=subtotal)
            for code,subtotal,amount in [('FIXED',10,10),('FIXED',100,20),('MINIMUM',50,5),('1',100,10),('0',100,10),('X'*1000,100,1)]:
                status,value,_=isolated({'code':code,'subtotal':subtotal})
                check(status==200 and value['discount_amount']==amount,'Valid code semantics preserved',code=code[:20])
            for code,subtotal in [('MISSING',100),('INACTIVE',100),('EXPIRED',100),('USED',100),('MINIMUM',49),('SAVE10','1e308')]:
                status,value,_=isolated({'code':code,'subtotal':subtotal})
                check(status==400 and value.get('valid') is False and 'message' in value,'Coupon rejection preserved',code=code)
            status,value,_=isolated({'code':'X'*65536,'subtotal':100})
            check(status==413 and isinstance(value,dict),'Original body guard enforced')
            reset_security()
            request({'code':'SAVE10','subtotal':100})
            with sqlite3.connect(base/'security.sqlite') as db:
                db.execute("UPDATE security_meta SET value='1' WHERE name='active'")
                db.execute("DELETE FROM security_buckets")
            statuses=[request({'code':'SAVE10','subtotal':100})[0] for _ in range(31)]
            check(statuses==[200]*30+[429],'Original 30 per 300 second coupon guard enforced',statuses=statuses)
            check(isolated(method='GET')[0]==405,'GET remains disallowed')
            check(snapshot()==before,'Preview never mutates coupons')
        finally:
            server.terminate(); server.wait(timeout=5); log.close()
            if not baseline:
                check('Warning:' not in (base/'server.log').read_text() and 'Fatal error:' not in (base/'server.log').read_text(),'No PHP warnings or fatal errors')

if os.environ.get('COUPON_BASELINE_API'):
    run(Path(os.environ['COUPON_BASELINE_API']),baseline=True)
run(ROOT/'api/validate-coupon.php')
print(json.dumps({'checks':len(checks),'passed':sum(c['passed'] for c in checks),'failed':[c for c in checks if not c['passed']]},indent=2))
raise SystemExit(any(not c['passed'] for c in checks))
