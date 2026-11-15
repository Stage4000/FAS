"""Offline guard regressions, with disposable Git history only. Never starts a browser."""
from pathlib import Path
import importlib.util
import json
import os
import shutil
import subprocess
import tempfile
import sys
sys.dont_write_bytecode = True

spec = importlib.util.spec_from_file_location('guard', Path(__file__).with_name('source_guard.py'))
g = importlib.util.module_from_spec(spec); spec.loader.exec_module(g)
checks = 0
with tempfile.TemporaryDirectory(prefix='fas-manual-guard-') as temp:
    root = Path(temp)
    def git(*args): return g.git(root, *args).decode().strip()
    git('init', '-q'); git('config', 'user.email', 'synthetic@example.invalid'); git('config', 'user.name', 'Synthetic fixture')
    (root/'existing.txt').write_text('base\n'); git('add', '.'); git('commit', '-qm', 'synthetic baseline')
    baseline = git('rev-parse', 'HEAD'); tree = git('rev-parse', 'HEAD^{tree}')
    (root/'reader.txt').write_text('candidate\n'); git('add', '.'); git('commit', '-qm', 'synthetic candidate')
    candidate = git('rev-parse', 'HEAD')
    manifest = {'baseline': baseline, 'baseline_tree': tree, 'source_blobs': {'reader.txt':g.blob_id(b'candidate\n')}, 'allowed_changes':{'reader.txt':'A'}}
    def check(label, expected=True):
        global checks
        try: g.verify_sources(root, manifest); passed = True
        except (RuntimeError, subprocess.CalledProcessError): passed = False
        assert passed == expected, label
        checks += 1; print('PASS ' + label)
    def reset():
        git('reset','--hard',candidate); git('clean','-fdx'); git('update-index','--no-assume-unchanged','existing.txt'); git('update-index','--no-skip-worktree','existing.txt')
    check('exact committed candidate')
    (root/'reader.txt').write_text('changed\n'); check('reject changed pinned bytes',False); reset()
    (root/'existing.txt').write_text('changed\n'); check('reject unrelated working tree edit',False); reset()
    (root/'new.txt').write_text('extra'); check('reject untracked file',False); reset()
    (root/'.gitignore').write_text('hidden\n'); git('add','.gitignore'); git('commit','-qm','extra'); (root/'hidden').write_text('hidden'); check('reject extra committed path plus ignored file',False); reset()
    (root/'existing.txt').write_text('changed\n'); git('add','.'); check('reject dirty index',False); reset()
    git('update-index','--assume-unchanged','existing.txt'); (root/'existing.txt').write_text('changed'); check('reject assume-unchanged hidden edit',False); reset()
    git('update-index','--skip-worktree','existing.txt'); (root/'existing.txt').write_text('changed'); check('reject skip-worktree hidden edit',False); reset()
    (root/'reader.txt').chmod(0o755); check('reject executable candidate',False); reset()
    (root/'reader.txt').unlink(); (root/'reader.txt').symlink_to('existing.txt'); check('reject symlink',False); reset()
    (root/'existing.txt').unlink(); check('reject missing tracked source',False); reset()
    (root/'reader.txt').rename(root/'renamed.txt'); git('add','-A'); git('commit','-qm','rename'); check('reject committed rename',False); reset()
    (root/'existing.txt').write_text('changed'); git('add','.'); git('commit','-qm','runtime drift'); check('reject extra committed runtime change',False); reset()
    manifest['baseline_tree']='0'*40; check('reject wrong baseline tree',False); manifest['baseline_tree']=tree
    check('exact candidate restored')
print(f'{checks} offline source-guard checks passed; browser not launched.')
