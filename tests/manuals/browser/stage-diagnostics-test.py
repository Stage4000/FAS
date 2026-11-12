"""Synthetic log-redaction, dependency receipt and real subprocess failure tests."""
from pathlib import Path
import json
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

known=['tests/manuals/prepare-fixture.py','tests/manuals/range-http-test.py','src/manuals/ManualByteRange.php']
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
print(f'{checks} sanitized-stage/dependency checks passed. No browser launched.')
