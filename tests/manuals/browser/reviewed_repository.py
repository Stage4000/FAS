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
ANALYTICS_BLOB = 'ab6e86db443145db471f7297cb7c9d86c91e8838'
ANALYTICS_TEST_BLOB = 'f667e88ea1b2ebd9340fdc2ef6a831606a9663eb'
GALLERY_FOCUS_BLOB = 'f0261fb7c43544d14881b7cc952e28d7176267f3'
COMPATIBILITY = {
    'product.php': 'M',
    'tests/keyboard-browser/heading-unit-test.py': 'M',
    'tests/keyboard-browser/email-restore.cjs': 'M',
    'tests/keyboard-browser/shipping_identity.py': 'A',
    'tests/keyboard-browser/shipping-identity-test.py': 'A',
    'tests/keyboard-browser/storage-unavailable.cjs': 'M',
    'tests/keyboard-browser/malformed-hydration.cjs': 'M',
    # Menu visibility and keyboard focus after leaving the compact navigation.
    'public/css/style.css': 'M',
    'tests/keyboard-browser/navbar.cjs': 'M',
    # Responsive homepage branding and the offline browser fixture that renders it.
    'index.php': 'M',
    'public/images/hero-image-300.webp': 'A',
    'public/images/hero-image-600.webp': 'A',
    'public/images/hero-image-900.webp': 'A',
    'tests/homepage-visual/prepare-fixture.py': 'M',
    'tests/homepage-visual/check-layout.cjs': 'M',
    'tests/analytics-link-classification-test.cjs': 'A',
    'public/js/analytics.js': 'M',
    # Saved-search capture and the two catalog notification renderers.
    'api/products.php': 'M',
    'api/saved-search.php': 'M',
    'includes/saved-search-notify-form.php': 'A',
    'products.php': 'M',
    'src/utils/SavedSearchCapture.php': 'M',
    'tests/catalog-heading-identity-test.py': 'A',
    'tests/catalog-focus-restoration-test.cjs': 'A',
    'tests/catalog-loader-lifecycle-test.cjs': 'M',
    'tests/form-input-shape-test.php': 'M',
    'tests/saved-search-capture-test.php': 'A',
    'admin/sale.php': 'M',
    'tests/sale-schedule-http-test.py': 'A',
    # Exact checkout discount row presentation and lifecycle regression.
    'checkout.php': 'M',
    'tests/checkout-coupon-lifecycle-test.cjs': 'A',
    'tests/cart-malformed-hydration-test.cjs': 'M',
    'tests/keyboard-browser/shipping-identity-test.py': 'A',
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


# Exact already-merged main drift and finite large-original changes.
# Bind preimages and postimages without admitting other repository changes.
LATER_MAIN = {
    'admin/growth.php': ('8f88313f57559cf42dd9d7d24f97add0a96f4266', '4012d5ca71266c99012dceaa97f3aa2e92275c2c'),
    'admin/merchant-feed-health.php': ('e3e6ec6ac3b9fb9f5945c65b3c69744ffa4e4bc8', 'acf76cfa8c3f77b18b9a1188bd15238129e0572d'),
    'admin/product-content.php': ('c162e2c4d39e9b04ec0a9876e15006a3ff341e22', 'ff517418713a330c9ff72db3c8094e4d645f16ff'),
    'admin/purgestore.php': ('36f6d450157a9f68ceee971cb5e9da705bd0dbac', 'fff83e6f5ccc4b5cc1d892c036812d8696f757c5'),
    'admin/pwa-test.php': ('e6cc7d43f6cda322c605dc4717ff2d49d8d900db', '01ac9c3bd11fdcc32af588c0cc7cecadc2c0309a'),
    'cart.php': ('badbd6ca9a53fef9672b0cf8b0ec6a2122c46aac', 'd78ea31930a80ba5ca0550615455b36a6ebeb5b7'),
    'docs/responsive-images.md': ('545e71d7f86cfbae1c8782f029fa4432c36d2924', 'f70d4da75f076c9ffd158dc088ac7f5701414f66'),
    'docs/showcase-admin-entry.md': ('2865f31d095f572767209c97361afc8f4e5518a1', 'c806bb09e6d44d7895032e7cbba020eaa3ef4b44'),
    'includes/footer.php': ('2ad0d3cb8581fc09e71d307650606149c6dc7009', '06466de2889887556465ac5cf97b269bb11b645e'),
    'includes/saved-search-notify-form.php': ('75a773af8dc82988be07246c5da2e30f98d49cdf', 'a36c9668efb799afc58ac17a2788ab8132dec522'),
    'products.php': ('655e3bca25ad5e8ef5b28a357c1c40dabef68ca6', '8e66e1bbfd33ebb7de23566de02de128c9bdb2d8'),
    'public/js/growth.js': ('1c84708e8cc8cf021f943f8c6f17b537d2eb2692', '4d9012c4bb69c23c370f29ffc6f4b3a737ec0882'),
    'src/integrations/EbayAPI.php': ('0fcf9c088e4ed6c12c98b2021273a1f66de47cf7', 'cbde6d9aca411165911509008e0225823aab680f'),
    'src/utils/ImportedImage.php': ('802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'a8a5a33cc6e57936a4a642335f66bb3442f74b7a'),
    'src/utils/MerchantFeedBuilder.php': ('96f08ce3b940bae904326eacd4318040ff551804', '0753b18332f4333439cd467589e65befdd562493'),
    'src/utils/merchant-feed-gallery-rules.php': (None, 'e25b59fea01ecee296929a91ac680578e899e8ac'),
    'src/utils/merchant-feed-image-pairs.php': (None, 'aac488d12c30073bbb3ef99f3e998d4a1a3c7899'),
    'tests/merchant-feed-image-test.php': (None, 'e96d11ee161a9220f9e170564fdd1c3daa84c0b2'),
    'tests/remote-image-http-test.py': ('9cab467c5e8a0967a68f18a1c34bd097a5ec9ac3', '29064df9d14db377e54f62f0b547d6041f5f7b5c'),
    'tests/saved-search-rendering-test.cjs': ('afb8575f7ed86101a96f510526863c210622941d', 'a8e6b7b54e07400dae94cbba56c82eb35108d3c0'),
    # Large-original work and already-merged #201/#202 paths.
    'admin/manuals-preview.php': ('ba32474c66f5eb089b10d01db6a49a7af5ed8f22', 'b1d65c5660da729469fb04b6f0c092a5e3326185'),
    'docs/manuals-range-delivery.md': ('649d61ac090e1b6da026bc08e5952d2309875c21', '100741ab90d3fb409577619da86fadf5e27a347a'),
    'docs/manuals-system.md': ('c5803c876228c960da9c86211ec5271982f28bfc', 'e326599281796ed1d182ed8abf07291f50def790'),
    'includes/manual-reader.php': ('b95a0f122c019bc55ad0f1cf0eb428c21a914dc4', 'fcbf4341cd72a986830ce96a6c518d249d9b748d'),
    'manuals/view.php': ('b83035ef391273a900307a866b6dc565a5615922', 'c0f0790aff779f92b2abbb76ecb439cc9f5d6012'),
    'public/js/manual-reader-loader.mjs': ('ea718a243941705dc65021b03e9c3474fd33593d', '3dca18151aedc829c568b90f91479cfc8e763749'),
    'public/js/manual-reader.mjs': ('fbc1c8c880c2d999304995cdcdf86f885eb91be0', '40435f11d0f7fbd4c19a347c3b4c641171f1d32a'),
    'scripts/confirmation-scheduler.php': (None, '0772696f4f1e75d5201e9c089dfb60663e9b07cb'),
    'src/manuals/.htaccess': (None, '187c2bd4dbefc0582fe860cd45a0756c6965adf7'),
    'src/manuals/ManualLibrary.php': ('165487299dd7d6b5685897bc3ca9e26b6d124556', '088bcaba868d4de83f3cfe7ce790e32974bb4e3e'),
    'src/marketing/Growth.php': ('11b42ad23954ae8033b5e855776cb6f04c7b0e59', '00def8c10b170a4634b64d4ddae96f85b71795bc'),
    'tests/growth-test.php': ('eb5a5e7adb9a882b8e3e65a502932cff6e565739', '3837bf9f975079a2e138fa5e40d46f60dad6b1bb'),
    'tests/manuals/browser/run.py': ('237538ca151de6aa4c5a15e13e87a440cc7f3d39', 'f9db72a28833273077e4bb834a93a311621c79c4'),
    'tests/manuals/large-original-browser-test.cjs': (None, '983e50b52b2983a5dba038a1cd1a079173d92716'),
    'tests/manuals/large-original-test.py': (None, '545799f6b8dcc66ac0ea585f4b58e40f8d0b01a3'),
    'tests/manuals/range-test.php': ('3c00defd330a8182c2aad84de2d60eba89826111', 'f43aef715ad3427bd89fafc3b33f87a518c2b85a'),
    'tests/manuals/upload-storage-test.php': ('27cecd0cba20de878b2aff7cae45959131c863e0', 'b7972750034798783f49cc7e65bfda5af133e23a'),
}


LARGE_MANUAL_TEST_SOURCES = {
    'tests/manuals/large-original-browser-test.cjs',
    'tests/manuals/large-original-test.py',
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
    # Pin the contact form readiness change and its matching offline fixtures.
    reviewed_postimages = {
        'contact.php': '5ec0eb84bd8a42334d71913430daca20e88e5043',
        'tests/contact-pending-edits-test.cjs': 'd6c9fa0f2d43ae2de34e79c961e1f06f53b44717',
        'tests/contact-retry-test.cjs': '4ced3041b3ba9c66e7b39453bbd7996ceeacd76f',
        # Exact catalog scroll controls retain the new menu colors.
        'tests/keyboard-browser/catalog-scroll-identity.cjs': '4e4907a7268cacc2e582a749b7086bc26ddf60a6',
        'tests/keyboard-browser/catalog-scroll-policy-test.cjs': 'cfdaf9e84f597edafa61040468c82c475b1edb9b',
        'tests/keyboard-browser/catalog-scroll.cjs': '818a94719167a1e0fb7773f9403f4c75d2ca4726',
    }
    for path, oid in reviewed_postimages.items():
        main_delta[path] = dict(integrated[path], sha=oid)
    reviewed.update(main_delta)
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
    for path, (before, after) in LATER_MAIN.items():
        require(re.search(r'^[0-9a-f]{40}$', after) is not None, 'Invalid later main postimage')
        if path not in COMPATIBILITY:
            historical = SHOWCASE_ADDITIONS.get(path, reviewed.get(path, {}).get('sha'))
            require(historical == before, 'Later main preimage mismatch: ' + path)
        changes[path] = 'M' if path in main else 'A'
    require(manifest['allowed_changes'] == changes, 'Exact changed path/status map required')
    sources = manifest['source_blobs']
    require(set(sources) == (set(original['source_blobs']) | set(COMPATIBILITY) | set(SHOWCASE_ADDITIONS) | LARGE_MANUAL_TEST_SOURCES) - {MANIFEST},
            'Exact source pin keys required')
    for path, oid in sources.items():
        require(isinstance(oid, str) and re.fullmatch('[0-9a-f]{40}', oid), 'Invalid source pin')
        if path in LATER_MAIN:
            require(oid == LATER_MAIN[path][1], 'Exact later main source pin mismatch: ' + path)
        elif path in SHOWCASE_ADDITIONS:
            require(oid == SHOWCASE_ADDITIONS[path], 'Exact showcase source pin mismatch: ' + path)
        elif path not in COMPATIBILITY:
            expected = integrated[path]['sha'] if path in main_delta else original['source_blobs'][path]
            require(oid == expected == reviewed[path]['sha'],
                    'Immutable source pin changed: ' + path)
    require(sources['tests/analytics-link-classification-test.cjs'] == ANALYTICS_TEST_BLOB, 'Exact analytics regression pin mismatch')
    require(sources['public/js/analytics.js'] == ANALYTICS_BLOB, 'Exact analytics source pin mismatch')
    require(sources['product.php'] == GALLERY_FOCUS_BLOB, 'Exact thumbnail focus source required')
    for path in COMPATIBILITY:
        data = read_regular(root, path)
        oid = blob(data)
        require(path == MANIFEST or oid == sources[path], 'Compatibility source pin mismatch: ' + path)
        reviewed[path] = {'mode': '100644', 'type': 'blob', 'sha': oid}
    reviewed.update({p: {'mode': '100644', 'type': 'blob', 'sha': oid}
                     for p, oid in SHOWCASE_ADDITIONS.items()})
    reviewed.update({p: {'mode': '100644', 'type': 'blob', 'sha': after}
                     for p, (_, after) in LATER_MAIN.items()})
    return {'expected': reviewed, 'changes': changes, 'main': main,
            'historical_main': {p: {'before': before, 'after': main[p]['sha']}
                                for p, before in HISTORICAL_MAIN.items()},
            'overlay': {p: reviewed[p] for p in set(additions) | set(COMPATIBILITY) | set(SHOWCASE_ADDITIONS) | set(HISTORICAL_MAIN) | set(main_delta) | set(LATER_MAIN)}}


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

