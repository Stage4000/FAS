"""Hosted acceptance only. Refuses missing source guard or kernel network isolation."""
from pathlib import Path
import argparse
import hashlib
import ipaddress
import json
import os
import re
import shutil
import signal
import socket
import sqlite3
import subprocess
import sys
sys.dont_write_bytecode = True
import tempfile
import time
import urllib.request
from source_guard import ROOT, MANIFEST, require, verify_sources
from process_session import ProcessSession
from stage_runner import execute_stage, cleanup_job

DISABLED='mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client'


def isolation():
    require(sys.platform=='linux' and os.geteuid()!=0,'Unprivileged Linux runner required')
    parent=os.environ.get('FAS_EXPECTED_PARENT_NETNS','')
    require(re.fullmatch(r'net:\[\d+\]',parent) is not None,'Parent namespace receipt required')
    require(os.readlink('/proc/self/ns/net')!=parent,'A fresh kernel network namespace is required')
    def ip(*args):return json.loads(subprocess.check_output(['ip',*args],text=True))
    links=ip('-j','link','show')
    require(len(links)==1 and links[0]['ifname']=='lo' and 'UP' in links[0]['flags'],'Only active loopback allowed')
    for item in ip('-j','address','show'):
        require(all(ipaddress.ip_address(x['local']).is_loopback for x in item.get('addr_info',[])),'Non-loopback address')
    for family in ['-4','-6']:
        require(all(r.get('dev')=='lo' and r.get('dst')!='default' for r in ip(family,'-j','route','show','table','all')),'Non-loopback route')
    require(not any(re.search(r'TOKEN|SECRET|PASSWORD|CREDENTIAL|AWS_|AZURE_|EBAY|PAYPAL|SMTP|PROXY',k,re.I) for k in os.environ),'Sensitive or proxy environment forbidden')
    return {'fresh_netns':True,'loopback_only':True,'unprivileged':True}


def main():
    ap=argparse.ArgumentParser();ap.add_argument('--output',required=True);ap.add_argument('--fullsite',action='store_true');ap.add_argument('--assets');args=ap.parse_args();require(not args.fullsite or args.assets,'Fullsite cached assets required')
    output=Path(args.output).resolve();output.mkdir(parents=True,exist_ok=True)
    require(not output.is_relative_to(ROOT),'Evidence must be outside the checkout')
    report={'status':'failed','browser':'not_run','cleanup':False,'stages':[]}
    scratch=None;fixture=None;jobs=[]
    pinned_paths=[]
    def stage(label,command,env,timeout=120):
        return execute_stage(label,command,env,timeout,ROOT,report,jobs,pinned_paths)
    def interrupted(signum,frame):raise InterruptedError('Runner received termination signal')
    old_term=signal.signal(signal.SIGTERM,interrupted)
    old_int=signal.signal(signal.SIGINT,interrupted)
    try:
        report['source']=verify_sources();report['isolation']=isolation()
        pinned_paths=list(json.loads(MANIFEST.read_text())['source_blobs'])
        require(Path(sys.executable).resolve()==Path('/usr/bin/python3').resolve(),'Pinned distribution Python is required')
        php='/usr/bin/php8.3';require(Path(php).is_file(),'Pinned distribution PHP 8.3 is required')
        scratch=Path(tempfile.mkdtemp(prefix='fas-manual-browser-run-'))
        for folder in ['home','tmp']:(scratch/folder).mkdir()
        fixture=scratch/'fixture'
        env={k:os.environ[k] for k in ['PATH','NODE_PATH','PLAYWRIGHT_BROWSERS_PATH'] if k in os.environ}
        env.update(HOME=str(scratch/'home'),TMPDIR=str(scratch/'tmp'),PYTHONDONTWRITEBYTECODE='1',
                   FAS_TEST_PHP=php,FAS_MANUAL_BROWSER_NETNS=os.readlink('/proc/self/ns/net'),
                   FAS_EXPECTED_PARENT_NETNS=os.environ['FAS_EXPECTED_PARENT_NETNS'])
        # Import/module receipts come from these exact executables inside isolation.
        stage('dependency-preflight',[sys.executable,str(Path(__file__).with_name('dependency_check.py')),php],env,30)
        report['runtime']=report['stages'][-1]['diagnostics']['runtime']
        require(report['runtime']['reportlab_distribution_package'],'Expected apt-owned ReportLab package required')
        report['php_version']=report['runtime']['php_version']
        # These execute after kernel isolation, never as an unisolated workflow pre-step.
        stage('lazy-text',['node','tests/manuals/reader-lazy-text-test.cjs'],env,30)
        stage('lazy-text-race',['node','tests/manuals/reader-lazy-text-race-test.cjs'],env,30)
        stage('swipe-ownership',['node','tests/manuals/swipe-ownership-test.cjs'],env,30)
        stage('pending-load',['node','tests/manuals/reader-pending-load-test.cjs'],env,30)
        stage('text-dedup',['node','tests/manuals/reader-text-dedup-test.cjs'],env,30)
        stage('search-yield',['node','tests/manuals/reader-search-yield-test.cjs'],env,30)
        stage('intake-inventory',[sys.executable,'tests/manuals/intake-inventory-test.py'],env,30)
        stage('intake-capacity',[sys.executable,'tests/manuals/intake-capacity-test.py'],env,30)
        stage('intake-manifest',[php,'tests/manuals/intake-manifest-test.php'],env,30)
        stage('page-resources',['node','tests/manuals/reader-page-resources-test.cjs'],env,30)
        stage('outline-model',['node','tests/manuals/reader-outline-test.cjs'],env,30)
        stage('outline-navigation',['node','tests/manuals/reader-outline-integration.cjs'],env,30)
        stage('load-control',['node','tests/manuals/reader-load-control-test.cjs'],env,30)
        stage('search-focus',['node','tests/manuals/reader-search-focus-test.cjs'],env,30)
        stage('upload-preflight',['node','tests/manuals/editor-upload-preflight-test.cjs'],env,30)
        stage('admin-search',[php,'tests/manuals/admin-search-test.php'],env,30)
        stage('admin-search-http',[sys.executable,'tests/manuals/admin-search-http-test.py'],env,90)
        stage('canvas-lifecycle',['node','tests/manuals/reader-canvas-lifecycle-test.cjs'],env,30)
        stage('reader-lifecycle',['node','tests/manuals/reader-lifecycle-test.cjs'],env,30)
        stage('reader-text-cache',['node','tests/manuals/reader-cache-test.cjs'],env,30)
        stage('reader-input-race',['node','tests/manuals/reader-input-race-test.cjs'],env,30)
        stage('fixture-config-reload',[sys.executable,'tests/manuals/config-reload-test.py'],env,120)
        stage('facet-bounds',[php,'tests/manuals/facet-bounds-test.php'],env,30)
        stage('feature-contrast',[sys.executable,'tests/manuals/contrast-source-test.py'],env,30)
        stage('hash-bound',[php,'tests/manuals/hash-bound-test.php'],env,30)
        stage('range-backend-http',[sys.executable,'tests/manuals/run-range-tests.py'],env,180)
        stage('upload-storage',[sys.executable,'tests/manuals/run-upload-storage-tests.py'],env,240)
        stage('strict-open-basedir',[sys.executable,'tests/manuals/open-basedir-test.py'],env,180)
        stage('fixture-build',[sys.executable,str(Path(__file__).with_name('prepare-fullsite.py' if args.fullsite else 'prepare.py')),str(fixture)],env)
        receipt=json.loads((fixture/'browser-fixture.json').read_text())
        (output/'fixture-receipt.json').write_text(json.dumps(receipt,indent=2))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        env.update(FAS_SECURITY_DB_PATH=str(fixture/'security.sqlite'))
        command=[php,'-d','allow_url_fopen=0','-d','disable_functions='+DISABLED,'-d','open_basedir='+str(fixture),
                 '-d','session.save_path='+str(fixture/'sessions'),'-d','upload_tmp_dir='+str(fixture/'uploads'),
                 '-d','display_errors=1','-d','html_errors=0','-d','error_reporting=-1',
                 '-d','log_errors=1','-d','error_log='+str(fixture/'php-errors.log'),
                 '-S',f'127.0.0.1:{port}','-t',str(fixture/'site'),str(fixture/'router.php')]
        with (scratch/'server.log').open('w') as log:
            server=ProcessSession(command,env=env,stdout=log,stderr=log);jobs.append(('fixture-server',server))
            ready=False
            for _ in range(100):
                require(server.process.poll() is None,'Synthetic PHP server exited')
                try:
                    with urllib.request.urlopen(base+'/manuals/',timeout=.3) as response:ready=response.status==200
                except (OSError,TimeoutError):pass
                if ready:break
                time.sleep(.05)
            require(ready,'Synthetic PHP server did not start')
            stage('strict-fixture-http',[sys.executable,str(Path(__file__).with_name('fixture-check.py')),str(fixture),base,php],env,45)
            report['fixture_http']='passed'
            errors=fixture/'php-errors.log'
            require(not errors.exists() or not errors.read_text().strip(),'PHP error log is nonempty before browser; fix the runtime rather than suppress warnings')
            report['browser']='running'
            stage('browser',['node',str(Path(__file__).with_name('fullsite-check.cjs' if args.fullsite else 'check.cjs')),base,str(fixture),str(output),php]+([str(Path(args.assets).resolve())] if args.fullsite else []),env,240)
            summary=json.loads((output/'browser-summary.json').read_text())
            require(summary['passed']==summary['total'] and not summary['unexpected'],'Incomplete/failed browser case inventory')
            report['browser']='passed'
        for rel,expected in receipt['source_copies_sha256'].items():
            require(hashlib.sha256((fixture/'site'/rel).read_bytes()).hexdigest()==expected,'Fixture runtime changed: '+rel)
        for rel,expected in receipt['generated_sha256'].items():
            require(hashlib.sha256((fixture/rel).read_bytes()).hexdigest()==expected,'Generated fixture source/config changed: '+rel)
        verify_sources()
        with sqlite3.connect(fixture/'database.sqlite') as db:
            require(db.execute('PRAGMA integrity_check').fetchone()[0]=='ok','Fixture database integrity failure')
            require(not db.execute('PRAGMA foreign_key_check').fetchall(),'Fixture foreign-key failure')
        require(not errors.exists() or not errors.read_text().strip(),'Unexpected PHP error log')
        report['status']='passed'
    except Exception as error:
        report['failure']=str(error)
    finally:
        # Do not remove a fixture while an owned server/browser/helper still uses it.
        signal.signal(signal.SIGTERM,signal.SIG_IGN)
        signal.signal(signal.SIGINT,signal.SIG_IGN)
        process_cleanup=[cleanup_job(label,job) for label,job in reversed(jobs)]
        clean=all(item['all_reaped'] for item in process_cleanup)
        report['process_cleanup']=process_cleanup;report['process_cleanup_complete']=clean
        (output/'process-cleanup.json').write_text(json.dumps({'verified_before_fixture_removal':True,'all_reaped':clean,'processes':process_cleanup},indent=2))
        if clean and scratch is not None and scratch.exists():shutil.rmtree(scratch)
        report['cleanup']=clean and (scratch is None or not scratch.exists())
        if not report['cleanup']:report['status']='failed';report['cleanup_failure']='Owned processes remain or fixture could not be removed'
        (output/'report.json').write_text(json.dumps(report,indent=2))
        signal.signal(signal.SIGTERM,old_term)
        signal.signal(signal.SIGINT,old_int)
    print(json.dumps(report,indent=2))
    return 0 if report['status']=='passed' and report['cleanup'] else 1


if __name__=='__main__':sys.exit(main())
