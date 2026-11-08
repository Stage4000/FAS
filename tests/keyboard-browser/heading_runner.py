"""Invoked only by the existing loopback-isolated runner; never a standalone server."""
from pathlib import Path
import base64, gzip, hashlib, json, re, sqlite3, subprocess

EXPECTED_BASE = '686f9df992f9b6b077b2400870b5a33a3ca6d18d'
EXPECTED_PATHS = {'index.php', 'products.php', 'api/products.php', 'product.php',
                  'cart.php', 'includes/product-merchandising.php', 'includes/footer.php', 'public/css/style.css', 'includes/ebay-seller-rating.php'}
sha256 = lambda data: hashlib.sha256(data).hexdigest()
INTEGRATION_PATHS = {'tests/keyboard-browser/' + name for name in [
    'run.py','heading_runner.py','heading.cjs','heading-policy.cjs','heading-sources.json',
    'heading-base-tree.json','heading-unit-test.py','heading-policy-test.cjs']}
EXPECTED_KEYS = {f'{surface}-{width}-{theme}' for surface in
    ['home','catalog','empty','product','cart','cart-populated','ajax-empty','ajax-populated']
    for width in [1440,390] for theme in ['light','dark']}

# Exact reviewed subsequent blobs; historical heading controls remain pinned.
REVIEWED_OVERLAY = {'.github/workflows/admin-product-acceptance.yml': {'mode': '100644',
                                                    'type': 'blob',
                                                    'sha': 'c180ab1b5298290d14ee41e4c90d352fe9436295'},
 'public/js/theme-toggle.js': {'mode': '100644',
                               'type': 'blob',
                               'sha': '34430acbe4b431f680c686cd40f52be41a1484bc'},
 'src/utils/ResponsiveImage.php': {'mode': '100644',
                                   'type': 'blob',
                                   'sha': 'd8bfa725d5aaf7320b3c1052966376129379fdd4'},
 'tests/admin-product-browser/README.md': {'mode': '100644',
                                           'type': 'blob',
                                           'sha': 'fb27ab2cac7920e65fbb6a06c02334ef54aefcf5'},
 'tests/admin-product-browser/guard-test.py': {'mode': '100644',
                                               'type': 'blob',
                                               'sha': 'f6ad34fcdc07b0200df968400e5ed45b95d11424'},
 'tests/admin-product-browser/run.py': {'mode': '100644',
                                        'type': 'blob',
                                        'sha': 'bdb201b019c9247fa2cbbe9f0a23bcb11cc833a2'},
 'tests/local-image-fallback-test.php': {'mode': '100644',
                                         'type': 'blob',
                                         'sha': '4b1bda015a752451c797d6e4d918e2a5f1f6ce65'},
 'tests/theme-toggle-test.cjs': {'mode': '100644',
                                 'type': 'blob',
                                 'sha': '1bae638fc11c8797f5e0295b372fa0ec96acea39'},
 'tests/catalog-loader-lifecycle-test.cjs': {'mode': '100644',
                                             'type': 'blob',
                                             'sha': '47b04f168c65ea2d2e3761adf4e872a9da863329'},
 'tests/catalog-loader-lifecycle-tests.md': {'mode': '100644',
                                             'type': 'blob',
                                             'sha': '468364bfed8f1b8d6f3cc6cc8af4637cd5b56cca'},
 'tests/keyboard-browser/navbar.cjs': {'mode': '100644',
                                       'type': 'blob',
                                       'sha': '6fcf4b3483fbef9b79bb58548357990700241757'},
 'tests/keyboard-browser/navbar-unit-test.cjs': {'mode': '100644',
                                                 'type': 'blob',
                                                 'sha': '309f70f76671fce2afd13abdf3875bf949122d86'}}

def parse_tree(raw):
    entries = {}
    for record in raw.split(b'\0'):
        if not record: continue
        meta, path = record.split(b'\t', 1)
        mode, kind, digest = meta.decode().split()
        entries[path.decode()] = {'mode':mode, 'type':kind, 'sha':digest}
    return entries

def base_tree_hash(entries):
    root = {}
    for entry in entries:
        parts=entry['path'].split('/')
        if any(p in ('','..','.') for p in parts): raise ValueError('Invalid base tree path')
        node=root
        for part in parts[:-1]:
            node=node.setdefault(part,{})
            if not isinstance(node,dict): raise ValueError('Tree collision')
        if parts[-1] in node: raise ValueError('Duplicate base tree path')
        node[parts[-1]]=(entry['mode'],entry['sha'])
    def digest(node):
        data=b''
        for name,value in sorted(node.items(),key=lambda pair:(pair[0]+('/' if isinstance(pair[1],dict) else '')).encode()):
            mode,sha=('40000',digest(value)) if isinstance(value,dict) else value
            data+=mode.encode()+b' '+name.encode()+b'\0'+bytes.fromhex(sha)
        return hashlib.sha1(b'tree '+str(len(data)).encode()+b'\0'+data).hexdigest()
    return digest(root)

def verify_tree(base, current, source_manifest):
    expected = {e['path']:{k:e[k] for k in ['mode','type','sha']} for e in base['entries']}
    expected.update(REVIEWED_OVERLAY)
    # Exact candidate blobs are derived from SHA-256-verified working bytes below.
    for path, blob in source_manifest.items():
        expected[path] = {'mode':'100644','type':'blob','sha':blob}
    for path in set(expected) | set(current):
        if path in INTEGRATION_PATHS:
            if path not in current or current[path]['mode'] != '100644' or current[path]['type'] != 'blob':
                raise ValueError('Missing/nonregular reviewed integration path: '+path)
        elif expected.get(path) != current.get(path):
            raise ValueError('Unexpected checkout drift: '+path)
    if not INTEGRATION_PATHS.issubset(current):
        raise ValueError('Missing integration files')

def verify_checkout(root):
    suite = root/'tests/keyboard-browser'
    manifest = json.loads((suite/'heading-sources.json').read_text())
    decode_manifest(manifest)
    candidate = {p:(root/p).read_bytes() for p in EXPECTED_PATHS}
    verify_candidate(manifest, candidate)
    base = json.loads((suite/'heading-base-tree.json').read_text())
    if base['base_commit'] != 'c7122b0e406ce5052bc87bcb443395f5a35d9618' or base['base_tree'] != '3087b90c93484dcd54df0211652b65af696b91ad':
        raise ValueError('Unexpected reference tree')
    if base_tree_hash(base['entries']) != base['base_tree']:
        raise ValueError('Embedded baseline entries do not reconstruct pinned tree')
    current = parse_tree(subprocess.check_output(['git','ls-tree','-rz','HEAD'],cwd=root))
    blobs = {p:hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest() for p,b in candidate.items()}
    verify_tree(base,current,blobs)
    # Prevent untracked/modified files and symlinks entering copied runtime trees.
    copied = [root/p for p in EXPECTED_PATHS] + [root/'404.php']
    for folder in ['src','includes','public']:
        for path in (root/folder).rglob('*'):
            if any(part=='uploads' for part in path.relative_to(root).parts) or path.match('config*.php') or path.suffix in ['.log','.db','.sqlite']:
                continue
            if path.is_symlink(): raise ValueError('Runtime symlink: '+str(path))
            if path.is_file(): copied.append(path)
    copied += [root/p for p in INTEGRATION_PATHS]
    copied += [root/p for p in current if p.startswith('tests/keyboard-browser/')]
    copied += [root/p for p in ['tests/fixtures/security-catalog.sql','tests/homepage-visual/prepare-assets.py',
                              'gallery/hero-image.png','gallery/favicons/favicon.png']]
    for path in copied:
        name=str(path.relative_to(root)); data=path.read_bytes()
        blob=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        if path.is_symlink() or current.get(name,{}).get('sha') != blob:
            raise ValueError('Untracked/modified copied source or integration: '+name)
    return {'commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),
            'tree':subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=root,text=True).strip(),
            'base_tree':base['base_tree'], 'integration_blobs':{p:current[p]['sha'] for p in sorted(INTEGRATION_PATHS)},
            'workflow_blob':current['.github/workflows/keyboard-browser.yml']['sha'],
            'consumed_sha256':{str(p.relative_to(root)):sha256(p.read_bytes()) for p in sorted(set(copied))},
            'guard':'Every tracked path outside nine source files and eight named integration files equals verified baseline plus exact reviewed overlay; copied bytes equal checkout blobs.'}

def decode_manifest(manifest):
    if manifest['base'] != EXPECTED_BASE or {f['path'] for f in manifest['files']} != EXPECTED_PATHS or len(manifest['files']) != 9:
        raise ValueError('Exact baseline and nine source paths required')
    baseline = {}
    for entry in manifest['files']:
        data = gzip.decompress(base64.b64decode(entry['baseline_gzip_b64'], validate=True))
        blob = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if sha256(data) != entry['baseline_sha256'] or blob != entry['baseline_git_blob']:
            raise ValueError('Baseline identity mismatch: ' + entry['path'])
        baseline[entry['path']] = data
    return baseline

def verify_candidate(manifest, candidate):
    for entry in manifest['files']:
        if sha256(candidate[entry['path']]) != entry['candidate_sha256']:
            raise ValueError('Candidate identity mismatch: ' + entry['path'])

def cart_restoration(candidate, baseline):
    # Restore only the Cart parent tag, not unrelated source or a remote ref.
    fixed = b'<h2 class="h5 fw-bold text-center mb-3">Popular Parts Shoppers Are Viewing</h2>'
    old = b'<h4 class="h5 fw-bold text-center mb-3">Popular Parts Shoppers Are Viewing</h4>'
    if candidate['cart.php'].count(fixed) != 1 or baseline['cart.php'].count(old) != 1:
        raise ValueError('Exact Cart parent anchors required')
    restored = dict(candidate)
    restored['cart.php'] = candidate['cart.php'].replace(fixed, old)
    # Identity is established by the exact manifest before one reviewed substitution.
    manifest = json.loads((Path(__file__).parent/'heading-sources.json').read_text())
    cart_entry=next(e for e in manifest['files'] if e['path']=='cart.php')
    if sha256(candidate['cart.php']) != cart_entry['candidate_sha256']:
        raise ValueError('Exact candidate Cart bytes required')
    restored_residual = residual_restoration(restored, verify=False)
    if restored_residual['cart.php'] != baseline['cart.php']:
        raise ValueError('Cart control differs outside reviewed residual anchors')
    return restored

RESIDUAL_CODES = {'CART_EMPTY_STATUS','CART_SUMMARY','CART_SHIPPING','CART_PAYMENT_LABEL','CART_ITEM','SELLER_IDENTITY'}
VARIANTS = ['candidate','baseline','cart-parent-restored','residual-restored']

def residual_restoration(candidate, verify=True):
    manifest = json.loads((Path(__file__).parent/'heading-sources.json').read_text())
    if verify: verify_candidate(manifest,candidate)
    changes_map=manifest['residual_changes']
    if set(changes_map) != {'cart.php','includes/ebay-seller-rating.php'} or [len(changes_map[p]) for p in ['cart.php','includes/ebay-seller-rating.php']] != [5,1] or {c[2] for cs in changes_map.values() for c in cs} != RESIDUAL_CODES:
        raise ValueError('Exact six residual codes and two paths required')
    restored = dict(candidate)
    for path, changes in changes_map.items():
        for old,new,code in changes:
            if restored[path].count(new.encode()) != 1:
                raise ValueError('Exact residual anchor required: '+code)
            restored[path] = restored[path].replace(new.encode(),old.encode())
    if verify:
        base=json.loads((Path(__file__).parent/'heading-base-tree.json').read_text())
        released={e['path']:e['sha'] for e in base['entries']}
        for path,data in restored.items():
            if hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() != released[path]:
                raise ValueError('Residual restoration must equal exact released source: '+path)
    return restored

def compare_visuals(baseline, candidate):
    errors = []
    a = {r['key']: r for r in baseline['rows']}
    b = {r['key']: r for r in candidate['rows']}
    if set(a) != set(b):
        return ['view identity mismatch']
    for key in a:
        left, right = a[key]['visual'], b[key]['visual']
        if len(left) != len(right):
            errors.append(key + ': heading count mismatch'); continue
        for i, (old, new) in enumerate(zip(left, right)):
            if old['text'] != new['text'] or old['visible'] != new['visible'] or old['style'] != new['style']:
                errors.append(f'{key}:{i}: heading content/visibility/style changed')
            if any(abs(old['rect'][k] - new['rect'][k]) > 1 for k in old['rect']):
                errors.append(f'{key}:{i}: heading geometry changed by more than 1px')
    return errors

def evaluate_reports(reports):
    errors = []
    for variant in VARIANTS:
        r = reports.get(variant)
        if not r or r.get('infrastructure_failures') or len(r.get('rows',[])) != len(EXPECTED_KEYS) or {row['key'] for row in r['rows']} != EXPECTED_KEYS:
            errors.append(variant + ': missing views or infrastructure failure')
    if errors:
        return errors
    if reports['candidate']['semantic_failures']:
        errors.append('candidate semantic assertions failed')
    baseline_codes = {f['code'] for f in reports['baseline']['semantic_failures']}
    required = {'HOME_CATEGORY', 'HOME_FEATURE', 'CATALOG_CARD', 'CATALOG_EMPTY',
                'PRODUCT_SECTION', 'FOOTER', 'CART_PARENT', 'SHARED_CARD'} | RESIDUAL_CODES
    if not required.issubset(baseline_codes):
        errors.append('baseline failed to detect every required semantic regression')
    if baseline_codes - (required | {'CATALOG_SIDEBAR'}):
        errors.append('baseline contains unexpected semantic failures')
    restored = reports['cart-parent-restored']['semantic_failures']
    if not restored or {f['code'] for f in restored} != {'CART_PARENT'}:
        errors.append('Cart negative control must fail only CART_PARENT')
    if {f['code'] for f in reports['residual-restored']['semantic_failures']} != RESIDUAL_CODES:
        errors.append('Residual control must fail exactly all six residual codes')
    for variant in VARIANTS[1:]:
        errors += compare_visuals(reports[variant], reports['candidate'])
    return errors

def seed_recommendations(site):
    # This is the existing disposable SQLite fixture, never a supplied live DB.
    dbpath = site / 'database/flipandstrip.db'
    with sqlite3.connect(dbpath) as db:
        db.row_factory = sqlite3.Row
        # The existing security fixture has empty orders/order_items but omits
        # orders.created_at, which the actual best-seller query references.
        # Add only that missing synthetic column; do not swallow its SQL error.
        order_columns={row[1] for row in db.execute('PRAGMA table_info(orders)')}
        if not order_columns: raise ValueError('Expected synthetic orders table')
        if 'created_at' not in order_columns:
            db.execute('ALTER TABLE orders ADD COLUMN created_at TEXT')
        original = dict(db.execute('SELECT * FROM products WHERE id=1').fetchone())
        if [r[0] for r in db.execute('SELECT id FROM products ORDER BY id')] != [1] or original.get('ebay_item_id') is not None:
            raise ValueError('Expected exact synthetic id1-only inventory')
        columns = list(original)
        for product_id in range(2, 41):
            row = dict(original, id=product_id, name=f'Synthetic test part {product_id:02}',
                       sku=f'TEST-{product_id}', created_at='2026-09-30', updated_at='2026-09-30')
            db.execute('INSERT INTO products (' + ','.join(columns) + ') VALUES (' + ','.join('?' for _ in columns) + ')',
                       [row[c] for c in columns])

def seed_seller(site):
    # Disposable database only; real helper/model getLatest uses this exact schema.
    with sqlite3.connect(site/'database/flipandstrip.db') as db:
        db.execute("CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    store_name TEXT NOT NULL UNIQUE,\n    seller_name TEXT,\n    feedback_score INTEGER,\n    positive_feedback_percent REAL,\n    store_url TEXT,\n    last_fetched_at TEXT,\n    last_error TEXT,\n    created_at TEXT DEFAULT (datetime('now')),\n    updated_at TEXT DEFAULT (datetime('now'))\n);")
        if db.execute('SELECT count(*) FROM ebay_seller_rating_cache').fetchone()[0]:
            raise ValueError('Expected empty synthetic seller cache')
        db.execute("INSERT INTO ebay_seller_rating_cache(store_name,seller_name,feedback_score,positive_feedback_percent,store_url,last_fetched_at) VALUES(?,?,?,?,?,?)",
                   ('synthetic-heading-store','Synthetic Heading Seller',123,99.8,'https://example.invalid/synthetic-seller','2026-09-30'))

def restore_database(seed_path, database):
    with sqlite3.connect(seed_path) as source, sqlite3.connect(database) as target:
        source.backup(target)

def reset_ephemeral_state(base):
    # No requests/browser contexts remain active between variant subprocesses.
    # These paths are created by run.py inside its disposable TemporaryDirectory.
    for name in ['security.sqlite','growth.sqlite']:
        for suffix in ['', '-wal', '-shm', '-journal']:
            path=base/(name+suffix)
            if path.is_symlink(): raise ValueError('Unexpected fixture state symlink')
            path.unlink(missing_ok=True)
    for path in (base/'sessions').iterdir():
        if path.is_symlink() or not path.is_file(): raise ValueError('Unexpected session fixture entry')
        path.unlink()

def php_diagnostics(text):
    return [line for line in text.splitlines() if re.search(
        r'(?:PHP\s+(?:Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught\s|SQLSTATE\[)',line)]

def run_heading_variants(root, site, origin, assets, output):
    suite = root / 'tests/keyboard-browser'
    manifest = json.loads((suite / 'heading-sources.json').read_text())
    baseline = decode_manifest(manifest)
    candidate = {p: (site / p).read_bytes() for p in EXPECTED_PATHS}
    verify_candidate(manifest, candidate)  # Fail closed before any heading browser starts.
    restored = cart_restoration(candidate, baseline)
    heading_output = output / 'headings'
    heading_output.mkdir()
    reports = {}; failures = []
    seed_recommendations(site)
    seed_seller(site)
    # Reuse the exact same initial synthetic DB for every variant, so read-side
    # setup and session-local data cannot bias the presentation comparison.
    database = site / 'database/flipandstrip.db'
    seed_path = heading_output/'synthetic-seed.sqlite'
    with sqlite3.connect(database) as source, sqlite3.connect(seed_path) as target:
        source.backup(target)
    seed = seed_path.read_bytes()
    identity = {'base': EXPECTED_BASE, 'manifest_sha256': sha256((suite/'heading-sources.json').read_bytes()),
                'source_patch_sha256': manifest['source_patch_sha256'],
                'candidate_sha256': {p: sha256(b) for p,b in candidate.items()},
                'baseline_sha256': {p: sha256(b) for p,b in baseline.items()},
                'synthetic_seed_sha256': sha256(seed),
                'checkout':verify_checkout(root),
                'test_sha256': {str(p.relative_to(root)): sha256(p.read_bytes()) for p in suite.iterdir() if p.is_file()},
                'fixture_sql_sha256':sha256((root/'tests/fixtures/security-catalog.sql').read_bytes()),
                'fixture_config_sha256':sha256((site/'src/config/config.php').read_bytes()),
                'router_sha256':sha256((site.parent/'router.php').read_bytes()),
                'presentation_manifest_sha256':sha256((assets/'manifest.json').read_bytes()),
                'state_reset':'Identical inventory via SQLite backup; fresh synthetic security/growth stores and isolated PHP sessions for each variant; no active browser between variants.',
                'scope': 'synthetic local PHP rendering only; no deployment/provider acceptance'}
    try:
        for variant, source in [('candidate', candidate), ('baseline', baseline), ('cart-parent-restored', restored), ('residual-restored', residual_restoration(candidate))]:
            for path, data in source.items():
                (site / path).write_bytes(data)
            # SQLite backup API handles journal/WAL state; never copy only a live main DB.
            restore_database(seed_path, database)
            reset_ephemeral_state(site.parent)
            directory = heading_output / variant
            directory.mkdir()
            php_log=output/'php.log'
            log_offset=php_log.stat().st_size
            cache_history=site.parent/'php-runtime.jsonl'
            cache_offset=cache_history.stat().st_size
            result = subprocess.run(['node', str(suite/'heading.cjs'), origin, str(assets), str(directory)], check=False)
            cache_bytes=cache_history.read_bytes()[cache_offset:]
            (directory/'php-runtime.jsonl').write_bytes(cache_bytes)
            cache_receipts=[json.loads(line) for line in cache_bytes.splitlines()]
            if not cache_receipts or any(not r['passed'] for r in cache_receipts) or len({r['pid'] for r in cache_receipts}) != 1:
                failures.append(variant+': missing/invalid same-process cache evidence')
            log_bytes=php_log.read_bytes()[log_offset:]
            (directory/'php.log').write_bytes(log_bytes)
            diagnostics=php_diagnostics(log_bytes.decode(errors='replace'))
            if diagnostics: failures.append(variant+': PHP diagnostics; inspect variant php.log')
            if result.returncode:
                failures.append(variant + ': browser suite infrastructure failed')
            report = directory/'report.json'
            if report.is_file():
                reports[variant] = json.loads(report.read_text())
    finally:
        for path, data in candidate.items():
            (site/path).write_bytes(data)
        verify_candidate(manifest, {p: (site/p).read_bytes() for p in EXPECTED_PATHS})
    failures += evaluate_reports(reports)
    (heading_output/'acceptance.json').write_text(json.dumps({**identity, 'passed':not failures,
        'failures':failures, 'expected_negative_controls':VARIANTS[1:],
        'candidate_restored':True}, indent=2)+'\n')
    return int(bool(failures))
