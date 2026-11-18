"""Actual PHP worker pool contention and termination/recovery, not mocked flock."""
import os, pathlib, selectors, shutil, subprocess, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
assert PHP, 'PHP CLI is required'
n = 0

def check(value):
    global n
    assert value
    n += 1

def line(process):
    with selectors.DefaultSelector() as selector:
        selector.register(process.stdout, selectors.EVENT_READ)
        assert selector.select(10), 'worker did not reach barrier'
    return process.stdout.readline().decode().strip()

worker = r'''<?php
require $argv[1];
use FAS\Showcase\ShowcaseConcurrency as C;
$c=new C([$argv[2].'/a',$argv[2].'/b'],[$argv[2].'/public'],static function(){echo "gate\n";fflush(STDOUT);return true;});
try {$c->execute(static function(){echo "entered\n";fflush(STDOUT);fgets(STDIN);echo "finished\n";fflush(STDOUT);});}
catch(OverflowException $e){echo "busy\n";fflush(STDOUT);}
'''
processes=[]
with tempfile.TemporaryDirectory(prefix='fas-pool-process-') as directory:
    d=pathlib.Path(directory);(d/'public').mkdir()
    for name in ['a','b']:(d/name).write_bytes(b'FAS-SHOWCASE-SLOT-v1\n')
    script=d/'worker.php';script.write_text(worker)
    def start():
        p=subprocess.Popen([PHP,str(script),str(ROOT/'src/showcase/ShowcaseConcurrency.php'),str(d)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,bufsize=0)
        processes.append(p);return p
    def entered(p):
        check(line(p)=='gate');check(line(p)=='entered')
    def busy():
        p=start();check(line(p)=='busy');out,err=p.communicate(timeout=10);check(p.returncode==0);check(out==b'');check(err==b'')
    def release(p):
        p.stdin.write(b'release\n');p.stdin.flush();check(line(p)=='finished')
        out,err=p.communicate(timeout=10);check(p.returncode==0);check(out==b'');check(err==b'')
    try:
        first=start();entered(first)
        second=start();entered(second)
        busy();busy() # No rate gate or operation output when pool is full.
        first.terminate();first.wait(timeout=10);check(first.returncode<0)
        replacement=start();entered(replacement) # Kernel releases killed worker's lease.
        busy() # Surviving worker still holds its own slot.
        release(second)
        replacement2=start();entered(replacement2)
        busy()
        release(replacement);release(replacement2)
        final=start();entered(final);release(final)
        for name in ['a','b']:check((d/name).read_bytes()==b'FAS-SHOWCASE-SLOT-v1\n')
    finally:
        for p in processes:
            if p.poll() is None:p.kill()
            p.communicate(timeout=10)
print(f'Concurrency process recovery: {n} assertions passed.')
