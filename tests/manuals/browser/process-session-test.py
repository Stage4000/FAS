"""Offline process lifecycle regressions. Python sleep children only, no browser."""
from pathlib import Path
import json
import os
import signal
import subprocess
import sys
sys.dont_write_bytecode=True
import tempfile
import time
from process_session import ProcessSession,snapshot

checks=0

def check(value,label):
    global checks
    assert value,label;checks+=1;print('PASS '+label)


def start(code,*args):
    return ProcessSession([sys.executable,'-c',code,*map(str,args)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)


def wait_file(path):
    deadline=time.monotonic()+3
    while not path.exists() and time.monotonic()<deadline:time.sleep(.02)
    assert path.exists(),'Child readiness receipt missing'
    return json.loads(path.read_text())

with tempfile.TemporaryDirectory(prefix='fas-process-test-') as temp:
    root=Path(temp)
    sentinel=start('import time;time.sleep(30)')
    try:
        simple=start('print("done")');out,_=simple.communicate(timeout=2)
        check(out.strip()=='done','Normal result retained')
        receipt=simple.cleanup();check(receipt['all_reaped'],'Normal child reaped')
        check(simple.cleanup()==receipt,'Cleanup is idempotent')
        # Parent times out while a detached TERM-ignoring child and its child run.
        ready=root/'timeout.json'
        grandchild='import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(30)'
        child="import subprocess,sys,signal,time,json,pathlib,os;signal.signal(signal.SIGTERM,signal.SIG_IGN);p=subprocess.Popen([sys.executable,'-c',sys.argv[2]],start_new_session=True);pathlib.Path(sys.argv[1]).write_text(json.dumps([os.getpid(),p.pid]));time.sleep(30)"
        parent="import subprocess,sys,time;p=subprocess.Popen([sys.executable,'-c',sys.argv[2],sys.argv[1],sys.argv[3]],start_new_session=True);time.sleep(30)"
        job=start(parent,ready,child,grandchild);pids=wait_file(ready)
        try:job.communicate(timeout=.1);raise AssertionError('Timeout required')
        except subprocess.TimeoutExpired:check(True,'Parent timeout observed')
        receipt=job.cleanup(grace=.15)
        check(receipt['all_reaped'] and receipt['owned_processes']>=3,'Detached descendants terminated and reaped')
        check('SIGKILL' in receipt['signals'],'TERM-ignoring children force bounded KILL')
        check(all(pid not in snapshot() for pid in pids),'No sleeping descendants survive timeout')
        check(sentinel.process.poll() is None,'Unrelated earlier child is untouched')
        # A leader exits successfully and leaves a detached child, with no pipe held.
        ready=root/'normal.json'
        parent="import subprocess,sys,json,pathlib;p=subprocess.Popen([sys.executable,'-c','import time;time.sleep(30)'],start_new_session=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);pathlib.Path(sys.argv[1]).write_text(json.dumps([p.pid]));print('leader done')"
        job=start(parent,ready);job.communicate(timeout=2);pids=wait_file(ready)
        receipt=job.cleanup();check(receipt['all_reaped'] and receipt['owned_processes']==2,'Orphan after normal leader exit is reaped')
        check(all(pid not in snapshot() for pid in pids),'No detached child survives normal completion')
        # Caller failure executes cleanup before fixture removal.
        directory=root/'owned-fixture';directory.mkdir()
        job=start('import time;time.sleep(30)')
        try:raise RuntimeError('Synthetic caller failure')
        except RuntimeError:pass
        finally:
            receipt=job.cleanup();check(receipt['all_reaped'] and directory.exists(),'Exception cleanup happens before fixture removal')
            directory.rmdir()
        check(not directory.exists(),'Fixture removed only after process cleanup')
        for sig in [signal.SIGTERM,signal.SIGINT]:
            ready=root/('signal-'+str(sig)+'.json')
            finished=root/('signal-'+str(sig)+'-cleanup.json')
            wrapper="""import sys,signal,time,json,pathlib
sys.dont_write_bytecode=True
sys.path.insert(0,sys.argv[1])
from process_session import ProcessSession
ready=pathlib.Path(sys.argv[2]);finished=pathlib.Path(sys.argv[3])
def stop(signum,frame):raise InterruptedError('synthetic runner signal')
signal.signal(signal.SIGTERM,stop);signal.signal(signal.SIGINT,stop)
job=ProcessSession([sys.executable,'-c','import time;time.sleep(30)'])
ready.write_text(json.dumps([job.process.pid]))
try:job.communicate(timeout=30)
except InterruptedError:pass
finally:finished.write_text(json.dumps(job.cleanup()))
"""
            wrapper_job=start(wrapper,Path(__file__).resolve().parent,ready,finished)
            children=wait_file(ready);wrapper_job.process.send_signal(sig)
            wrapper_job.communicate(timeout=5)
            result=json.loads(finished.read_text())
            check(result['all_reaped'] and all(pid not in snapshot() for pid in children),'Signal follows final cleanup: '+sig.name)
            check(wrapper_job.cleanup()['all_reaped'],'Signal wrapper itself reaped: '+sig.name)
    finally:
        check(sentinel.cleanup()['all_reaped'],'Unrelated test sentinel cleaned by its owner')
print(f'{checks} process lifecycle checks passed. No browser launched.')
