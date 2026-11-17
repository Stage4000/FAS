"""Exact manuals overlay and finite corrections; no network or Git ancestry lookup.

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
INTEGRATED_MAIN = '73ad3d987a971ee99c36b6aeaddd9cf1ae0684e5'
INTEGRATED_MAIN_TREE = 'b1626db71e9f668b3cdd13a2a41bcf74fa452f10'
MANIFEST = 'tests/manuals/browser/source-manifest.json'
INVENTORY = 'tests/manuals/browser/reviewed-repository.json'
# Exact finite names. A/M is relative to main, not the b99 snapshot.
COMPATIBILITY = {
    # Exact checkout discount row presentation and lifecycle regression.
    'checkout.php': 'M',
    'tests/checkout-coupon-lifecycle-test.cjs': 'A',
    # Reviewed banner UTC input and countdown presentation.
    'admin/banners.php': 'M',
    'includes/header.php': 'M',
    'src/utils/Timezone.php': 'M',
    # Exact reminder generation runtime and disposable regression overlay.
    'src/marketing/Growth.php': 'M',
    'tests/growth-generation-test.py': 'A',
    'tests/fixtures/growth-generation/race.php': 'A',
    'tests/fixtures/growth-generation/same-second.php': 'A',
    'tests/fixtures/growth-generation/payload.php': 'A',
    'tests/fixtures/growth-generation/legacy.php': 'A',
    'tests/fixtures/growth-generation/tokens.php': 'A',
    '.github/workflows/admin-product-acceptance.yml': 'M',
    '.github/workflows/keyboard-browser.yml': 'M',
    '.github/workflows/manual-reader-browser.yml': 'A',
    'tests/admin-product-browser/run.py': 'M',
    'tests/admin-product-browser/guard-test.py': 'M',
    'tests/keyboard-browser/heading_runner.py': 'M',
    'tests/keyboard-browser/catalog-scroll-guard-test.py': 'M',
    MANIFEST: 'A',
    INVENTORY: 'A',
    'tests/manuals/browser/fullsite-pins.json': 'A',
    'tests/manuals/browser/prepare-fullsite.py': 'A',
    'tests/manuals/browser/fullsite-check.cjs': 'A',
    'tests/manuals/browser/reviewed_repository.py': 'A',
    'tests/manuals/browser/repository-compatibility-test.py': 'A',
    'docs/manuals-whole-repository-gates.md': 'A',
    # Finite reviewed motion/geometry corrections; all other b99 runtime pins stay immutable.
    'includes/manual-reader.php': 'A',
    'public/js/manual-reader-loader.mjs': 'A',
    'public/js/manual-reader.mjs': 'A',
    'tests/manuals/reader-canvas-lifecycle-test.cjs': 'A',
    'tests/manuals/browser/check.cjs': 'A',
    'docs/manuals-motion-interruption.md': 'A',
    'docs/manuals-resize-navigation.md': 'A',
}


# Finite reviewed showcase additions. Sparse exclusion is not a source exemption:
# the entire committed tree and all materialized bytes remain verified.
SHOWCASE_ADDITIONS = {
    ".github/workflows/showcase-browser-acceptance.yml": "fc4819ad087bded6ed64ed48dcbf107e878023d4",
    "admin/showcase-detail.php": "e3e0660dd7885224cfcc5c2d8e8bbca6a7c69c2f",
    "admin/showcase-moderate.php": "f20c022785a75e6c88b14bc13bfb0f37978ac8c7",
    "admin/showcase-photo.php": "aedf3092e05da131569208dccfbde0330ceadd7e",
    "admin/showcase-queue.php": "fca6c5004225f27ae9fedd4b296a369c623d37dc",
    "docs/customer-showcase-foundation.md": "9a050fc443ee4d4ee79cf53ba6bc42bc3eda3657",
    "docs/showcase-admin-app-integration.md": "ac4ac824ac94910e80e602a135bea1bb0f74e4d9",
    "docs/showcase-admin-controller.md": "b6d64be901333f9b2c23ce39924cf1c359334be7",
    "docs/showcase-admin-entry.md": "2865f31d095f572767209c97361afc8f4e5518a1",
    "docs/showcase-admin-history-privacy.md": "49233def47db935661ea632788e35f811e46cef5",
    "docs/showcase-admin-runtime.md": "dafe104f3496bcd751bf2b94e767313f3f05c412",
    "docs/showcase-concurrency.md": "100a57a3364589385471d21d8f08b272020e1141",
    "docs/showcase-controller-integration.md": "362cd6f97e00fd69cd693c2c05b82e2b562bc3b6",
    "docs/showcase-gallery-integration.md": "7ec2366f43e5274992cab3c83db42803c77c9876",
    "docs/showcase-intake-application.md": "f57b5bc5b1b1e65cbc9a1daa54f58a6eafe2880d",
    "docs/showcase-intake-revocation.md": "2aa4817de5e80e73e4fbd838e4423dab0f1f3c98",
    "docs/showcase-intake-runtime.md": "2093ca9f0578992bcb24322b1590520a9fd1c6cc",
    "docs/showcase-private-storage.md": "fa64cdb3f56ecee0c428593443b90ac7ddb62aab",
    "docs/showcase-public-controller.md": "018f5fdd964193c7137111c8f2321c43a250ad30",
    "docs/showcase-public-entry.md": "a94a82f2fbff86dcb040cad77ea4fa4a87e40a04",
    "docs/showcase-public-runtime.md": "02b4fa11619965cf6a8acc96f526e0319eb5a650",
    "docs/showcase-rate-admission.md": "5acab5f1cde0abe17af59033a3476712167559ea",
    "docs/showcase-request-integration.md": "1134f59a3f7ee05204b70ef25e49b171e8aaeb4f",
    "docs/showcase-root-route.md": "5d2dc22fc19e43f00c04fd4ca0784f0edc6e671a",
    "docs/showcase-session-integration.md": "1d422bc30773c667c7d13f17615d8ccd4a69ae13",
    "showcase-gallery.php": "9642d4f345ce5f074f8c4d15d6811fee532a0165",
    "showcase-photo.php": "5d83ab87e46ee7df8ea4b4fb8b81b226dffe4acb",
    "showcase.php": "6324d874e18f8a3c9276af822ddc88655c9c95fd",
    "src/showcase/ShowcaseAdminAuthorization.php": "5837374c064563e279551e341f2928481eecdab7",
    "src/showcase/ShowcaseAdminController.php": "2b1002f587be191fa4b6fafb4cebe03f2a01178d",
    "src/showcase/ShowcaseAdminRuntime.php": "3041ef34bbce05134ededdd47a18959c11eccc2d",
    "src/showcase/ShowcaseConcurrency.php": "a523b206945740c641362bc415c45e98144a54cd",
    "src/showcase/ShowcaseForm.php": "83efa24645c830eda3318231212b5ccf7f5f19dc",
    "src/showcase/ShowcaseIntakeApplication.php": "a43dbf83c077e4d6f637dc2a45cf6ff7624b015e",
    "src/showcase/ShowcaseIntakeController.php": "703aa12bdb4a0d33bc116ea6fd8c49319286dee8",
    "src/showcase/ShowcaseIntakeRuntime.php": "87e9322995371480d59e6a0a24cd6a646e39d259",
    "src/showcase/ShowcasePhoto.php": "3f853e102f1e076bc230d0e2dca6fe421d0d64f5",
    "src/showcase/ShowcasePrivateStorage.php": "c44232b3d534c8fbf72a5062954068e8cdd370d8",
    "src/showcase/ShowcasePublicController.php": "767ce1cc768d8d58c44119d5e5d422daf7890024",
    "src/showcase/ShowcasePublicRuntime.php": "a49b4dc7cf64413a74246f6285d9fe0340f35591",
    "src/showcase/ShowcaseRateAdmission.php": "65a16c253b704708fe29f5df42ecead9b25bd15a",
    "src/showcase/ShowcaseRepository.php": "3e8071604930961a468072b78534456b5701d87d",
    "src/showcase/ShowcaseRequest.php": "31fac09cf1d06a6a67e6481dc526d68116a06236",
    "src/showcase/ShowcaseSessionForms.php": "0aea12fbcaf9c67d411e07a2c00dfd0589c7f5e4",
    "src/showcase/ShowcaseSubmission.php": "8656cf609a4de7f9e199fac006f2e8e2605e894c",
    "src/showcase/ShowcaseViews.php": "f9d2518c8c6632c76f0e0bd93e542ea5958e89d2",
    "src/showcase/admin-entry.php": "057507428020bce8add171efd22ef612d3fc7b11",
    "src/showcase/public-entry.php": "664e3ebf79e3ebd9ae6592a0bf9859501e4aa116",
    "tests/fixtures/README.txt": "21ffc13b9129914961ab189ae58f9e518cf3654e",
    "tests/fixtures/admin-auth-main.php": "f387c7c6657d247e0709951b17f5da45f160aa74",
    "tests/showcase-admin-authorization-test.php": "3d5e2584562d634f978c41a00475dd897e29221b",
    "tests/showcase-admin-browser-check.py": "c4226ded4a604fe12313da4b9e2be1b1adc9f8a5",
    "tests/showcase-admin-controller-guard.py": "3132fb5a2231bb190f5987decca26c89e9051a85",
    "tests/showcase-admin-controller-test.php": "ab8c01f97bc5f4ed0c28a264b5532b068568f2ef",
    "tests/showcase-admin-current-app-test.php": "fbb473e9c77301788c7247d0909271d5a7078096",
    "tests/showcase-admin-entry-test.py": "9bd12de17e09434ae27086bc55469e0f27ccaef8",
    "tests/showcase-admin-guard.py": "05325870ca9a1f38dec0f95142122a8d78692006",
    "tests/showcase-admin-http-router.php": "dbfeac586730c20f3b8083aabac741abdc11dff4",
    "tests/showcase-admin-http-test.py": "1b4df03295354ce0ba98a781a2126b23db32827e",
    "tests/showcase-admin-runtime-test.py": "ab71ac93325260c17a2009538015acd954227cb6",
    "tests/showcase-application-guard.py": "7825d99e151ab25e7a1dc5c652577db00fd9404b",
    "tests/showcase-application-http-test.py": "baf09d1879b29b3adbdf953066a56a684145dff8",
    "tests/showcase-application-router.php": "a095d10a44e600d3597415be0f8f7d029b6caa4e",
    "tests/showcase-boundary-test.php": "7cb325c626abf9ebc0ce4289f347c79c058775ae",
    "tests/showcase-concurrency-guard.py": "a3c5484b435be11be708c490c14caccb50918fcd",
    "tests/showcase-concurrency-process-test.py": "bd67022bfb306cc7d5e5d440d06506ec86d9d27d",
    "tests/showcase-concurrency-test.php": "1c71123928fb5672ef6352ed309ccbdfc20af6ec",
    "tests/showcase-controller-guards.py": "d3729fb5418ff6d2be30307c02489babfdc74783",
    "tests/showcase-controller-http-test.py": "db08cbcbd6a7b4a3009632a115579b6155aa92af",
    "tests/showcase-controller-router.php": "6fe244fa658f863c3449bc065dee00f8158045f6",
    "tests/showcase-foundation-test.php": "d4991d94c96427c953c3640110a0836199b51c00",
    "tests/showcase-gallery-guards.py": "8b62f28e863c7699e644d86dde8f490fd59fd75d",
    "tests/showcase-gallery-test.php": "696aeca04b3e934c68d71e9db4d83dcdd533142a",
    "tests/showcase-http-router.php": "3f4e6c5c787a1064210de4187a317702837ac1c2",
    "tests/showcase-http-test.py": "6c5294e418801bb48432ad7095cbb0caf0b69fa4",
    "tests/showcase-intake-revocation-test.php": "2bc3ca742983319fde5646ff680dd94813b15206",
    "tests/showcase-intake-runtime-test.php": "1054056e8978616fca8d0ff1ce4dc79a7b7b7384",
    "tests/showcase-private-storage-guard.py": "5b7459380f8ae0e57823648833e1a4cb2c2ad092",
    "tests/showcase-private-storage-test.php": "4461016145c91b3c159c0497d3b381b2e4fd1191",
    "tests/showcase-public-controller-test.php": "95d31b7f503999cbfe3243e44945709831cc3dcf",
    "tests/showcase-public-entry-test.py": "273d6cce00b1a22262170c03a5de55244e483120",
    "tests/showcase-public-runtime-test.php": "7e3c5325b7c82647d43b37ad79fe2dcf954a5731",
    "tests/showcase-rate-guard.py": "80d694fb7814d7d9924e1702efd4a452f5b5e21b",
    "tests/showcase-rate-test.php": "92398c9ac6d1bae20c12bb85263184d40791197e",
    "tests/showcase-repository-test.php": "50ebb83749778d98c056863a0994d1eb660d4683",
    "tests/showcase-request-test.php": "2eeb2977802a5c27287bdd3a92ec3fb11fb8950f",
    "tests/showcase-root-smoke.py": "099bdf338f864b157c1f1ecd38cc536d3cc48de4",
    "tests/showcase-session-guard.py": "fc40321fcfab476cc4980c669cbc146ce96070d7",
    "tests/showcase-session-http-test.py": "4ad1f8e05ac37ce593977476e9c348e68ff534a7",
    "tests/showcase-session-router.php": "b89b31fb59cfd9d2d5badf2f8931727ca521b16d",
    "tests/showcase-session-test.php": "4d375c3cf0a297433631b5884549ee6b9b20ce64"
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
                               'main', 'additions', 'baseline_manifest',
                               'integrated_main_commit', 'integrated_main_tree', 'integrated_main'}, 'Inventory keys differ')
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
    require((inventory['integrated_main_commit'], inventory['integrated_main_tree'])
            == (INTEGRATED_MAIN, INTEGRATED_MAIN_TREE), 'Integrated main anchors differ')
    integrated = entries_map(inventory['integrated_main'])
    require(tree_hash(integrated) == INTEGRATED_MAIN_TREE, 'Integrated main inventory differs')
    require(set(main) <= set(integrated) and not (set(integrated) & set(additions)),
            'Integrated main must preserve originals and not overlap manuals')
    main_delta = {p: e for p, e in integrated.items() if main.get(p) != e}
    reviewed.update(integrated)
    require(len(SHOWCASE_ADDITIONS) == 91 and not (set(SHOWCASE_ADDITIONS) & set(reviewed)),
            'Showcase additions must be exactly additive')
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
    changes.update({p: 'M' if p in main else 'A' for p in main_delta})
    for path, status in COMPATIBILITY.items():
        require(status == ('M' if path in main else 'A'), 'Compatibility status differs')
        changes[path] = status
    changes.update({p: 'A' for p in SHOWCASE_ADDITIONS})
    require(manifest['allowed_changes'] == changes, 'Exact changed path/status map required')
    sources = manifest['source_blobs']
    require(set(sources) == (set(original['source_blobs']) | set(COMPATIBILITY) | set(SHOWCASE_ADDITIONS)) - {MANIFEST},
            'Exact source pin keys required')
    for path, oid in sources.items():
        require(isinstance(oid, str) and re.fullmatch('[0-9a-f]{40}', oid), 'Invalid source pin')
        if path in SHOWCASE_ADDITIONS:
            require(oid == SHOWCASE_ADDITIONS[path], 'Exact showcase source pin mismatch: ' + path)
        elif path not in COMPATIBILITY:
            expected = integrated[path]['sha'] if path in main_delta else original['source_blobs'][path]
            require(oid == expected == reviewed[path]['sha'],
                    'Immutable source pin changed: ' + path)
    for path in COMPATIBILITY:
        data = read_regular(root, path)
        oid = blob(data)
        require(path == MANIFEST or oid == sources[path], 'Compatibility source pin mismatch: ' + path)
        reviewed[path] = {'mode': '100644', 'type': 'blob', 'sha': oid}
    reviewed.update({p: {'mode': '100644', 'type': 'blob', 'sha': oid}
                     for p, oid in SHOWCASE_ADDITIONS.items()})
    return {'expected': reviewed, 'changes': changes, 'main': main,
            'historical_main': {p: {'before': before, 'after': main[p]['sha']}
                                for p, before in HISTORICAL_MAIN.items()},
            'overlay': {p: reviewed[p] for p in set(additions) | set(COMPATIBILITY) | set(SHOWCASE_ADDITIONS) | set(HISTORICAL_MAIN) | set(main_delta)}}


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

