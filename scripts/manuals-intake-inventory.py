#!/usr/bin/env python3
"""Read-only, bounded local metadata/PDF-header inventory. Never imports files."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys

MAX_BYTES = 50 * 1024 * 1024
VIDEO = {'.mp4', '.mov', '.avi', '.mkv', '.webm', '.wmv', '.m4v', '.mpeg', '.mpg'}

def signature(s):
    return (s.st_dev, s.st_ino, s.st_mode, s.st_size, s.st_mtime_ns, s.st_ctime_ns)

def inventory(root, max_entries=10000, max_depth=16):
    result = {'version': 1, 'complete': True, 'entries_examined': 0, 'excluded_counts': {}, 'excluded_bytes': {}, 'limits': {'max_entries': max_entries, 'max_depth': max_depth, 'max_file_bytes': MAX_BYTES}, 'files': [], 'errors': [], 'duplicate_groups': [], 'candidate_count': 0, 'candidate_bytes': 0, 'unique_candidate_bytes': 0, 'regular_bytes_observed': 0, 'duplicate_candidate_bytes': 0, 'estimates_only': True, 'limitations': ['Header and SHA256 are not full PDF, MIME, malware or rights validation.', 'Byte totals count logical paths, not filesystem allocation or compression; hard links can share existing physical storage.', 'Excluded sizes are initial metadata estimates, not hashed or transactionally consistent bytes.', 'Current importer does not deduplicate. Unique bytes are a planning estimate, not actual storage savings.', 'No files imported, changed or removed; no network access.']}
    if os.name != 'posix' or not all(hasattr(os, flag) for flag in ['O_NOFOLLOW', 'O_NONBLOCK', 'O_DIRECTORY']) or os.open not in os.supports_dir_fd or os.stat not in os.supports_dir_fd or os.scandir not in os.supports_fd or os.stat not in os.supports_follow_symlinks:
        result['complete'] = False
        result['errors'] = [{'path': '', 'reason': 'unsupported_platform'}]
        return result
    groups = {}; entries = 0
    def error(path, reason):
        result['complete'] = False
        result['errors'].append({'path': path, 'reason': reason})
    def file_record(fd, name, relative, initial):
        record = {'path': relative, 'bytes': initial.st_size}
        result['files'].append(record)
        suffix = Path(name).suffix.lower()
        if suffix in VIDEO: record['status'] = 'video'; return
        if suffix != '.pdf': record['status'] = 'non_pdf'; return
        if initial.st_size == 0: record['status'] = 'empty'; return
        if initial.st_size > MAX_BYTES: record['status'] = 'oversized'; return
        opened = None
        try:
            opened = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
            before = os.fstat(opened)
            if not stat.S_ISREG(before.st_mode) or signature(before) != signature(initial):
                record['status'] = 'changed'; error(relative, 'changed'); return
            h = hashlib.sha256(); total = 0; header = b''
            read_limit = min(before.st_size + 1, MAX_BYTES + 1)
            while total < read_limit:
                data = os.read(opened, min(65536, read_limit - total))
                if not data: break
                if total == 0: header = data[:8]
                total += len(data); h.update(data)
            after = os.fstat(opened)
            current = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if total != before.st_size or signature(before) != signature(after) or signature(after) != signature(current):
                record['status'] = 'changed'; error(relative, 'changed'); return
            if not re.match(rb'%PDF-[12]\.[0-9]', header): record['status'] = 'bad_header'; return
            record.update(status='candidate', sha256=h.hexdigest())
            groups.setdefault((record['sha256'], total), []).append(relative)
            result['candidate_count'] += 1; result['candidate_bytes'] += total
        except OSError:
            record['status'] = 'unreadable'; error(relative, 'unreadable')
        finally:
            if opened is not None: os.close(opened)
    def walk(fd, relative, depth):
        nonlocal entries
        try:
            # Read at most the remaining entry budget plus one before sorting.
            names = []
            with os.scandir(fd) as stream:
                for entry in stream:
                    if entries >= max_entries:
                        error(relative, 'entry_limit'); break
                    names.append(entry.name); entries += 1
            for name in sorted(names):
                rel = relative + '/' + name if relative else name
                try:
                    initial = os.stat(name, dir_fd=fd, follow_symlinks=False)
                    if stat.S_ISLNK(initial.st_mode):
                        result['files'].append({'path': rel, 'status': 'symlink'}); continue
                    if stat.S_ISDIR(initial.st_mode):
                        if depth >= max_depth: error(rel, 'depth_limit'); continue
                        child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                        try:
                            before = os.fstat(child)
                            if signature(before) != signature(initial): error(rel, 'changed_directory'); continue
                            walk(child, rel, depth + 1)
                            if signature(before) != signature(os.fstat(child)) or signature(before) != signature(os.stat(name, dir_fd=fd, follow_symlinks=False)): error(rel, 'changed_directory')
                        finally: os.close(child)
                    elif stat.S_ISREG(initial.st_mode): file_record(fd, name, rel, initial)
                    else: result['files'].append({'path': rel, 'status': 'non_regular'})
                except OSError: error(rel, 'unreadable')
        except OSError: error(relative, 'unreadable_directory')
    if not isinstance(max_entries, int) or not 1 <= max_entries <= 100000 or not isinstance(max_depth, int) or not 0 <= max_depth <= 64:
        raise ValueError('Invalid traversal limits')
    fd = None; parent = None; leaf = None
    try:
        # Walk every supplied root component using pinned directory descriptors.
        raw = os.fspath(root)
        absolute = raw if os.path.isabs(raw) else os.path.join(os.getcwd(), raw)
        parts = [p for p in absolute.split('/') if p not in ('', '.')]
        if '..' in parts: raise OSError('Parent components are not accepted')
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        for component in parts:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            if parent is not None: os.close(parent)
            parent, fd, leaf = fd, child, component
        initial = os.fstat(fd)
        if not stat.S_ISDIR(initial.st_mode): raise OSError('Not a directory')
        before = os.fstat(fd)
        if signature(before) != signature(initial): raise OSError('Changed root')
        walk(fd, '', 0)
        if signature(before) != signature(os.fstat(fd)) or signature(before) != signature(os.stat(leaf, dir_fd=parent, follow_symlinks=False) if parent is not None else os.fstat(fd)): error('', 'changed_directory')
    except OSError: error('', 'unreadable_root')
    finally:
        if fd is not None: os.close(fd)
        if parent is not None: os.close(parent)
    result['files'].sort(key=lambda r: r['path'])
    result['errors'].sort(key=lambda r: (r['path'], r['reason']))
    result['entries_examined'] = entries
    result['excluded_counts'] = {}; result['excluded_bytes'] = {}
    for record in result['files']:
        result['regular_bytes_observed'] += record.get('bytes', 0)
        if record['status'] != 'candidate':
            result['excluded_counts'][record['status']] = result['excluded_counts'].get(record['status'], 0) + 1
            result['excluded_bytes'][record['status']] = result['excluded_bytes'].get(record['status'], 0) + record.get('bytes', 0)
    for (digest, size), paths in sorted(groups.items()):
        result['unique_candidate_bytes'] += size
        if len(paths) > 1: result['duplicate_groups'].append({'sha256': digest, 'bytes_each': size, 'paths': sorted(paths)})
    result['duplicate_candidate_bytes'] = result['candidate_bytes'] - result['unique_candidate_bytes']
    return result

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory'); parser.add_argument('--max-entries', type=int, default=10000); parser.add_argument('--max-depth', type=int, default=16)
    args = parser.parse_args()
    try: result = inventory(args.directory, args.max_entries, args.max_depth)
    except ValueError as e: parser.error(str(e))
    print(json.dumps(result, indent=2, sort_keys=True, ensure_ascii=True))
    return 0 if result['complete'] else 2

if __name__ == '__main__': sys.exit(main())
