"""Synthetic log-redaction, dependency receipt and real subprocess failure tests."""
from pathlib import Path
import json
import ast
import contextlib
import hashlib
import io
import os
import subprocess
import sys
sys.dont_write_bytecode=True
import tempfile
from unittest import mock
from stage_diagnostics import summarize,MAX_INPUT_BYTES
from dependency_check import probe
from stage_runner import execute_stage

checks=0

def check(value,label):
    global checks
    assert value,label;checks+=1;print('PASS '+label)

known=['tests/manuals/http-test.py','tests/manuals/open-basedir-test.py','tests/manuals/prepare-fixture.py','tests/manuals/range-http-test.py','src/manuals/ManualByteRange.php']
secret='SYNTHETIC-DO-NOT-RETAIN-SECRET'
raw='''Traceback (most recent call last):
  File "/private/account/tests/manuals/prepare-fixture.py", line 4, in <module>
    from reportlab.pdfgen import canvas
ModuleNotFoundError: No module named 'reportlab'
AssertionError: ('body', '<html><input name="csrf_token" value="SECRET"></html>', {'Set-Cookie':'PHPSESSID=SECRET', 'Authorization':'Bearer SECRET'}, 'password=SECRET')
password_hash=$2y$SECRET
'''.replace('SECRET',secret)
result=summarize('PASS synthetic check\n',raw,known,exit_code=1)
encoded=json.dumps(result)
check('reportlab_module_missing' in result['events'],'Missing ReportLab classified without raw exception text')
check(result['source_frames']==[{'source':'tests/manuals/prepare-fixture.py','line':4}],'Pinned relative traceback frame retained')
check(secret not in encoded,'Quoted tokens/passwords/header values absent')
check(all(s not in encoded for s in ['<html>','csrf_token','PHPSESSID','Set-Cookie','Authorization','password_hash','/private/account']),'HTML, headers, fields and absolute paths omitted')
check(result['exit_code']==1 and result['pass_lines_in_retained_tail']==1,'Exit status and successful check count retained')
result=summarize('',f'AssertionError: ("{secret}", b"{secret}")\nFile "/unreviewed/{secret}", line 9\n',known)
check(result['source_frames']==[] and secret not in json.dumps(result),'Unreviewed source paths and assertion payloads omitted')
result=summarize('',f'PHP Fatal error: Uncaught PDOException: could not find driver in /secret/src/manuals/ManualByteRange.php on line 99\n{secret}',known)
check('pdo_driver_missing' in result['events'] and 'php_diagnostic' in result['events'],'PHP dependency error classified')
check(result['source_frames']==[{'source':'src/manuals/ManualByteRange.php','line':99}],'PHP source frame normalized')
result=summarize('', '#0 /private/src/manuals/ManualByteRange.php(123): opaque("'+secret+'")',known)
check(result['source_frames']==[{'source':'src/manuals/ManualByteRange.php','line':123}] and secret not in json.dumps(result),'PHP call-site frame retained without arguments')
result=summarize(secret+'X'*(MAX_INPUT_BYTES*2),'\x00\x1b[31m'+secret+'\xff',known,timed_out=True,exit_code=-15)
check(result['input_tail_truncated'] and result['timed_out'],'Large and timeout input is bounded and marked')
check(len(json.dumps(result).encode())<8192 and secret not in json.dumps(result),'Diagnostic output is bounded without raw binary/control content')
result=summarize('', 'File "/x/tests/manuals/prepare-fixture.py", line '+('9'*10000),known)
check(result['source_frames']==[],'Oversized fake line numbers cannot break sanitization')
spoof={'python_executable':secret,'python_version':secret,'php_executable':secret,'php_version':secret,'extensions':{'pdo_sqlite':secret,'unknown':secret},'errors':[secret],'extra':secret}
result=summarize('FAS_RUNTIME_RECEIPT '+json.dumps(spoof),'',known)
check(secret not in json.dumps(result) and result['runtime']['python_executable']=='other','Runtime receipt schema drops unknown or unsafe values')
result=summarize('FAS_RUNTIME_RECEIPT {broken','',known)
check('malformed_runtime_receipt' in result['events'],'Malformed receipt becomes fixed diagnostic category')
normal={'php_version':'8.3.6','sapi':'cli-server','case':'forward','kind':'single-last','observed_status':206,'expected_status':206,'response_bytes':2,'status_matches':True,'length_matches':True,'range_matches':True,'body_matches':True,'safety_headers_match':True}
result=summarize('FAS_RANGE_NORMALIZATION_RECEIPT '+json.dumps(normal),'',known)
check(result['range_normalization']==[normal],'Exact fixed-enum same-request normalization receipt retained')
normal.update(observed_status=500,status_matches=False,body_matches=False,raw_header=secret,body=secret,cookie=secret)
result=summarize('FAS_RANGE_NORMALIZATION_RECEIPT '+json.dumps(normal),'',known)
check(result['range_normalization'][0]['observed_status']==500 and not result['range_normalization'][0]['body_matches'],'Unexpected server status stays visible as failed verification')
check(secret not in json.dumps(result),'Normalization receipt drops raw header/body/cookie fields')
normal.update(php_version=secret,sapi=secret,kind=secret,response_bytes=secret)
result=summarize('FAS_RANGE_NORMALIZATION_RECEIPT '+json.dumps(normal),'',known)
check(secret not in json.dumps(result) and result['range_normalization'][0]['kind']=='unrecognized','Normalization unknown values cannot leak arbitrary strings')
result=summarize('\n'.join('FAS_RANGE_NORMALIZATION_RECEIPT '+json.dumps(normal) for _ in range(30)),'',known)
check(len(result['range_normalization'])==4 and len(json.dumps(result))<8192,'Normalization receipt count is bounded')
result=summarize('FAS_RANGE_NORMALIZATION_RECEIPT '+json.dumps({'case':secret}),'',known)
check('malformed_normalization_receipt' in result['events'] and secret not in json.dumps(result),'Unknown normalization case fails closed without raw output')
php={'php_version':'8.3.12','extensions':{n:True for n in ['pdo_sqlite','sqlite3','fileinfo','mbstring','dom','json','session']},'sqlite_json':True}
with mock.patch('dependency_check.subprocess.run',return_value=subprocess.CompletedProcess([],0,json.dumps(php),'')):
    with mock.patch.dict(sys.modules,{'reportlab':None}):
        result=probe('/usr/bin/php8.3')
check(result['errors']==['reportlab_missing'] and result['extensions']['pdo_sqlite'],'Preflight preserves PHP receipt when ReportLab is missing')
php['extensions']['pdo_sqlite']=False;php['sqlite_json']=False
with mock.patch('dependency_check.subprocess.run',return_value=subprocess.CompletedProcess([],0,json.dumps(php),'')):
    result=probe('/usr/bin/php8.3')
check('php_extension_missing' in result['errors'] and 'sqlite_json_missing' in result['errors'],'Missing PHP module/SQLite JSON reported separately')
with tempfile.TemporaryDirectory(prefix='fas-stage-diagnostic-') as temp:
    root=Path(temp);env={'PATH':os.environ['PATH']};report={'stages':[]};jobs=[]
    command=[sys.executable,'-c',f'import sys;print("PASS synthetic");sys.stderr.write({raw!r});sys.exit(7)']
    try:execute_stage('synthetic-failure',command,env,5,root,report,jobs,known);raise AssertionError('Expected failure')
    except RuntimeError as error:check(str(error)=='Isolated stage failed: synthetic-failure','Failure raises fixed safe stage label')
    item=report['stages'][0]
    check(item['status']=='failed' and item['diagnostics']['exit_code']==7,'Actual failing subprocess retains exit diagnostics')
    check('reportlab_module_missing' in item['diagnostics']['events'] and item['process_cleanup']['all_reaped'],'Actual failure evidence survives reaping')
    check(secret not in json.dumps(report),'Actual failure artifact contains no raw secrets')
    report={'stages':[]};jobs=[]
    command=[sys.executable,'-c','import sys,time;sys.stderr.write("ModuleNotFoundError: No module named \'reportlab\'\\n");sys.stderr.flush();time.sleep(30)']
    try:execute_stage('synthetic-timeout',command,env,.15,root,report,jobs,known);raise AssertionError('Expected timeout')
    except RuntimeError:pass
    item=report['stages'][0]
    check(item['status']=='timed_out' and item['diagnostics']['timed_out'],'Actual timeout retains partial diagnostics')
    check(item['process_cleanup']['all_reaped'] and 'reportlab_module_missing' in item['diagnostics']['events'],'Timeout process is reaped before sanitized evidence retained')
# Execute the actual nested collector from open-basedir-test.py, not a copied
# imitation. Only its first synthetic HTTP child runs; no PHP/server/browser.
with tempfile.TemporaryDirectory(prefix='fas-nested-diagnostic-') as temp:
    root=Path(temp);child=root/'tests/manuals/http-test.py';child.parent.mkdir(parents=True)
    payload='<html><input name="csrf_token" value="'+secret+'"></html> Set-Cookie: PHPSESSID='+secret+' Authorization: Bearer '+secret+' password="'+secret+'" database=/private/'+secret+'.sqlite'
    child.write_text('print("PASS synthetic nested HTTP")\nraise AssertionError('+repr(payload)+')\n')
    source=Path(__file__).resolve().parents[1]/'open-basedir-test.py'
    outer="""import ast,os,subprocess,sys
from pathlib import Path
ROOT=Path.cwd();OUT=ROOT/'private-results';OUT.mkdir();ENV=dict(os.environ);base='http://127.0.0.1:1'
def check(condition,label):
    assert condition,label
source=Path(SOURCE_PATH)
tree=ast.parse(source.read_text())
loops=[n for n in ast.walk(tree) if isinstance(n,ast.For) and isinstance(n.target,ast.Name) and n.target.id=='name' and isinstance(n.iter,ast.List) and [x.value for x in n.iter.elts]==['http-test.py','range-http-test.py']]
assert len(loops)==1
exec(compile(ast.Module(body=loops,type_ignores=[]),str(source),'exec'))
""".replace('SOURCE_PATH',repr(str(source)))
    report={'stages':[]};jobs=[]
    try:execute_stage('synthetic-nested-http',[sys.executable,'-c',outer],{'PATH':os.environ['PATH']},5,root,report,jobs,known);raise AssertionError('Expected nested failure')
    except RuntimeError as error:check(str(error)=='Isolated stage failed: synthetic-nested-http','Nested HTTP failure remains a failed stage with a fixed label')
    item=report['stages'][0];diagnostics=item['diagnostics'];encoded=json.dumps(report)
    check({'source':'tests/manuals/http-test.py','line':2} in diagnostics['source_frames'],'Actual inner HTTP assertion frame survives the nested collector and sanitizer')
    check(any(frame['source']=='tests/manuals/open-basedir-test.py' for frame in diagnostics['source_frames']) and diagnostics['pass_lines_in_retained_tail']==1,'Outer assertion and nested progress remain attributable')
    check(secret not in encoded and all(value not in encoded for value in ['<html>','csrf_token','Set-Cookie','PHPSESSID','Authorization','password=','database=','/private/']),'Nested assertion HTML, cookie, token, password and database payloads never enter report')
    check(item['status']=='failed' and diagnostics['exit_code']==1 and item['process_cleanup']['all_reaped'] and len(encoded.encode())<8192,'Nested subprocess failure is reaped and bounded without being softened')
release={'case':'string_release','route':'catalog','php_version':'8.3.6','sapi':'cli-server','loaded_flag':'string_true','http_status':404,'response_bytes':15,'body_kind':'catalog_not_found','observation_present':True,'python_disk_matches_expected':True,'php_disk_matches_expected':True,'opcache_loaded':True,'php_diagnostic_body':False,'opcache_enable':True,'opcache_enable_cli':True,'opcache_validate_timestamps':True,'opcache_revalidate_path':False,'opcache_revalidate_freq':2,'opcache_file_update_protection':2}
result=summarize('FAS_RELEASE_CONFIG_RECEIPT '+json.dumps(release),'',known)
check(result['release_config']==[release],'Closed-schema exact config/HTTP receipt survives sanitization')
release.update(loaded_flag='boolean_true',http_status=200,config=secret,disk_sha256=secret,body=secret,headers={'Set-Cookie':secret})
result=summarize('FAS_RELEASE_CONFIG_RECEIPT '+json.dumps(release),'',known)
check(result['release_config'][0]['loaded_flag']=='boolean_true' and result['release_config'][0]['http_status']==200,'Unexpected loaded flag and status remain visible without implying success')
check(secret not in json.dumps(result) and 'disk_sha256' not in json.dumps(result),'Config values, hashes, headers and bodies are omitted')
release.update(loaded_flag=secret,php_version=secret,opcache_enable=secret,opcache_revalidate_freq=secret)
result=summarize('FAS_RELEASE_CONFIG_RECEIPT '+json.dumps(release),'',known)
check(secret not in json.dumps(result) and result['release_config'][0]['loaded_flag']=='unobserved' and result['release_config'][0]['opcache_enable'] is None,'Unknown config/OPcache receipt values cannot leak data or fake observations')
result=summarize('\n'.join('FAS_RELEASE_CONFIG_RECEIPT '+json.dumps(release) for _ in range(20)),'',known)
check(len(result['release_config'])==3 and len(json.dumps(result))<8192,'Config receipt count and artifact size remain bounded')
result=summarize('FAS_RELEASE_CONFIG_RECEIPT '+json.dumps({'case':secret,'route':secret}),'',known)
check('malformed_release_config_receipt' in result['events'] and secret not in json.dumps(result),'Unknown config case/route fails closed without raw content')

# Exercise the actual HTTP observation helper and mutation assertion with only
# synthetic files/responses. This is not a PHP or browser integration substitute.
with tempfile.TemporaryDirectory(prefix='fas-config-diagnostic-') as temp:
    root=Path(temp);config=root/'config.php';original="<?php return ['public_enabled'=>true];";expected=original.replace("'public_enabled'=>true","'public_enabled'=>'true'");config.write_text(expected)
    source=Path(__file__).resolve().parents[1]/'http-test.py';tree=ast.parse(source.read_text());helpers=[n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='release_probe'];assert len(helpers)==1
    namespace={'OUT':root,'config':config,'json':json,'hashlib':hashlib,'anon':object()}
    exec(compile(ast.Module(body=helpers,type_ignores=[]),str(source),'exec'),namespace)
    def response_with(flag,status,body):
        def request(path,who):
            assert not (root/'config-observation.json').exists(),'Stale marker must be removed before request'
            if flag is not None:(root/'config-observation.json').write_text(json.dumps({'php_version':'8.3.6','sapi':'cli-server','loaded_flag':flag,'disk_sha256':hashlib.sha256(config.read_bytes()).hexdigest(),'opcache_loaded':True}))
            return status,{'Set-Cookie':secret},body
        return request
    for flag,status,body in [('string_true',404,b'Page not found.'),('boolean_true',200,secret.encode()),(None,500,('Warning: '+secret).encode())]:
        (root/'config-observation.json').write_text('stale '+secret);namespace['req']=response_with(flag,status,body);stream=io.StringIO()
        with contextlib.redirect_stdout(stream):response,note=namespace['release_probe']('/manuals/',expected)
        result=summarize(stream.getvalue(),'',known);safe=result['release_config'][0]
        check(safe['http_status']==status and safe['loaded_flag']==(flag or 'unobserved') and safe['python_disk_matches_expected'],'Actual helper retains exact status, loaded flag and mutation evidence')
        check(secret not in json.dumps(result) and safe['observation_present']==(flag is not None),'Actual helper rejects stale markers and omits raw response/config data')
    mutation=[n for n in tree.body if isinstance(n,ast.Try) and 'String release flag fails closed' in ast.get_source_segment(source.read_text(),n)];assert len(mutation)==1
    def must(condition,label):assert condition,label
    class MockReload:
        def apply(self,value,case):config.write_text(value)
        def before_request(self):(root/'config-observation.json').unlink(missing_ok=True)
        def observe(self,*args):pass
    namespace.update(original=original,reload=MockReload(),req=response_with('boolean_true',200,secret.encode()),check=must);stream=io.StringIO();failed=False
    try:
        with contextlib.redirect_stdout(stream):exec(compile(ast.Module(body=mutation,type_ignores=[]),str(source),'exec'),namespace)
    except AssertionError as error:failed=str(error).startswith('String release flag fails closed')
    safe=summarize(stream.getvalue(),'',known)
    check(failed and safe['release_config'][0]['http_status']==200,'Unexpected HTTP200 remains a failed unchanged404 assertion after safe receipt')
    check(config.read_text()==original and secret not in json.dumps(safe),'Mutation failure restores original fixture config without leaking response data')
reload_note={'case':'storage_alias','route':'file','method':'forced_invalidation','http_status':503,'disk_matches':True,'flag_matches':True,'storage_matches':True,'same_server_process':True,'token':secret,'path':secret,'hash':secret}
result=summarize('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps(reload_note),'',known)
check(result['fixture_config_reload']['all_verified'] and result['fixture_config_reload']['methods']==['forced_invalidation'],'Verified same-process reload retains bounded per-case proof')
check(secret not in json.dumps(result),'Reload token, target and fingerprint omitted')
reload_note.update(storage_matches=False,http_status=200)
result=summarize('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps(reload_note),'',known)
check(not result['fixture_config_reload']['all_verified'] and result['fixture_config_reload']['first_failure']['http_status']==200,'Wrong loaded storage stays a failed observation despite HTTP200')
result=summarize('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps({'case':'string_release','route':'reload','method':'unavailable','http_status':503}),'',known)
check(result['fixture_config_reload']['first_failure']['http_status']==503 and not result['fixture_config_reload']['all_verified'],'Rejected invalidation preserves safe exact status')
result=summarize('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps({'case':secret,'route':secret}),'',known)
check('malformed_config_reload_receipt' in result['events'] and secret not in json.dumps(result),'Unknown reload case/route cannot retain arbitrary text')
result=summarize('\n'.join('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps(reload_note) for _ in range(200)),'',known)
check(result['fixture_config_reload']['observations']==64 and len(json.dumps(result))<8192,'Reload evidence counts and failed-observation detail remain bounded')
# All receipt families coexist in the strict stage; preserve the original cap.
lines=['FAS_RUNTIME_RECEIPT '+json.dumps({'python_version':'3.12.3','php_version':'8.3.6','extensions':php['extensions']})]
lines += ['FAS_RANGE_NORMALIZATION_RECEIPT '+json.dumps({**normal,'php_version':'8.3.6','sapi':'cli-server','kind':'single-first','response_bytes':2})]*4
lines += ['FAS_RELEASE_CONFIG_RECEIPT '+json.dumps({**release,'php_version':'8.3.6','loaded_flag':'string_true','opcache_enable':True,'opcache_revalidate_freq':2})]*3
for case in ['string_release','string_restore','boolean_release','boolean_restore','storage_outside','storage_outside_link','storage_direct_link','storage_alias','storage_restore']:
    lines.append('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps({**reload_note,'case':case}))
lines += ['File "/private/'+known[0]+'", line '+str(i) for i in range(1,13)]
result=summarize('\n'.join(lines),'AssertionError: opaque',known)
check(len(result['range_normalization'])==4 and len(result['release_config'])==3 and len(result['fixture_config_reload']['cases'])==9 and len(result['source_frames'])==12 and len(json.dumps(result).encode())<=8192 and secret not in json.dumps(result),'Runtime, release, range, reload and traceback receipts coexist within the unchanged 8 KiB secret-free budget')
print(f'{checks} sanitized-stage/dependency checks passed. No browser launched.')
