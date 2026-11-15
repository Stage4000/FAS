"""Fail-closed source boundary. No environment or command-line pin override."""
from pathlib import Path
import hashlib
import json
import os
import subprocess

ROOT = Path(__file__).resolve().parents[3]
MANIFEST = Path(__file__).with_name('source-manifest.json')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def blob_id(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()


def git(root, *args):
    return subprocess.run(['git', *args], cwd=root, check=True, capture_output=True).stdout


def verify_sources(root=ROOT, manifest=None, *, checkout=True):
    """checkout=False checks bytes only; the hosted runner never uses that mode."""
    root = Path(root).resolve()
    manifest = manifest or json.loads(MANIFEST.read_text())
    for rel, expected in manifest['source_blobs'].items():
        path = root / rel
        require(not Path(rel).is_absolute() and '..' not in Path(rel).parts,
                'Unsafe manifest path')
        require(path.is_file() and not path.is_symlink() and path.resolve().is_relative_to(root),
                'Missing, linked or escaped source: ' + rel)
        require(blob_id(path.read_bytes()) == expected, 'Pinned source mismatch: ' + rel)
    result = {'baseline': manifest['baseline'], 'pinned_sources': len(manifest['source_blobs']),
              'checkout_verified': False}
    if not checkout:
        return result
    baseline = manifest['baseline']
    require(git(root, 'rev-parse', baseline + '^{commit}').decode().strip() == baseline,
            'Reviewed baseline missing')
    require(git(root, 'rev-parse', baseline + '^{tree}').decode().strip() == manifest['baseline_tree'],
            'Reviewed baseline tree mismatch')
    git(root, 'merge-base', '--is-ancestor', baseline, 'HEAD')
    fields = git(root, 'diff', '--no-renames', '--name-status', '-z', baseline, 'HEAD').decode().split('\0')
    require(fields[-1] == '' and len(fields) % 2 == 1, 'Malformed changed-path receipt')
    changes = list(zip(fields[1:-1:2], fields[0:-1:2]))
    require(len(changes) == len(manifest['allowed_changes']) and dict(changes) == manifest['allowed_changes'],
            'Candidate changed paths/statuses differ from reviewed manifest')
    require(not git(root, 'diff', '--name-only', '-z', 'HEAD'), 'Dirty working tree')
    require(not git(root, 'diff', '--cached', '--name-only', '-z', 'HEAD'), 'Dirty index')
    require(not git(root, 'ls-files', '--others', '-z'), 'Untracked files in checkout')
    tags = git(root, 'ls-files', '-t', '-z').decode().split('\0')
    require(tags[-1] == '', 'Malformed index receipt')
    index = {row[2:]: row[0] for row in tags[:-1]}
    seen = set()
    count = 0
    for row in git(root, 'ls-tree', '-r', '-z', 'HEAD').decode().split('\0')[:-1]:
        metadata, rel = row.split('\t', 1)
        mode, kind, oid = metadata.split()
        require(rel in index and rel not in seen, 'Tree/index path mismatch')
        seen.add(rel)
        path = root / rel
        if not path.exists() and not path.is_symlink():
            require(index[rel] == 'S' and rel not in manifest['source_blobs'], 'Missing materialized source')
            continue
        require(mode in {'100644', '100755'} and kind == 'blob' and path.is_file()
                and not path.is_symlink() and path.resolve().is_relative_to(root), 'Unsafe tracked source')
        require(blob_id(path.read_bytes()) == oid, 'Materialized source differs: ' + rel)
        require(bool(path.stat().st_mode & 0o111) == (mode == '100755'), 'Tracked mode differs: ' + rel)
        if rel in manifest['allowed_changes']:
            require(mode == '100644', 'Candidate source must be non-executable regular file')
        count += 1
    require(seen == set(index), 'Tree/index inventories differ')
    for rel, oid in manifest['source_blobs'].items():
        require(git(root, 'rev-parse', 'HEAD:' + rel).decode().strip() == oid, 'Committed source pin mismatch')
    result.update(checkout_verified=True, materialized_blobs=count,
                  commit=git(root, 'rev-parse', 'HEAD').decode().strip(),
                  tree=git(root, 'rev-parse', 'HEAD^{tree}').decode().strip())
    return result


if __name__ == '__main__':
    print(json.dumps(verify_sources(), indent=2))
