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


# Exact reviewed catalog overlay; absent sparse paths retain committed-tree pins.
CATALOG_REVIEW_BLOBS = {'docs/reviewed-product-facts.md': {'mode': '100644', 'type': 'blob', 'sha': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060'}, 'products.php': {'mode': '100644', 'type': 'blob', 'sha': '35571501a7a828d3f53cc9f30ccc7d79632d08a4'}, 'src/utils/ReviewedProductFacts.php': {'mode': '100644', 'type': 'blob', 'sha': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '261ac04ab55a2bfda0d88ada196c793f789849f5'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '8b92e02b00d8fabb6d96bc86b6ee69d2338f1b52'}, 'tests/catalog-loader-lifecycle-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '44244918933b5bd8220c5c202403b2a8742f8a50'}, 'tests/rotor-reviewed-facts-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ee9ce4400c99262ae33857245350e2806649a94e'}, 'tests/catalog-focus-restoration-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'ea5b7eaf6e5ff513ccf8b82827bdfacc45a5de0f'}, 'tests/catalog-heading-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': '31f2a60d5d4646c54521d0776f7f960be019983c'}, 'tests/saved-search-rendering-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'afb8575f7ed86101a96f510526863c210622941d'}, 'product.php': {'mode': '100644', 'type': 'blob', 'sha': 'e57d1b3aad20283525d7bb159bc9a9d55563b659'}, 'tests/product-gallery-selection-test.php': {'mode': '100644', 'type': 'blob', 'sha': '6fb8ea818e9cb3ce6e3653d303c9e0874d8068a2'}, 'tests/keyboard-browser/heading-unit-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'ba6a66467fb54ab388a7b517308838e900cd50b6'}, 'tests/product-gallery-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': '2a27ceb5ed77bb36b8b88d4594836a5e914fbf4d'}, 'api/products.php': {'mode': '100644', 'type': 'blob', 'sha': '357aaf382cd2b201e2b0169d20a6f071da21251e'}, 'tests/catalog-zero-search-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'c56d9d6668d3b9684c61c940b7317e94fb3fcb90'}}
CATALOG_SCROLL_BLOBS.update({path: entry for path, entry in CATALOG_REVIEW_BLOBS.items() if path not in ('docs/reviewed-product-facts.md',)})
EMAIL_BLOBS.update(CATALOG_REVIEW_BLOBS)
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

# Exact reviewed catalog source restoration for historical heading controls.
# Both complete input and restored preimage are pinned; no partial edits are ignored.
CATALOG_REVIEWED_SHA256 = '60885c5aa58f3e343f94528e7d8c4fab06b8bc3b2b09400acc5fa19936578c33'
CATALOG_PREIMAGE_SHA256 = '70eaceb74c05cf1d73bd4af201653cdc9692cce99daee2e36832fb0820706d23'
CATALOG_PREIMAGE_GZIP_B64 = 'H4sIAAAAAAACA919a3fjNpLod/0KRNM3lBI9/Egyify6Tqcz8Wy/tu2e3T1OXw8lQRLTFMnwYbc28X+/VQWABEiQomwnO2dzTtoiiUehUKgXCoXjs2gVdWL+a+bF/CYMZpzd3Pxw8e7mho2YM/aCmZ/NeTJO0jDmizgM0qE7m/EkGUE956jzbOYG//D43U/efM6Dt3E4z2Zpwk7Ywk2e2z71+lBJNlupVNPa55+zXu/Zzd9eXF07ySq8u1nRd+cDOztjjtNnJycnzNl3oOnOeMxeh3fMD905m/OIB1Bw5vGEucGczQB+L8g4C8J47foscpecxVgm9oJlGzS4Ph+uuB/xWCKgtk4Sz8bQ38Jbjn9wU3fqJrxNlXU4534ylmPfocZP4ZrjeJ67KV+G8eaVG0UwqDYtZKlXdHnup1f8U9q+3iUPdyi88gisd5nPkx2qbYLZy3C5bId2LwAUxG7qhUEyfjF1N+dvL7bVy6c4EmgYrnk8WwHReEmBxU6WcPbj+eXPz2lif1YTe5R/eEWT8bPEZeV9zSQV5d7jcH82p6L8FRBeeaWjtfh4oSHiZ4kItUZwAXj/zZm3xkUQuekqYWnIeJBkMWfpim9Ykrpxyu68dMXGbBHGsKpmsGqoRtJZZMEMW5aLCdq6wA9voaXeM2yvz37rMPjPW7AeX0fpRr5W7/G/mKdZHDD6cERv7+lfAPFiwbzUwYXLAAk8DqDr9+9eDlQdNxl6Sd5BksZRmIgeBsxZpWk0GY8lb9hjv//OLCUSrciuUElkYBFCUZilzOfuHGaBjQfMnc+hYB142OtnNb3CR6DISt8mTPedAv9RnAX8BSIYp1iSFrC8nhvH7gaZqnoDYIlXt0DVUz8nw4t50p+Ibx0Bz7OF5wPO+Ry48vUHoBl8CSTA3dmK9bQmYRryHjb6aJ7NVp4/B+YKLXwmp1+Vu3bUR+dDP6+B/53Vj8Zae2Adi9HkpBiAgCsvCJB5ScJTvWlvDiABGDhjMJX90idoLch8/6hobuUm/xAQGNLPR66gClUQR60lQoBdaxB9IHR6cx2RioTgNdEM9o8iUYJeHf51Drs3/wCrzQ5iGmcahPcFgqgvSxVYQvk8Svz3y3Ba54gEu3w6MosrKrsWhWRlHSwNOLUCVCVaBZKh23k5NOj64XKI2oBk4TQ6+f51mP4YZkGO7Z3UoCBMhwusLRumeXaTy7yEah01nvt2cK556ipRsx2YFXCbXCCenXY6x3Pvls18N0lOuqjquF7A4+HCz4By1pvh191TglIvFod38i19+Ww4ZJfenE/dWPB9/j2wi2IRsuFQK2105w/95fCQ4Y/1fPgVW0+HX2lNVyq48bz02VZkKAbJpsvh3A1A/rMUhMHwbuWlnM1hbPwT+yVLUm+xGeKQOczMlKd3HPgOyKRlMISC62Q4gw88tnRIna4OVJ+rrxHwve7psadewaTCxA59L0nh9dg71fBxPF4d1LQ5zdIUmLNsZJoGDP4fJmv6A8LCh8mBRperFMYBGAvCgHdZuok4lKa6XebNEQtiUVyB+uNDCTf2XBpqHPpJ8fkVDzL5kX+KQG/hUBfXuHzpu1Pun3RFK6zg3zUooSGUUTBb8Vug7OE8vAtks0ITlz0RduzYGIshWSZ8DDPegg6m4XzDouEeEpjvRqDgoCVgoohwUAOA1h7O5HAZh1nEip+4TJJVEzZwbZwD680Z4ksv+GgsiEoVl62AGZx0lVKZdNkclvpQAXzS7RaDFK+AJKBVDS6k3/Iz2GAk+Y/RemN8tgpZ77NnXMjLfRQO+dOB8XQonlYlNRTfPgNNdxbe8njzHDDMRQdkWDkoDx3s8pY7IAAd54idnTagyko86UquHh2HzU3Q6JBh/5Fjm8BYGsEgUBJYUvladuegMRccaQHiJR2CFQkDLCbkWRoCU1cDRYwdj7GV0xZjhra8xVETYMdj97RTIKgyajGsKgGONQhX6dpPIj7zXB8snTixtEITXV03jUQpCMXARaWnn7fbRJPJT29evXh7/rcXN8/Pr1787c27ixeX1xUQP/QFanN0FLizjD5FYx8aV+swpwbkYOrTY1ZklIDo06mglvJUf07N4rJwX7DGEBjJaNmVbIG9hQ9JR+CgOuYpB0gTDr3HiWXc+PnPGjP21Xq8aH3KkX4P9dilGELtOGOOQn4IVox3C+q3ZaiixJ81WNFb++kFe/KjHO87qsrO5VBqh7yIOR8m0va3DBi/3xTf/5xxG522Hj4oELOPQ3hQStaP0AxTfg32AjQltEZqUYGeOQsGxOs/Z+DYV+vxRjwmYhSDfROwS1eNrtNCIEpDjBsmcn+7MBNNFPao2YAy5vdbSkVUiV7yW+6zfZbL2yaNqKIZNQkJciGTYaTafh/7PbAsAUCHnZwKUIVdjo4AdBD3B+zF66ubf3//5urF5YA5769+HH7rSDmmdK99o1+tFSq2FXj474mUtkKtOTnR4ShpOTaSagNlkm58sCYWaK7ecTQ1Jmwa+vOj7mmb6lUdbgGVuWLKjZqEGEvgrrkjJPR2kmgkfQvJHeh2aQ+tAfbm9cv/wvVx9dPFJfMlYXoJA+kHC5WDod+KOAu1qml+Cp/WvuHPard2bIux3JRajgftm/xDFhesI4Qif33w5GsuL3ZQKXaw49J8iiVa4fn5UpR0cGBZp4cPsJJarrhhGPGg5bI72G3Z7bb8LMvwsNUyPNh9GdYvR+s0FMvx4IHLsWFZHtiW5eHuTf+Zy1O8PsxfH/5Prdq82GGl2OEDF/cTLfKvbYv8UFGXBO4JlnW9U8RdtlzWhw9b1sby3n0RgIEp18FuzKSlF+PRHe7S0U4dyMJ+wrer1FbXoiA9clavM+B53TZOprXr+6caO73zfJ+5UcTdmLmLlMfCH59sgtnxWJRuBs3uXN0Fe3X+2err0iv5aO4tvHK9gD0Xfvptewnfqb2Eb4WXV5l6snp5bwGbf4tb2T+JHYOyiCntetj2J2yAQP/f1G4b7OfL+W6IinXX8MklMG2zlRASl/QbDPsk89OENtMb+b+qLDhjQY3U8NoNsgWwpCyGcf7+O+6IpWtACTnUpOevoe009ta9UisykGbEHIa7zz2jSfm1T9Aob5O5QJrZVxaD4EyVJHsNfExwsXYEuNqHkpFCdbGkcAoP1J5Wk+OVKdJhtGGnl7VHIsFwmfiCvgexx5E3UkgBMNcjY8at4UuFlkAlxfa6aFPsxgBC3TWFP6FcFjuJWUCbu9Vi1w56P1EMdIqNYdsg9K3ZhtaMeKq+vq+tFX6Jm0cAoANd8Ao+aio9x/nCSvqGV8JnYTB3442sdU8kpIcNbAWSUazXNkgvURF9DKTCue/oIRhGJdDHsMq4IItR4ZepDoL2UM6wEAae3Ewz0CZvfs14XFMaaUxOh6KeNnpjGUTTg6/tQuqLoIKLkopTUVv4BmYS3qzYmg/3hQbTGiqaokKLsbvuW+wKbmHOtJUtuO734adai+MYWPEahpGuQpAxS552mSt9h9quHcofwZGHWL67fe/l2AuiLJX7uWL4XYYaXKGtdtmt62d824Ra92ROW28UVZ0ZLeDbbwebalE3Jdjv9HD5/vvLq4ur91cvSrZFBXKLfdcCwoOdIDx4YggPW0B4uBOEh08FoS7Wt0Gpl20HrdH6VlgqekkTLFioHRBGs1uBsEvHLdBoEieHaX+HVUfhFqjm5XrrUPCPnBffekkGCvxGhlB0TyWzUkzneExtNPShMUMaiDA8tu7La2NGXSoHCBmbCizpssh3Z3xF3qeTbgm00WhkqOT50CTu5FObqRRlpYLZJqzArl63shNbmaHPZj60WxHuRztUzvU5jDu0SwijJAW/OXlMCOoIWezzYAb0beP/rUAxOb69v/1yX3l5CXfOj+31D2rqH5TqH9bUP6ypf6jqG9zG0ob+vdyWyak6VX5kaw8/lBsyuU2nSeOutqjxkZN9GX6YuyqLwv2+RnijE6kpeuvIRxCcz52B0XYLGjh7ok24HCy7JlnR67ss9VLcdXqO9ZjkBQ/cb0q9NUaoiaA7bK/zaD9XRVDY4/SE9q/i8ZJsuvZSa5CCHB+BKNhkpwh2q2ncNDFY4t7yuWxpiA+lMEBLt9Mw/Lh2YxUvcAmV9G6F9nw8RpauPQqHDGnGPwqihr8YSyviOqvbyyCfXmnLCChcCwLGjxTBI13sNgcLWw4Pu6edHfT2kgTVZZOUqdLVIoSrvsrFWLqnr9yPnI2ZDvlWYSr8b7Q/YfQpXglpZ+trO0GGEXmbpTDsnmL4G0KYlEAEgS+KqokoNiDK00AbEOvFUuLd7KFZfYNKhksJiyVpPHNhAa8j6RpS4eMGCx2w0kdoSp2uAOmt9nYK78gWp5ACpVMed8lDezwWTRd0/McRE5JzTkXk+RqzCM/ABNl6+lRUpHUiYmjnPJnF3pTPpxvj80/cj7q6oV5ZeIj4uZe4U99E/IOIUpwjM0e8hShFDaJG/PmGij6AKrXK7anTkMsV6tSafBSVlkDbgVojgwRQ1Rah5WUqoGkGppXOVsAUkjCLZ5z2rND3SOhLRuycrbEAvqKqLIz1eWLzEOoGYcroyGG8Zrd85c0w0jtcR27qTT3fSzcj4bA05EFZIHbKoddsGsa4B73HkpU7D+/Qe0S8XZdbPBnKiP6ayO1NRRDI6H38B0O2snUgfvvLIYqPNlH9UFQE9rOlG5Ev+FiMSQvo/0YtcgR63xLYr2Sp2KyRMnnNsTkUrihb51K0q4B/fRi0DWNs9GANMUsDfY4G7CPf3AEuB+IsqoqXUghksFrSlZewOb/1gARwj+DXzJt9VAdf8KQPGF+l6UNQyD2FcCowuzYk38VuJBBVQS4gUsQvm22XFAeilFYHWTy0IsXJFEFTtDuj3Ot/i715SeNQ5wOVKl0169qSZbf9YYKvWhwWkAjUqU4ci0REHu5yekKaujqVLZLhIVunyoXaaOpvkToaxX8lN0Zeg8T4BOJbsA6udj9qj6zkbdXstRy2UHf+AzjOis8+Qo2ZH+IpjYj7oOwuxQHsmPsutgZLdg1c7QrIPwx4/lqgCE94rkNYQy4TR70GyO2mwBQS3HxYrkBirrUlhDulIJbDu9FW8C6CWyB3XHV4tneJi24BC2AAbJeJeF1/g0c3ARbsIAqjzHcFp00AjXgkFrdkQfSwDTBq9Ij8Atx+tGUXNjrdIaZTIOEyWwJ4dHK3TVinRrEtZ6rKw4BPJnztEa+s7LLhVLmliZps2V9uWE8lhtR9WMBcBVWkiyT5845xc62dROTrKbqxbeA9qIlrR4xInJksvrZvUu70VRsWu4YDW59FvLSxA/gEHob6g1BnTVpXdYesDHS/pTdC7nZ1HxT7UuOxLKhr9wC7Py4QZvtKrNH3xAaYYgS6KwLUSdQR6LsQtixGCQIlDkF6HgqZIKK7LBVtapLOYgRfqapjXKlThSom5eNrahdesLsVaIKJt/YK7kznPrjFRkRlcgk6T0WEWxwT/pK8LZqLHCSN51t95Ex/QCKT7m9Zw3Cgv8B3KFhinoBy5mZpiIq5z9OiglSs5rWGLihpWLG7xWmldnjvhvt7e2UPlkThK27xFok/VVOZBDor67motxzIw7IKZZVaQ3qvgKBX0/BTvkkGHJsO5OQbLBoSLpi7xCMgaQjCnTK3uGi5gb6AInqK6RZyk+hH34tIYF+C+QfSVEn5USc32Q1atFA5UESaJXKUODR1bhaI6qQbhWA+cU0/lp61NoLNclhlHQJ0s81MO5tS4xtU/OtVXkPE37TiJpae3fS2bZfnV/9gY/Ye/n1Uj9PQTdt2+T2UfeT4YIEAdvEAYtth5jUe1XO3cTF2TzEuiI6+5irodlf1lui9+s8PDtqza6RBKOPW3jaYZ4k8ClVooV/rR8994d4KwmEs2oK/wALXIIhEfpotdpvFDpOmf52joEEPbranSvaPnncgi/Dclgt2iC7JJNMg98I7NSp0GqCUoQOaIRjESbMdAAYcmvENCNIMPM2lAez3rTRTiH7Z5SpEKBN2DlY6kh1A0JCnoIGKthN53ToWthqQu5XIW0T3CKFda6CX7IAKgYo0KjAJnyj6PS4mRZZotgw0BRC0ElnluRvPe5amMM5eSOevVMTqN/QjWQ/3D5wBW3tBTwLzBft6b8AO9/b69cpjK/3PupTlErQtZ3vIQkOUc7vJKE9EZMd/pCNd1MFjlKAibS7msGa9hYcEe8J+ruTAKr6/yVLQJSYT6E1+y/sb5Ol68lfXxuZwnirOXhJ9dXmRLTNj/TIe42LH/GTkOfDWtGRFCpW1+4lB++6GzUArCBfsq729tT3zwTNR7oRIRtHM/2Hf9iXhQM0aA00NBk/N5odmT5iRF2wykYX+PQM2CihNfgxjvUKOki2diOgIc2Xg8RSteh2WbHnHyNMpyaFRID6jOqL3KqW8FC5zSkJmpZIttq1oXeZcgx7MJGx6i9RF22bPtvsYG9iHNK/ckAykOQcJVLwaErmYh2ro1ZZAmoorVcXx0MOK7IYtqkmr0IGCXJrOGhmyFriryFcn8uHs5luKwsSjuuSmoiQU7S3uViWBgp+TNQTqkSBgLymy06kMee1ihbzkhap4AjPAlzdkz/Scv/w/yk13NhmP/+Jh5Icie7GRtd8yFmnlJkSoWv61oiFMqdneT6WB+vvvrastPJ/f8E+wLJNesVtQANGqoZYOqZ0OGakMZ4QeKZS89VIX+xp7AdUiAvULyEmyFjcFCQLWM+akU4MBou4B0x7eefN0NWHffXcQfeqzg69v70DiaB/++s23+OHwW/wA6+z2DiYYODsmdbPKJnECTRNM+j4GAD1MwyhfvwRNt+P66ZaVqbM6ueWKOdKg75Ou7/73ptvBlSieXTxu1O3Iw+SzLE7CeMIiVGl5fLST57bVeara1a0PEtPvqORZbQ0DmQOMPW4ktfs7Ai7NQSJFGipOjbs7D/DlPf6gnW7f5cSGXlPioDfyrOGODnRrliQvWIQ5hdLb5txADeC0zaL0aPS047BR7M34BQ7vhC15+mKx4HRO9C2+7/UoL1S/GA4VRx98Be+YOeRGfqbkk+WqegGZgnKXONAc0GsHkEl5Sj70J20Za2PyK3Ni1doCpjTR2el2SsPojK/3ok8UmbGPP3DGjraSSj4wQhG5GkqE0hAablGapTiwDjnJKPX1040ZU5PZ5gaR8Z1ERotVSmcA69oxkdqXWG1KwKN53PMkPF90atHZZufBbb99WWzQVwNE2uiDRugHNUYBqW3l1J+j2KoNDozg3PVU9Ramuesp8Xb+zq1BA6bjTOAd7ShNHsotIJzOZRzeIY09vXpO5/7EKWnMxFqE2ZClCavevXU9HyPmWkb0uylmtMb9WIliCjgbniLDn7obyveqYvNl7utWdqHBoGUvu4rbNA6DpTowvpnAEhVvtmYToM6ISU7jP1PTqDp9rp1p7AbzB+gaYqivFsu2427q/F8EF+soeDgmqgGrj8GMAGX39A0tcdLoDLeGtaRq93NXw2HnNMH6wfK2nT0kwqBBN3tQjg9daVE6CjWv76d3T59ptCDo5Aa3M93UAIYrbfYm11sPdlbAq+ktLKFlZQFJOwrpKg6z5YrBpOy3BTmMvaUXuL4F4u25Mp7IYt0+GU8wmp3x/xCOtaMp2jTF3dPLf3s/aanAJB+zQoNvP2+7WM2N4RMUN4GxGsM0RI9oasTg97wg1f0zv2Yu8Mx043w4O9vrn+6dOc6kiMnfOVcPuXZxH1D36RrOoN1zJVGbIuJiZxXyAT0RuZYHUM9WHtgLeVvaeLnyo2sP7GP4IG9aq/aB0ttOSbEoup1SCvF2DeDRyhtSiW/w5KU0HdGzWFfmoEWZfWsZBVvuudTgNo5sdh+qr1XbFecmWqOTStvayY3rtk2VrfFqkyL1ptFexaYXZYQfhJw/t65v/Txh+6O9B9Kzz4NlumoGRJSpBaT4DIDsKUjEMNHDvWWUWKR+kOprpelVCwyumjG40jFotm5kUbZyXmNX9QzTz6BDZc/R6S8NZx+NyvJ+FoukQBBBjJgA6p9xA+G7HfPMVRNKY7rwyM+Saoa57ULsfD7HyLvn0AbIszdZihvXNEZnB89C3aUPD49efcJgqG1hFrWhUdpZKeOjzEAGOhtt/lcSkBU6v8hQhbdosVNWk2z5OHBvjas75OYzXlonu+hqttHXdaEyWa6PFRVrtkaK4MXultiY+kyh6IJZowVa9DahqzrYwouTdMAAFvhXpgSjoM2Au/F0Q5fxJfLWGd/3osSrvx7iWdH6O9Ta2Ak7qHe3PFu7nwjbVyG6hzAK74T9taE8wium58S8PMrqvNFm8/jE0ln5kqTKPmkYY0oBhlu7DP5iI0Wb+OrLL7e1YYItrlPymh1Q97VfK0m5WvS2ZVe6GCOgp3cwoBnkbFiZyb5CAUVNaagdsn1V60t7rT8ZS7b5x9X8ABi02X4EMAbZ0mVuN1ng/ZrxXvFli1MyCeO0Xel6UM6aMtiLc4D81guzhH0vzLDGK3R8T2NfXGTVNCQ0EgSQy779LPT2OBa9dUwf2221AZCTn4h7ErS835hT1+TlAgXdtlfPWC5b+tx3f83Co1a3yjT5+I/Hvrft0oEGRgwjeSuiTPbqqUU7spXTKIUIbtmFFMlWiE2ofmoFZivSURTCxIVb8CdZD6fi6o12So3h18mJ5nQ0GrV1zAiE7+Ku6ew8TiPHJYUNCSJ9WC7jJ1omXtP6MO5O8vJLfbYjsgUmdSoVbW/Piaurhc387DXucz0ZLzs15MH/OFf7cgeuhoh4FEeLn5Kj2b9kvi2YGnTtdlHTmlFQPh2PSTyilCXxDCPms6nvzca/FEfioQtvScgd/ZKc3Z7oMeaez9eY66inH6rf0kS+bMai41MFwWnnzgvm4d3ox/PLm7fv3vzw/vkV/MCLtH44vzqHJaB1fcnDySSZrfja/XsSBr1rGhqdFr3BQ1sie7ztypvP6Po0pE/t/CjGKzzzkudZjMeEZZKhl664pgqKypwdN74r776aWLKjUZvKhYQ0n2dh6/cHAj69yxoAVcm8ISxX7koWMkLFqaD+BvMVq4IUKU4l9PQjWgkJ680szPDyJyxp5CPOwQLx481c/yaLfZWiX77CsIKOcPYdadf8prE7+6iakThFHtHT71mWU79wk/PA9TepN6Pr03EqwYC3fB1Rs/LmJtmVg1UaiWiUE4igg2KGNO23treeI+f/hpq59ThGQTZ2OGC/MW+95nMPZm5Cl9ey+75KznvfocRq83CW4ZyMgHPPN5cpFBUDk7GNOXR5QXc+f4HHsTB2nAc87jk/vHklE4y/hErAdAe1iMd7XXVLKeHpFSziMEt7dVUGbI9ug8UruF+FU8/XAhQAoIyllCK3kwOIUQbAHODn95uLec8xbwN1+pYRzIBrfASw1Wzm9IHHLVPRzQnb2gNepqkyEIua3gxvuqIcJSM6l31JqW5C6NNzVMw/zgN2MSIJhFCN5FW0SY+y4TnGpcGloiLxgypY6JPYtaVY6U7ShhqAJqN4FqnCFWO3BBLV3B2eooMW0OjAC8OKkAwEda5ijXuOcbcrzO8lxQpvRTbSG06OmEMtSwxGrgBJ/hvHAycATXJDH28UB1N5aRydCQG1XOqJZnLiSoGCK1eZ//3yzWtgFXHCexQYL7tEmrsA5adnhYbC2ZzrD0DcIv1HjxQlYJH0F2++hr8j4Jv5b1+klJbzOcMwetbjcRzGlvvV0aGTs418YEl5YGr4qgVjAEnTAAZi3CKW21ts8qZGiY+BoXsD9q2YF61/6RDTYCit20glmSiuhhG5EH6MwzXqa5J/IpykIqDCJ3Ei6o5AUnGkJMoFYSztlZvIHK8nLE9CMSgJxoGSf+JmmX1HXTyjbpr5MEpAvvY+IkWdqk6haXzT1xLWf5Z3Z5me4np1ee23gJCC+HS+VeJAfyldD8FW+07/bIR70PLNiDKE9WGx0tH83vjn5MvxcoA3HgiSE5meRDYBiQIdRzJ7Ex7WECMDKu4pXBnYlKoB4lIvaiCzPzCaEYjtf1Ak/30Y+twN+qNfQi/oIYh6B77Mcq9AOmP/FPicsGe/yZf3/0TlSoEC4xMYrBmobF7OQTEp1NOguEgg9icaAb7OFVIkQDGeflFa8BM3nbAfQKiMgvCuJw593Ju0j/eY8tjOV8SA89vMmySXkQHL0ektr96XIzzSSStPwHViYW852kUDIy+Af3+6evUSmaajCb18kYstMpFzTqfvmjYMh0I1mdjrUKCxgHLDU+Vq0NISq3FpCyeHCEzZF2DK9noqvxIdLOzjIjXAA1yg8z8y0TwDbSrlEtOAZehYF22yhhBBqAirixTEDdv5L0xJYSReiDzfd6rt6Mj5Z8du1tqPHysb99lvogcUEffd0/yRaPm+YjluSdJa5JIxoBciv3xhezUXCqEaYaIf96bB/E7kudInGKxgynp7VN09++eRhZYQacH8OV5O1pMoVIJQSJgGXVdpivyWuNWpseQQKOnUOBElRqkbwwIZUWKvBEhhVMlcq6+6ooF+hcy0pQqt22SfpjvhCtYqlB3sRT+jqZ/FvZLTWl8Y2uJoufxtKgipHmj2aEDhO63jfOllQbLyFqmuKBjF6pQOfWFbmKOJnacx+sp4bTDfBGMXwAqtEaiouu1BVD4x0FSSJya+bki8lLBqKUzBG5pfgMxIxyh339dn3EIqJg+u3rXNZ3py5bljTJoy83olHrpTH5Yczlov9wO2/41x3NvC4QUBC1a001oVVfTVqjdSXa/yKD97TdGKQqW/AGmg1xoh8wPkjLQJvKB6qHMMUV3c18fTWvgqEAUQpyWxaiy3JCINm0pCdyVW0Ga5tVhy98p8QLOqgb2KnEswbDpBaeeylFyrbs6u61JrfTC0G2yjqkgbhCLaRy84/P2BL9zMT3uGSinzH50QRGXlelSfMclUTMWQc1K0tXVtJKT6YNZ/jJHTVrIgOZYU3MnjzQoicpGABXNY4UX3JbW5DDoeSMGQMfZl5ZNAtFKVdfRs0JUFAysGQNnDJgLV9HtE2bQkWANjuQG4sqR8GslknYNOe57dzKRlZkirjUQ40tCiG0RmDdNUqtRDY2lis5/KJZUzyyyc+4SpfKPf8WyU++fKTeee5lLjhWu6VfOFR73UQRDeyExAwt9ZfCGvqZWmcDloc0GCkqRjPfkpKiv0NlrXOkMRb0wNv1UONc2S0QWoaE5bQ3RhG9iSwVItntFoZFy9RoBpzMUAT3s/yjd4TwhpBhc0fVRKeNLpfhyTe+d6wJE5JWEYu5E31gdJ2YzLWo64NwxUkbdvLq8cU1VZ0T2UMHm/MUeOc3iFWypQHPR0EFQ0EeNfEvS435uV8QDkpOxHksu/SJxwXxGnKDHyoaixjbCLikqdfw0/0rUOWHUkj7iWBSyexrhjAb9jL9Cr1qOy5GAjqn0fIM4xYBAxls+h07eq3XYiewJCk8CXLuuxkhsNAMysBPc4c5/ICPMX06WrGVAEJeOWY6nPODlybJomMVnAME//xZR1Witps8Iu6ayNsq6K2hV1UZBEalFUOiHq1fqC8ZV0+qZNIE0xq3UA/2GEZ9wV2Uh3BJNBePa1w2JKbhHAnDrVAS7wXJC/KQ+thj828ciF6ye8RrUdj9n538//s9ihErYwMKPCdeemqTtbqe3Un9xg7gPP61U22gy16tz3Qac0Lu1GZ7/0UtEd3oZZhW/abHTx8qgps/xI+nc+o0sgAOV8NEtjH/c96AF4uJs/kLWeP7l+ShsSZR8C6V0WdbpTioT9HnMYs/fvXgoNgeNVO0aZx2m95e7eIb/RTzxb+yxtBhTK0NGWYvvbixxsL3K4rYimPzUWlLsYxcDTUMQYk35UQQ5GtKMA24Ye6XCG4aq9TmXX4rsje9kDS9mDmrKHlrKH1rKFaliukX8pEQESfPHRFooqkZiUVNWB1psl6rOEenPToKGgqcdvLy60eLNcbTQyDZZutzMGJXamZuIau5o6B5U6B6LOQX2dw0qdQ1HnsAxwhfbeR3OMRRBXGMTEEDDOHXMYw0oGYbeyTD8Qa55or2m/5cimCAC1UALkKEtWFAjR+w3EJ5gXslkL98B4h+Km7PPXP4C6M+dTUHduPZckgVEDZbmKcDgP5peibAFUufnLWRzi3kZY9AGEvRZBEBgePuUpnkF4/58VipZjInfaf+AhIXaMibRs5L3FOZcfh6rbQyqZ+yCWEoL7IkhDzKXa+w3gXME0YOYoJ1mHYbpyDCVEd+BV0jXeV1T3+zwe5CLwUk/kRsx51EoKVEQVheMh1jt1QlfIbHMe8YBFZR59ISaQpN4JfeO5SGMO7Rx1hLsqF/HNE63FHtW12K/ta+ROMeDccODM8q9A+siwz7FMUScvXdMmen20wZgNS+W/9ewXu39Y3PTnahkGSAmUZ2iiDZ7FMdKNR1RxKHPo62fxGYCJzjBSFaGWvhdYuSD2pYhiKiKNReih+FdqiTubuPmdrmDenjnsS8Xj0lDGl/ThnfO5vOHnRpLSCbLY3xJvCXroRMP3SLy6b7A1+xVj0pHcLdd9F66H4a6PMW51Y7awbjHktbF/iSgYrczO75Qa1qgT9cl6ki+rjDYaymGqFqvqy9cq6+cHTVfGKIAyj7t2VEln4JipQo0XmUi3oL8LFwtgoM6HvH1MdYg9cF/GOBXxSPipX2JvUKwaFIUNewE5wum3SI/Ly2L+2gkjd4aHEAcOWK5Bgia0+i3SegKtRjzGY4o5gPhKAkhpuGSvb2VJ+q5DWaZMQSeCqMvyRDqUJfNsCD3RQ+bYCK9uE1ve5SGSkab6kj+qBCE/mFXrWb7F9+Am51HkbyRZvwL7BhvuSZ+HeLLhQUZl9tm2AGK9dNGOgLE4AWmDUpR5VblJU5a1FNUuyquWedjm6NlIal15Pt8I7+Wgu5asnpTa4r2tQWdkgpLRT75+8uKQRCPe4yBvejBPAWH/JoY5wOCEIjYc9wMxAhD4HEf73p+i+4j8V/hGXF/o+sK211luMiqH7uYGJ6xpYOxNuy/3Uos5p1nTTn/mOkzZaWAjlBZuA63lUX684Y9zH5DdBTIRqkpVwRpsmZ/R6Nu0Ul6z9dYUp2CxJIQ7Yzfnhfitu0DauzOqzDAjgwRFJ9gwAjcaDezo1cisZkjVVtrNvoFW9a01fExU3EWjyfNE1g3m4w0X9lb/QLOjak+IVZUjErkAXltzR3cnEj0k6qRIdXFEYYRKKa+PI3/MVu027Cr3oy4npPdRXtynuEoOXcFdxBe96nOq8oTwy0j6xVI5ZmjLVY+AXyx1PqD7DUohtXQNsj0QvYUv5b7TyoPW0NJN4mfLunIoe7cWcBqDjBvdc8VyR5cImqp+ZUU+xBnS3gnSiho7FeLCcf9RVEVp/Oroii47rqMsGZwtCrUkKn0GH0hN26hEhZv/60xnoaesLRxGspayvtKgtJohPfRNsvcmb0P1Pnk9rMdoRp9yWlDiftRgwyi/Pm6c+5J3s+kGr14N8J24chXD3fEJtT8u2utUXI0FvEbHGIwU8NcwxT3cbtMEjllMaEhYTsXXi5DUouWBWUOXodI7LwKk8nGgLAWIUdkoQ120atPraF0CVdQJgr7t/Ee9tfHAyS1aajWr/xITsRNeCx5YMgGk4rGuFd6dFlZgk/VXY/Vpfcu7lim6jrY+yVxrFaxXNQeE2i2D87y5OPEo94XJSWFq1LUbg08W4kZdX3GKHNymUg7VXk1FlhTN1AmU/ByQVrSdWDE3iB4mV/4XKRCSOKWH32ocNzlPiiPenWPycp12xl/Yz4/S54R9Me78X4qTAJXB/VRcPvLX0Xd0/4iYOMNxRZuKbpTwSRCmvRGeHtTpQibjmzBMm2FEVdlboQZs9SnZhs4wvmBXwOblRpA4/4q36givN1vAqmDrOcsiNFyOmOvfuRu8cyfC28W9NCHznpwdMR8ZA6/cutI06pagXpLpxQqnJG1dmXMAMJguQXVCUtWZMAwf2RsdwjhcDL8QbUv/PpP7BdpcjkpbCL9JIqXBHcZ8LYAUOe7Um3ukG0EtMmm/vFz1JgxmnOmpBqR3PwH7MEwxvnsViTRv/x9StpwBH7wAAA=='

def restore_pre_catalog_review(data):
    if sha256(data) != CATALOG_REVIEWED_SHA256:
        raise ValueError('Exact reviewed catalog source required')
    restored = gzip.decompress(base64.b64decode(CATALOG_PREIMAGE_GZIP_B64, validate=True))
    if sha256(restored) != CATALOG_PREIMAGE_SHA256:
        raise ValueError('Exact historical catalog preimage required')
    return restored

GALLERY_FIXED_SHA256 = 'cbfbccac53984a56b6334f82dd75521fb26f4c402a3ea76331c42bfb9f5fb752'
GALLERY_PREIMAGE_SHA256 = '687bebc45b6088379e3dc845c6eed1488dcd518f5e583259dda7060fec3acc88'
GALLERY_INDEX_DECLARATION = b'// Select the displayed hero, not the first stored photo; duplicate URLs select once.\n$activeImageIndex = array_search($mainImage, $images, true);\n\n'

def restore_pre_gallery_selection(data):
    if sha256(data) != GALLERY_FIXED_SHA256:
        raise ValueError('Exact reviewed gallery source required')
    if data.count(GALLERY_INDEX_DECLARATION) != 1 or data.count(b'$index === $activeImageIndex ?') != 2:
        raise ValueError('Exact gallery selection patch required')
    restored = data.replace(GALLERY_INDEX_DECLARATION, b'').replace(b'$index === $activeImageIndex ?', b'$index === 0 ?')
    if sha256(restored) != GALLERY_PREIMAGE_SHA256 or GALLERY_PREIMAGE_SHA256 != SHARE_FIXED_SHA256:
        raise ValueError('Gallery restoration must equal exact prior clipboard source')
    return restored

ZERO_SEARCH_HASHES = {
    'products.php': ('66b7ab25fa1bc328bc845518ee6cf9d2efa7575ee7e7c5eeb49d850499a81ce1', CATALOG_REVIEWED_SHA256),
    'api/products.php': ('d91ac82a3978dc9f5e3040023dfad33200feb5f98e89ee96c5f971c551b6ebbb', '1b1b9e3751bc24c944aff87ed2007fa78fbb40f4d1b2676c3b4a5575181db22f'),
}
ZERO_SEARCH_OLD = b'if ($search):'
ZERO_SEARCH_NEW = b"if ($search !== null && $search !== ''):"

def restore_pre_zero_search(path, data):
    if path not in ZERO_SEARCH_HASHES or sha256(data) != ZERO_SEARCH_HASHES[path][0]:
        raise ValueError('Exact reviewed zero-search renderer required')
    if data.count(ZERO_SEARCH_NEW) != 2:
        raise ValueError('Exact two zero-search presentation substitutions required')
    restored = data.replace(ZERO_SEARCH_NEW, ZERO_SEARCH_OLD)
    if sha256(restored) != ZERO_SEARCH_HASHES[path][1]:
        raise ValueError('Zero-search restoration must equal exact published preimage')
    return restored

def heading_identity_bytes(path, data):
    if path in ZERO_SEARCH_HASHES and sha256(data) == ZERO_SEARCH_HASHES[path][0]:
        data = restore_pre_zero_search(path, data)
    if path == 'product.php' and sha256(data) == GALLERY_FIXED_SHA256:
        data = restore_pre_gallery_selection(data)
    if path == 'products.php' and sha256(data) == CATALOG_REVIEWED_SHA256:
        data = restore_pre_catalog_review(data)
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
        if entry['path'] == 'api/products.php' and entry['candidate_sha256'] == ZERO_SEARCH_HASHES['api/products.php'][1] and sha256(candidate[entry['path']]) != ZERO_SEARCH_HASHES['api/products.php'][0]:
            raise ValueError('Exact reviewed zero-search API renderer required')
        if entry['path'] == 'products.php' and entry['candidate_sha256'] == CATALOG_PREIMAGE_SHA256 and sha256(candidate[entry['path']]) != ZERO_SEARCH_HASHES['products.php'][0]:
            raise ValueError('Exact reviewed catalog candidate required')
        if entry['path'] == 'public/css/style.css' and entry['candidate_sha256'] == CATALOG_SCROLL_BEFORE_SHA256 and sha256(candidate[entry['path']]) != CATALOG_SCROLL_AFTER_SHA256:
            raise ValueError('Exact reviewed catalog CSS candidate required')
        if entry['path'] == 'product.php' and entry['candidate_sha256'] == SHARE_PREIMAGE_SHA256 and sha256(candidate['product.php']) != GALLERY_FIXED_SHA256:
            raise ValueError('Exact reviewed gallery candidate required')
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
