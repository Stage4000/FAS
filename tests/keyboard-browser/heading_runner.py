"""Invoked only by the existing loopback-isolated runner; never a standalone server."""
from pathlib import Path
import base64, gzip, hashlib, json, re, sqlite3, subprocess
import storage_identity
import malformed_identity
import email_restore_identity

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
REVIEWED_OVERLAY = {'tests/product-share-fallback-test.cjs': {'mode':'100644','type':'blob','sha':'f208bed7648dcf2a93dfd48b59b3f4b80df1fce2'},'.github/workflows/admin-product-acceptance.yml': {'mode': '100644',
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
                                               'sha': '701c31c3632810cab4feda26b60094f31efdc9d0'},
 'tests/admin-product-browser/run.py': {'mode': '100644',
                                        'type': 'blob',
                                        'sha': '71a58d8ddb50a9676ff81a517f3d8f9990fb5317'},
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


QUANTITY_BLOBS = {'public/js/main.js': {'mode': '100644', 'type': 'blob', 'sha': 'f35622459159d2c83ca00aab54083ebf1388788a'}, 'tests/cart-quantity-validity-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '94d46849d5971eccde272e9ab44256075442a58b'}, 'tests/keyboard-browser/README.md': {'mode': '100644', 'type': 'blob', 'sha': 'c8e8cfcb9d8a0eca25aa4b46ed50ce7f4e6bb78e'}, 'tests/keyboard-browser/quantity-rendering-unit-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'b8652474f4d77a112ffe2e72c85848875c57aeac'}, 'tests/keyboard-browser/quantity.cjs': {'mode': '100644', 'type': 'blob', 'sha': '74f88bb0b5ee7b68beb14bc6b3c986ac16222d37'}, 'tests/keyboard-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '0229703984f8a53a8657ac01ee202ddc08c3ce6b'}}
STORAGE_BLOBS = {'public/js/main.js': {'mode': '100644', 'type': 'blob', 'sha': 'c2d8de237a122838770bd16463638555757146dd'}, 'cart.php': {'mode': '100644', 'type': 'blob', 'sha': '0082e0fcabbc1be3678235e2a7e9c18585e419cb'}, 'tests/keyboard-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': 'a86338d370ab325d24773748d41a36eb79b68719'}, 'tests/keyboard-browser/quantity.cjs': {'mode': '100644', 'type': 'blob', 'sha': '600ab0539566ea32e7870af6fd83cbd9ecf831a2'}, 'tests/keyboard-browser/storage_identity.py': {'mode': '100644', 'type': 'blob', 'sha': '2073f45b92befae1838e2bea78624048da53e6ab'}, 'tests/keyboard-browser/storage-sources.json': {'mode': '100644', 'type': 'blob', 'sha': 'a42fb5fb4d461878d7d3f6980379f8070541bfaa'}, 'tests/keyboard-browser/storage-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': '23da43ac612305e7b7d2137a5b9ee02fd38e5f85'}, 'tests/keyboard-browser/storage-quantity-identity-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'f198236dccb32c3e1575114a9e3c542c52d02e8a'}, 'tests/keyboard-browser/storage-unavailable.cjs': {'mode': '100644', 'type': 'blob', 'sha': '8f148c9c6fae05590ccc3c0b8c1e9d3c83c01cd9'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': 'c904cc011a56749c79568ace1911bf728ebeaefe'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '9b549651f4bf10cd59fb87027e070f3cc6528ccf'}}
MALFORMED_BLOBS = {'public/js/main.js': {'mode': '100644', 'type': 'blob', 'sha': 'b88352baedbd514f2cc8241ea74a17d02fa38c7e'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '8b716da3224ef96940b8efbf17291b016560efa6'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '1eb5edc576caac8288e3629cb53f8a89b93449d5'}, 'tests/cart-malformed-hydration-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '261efab97ff17f698714f631b3a2a580eccbb23c'}, 'tests/keyboard-browser/malformed-hydration.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'ca3b01bd0e7b5ce929aa11cf6ef067288e963f8d'}, 'tests/keyboard-browser/malformed-identity-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '83bf9fef0d91630a9372f4ed1f6087c57c20a420'}, 'tests/keyboard-browser/malformed-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': '42674d548f9c3fe0e2dc588f511d30cdeb157c4d'}, 'tests/keyboard-browser/malformed-identity.cjs': {'mode': '100644', 'type': 'blob', 'sha': '52cd30143a58da510a1e8e740b91c167b35e5525'}, 'tests/keyboard-browser/malformed-sources.json': {'mode': '100644', 'type': 'blob', 'sha': '0328877491f41f0b253876a1553f44d782dab893'}, 'tests/keyboard-browser/malformed_identity.py': {'mode': '100644', 'type': 'blob', 'sha': '2e19b7b6e54fa22a3db1e419c00c29626c22a12e'}, 'tests/keyboard-browser/quantity.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'd2f7b5d3a01b25695b826100005dfa7bd20c0942'}, 'tests/keyboard-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': 'b8259116762a34f2dd2de3cba0087214c13e579b'}, 'tests/keyboard-browser/storage-unavailable.cjs': {'mode': '100644', 'type': 'blob', 'sha': '745b825c1e77f325a1e7bced9652de5b4a56b1c9'}, 'tests/keyboard-browser/storage_identity.py': {'mode': '100644', 'type': 'blob', 'sha': '8658e70e1e668153dad9d7b3587b582f60334ddc'}}
EMAIL_BLOBS = {'public/js/email-action.js': {'mode': '100644', 'type': 'blob', 'sha': 'c21e32786ff2f352cd79d3038aadac652429c31f'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '1727f1dd41307f304f08b6b579e61c9fdb41b3d4'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '657e0be65b2e1a90e5fbe1402e2372c9974278fc'}, 'tests/email-action-lifecycle-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '3f207f98d0dda2032e63e4088853fb15a7ed76d3'}, 'tests/email-restore-preservation-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '5ddf103c9e7a3d6704bc2ee8bfc0423f6f057488'}, 'tests/growth-client-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '8f9c814a29dfc4678b17482415776e9bedde292f'}, 'tests/keyboard-browser/email-restore-guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '8930c18894174155c3bb60d2ee702bc48ef4e32b'}, 'tests/keyboard-browser/email-restore-policy-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'f1f230ba429c2b877d71e0e5202708ffcc22883e'}, 'tests/keyboard-browser/email-restore-sources.json': {'mode': '100644', 'type': 'blob', 'sha': '9713e6397410caff7cbe951b3ff28e07a172ebb6'}, 'tests/keyboard-browser/email-restore.cjs': {'mode': '100644', 'type': 'blob', 'sha': '675c6c108c4de4942e4aedbc62c234a37e4627e8'}, 'tests/keyboard-browser/email_restore_identity.py': {'mode': '100644', 'type': 'blob', 'sha': '5ce99622bec9287ad9a6504cf9aafe64baf0cd0c'}, 'tests/keyboard-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '7a26a99e6fe66bcc6b22b91c2c74701cb70760d3'}, 'tests/keyboard-browser/heading-policy.cjs': {'mode': '100644', 'type': 'blob', 'sha': '6af31f0d69a83ad68e55bd9f4f8d1880b2f45efd'}}
MALFORMED_BLOBS.update(EMAIL_BLOBS)
STORAGE_BLOBS.update(MALFORMED_BLOBS)
QUANTITY_BLOBS.update(STORAGE_BLOBS)
REVIEWED_OVERLAY.update(QUANTITY_BLOBS)

# Exact catalog focus test additions; legacy identities and controls stay pinned.
CATALOG_SCROLL_BLOBS = {'tests/keyboard-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '75ad7e455e8dfced062d568eb1defd0bee40241a'}, 'tests/keyboard-browser/README.md': {'mode': '100644', 'type': 'blob', 'sha': '567982a7ebf4a4ef8a42ce5c5c3eb7cd53888f91'}, 'tests/catalog-scroll-offset-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'bd822d1f3cee43f13cec01e151685ad88134da58'}, 'tests/keyboard-browser/catalog-scroll.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'c189ae8cee2b45af2ef9c51d677415415ba39203'}, 'tests/keyboard-browser/catalog-scroll-identity.cjs': {'mode': '100644', 'type': 'blob', 'sha': '42aac464164ce8231fe6ff0e81445a5b2db48bdd'}, 'tests/keyboard-browser/catalog-scroll-policy.cjs': {'mode': '100644', 'type': 'blob', 'sha': '7716c602d7640c58286964940b2f69f8a5150256'}, 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '29c05ea25c44544ffc1fd1653aea83e1e8474b03'}, 'tests/keyboard-browser/catalog-scroll-guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '821bd44f751b11830931ed67b1a34a5157206f10'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': 'f2640f478469406dfc7da988499f7f7324e662a9'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'bc7600987469c3fb34dec0122ada6723c0b0cc78'}}
EMAIL_BLOBS.update(CATALOG_SCROLL_BLOBS)
MALFORMED_BLOBS.update(EMAIL_BLOBS)
STORAGE_BLOBS.update(MALFORMED_BLOBS)
QUANTITY_BLOBS.update(STORAGE_BLOBS)
REVIEWED_OVERLAY.update(QUANTITY_BLOBS)


# Exact reviewed reliability overlay; absent sparse paths retain committed-tree pins.
RELIABILITY_BLOBS = {'admin/banners.php': {'mode': '100644', 'type': 'blob', 'sha': '66eb77cef5e6688a8627d4cdd0476d25c20faca2'}, 'admin/purgestore.php': {'mode': '100644', 'type': 'blob', 'sha': '36f6d450157a9f68ceee971cb5e9da705bd0dbac'}, 'admin/sale.php': {'mode': '100644', 'type': 'blob', 'sha': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6'}, 'admin/stale-inventory.php': {'mode': '100644', 'type': 'blob', 'sha': '484b9a5c65d167b342e7eecc81591adadba43b01'}, 'api/contact-form.php': {'mode': '100644', 'type': 'blob', 'sha': '6f97709f0b1770fc27e136fb20e6066cd12e1b37'}, 'contact.php': {'mode': '100644', 'type': 'blob', 'sha': 'a304e3225c6ed63175811bd2d74cc1308fa69e50'}, 'docs/reviewed-product-facts.md': {'mode': '100644', 'type': 'blob', 'sha': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060'}, 'includes/sale-helper.php': {'mode': '100644', 'type': 'blob', 'sha': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7'}, 'src/models/Banner.php': {'mode': '100644', 'type': 'blob', 'sha': '2adbeafae3c4f1373409949c532520e51619daae'}, 'src/models/Coupon.php': {'mode': '100644', 'type': 'blob', 'sha': 'bb899ad8a612b4c38fd0d18c72339123d57105d8'}, 'src/utils/ReviewedProductFacts.php': {'mode': '100644', 'type': 'blob', 'sha': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'src/utils/ShippingRules.php': {'mode': '100644', 'type': 'blob', 'sha': '758e2adec9069fa5f8684597becdefaea9460a87'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '9f90d9b0c8b2e1e097af954127e5ca93c65ea0f3'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '8edde3f82ef9803a03cd483e2d10463cc93c5fc2'}, 'tests/rotor-reviewed-facts-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ee9ce4400c99262ae33857245350e2806649a94e'}, 'tests/purgestore-csrf-test.py': {'mode': '100644', 'type': 'blob', 'sha': '48dfc923f4f666cceb48e74e2384fbdb497a0966'}, 'tests/free-shipping-destination-api-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'd283aed369602f9015f833f3262ca9c40c3e2cd5'}, 'tests/contact-pending-edits-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'e20affefa282fe0bee690033cacf828fec54221a'}, 'tests/sale-schedule-test.py': {'mode': '100644', 'type': 'blob', 'sha': '8dd204600f8bf213974353b07a837793dabd0f15'}, 'tests/stale-inventory-csrf-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'b8026c225f21bd37615fc9ef939c96dfdfc81c63'}, 'tests/banner-schedule-timezone-test.php': {'mode': '100644', 'type': 'blob', 'sha': '18cd1384c0a26ca280a695ecbbb34e78583942e6'}, 'tests/banner-csrf-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'd7ae700887e1daaf0fc57518f4bdd24606024c33'}, 'tests/campaign-code-case-test.php': {'mode': '100644', 'type': 'blob', 'sha': '08007f693916900c8b72ebb5718fa7d7c825565b'}, 'tests/coupon-expiry-timezone-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ffd444d00624e127638180aebc857bd2a1c1e28c'}, 'tests/contact-delivery-test.py': {'mode': '100644', 'type': 'blob', 'sha': '2e4e160cf6c62d4eb3eec8b8668fe88724f1722c'}, 'tests/contact-retry-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'dccea24eed65314386728bdf9c16937801830347'}, 'tests/free-shipping-destination-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'cb80e4cdda5d7a9bcd245b86ab7ed5bd40fb52ff'}, 'tests/banner-input-validation-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '6c8e5274bce727af794fdfbf21d7f17afa995139'}, 'tests/sale-schedule-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '1c39b832510e4f399cf6c2809b96df5233fe5cab'}, 'tests/contact-provider-response-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '1b1254fccf75ea69bc495cd9331bde8edd4be3cd'}, 'public/js/growth.js': {'mode': '100644', 'type': 'blob', 'sha': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692'}, 'tests/newsletter-pending-edits-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'b7d6b6cee085eb4b515615014e2b0c99ac82c299'}, 'tests/keyboard-browser/email-restore.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'ac21fdaafba504551fd4faaaa741f2b7d245f884'}}
CATALOG_SCROLL_BLOBS.update({path: entry for path, entry in RELIABILITY_BLOBS.items() if path not in ('admin/banners.php', 'admin/purgestore.php', 'admin/sale.php', 'admin/stale-inventory.php', 'api/contact-form.php', 'docs/reviewed-product-facts.md')})
EMAIL_BLOBS.update(RELIABILITY_BLOBS)
MALFORMED_BLOBS.update(EMAIL_BLOBS)
STORAGE_BLOBS.update(MALFORMED_BLOBS)
QUANTITY_BLOBS.update(STORAGE_BLOBS)
REVIEWED_OVERLAY.update(QUANTITY_BLOBS)

# Normalize only the exact reviewed, non-heading clipboard correction for the
# historical heading identity controls. Runtime copies retain the actual fix.
SHARE_FIXED_SHA256 = '687bebc45b6088379e3dc845c6eed1488dcd518f5e583259dda7060fec3acc88'
SHARE_PREIMAGE_SHA256 = 'e0283b86a81c7c5631f556f7671abe5ae838e7e475772990ea39b437dae8af03'
SHARE_OLD = b"                document.execCommand('copy');"
SHARE_NEW = b"                if (!document.execCommand('copy')) {\n                    throw new Error('Clipboard copy was not accepted');\n                }"

# The sole runtime delta is normalized only for historical heading identity.
CATALOG_SCROLL_BEFORE_SHA256 = 'a93225a27b120a97b80fcedb5537d1c033e1214024388721096b3ac6105682b5'
CATALOG_SCROLL_AFTER_SHA256 = 'e95cade954370b5b003f4d7d863135c4920eb81ef8b582fb76c8c22d955c6ca3'
CATALOG_SCROLL_OLD = b'#main-content { scroll-margin-top: 6rem; }'
CATALOG_SCROLL_NEW = b'#main-content, #productsContent { scroll-margin-top: 6rem; }'

def restore_pre_catalog_scroll(data):
    if sha256(data) != CATALOG_SCROLL_AFTER_SHA256 or data.count(CATALOG_SCROLL_NEW) != 1:
        raise ValueError('Exact reviewed catalog CSS required')
    restored = data.replace(CATALOG_SCROLL_NEW, CATALOG_SCROLL_OLD, 1)
    if sha256(restored) != CATALOG_SCROLL_BEFORE_SHA256:
        raise ValueError('Catalog CSS restoration must equal exact pinned preimage')
    return restored

def heading_identity_bytes(path, data):
    data = storage_identity.restore(path, data)
    if path == 'public/css/style.css' and sha256(data) == CATALOG_SCROLL_AFTER_SHA256:
        return restore_pre_catalog_scroll(data)
    if path != 'product.php' or sha256(data) != SHARE_FIXED_SHA256:
        return data
    if data.count(SHARE_NEW) != 1:
        raise ValueError('Exact reviewed clipboard guard required')
    restored = data.replace(SHARE_NEW, SHARE_OLD)
    if sha256(restored) != SHARE_PREIMAGE_SHA256:
        raise ValueError('Clipboard restoration must equal exact preimage')
    return restored

QUANTITY_BEFORE_SHA256 = 'da8fca43f1e10c9876b3aa6dcb6ae1ff68d927c76c719f3462629f1c62f142a8'
QUANTITY_AFTER_SHA256 = 'cc932f5f15e3dc0e0825ba326bae26273a404091a3d81a90249ccc78f4d06167'
QUANTITY_GUARD = b'            if (!Number.isFinite(newQuantity)) {\n                return;\n            }\n'

def restore_pre_quantity_main(data):
    if sha256(data) != QUANTITY_AFTER_SHA256 or data.count(QUANTITY_GUARD) != 1:
        raise ValueError('Exact reviewed quantity guard required')
    restored = data.replace(QUANTITY_GUARD, b'')
    if sha256(restored) != QUANTITY_BEFORE_SHA256:
        raise ValueError('Quantity restoration must equal exact pinned preimage')
    return restored

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
        if path in INTEGRATION_PATHS and path not in QUANTITY_BLOBS:
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
    email_restore_identity.verify_runtime(root)
    email_restore_identity.verify_fixture_contract((root/"tests/keyboard-browser/run.py").read_bytes())
    malformed_identity.verify_runtime(root)
    storage_identity.verify_runtime(root)
    restore_pre_quantity_main(storage_identity.restore("public/js/main.js", (root/"public/js/main.js").read_bytes(), strict=True))
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
    copied += [root/p for p in ['tests/catalog-scroll-offset-test.cjs','recover-cart.php', 'tests/email-action-lifecycle-test.cjs', 'tests/growth-client-test.cjs', 'tests/email-restore-preservation-test.cjs','tests/cart-malformed-hydration-test.cjs','checkout.php','public/js/shipping-estimator.js','tests/fixtures/security-catalog.sql','tests/homepage-visual/prepare-assets.py',
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
        if entry['path'] == 'public/css/style.css' and entry['candidate_sha256'] == CATALOG_SCROLL_BEFORE_SHA256 and sha256(candidate[entry['path']]) != CATALOG_SCROLL_AFTER_SHA256:
            raise ValueError('Exact reviewed catalog CSS candidate required')
        if entry['path'] == 'product.php' and entry['candidate_sha256'] == SHARE_PREIMAGE_SHA256 and sha256(candidate['product.php']) != SHARE_FIXED_SHA256:
            raise ValueError('Exact reviewed clipboard candidate required')
        if sha256(heading_identity_bytes(entry['path'], candidate[entry['path']])) != entry['candidate_sha256']:
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

# Exact reviewed GBP addition only. Historical negative-control bytes stay unchanged.
GBP_FOOTER_BEFORE_SHA256 = 'ff410cfc1ba336ddd0e363c2fe11ab491780e009ae942a69bfe90e4e2e56473a'
GBP_FOOTER_AFTER_SHA256 = '6033339dccfe08e4117c25df9b567923c8baa3822285d1b65dd1a8b395bbf697'
GBP_FOOTER_LINE = b'            <p><a href="https://www.google.com/maps/place/Flip+and+Strip+Parts/data=!4m2!3m1!1s0x0:0xacc822ae250ccdb1" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none">Find us on Google</a></p>\n'

def restore_pre_gbp_footer(data):
    if sha256(data) != GBP_FOOTER_AFTER_SHA256 or data.count(GBP_FOOTER_LINE) != 1:
        raise ValueError('Exact GBP footer candidate required')
    restored = data.replace(GBP_FOOTER_LINE, b'', 1)
    if sha256(restored) != GBP_FOOTER_BEFORE_SHA256:
        raise ValueError('Exact pre-GBP footer required')
    return restored

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
        # Strip only the pinned addition before exact historical blob checks.
        restored['includes/footer.php'] = restore_pre_gbp_footer(restored['includes/footer.php'])
        base=json.loads((Path(__file__).parent/'heading-base-tree.json').read_text())
        released={e['path']:e['sha'] for e in base['entries']}
        for path,data in restored.items():
            data = heading_identity_bytes(path, data)
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
    runtime_candidate = {p: (site / p).read_bytes() for p in EXPECTED_PATHS}
    candidate = {p: storage_identity.restore(p, data) for p, data in runtime_candidate.items()}
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
                'storage_runtime_sha256': {p: sha256(b) for p,b in runtime_candidate.items()},
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
        for path, data in runtime_candidate.items():
            (site/path).write_bytes(data)
        verify_candidate(manifest, {p: (site/p).read_bytes() for p in EXPECTED_PATHS})
    failures += evaluate_reports(reports)
    (heading_output/'acceptance.json').write_text(json.dumps({**identity, 'passed':not failures,
        'failures':failures, 'expected_negative_controls':VARIANTS[1:],
        'candidate_restored':True}, indent=2)+'\n')
    return int(bool(failures))
