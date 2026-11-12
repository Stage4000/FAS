"""Exact gallery delta must restore the prior source before historical heading checks."""
from pathlib import Path
import sys, json, copy
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tests/keyboard-browser'))
import heading_runner as h
checks = 0
def check(ok):
    global checks
    assert ok
    checks += 1
def rejected(fn):
    try: fn()
    except (ValueError, KeyError): check(True)
    else: raise AssertionError('Unexpected source accepted')
product = (ROOT / 'product.php').read_bytes()
manifest = json.loads((ROOT / 'tests/keyboard-browser/heading-sources.json').read_text())
manifest['files'] = [e for e in manifest['files'] if e['path'] == 'product.php']
previous = h.restore_pre_gallery_selection(product)
check(h.sha256(previous) == h.SHARE_FIXED_SHA256 == h.GALLERY_PREIMAGE_SHA256)
check(h.heading_identity_bytes('product.php', product) == h.heading_identity_bytes('product.php', previous))
check(h.heading_identity_bytes('other.php', product) == product)
h.verify_candidate(manifest, {'product.php': product}); check(True)
mutations = [previous, b'', product + b'changed', product.replace(b'$activeImageIndex ?', b'0 ?', 1),
             product.replace(b'array_search($mainImage, $images, true)', b'array_search($mainImage, $images, false)'),
             product.replace(b'<h2', b'<h4', 1), product + h.GALLERY_INDEX_DECLARATION]
for mutation in mutations:
    rejected(lambda: h.restore_pre_gallery_selection(mutation))
    rejected(lambda: h.verify_candidate(manifest, {'product.php': mutation}))
rejected(lambda: h.verify_candidate(manifest, {}))
old = h.GALLERY_PREIMAGE_SHA256
try:
    h.GALLERY_PREIMAGE_SHA256 = '0' * 64
    rejected(lambda: h.restore_pre_gallery_selection(product))
finally: h.GALLERY_PREIMAGE_SHA256 = old
old = h.GALLERY_INDEX_DECLARATION
try:
    h.GALLERY_INDEX_DECLARATION = b'missing'
    rejected(lambda: h.restore_pre_gallery_selection(product))
finally: h.GALLERY_INDEX_DECLARATION = old
print(f'PASS {checks} exact gallery identity checks')
