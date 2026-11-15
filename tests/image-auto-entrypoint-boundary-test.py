"""Exact CLI entrypoints tested in fresh minimal PHP processes.
Negative early gates also load real modules. Positive dispatch uses explicit spies,
not PDO/GD/cURL acceptance, and never touches a provider or production database.
"""
import hashlib, json, os, pathlib, shutil, subprocess, sys, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
FIXTURES = pathlib.Path(__file__).resolve().parent / 'fixtures/image-auto-pilot'
ENTRY_DIR = FIXTURES/'entrypoints' if FIXTURES.is_dir() else ROOT/'candidate'
SOURCE_DIR = FIXTURES if FIXTURES.is_dir() else ROOT/'candidate/src'
PHP = pathlib.Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else pathlib.Path(shutil.which('php') or str(ROOT.parent / 'php83-cli-workspace/php-8.3.36/sapi/cli/php'))
checks = 0

def check(value, label):
    global checks
    if not value:
        raise AssertionError(label)
    checks += 1

PDO_SPY = r'''<?php
function track(array $v):void { file_put_contents(__DIR__.'/effects.jsonl',json_encode($v)."\n",FILE_APPEND); }
class PDO {
 const SQLITE_ATTR_OPEN_FLAGS=1000,SQLITE_OPEN_READONLY=1,SQLITE_OPEN_READWRITE=2;
 const ATTR_ERRMODE=3,ERRMODE_EXCEPTION=4,ATTR_STRINGIFY_FETCHES=5;
 function __construct($dsn,$user=null,$password=null,$options=[]) {track(['pdo',$dsn,$options]);}
 function setAttribute($k,$v) {track(['attribute',$k,$v]);return true;}
 function exec($sql) {track(['exec',$sql]);return 0;}
}
'''
SOURCE_SPY = r'''<?php
namespace FAS\Utils;
class CurlImageIntakeTransport {function __construct($enabled=false){\track(['network_enabled',$enabled]);}}
class ImageSourceIntake {
 function __construct($db,$store,$transport,$enabled=false){\track(['intake_enabled',$enabled]);}
 function acquire($job){\track(['acquire',$job]);return ['synthetic'=>true];}
}
'''
STORE_SPY = r'''<?php
namespace FAS\Utils;
class PosixImageIntakeStore {function __construct($state,$site){\track(['store',$state,$site]);}}
'''
PILOT_SPY = r'''<?php
namespace FAS\Utils;
class ImagePilot {
 function __construct($db,$site,$private,$input,$enabled=false,$requireIntake=false){\track(['pilot',$enabled,$requireIntake]);}
 function enqueue($job,$record){\track(['enqueue',$job,$record]);return ['state'=>'queued'];}
 function run($job){\track(['run',$job]);return ['state'=>'done'];}
 function inspect($job){\track(['inspect',$job]);return ['state'=>'done'];}
}
'''

# -n must actually remove PDO before the explicit PDO spy is declared.
probe=subprocess.run([str(PHP),'-n','-r',"echo class_exists('PDO',false)?'present':'absent';"],capture_output=True,text=True,timeout=10)
check(probe.returncode==0 and probe.stdout=='absent','minimal spy runtime has no real PDO')

with tempfile.TemporaryDirectory(prefix='fas-cli-boundary-') as tmp:
    base = pathlib.Path(tmp)
    def fixture(spies=True):
        d = base / ('case-' + str(len(list(base.iterdir()))))
        d.mkdir(mode=0o700)
        for name in ('intake.php','automatic-pilot.php'):
            shutil.copyfile(ENTRY_DIR/name,d/name)
        if spies:
            (d/'src').mkdir()
            for name, content in [('ImageSourceIntake.php',SOURCE_SPY),('PosixImageIntakeStore.php',STORE_SPY),('ImagePilot.php',PILOT_SPY)]:
                (d/'src'/name).write_text(content)
            (d/'spy.php').write_text(PDO_SPY)
        else:
            shutil.copytree(SOURCE_DIR,d/'src',ignore=shutil.ignore_patterns('entrypoints'))
        state=d/'private';state.mkdir(mode=0o700);(state/'inputs').mkdir(mode=0o700)
        site=d/'site';site.mkdir(mode=0o700)
        db=d/'catalog.sqlite';db.write_bytes(b'SYNTHETIC-NOT-A-DATABASE')
        config={'enabled':True,'network_enabled':True,'site':str(site),'private':str(state),'input':str(state/'inputs'),'database':str(db),'job':'a'*32}
        return d, config
    def save(path, data, mode=0o600):
        path.write_bytes(data if isinstance(data,bytes) else json.dumps(data).encode())
        path.chmod(mode)
    def run(d, script, args, spies=True):
        cmd=[str(PHP),'-n','-d','allow_url_fopen=0','-d','allow_url_include=0','-d',f'open_basedir={d}', '-d','disable_functions=exec,system,shell_exec,passthru,popen,proc_open,mail,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,curl_exec,curl_multi_exec']
        if spies:cmd += ['-d',f'auto_prepend_file={d}/spy.php']
        result=subprocess.run(cmd+[str(d/script),*args],capture_output=True,text=True,timeout=10,env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
        trace=d/'effects.jsonl'
        effects=[json.loads(line) for line in trace.read_text().splitlines()] if trace.exists() else []
        return result,effects

    # These exact early refusal paths must pass with real modules as well as spies.
    for spies in (False,True):
        for script, arg, disabled_msg in [('intake.php','acquire','Source acquisition disabled'),('automatic-pilot.php','inspect','Automatic image pilot disabled')]:
            cases=[('missing',None,'Private fixed configuration required'),('disabled',{'enabled':False},disabled_msg),('no-enable',{},disabled_msg),('string-enable',{'enabled':'true'},disabled_msg),('bad-json',b'{','Syntax error'),('too-large',b' '*16385,'Configuration byte bound'),('world-readable',{'enabled':False},'Private fixed configuration required')]
            if script=='intake.php':
                cases += [('network-off',{'enabled':True,'network_enabled':False},disabled_msg),('network-missing',{'enabled':True},disabled_msg),('network-string',{'enabled':True,'network_enabled':'true'},disabled_msg)]
            for label,data,msg in cases:
                d,c=fixture(spies)
                if data is not None:save(d/'config.json',data,0o644 if label=='world-readable' else 0o600)
                result,effects=run(d,script,[arg],spies)
                check(result.returncode!=0 and msg in result.stdout+result.stderr,f'{script} {label} refusal (spies={spies})')
                check(not effects,f'{script} {label} no effects')
            for args in ([],[arg,'extra'],['invalid']):
                d,c=fixture(spies)
                result,effects=run(d,script,args,spies)
                msg='Only explicit acquire mode' if script=='intake.php' else 'Explicit supported mode'
                check(result.returncode!=0 and msg in result.stdout+result.stderr,f'{script} argument refusal {args}')
                check(not effects,'argument check before configuration/database')

    for script,arg in [('intake.php','acquire'),('automatic-pilot.php','inspect')]:
        for label,change,msg in [
            ('missing-field',lambda c:c.pop('site'),'Missing fixed configuration'),
            ('bad-job',lambda c:c.update(job='../other'),'Fixed job/input binding'),
            ('wrong-input',lambda c:c.update(input=c['private']+'/other'),'Fixed job/input binding'),
            ('missing-db',lambda c:c.update(database=c['database']+'.missing'),'Existing canonical database'),
            ('db-directory',lambda c:c.update(database=c['private']),'Existing canonical database'),
            ('noncanonical-db',lambda c:c.update(database=str(pathlib.Path(c['database']).parent)+'/./catalog.sqlite'),'Existing canonical database')]:
            d,c=fixture();change(c);save(d/'config.json',c)
            result,effects=run(d,script,[arg])
            check(result.returncode!=0 and msg in result.stdout+result.stderr,script+' '+label)
            check(not effects,'invalid configuration precedes constructors')
        d,c=fixture();save(d/'secret.json',c);(d/'config.json').symlink_to(d/'secret.json')
        result,effects=run(d,script,[arg]);check(result.returncode!=0 and not effects,'symlink configuration refused')
        d,c=fixture();(d/'db-link.sqlite').symlink_to(c['database']);c['database']=str(d/'db-link.sqlite');save(d/'config.json',c)
        result,effects=run(d,script,[arg]);check(result.returncode!=0 and not effects,'symlink database refused')

    for label,request,msg in [('missing',None,'Private fixed intake request'),('wrong-id',{'job':'b'*32},'Intake identity differs'),('too-large',b' '*262145,'Intake request byte bound'),('bad-json',b'{','Syntax error'),('non-object',[],'Intake identity differs'),('world-readable',{'job':'a'*32},'Private fixed intake request')]:
        d,c=fixture();save(d/'config.json',c)
        if request is not None:save(d/'private/intake-request.json',request,0o644 if label=='world-readable' else 0o600)
        result,effects=run(d,'intake.php',['acquire'])
        check(result.returncode!=0 and msg in result.stdout+result.stderr,'request '+label)
        check(not any(e[0] in ('pdo','acquire','network_enabled') for e in effects),'invalid request cannot acquire/network/open DB')

    d,c=fixture();save(d/'config.json',c);request={'job':'a'*32,'synthetic':'request'};save(d/'private/intake-request.json',request)
    original=(d/'catalog.sqlite').read_bytes();result,effects=run(d,'intake.php',['acquire'])
    check(result.returncode==0,'intake spy dispatch')
    check(next(e for e in effects if e[0]=='pdo')[2]=={'1000':1},'intake opens readonly no-create')
    check(['network_enabled',True] in effects and ['intake_enabled',True] in effects,'both explicit enables forwarded')
    check(['acquire',request] in effects,'fixed request forwarded')
    check((d/'catalog.sqlite').read_bytes()==original,'synthetic DB marker unchanged')

    for mode in ('enqueue','run','inspect'):
        d,c=fixture();save(d/'config.json',c);save(d/'private/approved-record.json',{'synthetic':True})
        result,effects=run(d,'automatic-pilot.php',[mode])
        check(result.returncode==0,'automatic spy dispatch '+mode)
        check(next(e for e in effects if e[0]=='pdo')[2]=={'1000':2},'encoder opens readwrite without CREATE')
        check(['pilot',True,True] in effects,'committed intake gate always enabled')
        check(any(e[0]==mode for e in effects),'fixed requested mode dispatched')
        check(not any(e[0] in ('network_enabled','acquire') for e in effects),'encoder never invokes transport')

print(json.dumps({'status':'PASS','checks':checks,'php':str(PHP),'default_off_with_real_modules':True,'positive_dispatch_uses_spies':True,'pdo_curl_runtime_acceptance':False,'provider_calls':False,'entrypoint_sha256':{name:hashlib.sha256((ENTRY_DIR/name).read_bytes()).hexdigest() for name in ('intake.php','automatic-pilot.php')}}))
