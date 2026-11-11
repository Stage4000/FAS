"""Pure offline adversarial fixtures. No server/browser or network namespace started."""
import importlib.util, os, subprocess, tempfile
from pathlib import Path
spec=importlib.util.spec_from_file_location('guard',Path(__file__).with_name('guard.py'))
g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)
def git(*args):return subprocess.check_output(['git',*args],cwd=g.ROOT,text=True,stderr=subprocess.DEVNULL).strip()
def reject(label):
    try:g.verify_candidate()
    except RuntimeError:return print('PASS reject '+label)
    raise AssertionError('Accepted '+label)
with tempfile.TemporaryDirectory(prefix='zoom-guard-') as d:
    g.ROOT=Path(d)
    git('init','-q');git('config','user.email','fixture@example.invalid');git('config','user.name','Synthetic fixture')
    (g.ROOT/'runtime.css').write_text('before\n');(g.ROOT/'unchanged.txt').write_text('keep\n')
    git('add','.');git('commit','-qm','baseline')
    g.BASELINE=git('rev-parse','HEAD');g.BASELINE_TREE=git('rev-parse','HEAD^{tree}')
    before=git('rev-parse','HEAD:runtime.css')
    (g.ROOT/'runtime.css').write_text('after\n');(g.ROOT/'test.txt').write_text('test\n')
    git('add','.');git('commit','-qm','candidate');head=git('rev-parse','HEAD')
    g.ALLOWED_CHANGES={'runtime.css':'M','test.txt':'A'}
    g.EXPECTED_RUNTIME={'runtime.css':{'before':before,'after':git('rev-parse','HEAD:runtime.css')}}
    g.EXPECTED_STORAGE_BLOBS={'test.txt':git('rev-parse','HEAD:test.txt')}
    assert g.verify_candidate()['materialized_tracked_blobs_verified']==3;print('PASS exact candidate')
    (g.ROOT/'extra').write_text('x');reject('untracked file');(g.ROOT/'extra').unlink()
    (g.ROOT/'unchanged.txt').write_text('tamper');reject('tracked mutation');git('checkout','--','unchanged.txt')
    git('update-index','--assume-unchanged','unchanged.txt');(g.ROOT/'unchanged.txt').write_text('hidden');reject('assume-unchanged hidden bytes');git('update-index','--no-assume-unchanged','unchanged.txt');git('checkout','--','unchanged.txt')
    git('update-index','--skip-worktree','unchanged.txt');(g.ROOT/'unchanged.txt').write_text('hidden');reject('skip-worktree materialized tamper');git('update-index','--no-skip-worktree','unchanged.txt');git('checkout','--','unchanged.txt')
    (g.ROOT/'unchanged.txt').unlink();(g.ROOT/'unchanged.txt').symlink_to('test.txt');reject('symlink source');git('checkout','--','unchanged.txt')
    os.chmod(g.ROOT/'unchanged.txt',0o755);reject('mode change');os.chmod(g.ROOT/'unchanged.txt',0o644)
    (g.ROOT/'runtime.css').write_text('unreviewed\n');git('add','.');git('commit','-qm','unexpected runtime');reject('unreviewed runtime blob');git('reset','--hard',head)
    (g.ROOT/'extra').write_text('extra');git('add','.');git('commit','-qm','unexpected path');reject('unreviewed committed path');git('reset','--hard',head)
    (g.ROOT/'test.txt').write_text('unreviewed test');git('add','.');git('commit','-qm','unexpected test');reject('unreviewed test blob')
print('PASS all pure source guard cases')
