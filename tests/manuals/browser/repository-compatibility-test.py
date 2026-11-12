"""Offline exact policy/metadata-Git controls. No app/server/browser/network."""
from pathlib import Path
import ast
import copy
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
sys.dont_write_bytecode = True
import reviewed_repository as g

ROOT = Path(__file__).resolve().parents[3]
ADMIN_PREIMAGES = {'docs/reviewed-product-facts.md': {'mode': '100644', 'type': 'blob', 'sha': 'e4f740df5b1578a80d257e0d8deac934bdee88f0'}, 'public/css/style.css': {'mode': '100644', 'type': 'blob', 'sha': '103706af92484631585fcc26553239b0b42681c4'}, 'src/utils/ReviewedProductFacts.php': {'mode': '100644', 'type': 'blob', 'sha': 'af5c5e4b277dd3005e825ec0f384756ed9441637'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '1727f1dd41307f304f08b6b579e61c9fdb41b3d4'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '657e0be65b2e1a90e5fbe1402e2372c9974278fc'}, 'tests/keyboard-browser/README.md': {'mode': '100644', 'type': 'blob', 'sha': 'c8e8cfcb9d8a0eca25aa4b46ed50ce7f4e6bb78e'}, 'tests/keyboard-browser/heading_runner.py': {'mode': '100644', 'type': 'blob', 'sha': 'd947a990d7aff9452c5948ec7ede62424635fa27'}, 'tests/keyboard-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '7a26a99e6fe66bcc6b22b91c2c74701cb70760d3'}, 'tests/catalog-scroll-offset-test.cjs': None, 'tests/keyboard-browser/catalog-scroll-guard-test.py': None, 'tests/keyboard-browser/catalog-scroll-identity.cjs': None, 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': None, 'tests/keyboard-browser/catalog-scroll-policy.cjs': None, 'tests/keyboard-browser/catalog-scroll.cjs': None, 'tests/rotor-reviewed-facts-test.php': None}
checks = 0

def check(label, action, accepted=True, *, reason=None):
    global checks
    try:
        result = action()
    except (RuntimeError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        if accepted:
            raise
        if reason is not None:
            assert str(error) == reason, label + ": wrong rejection: " + str(error)
    else:
        assert accepted, 'Unexpected acceptance: ' + label
    checks += 1
    print('PASS ' + label)


def selected_guard(path, names, functions):
    """Compile only reviewed constants/functions; never import an app runner."""
    body = []
    for node in ast.parse(path.read_text()).body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id in names:
            body.append(node)
        elif isinstance(node, ast.Expr) and isinstance(node.value, ast.Call) and isinstance(node.value.func, ast.Attribute) and isinstance(node.value.func.value, ast.Name) and node.value.func.value.id in names and node.value.func.attr == 'update':
            body.append(node)
        elif isinstance(node, ast.FunctionDef) and node.name in functions:
            body.append(node)
    namespace = {'hashlib': __import__('hashlib')}
    exec(compile(ast.Module(body=body, type_ignores=[]), str(path), 'exec'), namespace)
    return namespace


class Repository:
    def __init__(self, base, policy):
        self.root = base / 'repo'; self.root.mkdir()
        self.env = {'PATH': os.environ['PATH'], 'HOME': str(base), 'LC_ALL': 'C',
                    'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull,
                    'GIT_AUTHOR_NAME': 'Synthetic policy', 'GIT_COMMITTER_NAME': 'Synthetic policy',
                    'GIT_AUTHOR_EMAIL': 'fixture@example.invalid', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid',
                    'GIT_AUTHOR_DATE': '2026-11-12T00:00:00Z', 'GIT_COMMITTER_DATE': '2026-11-12T00:00:00Z',
                    'GIT_TERMINAL_PROMPT': '0'}
        self.git('init', '-q'); self.git('config', 'core.hooksPath', str(base / 'no-hooks'))
        self.git('config', 'core.autocrlf', 'false')
        # Only reviewed policy files are materialized; all other entries are
        # metadata-only sparse exclusions. No runtime or private fixture copied.
        self.policy = policy
        for rel in g.COMPATIBILITY:
            out = self.root / rel; out.parent.mkdir(parents=True, exist_ok=True)
            out.write_bytes((ROOT / rel).read_bytes())
            self.git('hash-object', '-w', '--', rel)
        self.install(policy['expected'])

    def git(self, *args, data=None):
        return subprocess.run(['git', *args], cwd=self.root, env=self.env, input=data,
                              capture_output=True, check=True, timeout=20).stdout

    def tree(self, entries):
        nodes = {}
        for path, entry in entries.items():
            node = nodes; parts = path.split('/')
            for part in parts[:-1]: node = node.setdefault(part, {})
            node[parts[-1]] = entry
        def build(node):
            records = []
            for name, value in node.items():
                if 'sha' in value and 'mode' in value:
                    mode, kind, oid = value['mode'], value['type'], value['sha']
                else: mode, kind, oid = '040000', 'tree', build(value)
                records.append(f'{mode} {kind} {oid}\t{name}'.encode())
            return self.git('mktree', '-z', '--missing', data=b'\0'.join(records)+b'\0').decode().strip()
        return build(nodes)

    def install(self, entries, parent=None):
        tree = self.tree(entries)
        commit = self.git('commit-tree', tree, *(['-p', parent] if parent else []), data=b'synthetic metadata fixture\n').decode().strip()
        self.git('update-ref', 'HEAD', commit); self.git('read-tree', 'HEAD')
        missing = set(entries) - set(g.COMPATIBILITY)
        self.git('update-index', '--skip-worktree', '-z', '--stdin', data=b''.join(p.encode()+b'\0' for p in sorted(missing)))
        return commit

    def restore(self):
        for rel in g.COMPATIBILITY:
            target = self.root / rel
            if target.is_symlink(): target.unlink()
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((ROOT / rel).read_bytes()); target.chmod(0o644)
        self.install(self.policy['expected'])


def main():
    policy = g.load_policy(ROOT)
    inventory = g.decode((ROOT / g.INVENTORY).read_bytes())
    check('both complete immutable trees and finite policy', lambda: g.load_policy(ROOT))
    assert len(policy['main']) == 513 and len(inventory['additions']) == 309
    # Keep all legacy constants/functions intact. Only the optional exact overlay
    # is added; omission reproduces the original checkout drift.
    names = {'INTEGRATION_PATHS', 'REVIEWED_OVERLAY', 'QUANTITY_BLOBS', 'STORAGE_BLOBS', 'MALFORMED_BLOBS', 'EMAIL_BLOBS', 'CATALOG_SCROLL_BLOBS', 'EXPECTED_PATHS'}
    h = selected_guard(ROOT/'tests/keyboard-browser/heading_runner.py', names, {'verify_tree'})
    base = {'entries': inventory['main']}
    candidate = {p: policy['main'][p]['sha'] for p in h['EXPECTED_PATHS']}
    b99 = dict(policy['main'], **g.entries_map(inventory['additions']))
    check('legacy keyboard rejects exact 309 manuals additions', lambda: h['verify_tree'](base, b99, candidate), False)
    check('keyboard accepts only verified exact overlay', lambda: h['verify_tree'](base, policy['expected'], candidate, policy['overlay']))
    for rel in [e['path'] for e in inventory['additions']] + list(g.HISTORICAL_MAIN) + ['admin/products.php', 'includes/header.php']:
        for kind in ['modified', 'missing', 'mode', 'type']:
            tree = copy.deepcopy(policy['expected'])
            if kind == 'modified': tree[rel]['sha'] = '1'*40
            elif kind == 'missing': del tree[rel]
            elif kind == 'mode': tree[rel]['mode'] = '120000'
            else: tree[rel]['type'] = 'commit'
            check('reject exact tree '+kind+' '+rel, lambda:g.verify_tree(tree,policy['expected']),False)
    with tempfile.TemporaryDirectory(prefix='manuals-repository-guard-') as temp:
        repo = Repository(Path(temp), policy)
        check('exact sparse metadata checkout without ancestors', lambda: g.verify_checkout(repo.root))
        # Recursive ls-tree omits empty subtrees and normalizes tree encoding.
        # Each real committed mutation below retains the exact same flattened
        # blob inventory and index; the root identity must nevertheless reject.
        original_tree = repo.git('rev-parse','HEAD^{tree}').decode().strip()
        root_rows = repo.git('ls-tree','-z',original_tree)
        empty = repo.git('mktree',data=b'').decode().strip()
        def tree_with(rows):
            return repo.git('mktree','-z','--missing',data=rows).decode().strip()
        def subtree_row(oid,name):
            return f'040000 tree {oid}\t{name}'.encode()+b'\0'
        extra_root = tree_with(root_rows+subtree_row(empty,'unexpected-empty-tree'))
        docs_rows = repo.git('ls-tree','-z',original_tree+':docs')
        docs_extra = tree_with(docs_rows+subtree_row(empty,'unexpected-empty-tree'))
        nested = tree_with(b'\0'.join(subtree_row(docs_extra,'docs')[:-1] if row.endswith(b'\tdocs') else row
                                      for row in root_rows.split(b'\0')[:-1])+b'\0')
        empty_chain = tree_with(subtree_row(empty,'leaf'))
        branch = tree_with(root_rows+subtree_row(empty_chain,'unexpected-empty-branch'))
        raw = repo.git('cat-file','tree',original_tree)
        records=[];offset=0
        while offset<len(raw):
            end=raw.index(b'\0',offset)+21
            records.append(raw[offset:end]);offset=end
        # These deliberately noncanonical metadata objects exist only in the
        # disposable no-remote fixture; no content or application is executed.
        reversed_tree=repo.git('hash-object','--literally','-t','tree','-w','--stdin',data=b''.join(reversed(records))).decode().strip()
        first_tree=next(i for i,row in enumerate(records) if row.startswith(b'40000 '))
        padded_records=list(records);padded_records[first_tree]=b'0'+padded_records[first_tree]
        padded_tree=repo.git('hash-object','--literally','-t','tree','-w','--stdin',data=b''.join(padded_records)).decode().strip()
        for name,tree in [('root empty subtree',extra_root),('nested empty subtree',nested),
                          ('empty subtree chain',branch),('noncanonical tree order',reversed_tree),
                          ('noncanonical directory mode encoding',padded_tree)]:
            assert tree != original_tree
            commit=repo.git('commit-tree',tree,data=b'synthetic structural mutation\n').decode().strip()
            repo.git('update-ref','HEAD',commit)
            assert g.parse_tree(repo.git('ls-tree','-rz','HEAD')) == policy['expected']
            check('reject committed '+name,lambda:g.verify_checkout(repo.root),False,
                  reason='Committed root tree differs from exact reviewed tree')
        repo.restore()

        # Missing historical baseline is the observed shallow-checkout failure;
        # no test downloads/fetches or manufactures the real commit object.
        check('missing admin historical baseline fails', lambda: repo.git('rev-parse', '81dc308e6a7830f31ea52260a7122cee4c6d5222^{commit}'), False)
        # Reconstruct the exact immutable historical admin tree, then use
        # synthetic commit IDs only (the application baseline constants are not
        # edited). This tests the actual full-tree diff/ancestry implementation.
        admin_base = copy.deepcopy(policy['main'])
        for rel, before in ADMIN_PREIMAGES.items():
            if before is None: del admin_base[rel]
            else: admin_base[rel] = before
        assert g.tree_hash(admin_base) == '00ebb2f16bea9655f81c88de754fee75b76c534f'
        a = selected_guard(ROOT/'tests/admin-product-browser/run.py',
            {'BASELINE','BASELINE_TREE','ALLOWED_CHANGES','EXPECTED_RUNTIME','EXPECTED_STORAGE_BLOBS'},
            {'require','verify_candidate'})
        baseline = repo.git('commit-tree',repo.tree(admin_base),data=b'synthetic original baseline\n').decode().strip()
        repo.install(policy['expected'],parent=baseline)
        def run_command(args, *, cwd):
            assert args[0]=='git' and cwd==repo.root
            return subprocess.run(args,cwd=cwd,env=repo.env,capture_output=True,check=True,timeout=20)
        a.update(ROOT=repo.root,BASELINE=baseline,git=lambda *args:repo.git(*args).decode().strip(),run_command=run_command)
        check('legacy admin rejects manuals and already-merged facts',lambda:a['verify_candidate'](),False)
        check('admin exact full delta and baseline ancestry',lambda:a['verify_candidate'](compatibility=g.verify_checkout(repo.root)))
        for rel in g.HISTORICAL_MAIN:
            bad=copy.deepcopy(policy);bad['historical_main'][rel]['after']='1'*40
            check('reject historical main postimage '+rel,lambda:a['verify_candidate'](compatibility=bad),False)
        bad=copy.deepcopy(policy);bad['historical_main']['src/utils/ReviewedProductFacts.php']['before']='1'*40
        check('reject historical main preimage',lambda:a['verify_candidate'](compatibility=bad),False)
        repo.restore()
        for rel in ['manuals/index.php', 'public/vendor/pdfjs-6.5.438/pdf.mjs', 'src/config/Database.php', 'tests/manuals/browser/response-policy-test.cjs']:
            for kind in ['modified', 'missing', 'symlink', 'executable']:
                tree = copy.deepcopy(policy['expected'])
                if kind == 'modified': tree[rel]['sha'] = '1'*40
                elif kind == 'missing': del tree[rel]
                elif kind == 'symlink': tree[rel]['mode'] = '120000'
                else: tree[rel]['mode'] = '100755'
                repo.install(tree)
                check('reject '+kind+' committed '+rel, lambda: g.verify_checkout(repo.root), False)
        repo.restore()
        tree = copy.deepcopy(policy['expected']);tree['tests/unreviewed.txt'] = dict(mode='100644', type='blob', sha='1'*40)
        repo.install(tree);check('reject extra committed path', lambda: g.verify_checkout(repo.root), False);repo.restore()
        for rel in g.COMPATIBILITY:
            path = repo.root / rel
            path.write_bytes(path.read_bytes()+b'\n')
            check('reject changed required bytes '+rel, lambda: g.verify_checkout(repo.root), False);repo.restore()
            path.unlink();repo.git('update-index','--skip-worktree','--',rel)
            check('reject sparse-hidden missing policy '+rel, lambda: g.verify_checkout(repo.root), False);repo.restore()
        for flag in ['--assume-unchanged','--skip-worktree']:
            rel = 'tests/manuals/browser/reviewed_repository.py';repo.git('update-index',flag,'--',rel)
            (repo.root/rel).write_bytes((repo.root/rel).read_bytes()+b'changed')
            check('reject hidden dirty policy '+flag, lambda: g.verify_checkout(repo.root), False);repo.restore()
        rel = 'tests/manuals/browser/reviewed_repository.py'
        path = repo.root/rel;path.chmod(0o755)
        check('reject materialized executable policy',lambda:g.verify_checkout(repo.root),False);repo.restore()
        path.unlink();path.symlink_to('source-manifest.json')
        check('reject materialized policy symlink',lambda:g.verify_checkout(repo.root),False);repo.restore()
        (repo.root/'untracked.txt').write_text('synthetic')
        check('reject untracked source',lambda:g.verify_checkout(repo.root),False);(repo.root/'untracked.txt').unlink()
        (repo.root/'.git/info/exclude').write_text('ignored.txt\n');(repo.root/'ignored.txt').write_text('synthetic')
        check('reject ignored untracked source',lambda:g.verify_checkout(repo.root),False);(repo.root/'ignored.txt').unlink()
        repo.git('update-index','--cacheinfo','100644,'+'1'*40+','+rel)
        check('reject dirty index',lambda:g.verify_checkout(repo.root),False);repo.restore()
        rel = 'public/css/style.css'
        target = repo.root/rel;target.parent.mkdir(parents=True,exist_ok=True)
        data=(ROOT/rel).read_bytes();assert g.blob(data)==policy['main'][rel]['sha']
        target.write_bytes(data);repo.git('hash-object','-w','--',rel)
        repo.git('update-index','--no-skip-worktree','--',rel)
        check('materialized exact main bytes',lambda:g.verify_checkout(repo.root))
        for flag in ['--assume-unchanged','--skip-worktree']:
            repo.git('update-index',flag,'--',rel);target.write_bytes(data+b'changed')
            check('reject hidden main edit '+flag,lambda:g.verify_checkout(repo.root),False)
            target.write_bytes(data);repo.git('update-index','--no-assume-unchanged','--',rel)
            repo.git('update-index','--no-skip-worktree','--',rel)
        target.chmod(0o755)
        check('reject changed main executable mode',lambda:g.verify_checkout(repo.root),False);target.chmod(0o644)
        target.unlink()
        check('reject absent nonsparse main source',lambda:g.verify_checkout(repo.root),False);repo.restore()
        for rel in [g.MANIFEST,g.INVENTORY]:
            path=repo.root/rel;raw=path.read_text();path.write_text('{"duplicate":1,"duplicate":2,'+raw[1:])
            check('reject duplicate JSON key '+rel,lambda:g.load_policy(repo.root),False);repo.restore()
        # Each metadata mutation is tested independently, not merely a dirty Git
        # rejection: policy loader itself must reject before checkout checking.
        def mutate_policy(field, change):
            p=repo.root/field; obj=json.loads(p.read_text());change(obj);p.write_text(json.dumps(obj))
            check('reject policy mutation '+field,lambda:g.load_policy(repo.root),False);repo.restore()
        mutate_policy(g.INVENTORY, lambda x:x['main'][0].update(sha='1'*40))
        mutate_policy(g.INVENTORY, lambda x:x['additions'][0].update(sha='1'*40))
        mutate_policy(g.INVENTORY, lambda x:x.update(baseline_manifest=x['baseline_manifest']+' '))
        mutate_policy(g.MANIFEST, lambda x:x['allowed_changes'].update({'tests/unreviewed.txt':'A'}))
        mutate_policy(g.MANIFEST, lambda x:x['allowed_changes'].update({'manuals/index.php':'M'}))
        mutate_policy(g.MANIFEST, lambda x:x['source_blobs'].update({'manuals/index.php':'1'*40}))
        mutate_policy(g.MANIFEST, lambda x:x['source_blobs'].update({'unreviewed':'1'*40}))
        mutate_policy(g.MANIFEST, lambda x:x['source_blobs'].pop('manuals/index.php'))
        mutate_policy(g.MANIFEST, lambda x:x.update(scope='widened'))
        check('exact sparse candidate restored',lambda:g.verify_checkout(repo.root))
        # Git's own path-pattern matcher exercises the actual non-cone sparse
        # rules. Positive matches here mean included paths in sparse-checkout.
        for workflow in ['admin-product-acceptance','keyboard-browser','manual-reader-browser']:
            lines=(ROOT/f'.github/workflows/{workflow}.yml').read_text().splitlines()
            start=next(i for i,x in enumerate(lines) if x.strip()=='sparse-checkout: |')+1
            end=start
            while end<len(lines) and lines[end].startswith('            '):end+=1
            patterns=[x.strip() for x in lines[start:end]]
            (repo.root/'.git/info/exclude').write_text('\n'.join(patterns)+'\n')
            required=set(g.COMPATIBILITY)|{'public/css/style.css'}
            raw=repo.git('check-ignore','--no-index','-z','--stdin',data=b''.join(p.encode()+b'\0' for p in sorted(required)))
            assert set(raw.decode().split('\0')[:-1])==required,workflow+' sparse dependencies missing'
            check('actual sparse patterns include every policy dependency '+workflow,lambda:None)

    # Exact public workflow boundaries, without running their commands.
    admin=(ROOT/'.github/workflows/admin-product-acceptance.yml').read_text()
    keyboard=(ROOT/'.github/workflows/keyboard-browser.yml').read_text()
    assert 'env PYTHONDONTWRITEBYTECODE=1 NODE_PATH=' in keyboard
    assert 'fetch-depth: 60' in admin and 'fetch-depth: 0' not in admin
    import fnmatch
    for text in [admin,keyboard]:
        lines=text.splitlines()
        for start in [i+1 for i,x in enumerate(lines) if x.strip()=='paths:']:
            end=start
            while end<len(lines) and lines[end].startswith('      - '):end+=1
            patterns=[x.split("'",2)[1] for x in lines[start:end]]
            for rel in g.COMPATIBILITY:
                assert any(fnmatch.fnmatchcase(rel,p) for p in patterns),'Missing trigger: '+rel
        check('finite shared-policy event triggers',lambda:None)
    for text in [admin,keyboard]:
        assert 'ref: ${{ github.event.pull_request.head.sha || github.sha }}' in text
        assert 'persist-credentials: false' in text and 'sudo unshare --net' in text
    print(json.dumps({'passed':True,'checks':checks,'manuals_additions':309,'b99_unchanged_main_entries':513,'candidate_entries':len(policy['expected']),'candidate_changes':len(policy['changes']),
                      'scope':'offline policy and metadata-only Git; no runtime/browser proof'}))

if __name__ == '__main__':
    main()
