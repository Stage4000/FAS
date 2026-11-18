"""Invoked only by the existing loopback-isolated runner; never a standalone server."""
from contextlib import closing, contextmanager
from pathlib import Path
import base64, gzip, hashlib, json, re, sqlite3, subprocess
import storage_identity
import shipping_identity
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
# Exact reviewed shipping amount guard and existing main rotor facts.
# Only these pinned bytes are added; no generic source exemption.
SHIPPING_AMOUNT_BLOBS = {'src/shipping/CarrierRates.php': {'mode': '100644', 'type': 'blob', 'sha': '1de6765d76ab0ea11961971f9c70d53c8c019100'}, 'tests/shipping-parcel-total-boundary-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'cf8d410001a652d4ecaf9817fdb2820335771513'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '492c113939693adcd50c2701a96fbe8132418e81'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '34311572806f6794eee7313cda9728a363ed67e1'}, 'docs/reviewed-product-facts.md': {'mode': '100644', 'type': 'blob', 'sha': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060'}, 'src/utils/ReviewedProductFacts.php': {'mode': '100644', 'type': 'blob', 'sha': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'tests/rotor-reviewed-facts-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ee9ce4400c99262ae33857245350e2806649a94e'}}
# The existing browser sparse checkout intentionally omits docs/. Keep this
# document pinned in the committed-tree inventory, not the materialized catalog
# files exercised by catalog-scroll-guard-test.py. All runtime/test pins remain.
CATALOG_SCROLL_BLOBS.update({path: entry for path, entry in SHIPPING_AMOUNT_BLOBS.items()
                             if path != 'docs/reviewed-product-facts.md'})
EMAIL_BLOBS.update(CATALOG_SCROLL_BLOBS)
MALFORMED_BLOBS.update(EMAIL_BLOBS)
STORAGE_BLOBS.update(MALFORMED_BLOBS)
QUANTITY_BLOBS.update(STORAGE_BLOBS)
REVIEWED_OVERLAY.update(QUANTITY_BLOBS)
REVIEWED_OVERLAY.update(SHIPPING_AMOUNT_BLOBS)


# Exact reviewed catalog overlay; absent sparse paths retain committed-tree pins.
REVIEWED_RELEASE_BLOBS = {'about.php': {'mode': '100644', 'type': 'blob', 'sha': 'ededab775a76bb3c1443f0cde713b3903b34fbbf'}, 'admin/banners.php': {'mode': '100644', 'type': 'blob', 'sha': '66eb77cef5e6688a8627d4cdd0476d25c20faca2'}, 'admin/coupons.php': {'mode': '100644', 'type': 'blob', 'sha': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098'}, 'admin/ebay-sync-health.php': {'mode': '100644', 'type': 'blob', 'sha': '4fd912aad6e5e2d513360a369a33b4018087df34'}, 'admin/purgestore.php': {'mode': '100644', 'type': 'blob', 'sha': '36f6d450157a9f68ceee971cb5e9da705bd0dbac'}, 'admin/sale.php': {'mode': '100644', 'type': 'blob', 'sha': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6'}, 'admin/stale-inventory.php': {'mode': '100644', 'type': 'blob', 'sha': '484b9a5c65d167b342e7eecc81591adadba43b01'}, 'api/contact-form.php': {'mode': '100644', 'type': 'blob', 'sha': '6f97709f0b1770fc27e136fb20e6066cd12e1b37'}, 'api/products.php': {'mode': '100644', 'type': 'blob', 'sha': '357aaf382cd2b201e2b0169d20a6f071da21251e'}, 'api/validate-coupon.php': {'mode': '100644', 'type': 'blob', 'sha': '652bea7a87733a16ecb27deb57367a3c2e036e48'}, 'checkout.php': {'mode': '100644', 'type': 'blob', 'sha': 'cb9896037c05ce66c4e31fead6ee85595bf85529'}, 'contact.php': {'mode': '100644', 'type': 'blob', 'sha': '87886a0f564f01522ebf48ceecc7e15e5eee76fc'}, 'docs/reviewed-product-facts.md': {'mode': '100644', 'type': 'blob', 'sha': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060'}, 'includes/header.php': {'mode': '100644', 'type': 'blob', 'sha': '83ed8300bd5aaaf7121232e80b5b1af7dda45231'}, 'includes/sale-helper.php': {'mode': '100644', 'type': 'blob', 'sha': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7'}, 'product.php': {'mode': '100644', 'type': 'blob', 'sha': 'e57d1b3aad20283525d7bb159bc9a9d55563b659'}, 'products.php': {'mode': '100644', 'type': 'blob', 'sha': '35571501a7a828d3f53cc9f30ccc7d79632d08a4'}, 'public/js/growth.js': {'mode': '100644', 'type': 'blob', 'sha': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692'}, 'src/integrations/EbayAPI.php': {'mode': '100644', 'type': 'blob', 'sha': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7'}, 'src/models/Banner.php': {'mode': '100644', 'type': 'blob', 'sha': '2adbeafae3c4f1373409949c532520e51619daae'}, 'src/models/Coupon.php': {'mode': '100644', 'type': 'blob', 'sha': 'bb899ad8a612b4c38fd0d18c72339123d57105d8'}, 'src/utils/ReviewedProductFacts.php': {'mode': '100644', 'type': 'blob', 'sha': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'src/utils/ShippingRules.php': {'mode': '100644', 'type': 'blob', 'sha': '758e2adec9069fa5f8684597becdefaea9460a87'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '85ff48be9c98c3199b3f78e80f389e0805e83fd8'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '2754356feb0a39115a851a1691e97f8e76635805'}, 'tests/banner-csrf-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'd7ae700887e1daaf0fc57518f4bdd24606024c33'}, 'tests/banner-input-validation-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '6c8e5274bce727af794fdfbf21d7f17afa995139'}, 'tests/banner-schedule-timezone-test.php': {'mode': '100644', 'type': 'blob', 'sha': '18cd1384c0a26ca280a695ecbbb34e78583942e6'}, 'tests/campaign-code-case-test.php': {'mode': '100644', 'type': 'blob', 'sha': '08007f693916900c8b72ebb5718fa7d7c825565b'}, 'tests/cart-malformed-hydration-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '7261f62a77364c7064930e232aa604ccacd65bf3'}, 'tests/catalog-focus-restoration-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'ea5b7eaf6e5ff513ccf8b82827bdfacc45a5de0f'}, 'tests/catalog-heading-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': '31f2a60d5d4646c54521d0776f7f960be019983c'}, 'tests/catalog-loader-lifecycle-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '44244918933b5bd8220c5c202403b2a8742f8a50'}, 'tests/catalog-search-layout-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '88326b077688f393fa45fd5f36436a53a884e303'}, 'tests/catalog-zero-search-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'c56d9d6668d3b9684c61c940b7317e94fb3fcb90'}, 'tests/checkout-coupon-lifecycle-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': '97896bf75ea4af85c12f800da671da75c8480dd2'}, 'tests/contact-delivery-test.py': {'mode': '100644', 'type': 'blob', 'sha': '2e4e160cf6c62d4eb3eec8b8668fe88724f1722c'}, 'tests/contact-pending-edits-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'e20affefa282fe0bee690033cacf828fec54221a'}, 'tests/contact-provider-response-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '1b1254fccf75ea69bc495cd9331bde8edd4be3cd'}, 'tests/contact-retry-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'dccea24eed65314386728bdf9c16937801830347'}, 'tests/coupon-active-checkbox-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '41ed98b47c9f8553fb77d54764d66a876ebc8411'}, 'tests/coupon-expiry-timezone-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ffd444d00624e127638180aebc857bd2a1c1e28c'}, 'tests/coupon-input-shape-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'b1031f0aa30c68774847f74aaafbb94df52fb479'}, 'tests/ebay-remaining-quantity-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ac53e8cd9705355f1fdcd794e3adc2bd896d17ce'}, 'tests/free-shipping-destination-api-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'd283aed369602f9015f833f3262ca9c40c3e2cd5'}, 'tests/free-shipping-destination-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'cb80e4cdda5d7a9bcd245b86ab7ed5bd40fb52ff'}, 'tests/keyboard-browser/email-restore.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'ac21fdaafba504551fd4faaaa741f2b7d245f884'}, 'tests/keyboard-browser/heading-unit-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'ba6a66467fb54ab388a7b517308838e900cd50b6'}, 'tests/keyboard-browser/navbar.cjs': {'mode': '100644', 'type': 'blob', 'sha': '4f93133adbd8f9b12c54461713a12c6b909c3004'}, 'tests/newsletter-pending-edits-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'b7d6b6cee085eb4b515615014e2b0c99ac82c299'}, 'tests/product-gallery-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': '2a27ceb5ed77bb36b8b88d4594836a5e914fbf4d'}, 'tests/product-gallery-selection-test.php': {'mode': '100644', 'type': 'blob', 'sha': '6fb8ea818e9cb3ce6e3653d303c9e0874d8068a2'}, 'tests/purgestore-csrf-test.py': {'mode': '100644', 'type': 'blob', 'sha': '48dfc923f4f666cceb48e74e2384fbdb497a0966'}, 'tests/rotor-reviewed-facts-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ee9ce4400c99262ae33857245350e2806649a94e'}, 'tests/sale-schedule-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': '1c39b832510e4f399cf6c2809b96df5233fe5cab'}, 'tests/sale-schedule-test.py': {'mode': '100644', 'type': 'blob', 'sha': '8dd204600f8bf213974353b07a837793dabd0f15'}, 'tests/saved-search-rendering-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'afb8575f7ed86101a96f510526863c210622941d'}, 'tests/stale-inventory-csrf-http-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'b8026c225f21bd37615fc9ef939c96dfdfc81c63'}, 'public/images/navbar-logo.png': {'mode': '100644', 'type': 'blob', 'sha': 'cc39aa73e9c93bb75346bd96394e2f59184445f6'}, 'tests/homepage-branding-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'b1710429404acd153838dd5d40a11f3a4c0e61a3'}, 'tests/homepage-visual/check-layout.cjs': {'mode': '100644', 'type': 'blob', 'sha': '6eb3e15e58768ca3070fd80f4a26f0891bb26463'}, 'tests/homepage-visual/prepare-fixture.py': {'mode': '100644', 'type': 'blob', 'sha': '7b0828c6e7c49d654362a72657e23cc6ba1b85ff'}}
CATALOG_SCROLL_BLOBS.update({path: REVIEWED_RELEASE_BLOBS[path] for path in ('about.php', 'api/products.php', 'checkout.php', 'contact.php', 'includes/header.php', 'includes/sale-helper.php', 'product.php', 'products.php', 'public/images/navbar-logo.png', 'public/js/growth.js', 'src/integrations/EbayAPI.php', 'src/models/Banner.php', 'src/models/Coupon.php', 'src/utils/ReviewedProductFacts.php', 'src/utils/ShippingRules.php', 'tests/admin-product-browser/guard-test.py', 'tests/admin-product-browser/run.py', 'tests/banner-csrf-http-test.py', 'tests/banner-input-validation-http-test.py', 'tests/banner-schedule-timezone-test.php', 'tests/campaign-code-case-test.php', 'tests/cart-malformed-hydration-test.cjs', 'tests/catalog-focus-restoration-test.cjs', 'tests/catalog-heading-identity-test.py', 'tests/catalog-loader-lifecycle-test.cjs', 'tests/catalog-search-layout-test.cjs', 'tests/catalog-zero-search-test.php', 'tests/checkout-coupon-lifecycle-test.cjs', 'tests/contact-delivery-test.py', 'tests/contact-pending-edits-test.cjs', 'tests/contact-provider-response-http-test.py', 'tests/contact-retry-test.cjs', 'tests/coupon-active-checkbox-http-test.py', 'tests/coupon-expiry-timezone-test.php', 'tests/coupon-input-shape-http-test.py', 'tests/ebay-remaining-quantity-test.php', 'tests/free-shipping-destination-api-test.py', 'tests/free-shipping-destination-test.php', 'tests/homepage-branding-test.py', 'tests/homepage-visual/check-layout.cjs', 'tests/homepage-visual/prepare-fixture.py', 'tests/keyboard-browser/email-restore.cjs', 'tests/keyboard-browser/heading-unit-test.py', 'tests/keyboard-browser/navbar.cjs', 'tests/newsletter-pending-edits-test.cjs', 'tests/product-gallery-identity-test.py', 'tests/product-gallery-selection-test.php', 'tests/purgestore-csrf-test.py', 'tests/rotor-reviewed-facts-test.php', 'tests/sale-schedule-http-test.py', 'tests/sale-schedule-test.py', 'tests/saved-search-rendering-test.cjs', 'tests/stale-inventory-csrf-http-test.py')})
EMAIL_BLOBS.update(REVIEWED_RELEASE_BLOBS)
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
CATALOG_PREIMAGE_GZIP_B64 = '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'

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

GALLERY_FOCUS_SHA256 = '47fb898d2b529eceb109a6b0997d85befc2aac3fc13a2263eeb050ab6e7d07ca'


def restore_pre_gallery_focus(data):
    if sha256(data) != GALLERY_FOCUS_SHA256:
        raise ValueError('Exact reviewed thumbnail focus source required')
    padded = b'product-thumbnails mt-3 d-flex gap-2 p-2"'
    start = data.index(b"const productThumbnails = document.querySelector('.product-thumbnails');\n")
    end = data.index(b'// Quantity controls\n', start)
    restored = (data[:start] + data[end:]).replace(padded, b'product-thumbnails mt-3 d-flex gap-2"', 1)
    if sha256(restored) != shipping_identity.HASHES['product.php'][0]:
        raise ValueError('Thumbnail focus restoration differs from the reviewed product page')
    return restored


def restore_pre_gallery_selection(data):
    if sha256(data) == GALLERY_FOCUS_SHA256:
        data = restore_pre_gallery_focus(data)
    data = shipping_identity.restore('product.php', data)
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

# Exact contrast-only restoration keeps historical source controls unchanged.
BADGE_HASHES = {'products.php': ('e89ed7935f711288bd06ec309ef4d127e488368dc5a137fbf7bd99f1a5dcedac', '66b7ab25fa1bc328bc845518ee6cf9d2efa7575ee7e7c5eeb49d850499a81ce1'), 'api/products.php': ('54927dd5c3708d6c6780e8de4424b1ae5579d49d305c0f45dad59c183b19a3e1', 'd91ac82a3978dc9f5e3040023dfad33200feb5f98e89ee96c5f971c551b6ebbb'), 'includes/product-merchandising.php': ('860f407a9688a6f31b86de3975192497c83e64f436a035c3d30ed17eaebb138a', 'bedf7fb2ae7e22639688e9af3b4fc4b55bb018e7bddca7f84081cc71e814148f')}

def restore_pre_badge(path, data):
    if path not in BADGE_HASHES or sha256(data) != BADGE_HASHES[path][0]:
        return data
    new = b'class="badge bg-info text-dark product-badge"'
    old = b'class="badge bg-info product-badge"'
    if data.count(new) != 1:
        raise ValueError('Exact single reviewed badge substitution required')
    restored = data.replace(new, old)
    if sha256(restored) != BADGE_HASHES[path][1]:
        raise ValueError('Badge restoration must equal exact reviewed preimage')
    return restored

def restore_pre_zero_search(path, data):
    data = restore_pre_badge(path, data)
    if path not in ZERO_SEARCH_HASHES or sha256(data) != ZERO_SEARCH_HASHES[path][0]:
        raise ValueError('Exact reviewed zero-search renderer required')
    if data.count(ZERO_SEARCH_NEW) != 2:
        raise ValueError('Exact two zero-search presentation substitutions required')
    restored = data.replace(ZERO_SEARCH_NEW, ZERO_SEARCH_OLD)
    if sha256(restored) != ZERO_SEARCH_HASHES[path][1]:
        raise ValueError('Zero-search restoration must equal exact published preimage')
    return restored

ORIGINAL_INLINE_NOTIFICATION_FORM = b"""<form class="saved-search-notify-form border rounded-3 p-3 mb-3" data-saved-search-notify>
<div class="small fw-semibold mb-2"><i class="fas fa-bell text-danger me-1"></i>Notify me when similar parts arrive</div>
<div class="row g-2 align-items-start">
<div class="col-lg">
<input type="email" class="form-control form-control-sm" name="email" placeholder="Email address" autocomplete="email" required>
</div>
<div class="col-lg-auto">
<button class="btn btn-danger btn-sm w-100" type="submit">Notify Me</button>
</div>
</div>
<label class="form-check small text-muted mt-2 mb-0">
<input class="form-check-input" type="checkbox" name="consent" value="1" required>
I agree to be contacted only about matching Flip and Strip inventory.
</label>
<div class="saved-search-notify-status small mt-2" aria-live="polite"></div>
</form>
"""
NOTIFICATION_RESTORATIONS = {
    'products.php': ('bea9dc98c5536124c9030d27cf9f8f89b2025337783fe14c2cb56d82847278f3', 'e89ed7935f711288bd06ec309ef4d127e488368dc5a137fbf7bd99f1a5dcedac',
        b"<?php require __DIR__ . '/includes/saved-search-notify-form.php'; ?>\n", ORIGINAL_INLINE_NOTIFICATION_FORM),
    'api/products.php': ('c25a611f5630caf8fe1d5113b8243491385642c220d3804e29894bb3a32b9b4d', '54927dd5c3708d6c6780e8de4424b1ae5579d49d305c0f45dad59c183b19a3e1',
        b"<?php require __DIR__ . '/../includes/saved-search-notify-form.php'; ?>\n", b''),
}


def restore_pre_notification_form(path, data):
    current, previous, include, inline = NOTIFICATION_RESTORATIONS[path]
    if sha256(data) != current or data.count(include) != 1:
        raise ValueError('Exact reviewed saved-search renderer required: ' + path)
    restored = data.replace(include, inline, 1)
    if sha256(restored) != previous or previous != BADGE_HASHES[path][0]:
        raise ValueError('Saved-search restoration must match the preceding renderer: ' + path)
    return restored


def heading_identity_bytes(path, data):
    if path == 'product.php' and sha256(data) == GALLERY_FOCUS_SHA256:
        data = restore_pre_gallery_focus(data)
    if path in NOTIFICATION_RESTORATIONS and sha256(data) == NOTIFICATION_RESTORATIONS[path][0]:
        data = restore_pre_notification_form(path, data)
    data = shipping_identity.restore(path, data)
    data = restore_pre_badge(path, data)
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

# Exact test-only blobs already merged in PR164; runtime remains unchanged.
REVIEWED_OVERLAY.update({'tests/fixtures/image-auto-bootstrap/FixedImageBootstrap.php': {'mode': '100644', 'type': 'blob', 'sha': '2ee98ccd884eda672642b09037bd604a06fe9f1a'}, 'tests/fixtures/image-auto-local/ImmutableImageSnapshot.php': {'mode': '100644', 'type': 'blob', 'sha': '4fcdee7e349a622c28f3e3b231a07ea0b15342ba'}, 'tests/fixtures/image-auto-local/ImmutableLocalPilot.php': {'mode': '100644', 'type': 'blob', 'sha': '2b12295ccbb99c5823997794057028d35318d8ec'}, 'tests/fixtures/image-auto-operation/candidate/src/ImageEffectObservation.php': {'mode': '100644', 'type': 'blob', 'sha': '6d039e17589eca14af3c3e710be026e7c3566fd1'}, 'tests/fixtures/image-auto-operation/candidate/src/ImageIntakeBinding.php': {'mode': '100644', 'type': 'blob', 'sha': '435a1d12041f3e8852e479b03f84ce9c6dbeb3a3'}, 'tests/fixtures/image-auto-operation/candidate/src/ImagePilot.php': {'mode': '100644', 'type': 'blob', 'sha': '12f5dce030ccde2f0121fd79a317751d9f628bdc'}, 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceIntake.php': {'mode': '100644', 'type': 'blob', 'sha': '438cf977c26224a15ddd2ec0f65d02fcf8884629'}, 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceSelection.php': {'mode': '100644', 'type': 'blob', 'sha': '0ac0bfb10e92d3aa28482e7565ab48e613c83b68'}, 'tests/fixtures/image-auto-operation/candidate/src/ImportedImage.php': {'mode': '100644', 'type': 'blob', 'sha': '802a203112acd7caaa2fc0a7ef13d051fb843fb4'}, 'tests/fixtures/image-auto-operation/candidate/src/PosixImageIntakeStore.php': {'mode': '100644', 'type': 'blob', 'sha': '7016a52f8813bc4183b392fbef848cc3695db3b5'}, 'tests/fixtures/image-auto-operation/candidate/src/ResponsiveImage.php': {'mode': '100644', 'type': 'blob', 'sha': 'd8bfa725d5aaf7320b3c1052966376129379fdd4'}, 'tests/fixtures/image-auto-operation/controller-cycle-next/ControllerQuotaWitness.php': {'mode': '100644', 'type': 'blob', 'sha': 'db3989c69b5513544dc62e16b9172db4fbb45355'}, 'tests/fixtures/image-auto-operation/controller-cycle-next/CycleAdmissionBridge.php': {'mode': '100644', 'type': 'blob', 'sha': 'f0c5c6c6ed254029fcd3ee96b492ce65a258a638'}, 'tests/fixtures/image-auto-operation/controller-cycle-next/test-cohesive.php': {'mode': '100644', 'type': 'blob', 'sha': 'ad4fa6b12e5d0c7529d556bdd4946107f259e3d7'}, 'tests/fixtures/image-auto-operation/controller-cycle-next/test-retained-lifetime.php': {'mode': '100644', 'type': 'blob', 'sha': '5ddfa9b7f819dd71b19ad787ffb6c29d1faa0b7a'}, 'tests/fixtures/image-auto-operation/controller-cycle-next/worker.php': {'mode': '100644', 'type': 'blob', 'sha': '0fb075f219cd6ad05004dbff81d51f79d5b31294'}, 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageOperationPilot.php': {'mode': '100644', 'type': 'blob', 'sha': 'ff9157063aa11f1b8f79e21e95ecd77a3ab1c172'}, 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageRemoteAcquisition.php': {'mode': '100644', 'type': 'blob', 'sha': 'f3a1d88d9117f42fc7cecd5e60f1fed38f56a836'}, 'tests/fixtures/image-auto-operation/coordinator-vnext/test_coordinator.php': {'mode': '100644', 'type': 'blob', 'sha': 'f77f1694cc067c2163eec5acac4b1f5af6781370'}, 'tests/fixtures/image-auto-operation/coordinator-vnext/test_default_off.php': {'mode': '100644', 'type': 'blob', 'sha': 'fe08ee8baba6a30fc63d8535967dec284bf003a8'}, 'tests/fixtures/image-auto-operation/coordinator-vnext/test_superclass_guard.php': {'mode': '100644', 'type': 'blob', 'sha': 'b4a7c0adbe9dd849c5651a17c465272a859387bf'}, 'tests/fixtures/image-auto-operation/front-end-vnext/ImageOperationFrontEnd.php': {'mode': '100644', 'type': 'blob', 'sha': '642ea3fab0af49e0328ffe560c0deb61c995b0d6'}, 'tests/fixtures/image-auto-operation/front-end-vnext/ImageQuotaAdmission.php': {'mode': '100644', 'type': 'blob', 'sha': 'cc109321b9d2068ea45d40f20499beb3678756a2'}, 'tests/fixtures/image-auto-operation/front-end-vnext/test_front_end.php': {'mode': '100644', 'type': 'blob', 'sha': 'aa3c10c6bd262e6f77de96c203dbe630e008c1b6'}, 'tests/fixtures/image-auto-operation/front-end-vnext/test_quota.php': {'mode': '100644', 'type': 'blob', 'sha': '46df13e40eee48d1bf4a38c4f9b30dd2d61697ba'}, 'tests/fixtures/image-auto-operation/operation-v6/ImageObservedSettlement.php': {'mode': '100644', 'type': 'blob', 'sha': '52b9fe81eb06e45074487770a42b1d5427496450'}, 'tests/fixtures/image-auto-operation/operation-v6/ImageOperationBinding.php': {'mode': '100644', 'type': 'blob', 'sha': '67d8472f352b0607e8d500f6fb598b6e91ab5c4f'}, 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.json': {'mode': '100644', 'type': 'blob', 'sha': '900941fa18a3b695b8747e6f6dba7e1b5a39ec68'}, 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.sql': {'mode': '100644', 'type': 'blob', 'sha': 'b366c0dce410401c70768d9e16728804c8308754'}, 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.json': {'mode': '100644', 'type': 'blob', 'sha': '4216181889012f184a65410dc6ba6966251f12c8'}, 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.sql': {'mode': '100644', 'type': 'blob', 'sha': 'd15f29b6ac1e0be9bc40c962aa69a4af938d359e'}, 'tests/fixtures/image-auto-operation/operation-v6/fixtures/schema-empty.sql': {'mode': '100644', 'type': 'blob', 'sha': '0247fec25555f84b434173fa10d49083cf99d322'}, 'tests/fixtures/image-auto-operation/operation-v6/same-process-fixture.php': {'mode': '100644', 'type': 'blob', 'sha': '9183e29e7ce75f2bede4546d18586fc9e66e7797'}, 'tests/fixtures/image-auto-operation/operation-v6/schema-v6.json': {'mode': '100644', 'type': 'blob', 'sha': '22dfc047d2ccdedc2fc6015b8264aee27634bd14'}, 'tests/fixtures/image-auto-operation/operation-v6/test_pdo.php': {'mode': '100644', 'type': 'blob', 'sha': 'f165c88a9a1aa119b2ac19f27f62db15e530c621'}, 'tests/fixtures/image-auto-operation/operation-v6/test_same_process.php': {'mode': '100644', 'type': 'blob', 'sha': '16001eeff37b2a5094ab985e0739c6375955262d'}, 'tests/fixtures/image-auto-operation/tests/effect-fixtures/source.png.base64': {'mode': '100644', 'type': 'blob', 'sha': '2d801cefd40e3e6a1744b71f3396622997899f78'}, 'tests/fixtures/image-auto-operation/tests/effect-fixtures/variant.webp.base64': {'mode': '100644', 'type': 'blob', 'sha': '7618e59ce1aea6b0a706ef5b24e2da2d180685af'}, 'tests/fixtures/image-auto-pilot/ImageIntakeBinding.php': {'mode': '100644', 'type': 'blob', 'sha': '435a1d12041f3e8852e479b03f84ce9c6dbeb3a3'}, 'tests/fixtures/image-auto-pilot/ImagePilot.php': {'mode': '100644', 'type': 'blob', 'sha': '12f5dce030ccde2f0121fd79a317751d9f628bdc'}, 'tests/fixtures/image-auto-pilot/ImageSourceIntake.php': {'mode': '100644', 'type': 'blob', 'sha': '438cf977c26224a15ddd2ec0f65d02fcf8884629'}, 'tests/fixtures/image-auto-pilot/ImageSourceSelection.php': {'mode': '100644', 'type': 'blob', 'sha': '0ac0bfb10e92d3aa28482e7565ab48e613c83b68'}, 'tests/fixtures/image-auto-pilot/ImportedImage.php': {'mode': '100644', 'type': 'blob', 'sha': '802a203112acd7caaa2fc0a7ef13d051fb843fb4'}, 'tests/fixtures/image-auto-pilot/PosixImageIntakeStore.php': {'mode': '100644', 'type': 'blob', 'sha': '7016a52f8813bc4183b392fbef848cc3695db3b5'}, 'tests/fixtures/image-auto-pilot/ResponsiveImage.php': {'mode': '100644', 'type': 'blob', 'sha': 'd8bfa725d5aaf7320b3c1052966376129379fdd4'}, 'tests/fixtures/image-auto-pilot/entrypoints/automatic-pilot.php': {'mode': '100644', 'type': 'blob', 'sha': '57fdf7a6ef22d6ab866f5fb23c75c05db0925cd3'}, 'tests/fixtures/image-auto-pilot/entrypoints/intake.php': {'mode': '100644', 'type': 'blob', 'sha': '648e6fd08c9e2a107c0497a7154b0584b5b94a4f'}, 'tests/fixtures/image-handoff-migration/ImageHandoffMigration.php': {'mode': '100644', 'type': 'blob', 'sha': 'd993d991e74c46f4ca076a67743f2aa5272be77d'}, 'tests/fixtures/image-handoff-migration/schema-v2.json': {'mode': '100644', 'type': 'blob', 'sha': '5be589dccc6ee804a056bd14c7f38de450467064'}, 'tests/fixtures/image-handoff-migration/schema-v3.json': {'mode': '100644', 'type': 'blob', 'sha': 'bc453b2f25d1fe44b9d4d7821e0f6533b09eb3c7'}, 'tests/fixtures/image-handoff-v2/ImageHandoffV2.php': {'mode': '100644', 'type': 'blob', 'sha': '16f74aa66ea76843a92a56b2cd7408fab98bb91d'}, 'tests/fixtures/image-handoff-v2/ImageSnapshotV2.php': {'mode': '100644', 'type': 'blob', 'sha': '74a45b4adc0eec2dd005355596ef0a176e852649'}, 'tests/fixtures/image-handoff-v2/outbox.sql': {'mode': '100644', 'type': 'blob', 'sha': '95e34ea9c435c3214e2c36935b469b5b0ffb41d2'}, 'tests/fixtures/image-handoff-v2/schema-manifest.json': {'mode': '100644', 'type': 'blob', 'sha': '5be589dccc6ee804a056bd14c7f38de450467064'}, 'tests/fixtures/image-handoff-v3/ImageHandoffV3.php': {'mode': '100644', 'type': 'blob', 'sha': '46a893a1fb744b49764c6a3aaf7b95b30f93c9c0'}, 'tests/fixtures/image-handoff-v3/ImageSnapshotV2.php': {'mode': '100644', 'type': 'blob', 'sha': '74a45b4adc0eec2dd005355596ef0a176e852649'}, 'tests/fixtures/image-handoff-v3/schema-manifest.json': {'mode': '100644', 'type': 'blob', 'sha': 'bc453b2f25d1fe44b9d4d7821e0f6533b09eb3c7'}, 'tests/fixtures/image-pilot/ImagePilot.php': {'mode': '100644', 'type': 'blob', 'sha': '19423938b5a720ed36556198798580436a72229b'}, 'tests/fixtures/image-pilot/ImportedImage.php': {'mode': '100644', 'type': 'blob', 'sha': '802a203112acd7caaa2fc0a7ef13d051fb843fb4'}, 'tests/fixtures/image-pilot/ResponsiveImage.php': {'mode': '100644', 'type': 'blob', 'sha': 'd8bfa725d5aaf7320b3c1052966376129379fdd4'}, 'tests/fixtures/image-pilot/pilot.php': {'mode': '100644', 'type': 'blob', 'sha': 'a4c796f0c4ecc78ee0886cce778d46ffaed452e6'}, 'tests/image-auto-bootstrap-boundary-test.py': {'mode': '100644', 'type': 'blob', 'sha': '9ba7d1d139b5ae8961280aa3e070badcb8010756'}, 'tests/image-auto-bootstrap-lifecycle-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'e36e581842abc1c56accca88ccf6e27884d7dda0'}, 'tests/image-auto-bounded-snapshot-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'ab27e918a1c253229d2016b8b87adc4d48a4efc1'}, 'tests/image-auto-controller-cycle-test.py': {'mode': '100644', 'type': 'blob', 'sha': '0f411f86e16285167b46a81cce902e44dc050d30'}, 'tests/image-auto-controller-quota-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'b418ecbf83cdca6ab69e12628f19885fed8b15fe'}, 'tests/image-auto-entrypoint-boundary-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'dd5ec99dfac1b46c864548c8bced528f06315242'}, 'tests/image-auto-entrypoint-pdo-test.py': {'mode': '100644', 'type': 'blob', 'sha': '08add7f6122d3c062eb30801695c5e83d1723da3'}, 'tests/image-auto-gallery-binding-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'b8671582a487b6137a133874cf5e8e13512f788e'}, 'tests/image-auto-gallery-pipeline-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'cbc23d06a9400afd148eaf5c93a956c6c748fcb2'}, 'tests/image-auto-immutable-local-pipeline-test.php': {'mode': '100644', 'type': 'blob', 'sha': '3bd24dde79c16eef25f48cc15b899b1bc4d2d847'}, 'tests/image-auto-immutable-snapshot-test.php': {'mode': '100644', 'type': 'blob', 'sha': '24011ae6e96c2056a5836c3abdc0934d2fb2d97b'}, 'tests/image-auto-intake-binding-test.php': {'mode': '100644', 'type': 'blob', 'sha': '4b248e30ce00ed94f117b33240410aa31eea19ce'}, 'tests/image-auto-intake-pipeline-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'd192eda9eb8e394dc6f60a919c1c58512c05eb54'}, 'tests/image-auto-intake-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'f1e2842becf3d44fc3b1e46e3eb320e7fbbf63ff'}, 'tests/image-auto-operation-binding-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'e1a31cbfd7273421b3377ee3c8164ba283a60b62'}, 'tests/image-auto-operation-composition-test.php': {'mode': '100644', 'type': 'blob', 'sha': '818353a05391326c285c2cb46a7c078f96337714'}, 'tests/image-auto-operation-coordinator-test.php': {'mode': '100644', 'type': 'blob', 'sha': '9e582a1d98a68d9d02c38701415b130d463326ad'}, 'tests/image-auto-operation-default-off-test.php': {'mode': '100644', 'type': 'blob', 'sha': '9233ce6ff190e7b0924ea2988b6d031ab4234deb'}, 'tests/image-auto-operation-front-end-test.php': {'mode': '100644', 'type': 'blob', 'sha': '9e932a45060d23e10bdb6c76663aaf5e1fcfa470'}, 'tests/image-auto-operation-quota-test.php': {'mode': '100644', 'type': 'blob', 'sha': '3d3c65e58b881eacc4765ac35d3508adf94fc5ee'}, 'tests/image-auto-operation-settlement-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'ecf19ceca51552daf44e3e94ee0b0dacf85aa3af'}, 'tests/image-auto-operation-superclass-test.php': {'mode': '100644', 'type': 'blob', 'sha': '4567574d60ef67d7939714f7ca010a8b5e48ed0c'}, 'tests/image-auto-pilot-intake-integration-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'fe4fc24cc4d282f863266c9be0c83ffbf780d250'}, 'tests/image-auto-posix-intake-store-test.php': {'mode': '100644', 'type': 'blob', 'sha': '3e2f0ccda866466fe8d844762e511f882c9763c6'}, 'tests/image-auto-source-selection-test.php': {'mode': '100644', 'type': 'blob', 'sha': '374ab050ad4af3cc32b33fdd52b802a8a7a362ed'}, 'tests/image-auto-source-url-test.php': {'mode': '100644', 'type': 'blob', 'sha': '11e6c22a94bf6f57162633625b06abdabbf0dcaf'}, 'tests/image-handoff-migration-file-test.php': {'mode': '100644', 'type': 'blob', 'sha': '8dcf46f87061ef48468410e25239f856147a28e5'}, 'tests/image-handoff-migration-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'd04bf0e94e77033e54dc237e7434f814d874a636'}, 'tests/image-handoff-v2-concurrency-test.php': {'mode': '100644', 'type': 'blob', 'sha': '0a58ec82cf1eb88a64f1247ef80a39a370278218'}, 'tests/image-handoff-v2-integration-test.php': {'mode': '100644', 'type': 'blob', 'sha': '8663f599ba29599ebd35d682438137eba47c5a37'}, 'tests/image-handoff-v2-pdo-test.php': {'mode': '100644', 'type': 'blob', 'sha': '64baac41dc06a9902097e28e596669b580f0052a'}, 'tests/image-handoff-v2-writer-contract-test.php': {'mode': '100644', 'type': 'blob', 'sha': '699101dad61129a2cefbea2bb90fb7026800685d'}, 'tests/image-handoff-v3-quarantine-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'a65aa371ebe865e3c5bcb8fe88ff2938e6d45c70'}, 'tests/image-handoff-v3-race-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'a24e1ed0a4617b3dfdb99f0b1b8ebad4a5e6e4c2'}, 'tests/image-pilot-cli-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'ed3ec5cc2d9b8b60994387c5f26629bb931fcb23'}, 'tests/image-pilot-test.php': {'mode': '100644', 'type': 'blob', 'sha': 'e9f3098d2cef89e39abb6ea9dbfe1e8503db47a7'}, 'tests/condition-badge-contrast-test.py': {'mode': '100644', 'type': 'blob', 'sha': '52c79b6074e8e85e4e2d9b5ac12eaee92cb3f703'}, 'tests/catalog-heading-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'da903694533a2ca00981fea5ef178a44a600cd9f'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '846dba0119829da801fbdc5463d3b37a5995bf33'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '6132fe5215b1ca2339f956835bbbb720ce9f43eb'}})

# Exact badge integration pins also feed the catalog guard mutation suite.
BADGE_INTEGRATION_BLOBS = {'products.php': {'mode': '100644', 'type': 'blob', 'sha': '82bef0dc76c40a5aaca005425d8856df30cf1b8d'}, 'api/products.php': {'mode': '100644', 'type': 'blob', 'sha': '6ea53e5d29f2b0d101e5e09acaeff067e1522d75'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '846dba0119829da801fbdc5463d3b37a5995bf33'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '6132fe5215b1ca2339f956835bbbb720ce9f43eb'}, 'tests/catalog-heading-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'da903694533a2ca00981fea5ef178a44a600cd9f'}}
CATALOG_SCROLL_BLOBS.update(BADGE_INTEGRATION_BLOBS)
QUANTITY_BLOBS.update(BADGE_INTEGRATION_BLOBS)
REVIEWED_OVERLAY.update(BADGE_INTEGRATION_BLOBS)


# Reviewed input-purpose-only Contact markup and its exact offline regression.
# No wildcard exclusions: any further byte or mode drift remains rejected.
CONTACT_INPUT_PURPOSE_BLOBS = {
    'contact.php': {'mode': '100644', 'type': 'blob', 'sha': '52a106dbb898ecdf50c68dbaabfd7d08f80c089a'},
    'tests/contact-input-purpose-test.py': {'mode': '100644', 'type': 'blob', 'sha': '34b00ab77be32623d2547badc70241dd69b55416'},
}
CATALOG_SCROLL_BLOBS.update(CONTACT_INPUT_PURPOSE_BLOBS)
QUANTITY_BLOBS.update(CONTACT_INPUT_PURPOSE_BLOBS)
REVIEWED_OVERLAY.update(CONTACT_INPUT_PURPOSE_BLOBS)

# Exact reviewed PR183 runtime and test inputs; all other paths stay immutable.
SHIPPING_INTEGRATION_BLOBS = {'api/shipping-estimate.php': {'mode': '100644', 'type': 'blob', 'sha': '6f68638d9c961e75bfbb7594a616ffb868f4bc75'}, 'cart.php': {'mode': '100644', 'type': 'blob', 'sha': 'badbd6ca9a53fef9672b0cf8b0ec6a2122c46aac'}, 'checkout.php': {'mode': '100644', 'type': 'blob', 'sha': 'f9e80306880977e15f289caeee032e3ee969574f'}, 'includes/catalog-meta.php': {'mode': '100644', 'type': 'blob', 'sha': 'b77464e1cdf9f4b5684f1bde224dca683ab8a428'}, 'product.php': {'mode': '100644', 'type': 'blob', 'sha': 'd00036ad68cd4d01cf1ae254d8f34d53c4a62478'}, 'public/js/shipping-estimator.js': {'mode': '100644', 'type': 'blob', 'sha': '13b11995172cabbf7be019232b45eed941c97a02'}, 'src/utils/ShippingRules.php': {'mode': '100644', 'type': 'blob', 'sha': 'ce56c43e6120af85c8e0a12f755d661619677c30'}, 'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '0513965cf4e250dd719fcb4fecbff9b87ba4a0f6'}, 'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '4547ac37856456d64fbbd926c22bfe8a50c5c35d'}, 'tests/cart-malformed-hydration-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'd72d25178e2ab3f414f6581eb4a15c9554a939fb'}, 'tests/free-shipping-destination-api-test.py': {'mode': '100644', 'type': 'blob', 'sha': '7461b88e9cf1cf1d04cd16c61e0ec999a0a7ac52'}, 'tests/free-shipping-destination-test.php': {'mode': '100644', 'type': 'blob', 'sha': '4ebb3ba83755803c7bdd7effa8858a4f169607ba'}, 'tests/keyboard-browser/shipping_identity.py': {'mode': '100644', 'type': 'blob', 'sha': '075be901e2f24af392495bb3d9977fe1586e0edc'}, 'tests/keyboard-browser/shipping-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'dfc71b4c80215ac9e18b36239f30d9956722114b'}, 'tests/keyboard-browser/storage_identity.py': {'mode': '100644', 'type': 'blob', 'sha': '38da96168a315f18613c3e02e9a48176b879138f'}, 'tests/keyboard-browser/heading-unit-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'f0c6aaecdfceee85128a38c68590facbc5cee5ba'}, '.github/workflows/keyboard-browser.yml': {'mode': '100644', 'type': 'blob', 'sha': '856ac38990bd6275be4e69f8b50da7288a4b0a7a'}, 'tests/keyboard-browser/malformed-hydration.cjs': {'mode': '100644', 'type': 'blob', 'sha': '7100046f80307c2fb698be747bfcb085b4e67327'}, 'tests/keyboard-browser/storage-unavailable.cjs': {'mode': '100644', 'type': 'blob', 'sha': '4af982adb74194c9678ebdf10360eefe1acd40ed'}}
MALFORMED_BLOBS.update({p: SHIPPING_INTEGRATION_BLOBS[p] for p in ('tests/keyboard-browser/storage-unavailable.cjs', 'tests/keyboard-browser/malformed-hydration.cjs')})
STORAGE_BLOBS.update(SHIPPING_INTEGRATION_BLOBS)
CATALOG_SCROLL_BLOBS.update(SHIPPING_INTEGRATION_BLOBS)
QUANTITY_BLOBS.update(SHIPPING_INTEGRATION_BLOBS)
REVIEWED_OVERLAY.update(SHIPPING_INTEGRATION_BLOBS)

# Exact coupon summary presentation overlay; historical inventories stay pinned.
COUPON_SUMMARY_BLOBS = {
    '.github/workflows/admin-product-acceptance.yml': {'mode': '100644', 'type': 'blob', 'sha': 'dc6c4ecb58c4e1025825bf2adc5df6490cd9888c'},
    '.github/workflows/keyboard-browser.yml': {'mode': '100644', 'type': 'blob', 'sha': 'e89c3c1d8bdbacf9fd124dadc77e05f76473e0ca'},
    '.github/workflows/manual-reader-browser.yml': {'mode': '100644', 'type': 'blob', 'sha': 'ec59c301a1f28d01bb3503da0041854832bc5b17'},
    'checkout.php': {'mode': '100644', 'type': 'blob', 'sha': '55fbb9cd5d9da429d5b7314d3de41de1bd995ad4'},
    'tests/admin-product-browser/guard-test.py': {'mode': '100644', 'type': 'blob', 'sha': '620774b24e9e1b451ca6285efa4202793308e515'},
    'tests/admin-product-browser/run.py': {'mode': '100644', 'type': 'blob', 'sha': '2e46d53b01c77c87e91fd65ce0e80c04d4b7755d'},
    'tests/cart-malformed-hydration-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'f438c7cb5485f3af73cebcd97305ab708e75e418'},
    'tests/checkout-coupon-lifecycle-test.cjs': {'mode': '100644', 'type': 'blob', 'sha': 'c696139b8dd15d47ead2001ffa59353756bfe138'},
    'tests/keyboard-browser/shipping-identity-test.py': {'mode': '100644', 'type': 'blob', 'sha': 'b03b8686f6e7bd812150f262ed405378a31a7340'},
}
CATALOG_SCROLL_BLOBS.update(COUPON_SUMMARY_BLOBS)
EMAIL_BLOBS.update(COUPON_SUMMARY_BLOBS)
MALFORMED_BLOBS.update(COUPON_SUMMARY_BLOBS)
STORAGE_BLOBS.update(COUPON_SUMMARY_BLOBS)
QUANTITY_BLOBS.update(COUPON_SUMMARY_BLOBS)
REVIEWED_OVERLAY.update(COUPON_SUMMARY_BLOBS)

def manuals_policy(root, *, checkout=True):
    import sys
    sys.dont_write_bytecode = True
    sys.path.insert(0, str(root / 'tests/manuals/browser'))
    from reviewed_repository import load_policy, verify_checkout
    return verify_checkout(root) if checkout else load_policy(root)


def verify_tree(base, current, source_manifest, reviewed_manuals=None):
    expected = {e['path']:{k:e[k] for k in ['mode','type','sha']} for e in base['entries']}
    expected.update(REVIEWED_OVERLAY)
    # Exact candidate blobs are derived from SHA-256-verified working bytes below.
    for path, blob in source_manifest.items():
        expected[path] = {'mode':'100644','type':'blob','sha':blob}
    if reviewed_manuals is not None:
        expected.update(reviewed_manuals)
    for path in set(expected) | set(current):
        if path in INTEGRATION_PATHS and path not in QUANTITY_BLOBS:
            if path not in current or current[path]['mode'] != '100644' or current[path]['type'] != 'blob':
                raise ValueError('Missing/nonregular reviewed integration path: '+path)
        elif expected.get(path) != current.get(path):
            raise ValueError('Unexpected checkout drift: '+path)
    if not INTEGRATION_PATHS.issubset(current):
        raise ValueError('Missing integration files')

def verify_checkout(root):
    manuals = manuals_policy(root)
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
    verify_tree(base,current,blobs,manuals['overlay'])
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
    # Bind actual reviewed sources before historical copy normalization.
    historical = {'product.php': 'e0283b86a81c7c5631f556f7671abe5ae838e7e475772990ea39b437dae8af03', 'cart.php': '31408066a41b8b31820f059059ff07e5647ede30083c3d7bbdec612bcda37b70'}
    candidate = dict(candidate)
    for entry in manifest['files']:
        path = entry['path']
        if path in historical and entry['candidate_sha256'] == historical[path]:
            if path == 'product.php':
                candidate[path] = restore_pre_gallery_focus(candidate[path])
            permitted = {shipping_identity.HASHES[path][0]}
            # The heading fixture already restores the exact Cart storage overlay.
            if path == 'cart.php':
                permitted.add(historical[path])
            if sha256(candidate[path]) not in permitted:
                raise ValueError('Exact reviewed shipping candidate required: '+path)
    candidate = {p: shipping_identity.restore(p, b) for p, b in candidate.items()}
    historical_catalog = {'products.php': CATALOG_PREIMAGE_SHA256,
                          'api/products.php': ZERO_SEARCH_HASHES['api/products.php'][1]}
    for entry in manifest['files']:
        path = entry['path']
        if path in historical_catalog and entry['candidate_sha256'] == historical_catalog[path]:
            candidate[path] = restore_pre_notification_form(path, candidate[path])
    candidate = {p: restore_pre_badge(p, b) for p, b in candidate.items()}
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
    candidate = {p: shipping_identity.restore(p, b) for p, b in candidate.items()}
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

@contextmanager
def fixture_database(path):
    with closing(sqlite3.connect(path)) as connection, connection:
        yield connection


def seed_recommendations(site):
    # This is the existing disposable SQLite fixture, never a supplied live DB.
    dbpath = site / 'database/flipandstrip.db'
    with fixture_database(dbpath) as db:
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
    with fixture_database(site/'database/flipandstrip.db') as db:
        db.execute("CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    store_name TEXT NOT NULL UNIQUE,\n    seller_name TEXT,\n    feedback_score INTEGER,\n    positive_feedback_percent REAL,\n    store_url TEXT,\n    last_fetched_at TEXT,\n    last_error TEXT,\n    created_at TEXT DEFAULT (datetime('now')),\n    updated_at TEXT DEFAULT (datetime('now'))\n);")
        if db.execute('SELECT count(*) FROM ebay_seller_rating_cache').fetchone()[0]:
            raise ValueError('Expected empty synthetic seller cache')
        db.execute("INSERT INTO ebay_seller_rating_cache(store_name,seller_name,feedback_score,positive_feedback_percent,store_url,last_fetched_at) VALUES(?,?,?,?,?,?)",
                   ('synthetic-heading-store','Synthetic Heading Seller',123,99.8,'https://example.invalid/synthetic-seller','2026-09-30'))

def restore_database(seed_path, database):
    with fixture_database(seed_path) as source, fixture_database(database) as target:
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

NOTIFICATION_BASELINE_HASHES = {
    'products.php': ('fc1415e9b54262b5d8d34a9cb521e779f1ae78dae8abcca589ea9255501a8055',
        '0ba3c8f0e26418bd890b4c3a31cb6910716a2ba37a325bce319548ee9463e53d'),
    'api/products.php': ('62a33018504ec723f24c80002939f4de95e5291e5c9b2035293945711966851c',
        '6e8568b74b8e39c48f1905a6191d3813f7620a7efdd05d4ff23eb755216847cc'),
}


def browser_notification_baseline(baseline):
    """Render the same notification form on both sides of the historical heading comparison."""
    result = dict(baseline)
    api_anchor = b'<div class="d-flex flex-wrap gap-2">\n<a href="/products/motorcycle"'
    for path, (before, after) in NOTIFICATION_BASELINE_HASHES.items():
        include = NOTIFICATION_RESTORATIONS[path][2]
        original = ORIGINAL_INLINE_NOTIFICATION_FORM if path == 'products.php' else api_anchor
        replacement = include if path == 'products.php' else include + api_anchor
        data = baseline[path]
        if sha256(data) != before or data.count(original) != 1:
            raise ValueError('Expected the immutable heading fixture: ' + path)
        result[path] = data.replace(original, replacement, 1)
        if sha256(result[path]) != after:
            raise ValueError('Unexpected notification-form comparison fixture: ' + path)
    return result


def run_heading_variants(root, site, origin, assets, output):
    suite = root / 'tests/keyboard-browser'
    manifest = json.loads((suite / 'heading-sources.json').read_text())
    baseline = decode_manifest(manifest)
    runtime_candidate = {p: (site / p).read_bytes() for p in EXPECTED_PATHS}
    candidate = {p: storage_identity.restore(p, data) for p, data in runtime_candidate.items()}
    verify_candidate(manifest, candidate)  # Fail closed before any heading browser starts.
    restored = cart_restoration(candidate, baseline)
    browser_baseline = browser_notification_baseline(baseline)
    heading_output = output / 'headings'
    heading_output.mkdir()
    reports = {}; failures = []
    seed_recommendations(site)
    seed_seller(site)
    # Reuse the exact same initial synthetic DB for every variant, so read-side
    # setup and session-local data cannot bias the presentation comparison.
    database = site / 'database/flipandstrip.db'
    seed_path = heading_output/'synthetic-seed.sqlite'
    with fixture_database(database) as source, fixture_database(seed_path) as target:
        source.backup(target)
    seed = seed_path.read_bytes()
    identity = {'base': EXPECTED_BASE, 'manifest_sha256': sha256((suite/'heading-sources.json').read_bytes()),
                'source_patch_sha256': manifest['source_patch_sha256'],
                'candidate_sha256': {p: sha256(b) for p,b in candidate.items()},
                'storage_runtime_sha256': {p: sha256(b) for p,b in runtime_candidate.items()},
                'baseline_sha256': {p: sha256(b) for p,b in baseline.items()},
                'notification_baseline_sha256': {p: sha256(browser_baseline[p]) for p in NOTIFICATION_BASELINE_HASHES},
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
        for variant, source in [('candidate', candidate), ('baseline', browser_baseline), ('cart-parent-restored', restored), ('residual-restored', residual_restoration(candidate))]:
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
