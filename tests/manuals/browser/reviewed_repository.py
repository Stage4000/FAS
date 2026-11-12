"""Exact b99 manuals overlay for historical guards; no network or Git ancestry lookup.

The source manifest is the existing, independently reviewed policy root. It does
not hash itself. Its finite key/status schema is checked here; it is NOT proof
against arbitrary edits to this verifier or the reviewed workflow/policy files.
"""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import sys
sys.dont_write_bytecode = True

MAIN = '6993209ba92dbe77c36e3a61ba7146bf2d2db236'
MAIN_TREE = '8fdf7f25f130ddbda16d7b27ecc90fb6c7699345'
MANUALS = 'b99c463f31210413562efdef3e729383c30436a8'
MANUALS_TREE = 'b4bd3e1baa9980ed97cc5198290dcec514e8c7c4'
# Already merged on main after the historical catalog baseline; no new runtime edits.
HISTORICAL_MAIN = {
    'docs/reviewed-product-facts.md': 'e4f740df5b1578a80d257e0d8deac934bdee88f0',
    'src/utils/ReviewedProductFacts.php': 'af5c5e4b277dd3005e825ec0f384756ed9441637',
    'tests/rotor-reviewed-facts-test.php': None,
}
MANIFEST = 'tests/manuals/browser/source-manifest.json'
INVENTORY = 'tests/manuals/browser/reviewed-repository.json'
# Exact finite names. A/M is relative to main, not the b99 snapshot.
COMPATIBILITY = {
    '.github/workflows/admin-product-acceptance.yml': 'M',
    '.github/workflows/keyboard-browser.yml': 'M',
    '.github/workflows/manual-reader-browser.yml': 'A',
    'tests/admin-product-browser/run.py': 'M',
    'tests/keyboard-browser/heading_runner.py': 'M',
    'tests/keyboard-browser/catalog-scroll-guard-test.py': 'M',
    MANIFEST: 'A',
    INVENTORY: 'A',
    'tests/manuals/browser/reviewed_repository.py': 'A',
    'tests/manuals/browser/repository-compatibility-test.py': 'A',
    'docs/manuals-whole-repository-gates.md': 'A',
}


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Duplicate policy key')
        result[key] = value
    return result


def decode(data):
    return json.loads(data, object_pairs_hook=unique_pairs)


def read_regular(root, rel):
    path = root / rel
    require(not Path(rel).is_absolute() and all(p not in ('', '.', '..') for p in rel.split('/')),
            'Unsafe policy path')
    require(path.is_file() and not path.is_symlink() and path.resolve().is_relative_to(root),
            'Missing/nonregular required policy source: ' + rel)
    # Also disallow symlinked ancestors, including links that remain in root.
    require(not any(p.is_symlink() for p in [path, *path.parents] if p != root and root in p.parents),
            'Linked required policy source: ' + rel)
    require(not path.stat().st_mode & 0o111, 'Executable required policy source: ' + rel)
    return path.read_bytes()


def entries_map(entries):
    result = {}
    require(isinstance(entries, list), 'Invalid inventory')
    for row in entries:
        require(set(row) == {'path', 'mode', 'type', 'sha'}, 'Invalid inventory entry keys')
        name = row['path']
        require(isinstance(name, str) and name and not name.startswith('/')
                and all(p not in ('', '.', '..', '.git') for p in name.split('/')),
                'Invalid inventory path')
        require(name not in result and row['mode'] in ('100644', '100755')
                and row['type'] == 'blob' and re.fullmatch('[0-9a-f]{40}', row['sha']),
                'Duplicate/nonregular inventory entry')
        result[name] = {key: row[key] for key in ('mode', 'type', 'sha')}
    return result


def tree_hash(entries):
    root = {}
    for path, entry in entries.items():
        node = root
        parts = path.split('/')
        for part in parts[:-1]:
            node = node.setdefault(part, {})
            require(isinstance(node, dict), 'Inventory path collision')
        require(parts[-1] not in node, 'Inventory path collision')
        node[parts[-1]] = (entry['mode'], entry['sha'])
    def digest(node):
        data = b''
        for name, value in sorted(node.items(), key=lambda pair: (pair[0] + ('/' if isinstance(pair[1], dict) else '')).encode()):
            mode, oid = ('40000', digest(value)) if isinstance(value, dict) else value
            data += mode.encode() + b' ' + name.encode() + b'\0' + bytes.fromhex(oid)
        return hashlib.sha1(b'tree ' + str(len(data)).encode() + b'\0' + data).hexdigest()
    return digest(root)


def load_policy(root):
    root = Path(root).resolve()
    inventory = decode(read_regular(root, INVENTORY))
    require(set(inventory) == {'main_commit', 'main_tree', 'manuals_commit', 'manuals_tree',
                               'main', 'additions', 'baseline_manifest'}, 'Inventory keys differ')
    require((inventory['main_commit'], inventory['main_tree'], inventory['manuals_commit'], inventory['manuals_tree'])
            == (MAIN, MAIN_TREE, MANUALS, MANUALS_TREE), 'Immutable inventory anchors differ')
    main = entries_map(inventory['main'])
    additions = entries_map(inventory['additions'])
    require(len(main) == 513 and len(additions) == 309 and not (main.keys() & additions.keys()),
            'Exact 513 unchanged plus 309 added paths required')
    require(all(e['mode'] == '100644' for e in additions.values()), 'Nonregular manuals addition')
    reviewed = dict(main, **additions)
    require(tree_hash(main) == MAIN_TREE and tree_hash(reviewed) == MANUALS_TREE,
            'Inventory does not reconstruct both immutable trees')
    old_bytes = inventory['baseline_manifest'].encode()
    require(blob(old_bytes) == reviewed[MANIFEST]['sha'], 'Original manifest identity differs')
    original = decode(old_bytes)
    raw = read_regular(root, MANIFEST)
    manifest = decode(raw)
    require(set(manifest) == set(original) == {'baseline', 'baseline_tree', 'scope', 'source_blobs', 'allowed_changes'},
            'Manifest keys differ')
    require(manifest['baseline'] == MAIN and manifest['baseline_tree'] == MAIN_TREE
            and manifest['scope'] == original['scope'], 'Manifest anchors/scope differ')
    changes = {p: 'A' for p in additions}
    for path, status in COMPATIBILITY.items():
        require(status == ('M' if path in main else 'A'), 'Compatibility status differs')
        changes[path] = status
    require(manifest['allowed_changes'] == changes, 'Exact changed path/status map required')
    sources = manifest['source_blobs']
    require(set(sources) == (set(original['source_blobs']) | set(COMPATIBILITY)) - {MANIFEST},
            'Exact source pin keys required')
    for path, oid in sources.items():
        require(isinstance(oid, str) and re.fullmatch('[0-9a-f]{40}', oid), 'Invalid source pin')
        if path not in COMPATIBILITY:
            require(oid == original['source_blobs'][path] == reviewed[path]['sha'],
                    'Immutable source pin changed: ' + path)
    for path in COMPATIBILITY:
        data = read_regular(root, path)
        oid = blob(data)
        require(path == MANIFEST or oid == sources[path], 'Compatibility source pin mismatch: ' + path)
        reviewed[path] = {'mode': '100644', 'type': 'blob', 'sha': oid}
    return {'expected': reviewed, 'changes': changes, 'main': main,
            'historical_main': {p: {'before': before, 'after': main[p]['sha']}
                                for p, before in HISTORICAL_MAIN.items()},
            'overlay': {p: reviewed[p] for p in set(additions) | set(COMPATIBILITY) | set(HISTORICAL_MAIN)}}


def git(root, *args):
    return subprocess.run(['git', *args], cwd=root, capture_output=True, check=True, timeout=30).stdout


def parse_tree(raw):
    require(raw.endswith(b'\0'), 'Malformed tree framing')
    entries = []
    for record in raw.split(b'\0')[:-1]:
        metadata, path = record.split(b'\t', 1)
        mode, kind, oid = metadata.decode().split()
        entries.append(dict(path=path.decode(), mode=mode, type=kind, sha=oid))
    return entries_map(entries)


def verify_tree(current, expected):
    require(current == expected, 'Checkout differs from exact reviewed manuals/compatibility tree')


def verify_checkout(root):
    root = Path(root).resolve()
    policy = load_policy(root)
    # Recursive ls-tree omits empty subtrees and normalizes raw tree encoding.
    # Bind the full committed structure, not just its flattened blob inventory.
    require(git(root, 'rev-parse', 'HEAD^{tree}').decode().strip() == tree_hash(policy['expected']),
            'Committed root tree differs from exact reviewed tree')
    current = parse_tree(git(root, 'ls-tree', '-rz', 'HEAD'))
    verify_tree(current, policy['expected'])
    require(not git(root, 'diff', '--name-only', '-z', 'HEAD'), 'Dirty working tree')
    require(not git(root, 'diff', '--cached', '--name-only', '-z', 'HEAD'), 'Dirty index')
    require(not git(root, 'ls-files', '--others', '-z'), 'Untracked checkout file')
    rows = git(root, 'ls-files', '-t', '-z').split(b'\0')
    require(rows[-1] == b'', 'Malformed index framing')
    index = {}
    for row in rows[:-1]:
        require(len(row) > 2 and row[1:2] == b' ', 'Malformed index entry')
        rel = row[2:].decode()
        require(rel not in index, 'Duplicate index entry')
        index[rel] = row[:1]
    require(set(index) == set(current), 'Index/tree paths differ')
    checked = absent = 0
    for rel, entry in current.items():
        path = root / rel
        if not path.exists() and not path.is_symlink():
            require(index[rel] == b'S' and rel not in COMPATIBILITY, 'Missing tracked file: ' + rel)
            absent += 1
            continue
        require(path.is_file() and not path.is_symlink() and path.resolve().is_relative_to(root)
                and not any(p.is_symlink() for p in path.parents if p != root and root in p.parents),
                'Unsafe tracked source: ' + rel)
        require(blob(path.read_bytes()) == entry['sha'], 'Materialized source differs: ' + rel)
        require(bool(path.stat().st_mode & 0o111) == (entry['mode'] == '100755'), 'Materialized mode differs: ' + rel)
        checked += 1
    return dict(policy, receipt={'main': MAIN, 'manuals': MANUALS, 'manuals_tree': MANUALS_TREE,
                'manuals_additions': 309, 'compatibility_paths': sorted(COMPATIBILITY),
                'materialized': checked, 'absent_sparse': absent})


if __name__ == '__main__':
    print(json.dumps(verify_checkout(Path(__file__).resolve().parents[3])['receipt'], indent=2))
