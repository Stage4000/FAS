"""Offline guard-only tests; never import or invoke the acceptance runner.

Only the reviewed require/verify_candidate function bodies are compiled from
run.py. All Git commands target new temporary repositories with no remotes,
hooks or inherited Git configuration. No application or browser is started.
"""
from __future__ import annotations

import ast
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


EXPECTED_BASELINE = '81dc308e6a7830f31ea52260a7122cee4c6d5222'
EXPECTED_PATHS = {'.github/workflows/keyboard-browser.yml': 'M', 'about.php': 'M', 'admin/banners.php': 'M', 'admin/coupons.php': 'M', 'admin/ebay-sync-health.php': 'M', 'admin/purgestore.php': 'M', 'admin/sale.php': 'M', 'admin/stale-inventory.php': 'M', 'api/contact-form.php': 'M', 'api/products.php': 'M', 'api/shipping-estimate.php': 'M', 'api/validate-coupon.php': 'M', 'cart.php': 'M', 'checkout.php': 'M', 'contact.php': 'M', 'docs/reviewed-product-facts.md': 'M', 'includes/catalog-meta.php': 'M', 'includes/header.php': 'M', 'includes/product-merchandising.php': 'M', 'includes/sale-helper.php': 'M', 'product.php': 'M', 'products.php': 'M', 'public/css/style.css': 'M', 'public/images/navbar-logo.png': 'A', 'public/js/growth.js': 'M', 'public/js/shipping-estimator.js': 'M', 'src/integrations/EbayAPI.php': 'M', 'src/models/Banner.php': 'M', 'src/models/Coupon.php': 'M', 'src/shipping/CarrierRates.php': 'M', 'src/utils/ReviewedProductFacts.php': 'M', 'src/utils/ShippingRules.php': 'M', 'src/utils/Timezone.php': 'M', 'tests/admin-product-browser/guard-test.py': 'M', 'tests/admin-product-browser/run.py': 'M', 'tests/banner-csrf-http-test.py': 'A', 'tests/banner-input-validation-http-test.py': 'A', 'tests/banner-schedule-timezone-test.php': 'A', 'tests/campaign-code-case-test.php': 'A', 'tests/cart-malformed-hydration-test.cjs': 'M', 'tests/catalog-focus-restoration-test.cjs': 'A', 'tests/catalog-heading-identity-test.py': 'A', 'tests/catalog-loader-lifecycle-test.cjs': 'M', 'tests/catalog-scroll-offset-test.cjs': 'A', 'tests/catalog-search-layout-test.cjs': 'M', 'tests/catalog-zero-search-test.php': 'A', 'tests/checkout-coupon-lifecycle-test.cjs': 'A', 'tests/condition-badge-contrast-test.py': 'A', 'tests/contact-delivery-test.py': 'A', 'tests/contact-input-purpose-test.py': 'A', 'tests/contact-pending-edits-test.cjs': 'A', 'tests/contact-provider-response-http-test.py': 'A', 'tests/contact-retry-test.cjs': 'A', 'tests/coupon-active-checkbox-http-test.py': 'A', 'tests/coupon-expiry-timezone-test.php': 'A', 'tests/coupon-input-shape-http-test.py': 'A', 'tests/ebay-remaining-quantity-test.php': 'A', 'tests/fixtures/image-auto-bootstrap/FixedImageBootstrap.php': 'A', 'tests/fixtures/image-auto-local/ImmutableImageSnapshot.php': 'A', 'tests/fixtures/image-auto-local/ImmutableLocalPilot.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageEffectObservation.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageIntakeBinding.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImagePilot.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceIntake.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceSelection.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImportedImage.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/PosixImageIntakeStore.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ResponsiveImage.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/ControllerQuotaWitness.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/CycleAdmissionBridge.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-cohesive.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-retained-lifetime.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/worker.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageOperationPilot.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageRemoteAcquisition.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_coordinator.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_default_off.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_superclass_guard.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageOperationFrontEnd.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageQuotaAdmission.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/test_front_end.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/test_quota.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/ImageObservedSettlement.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/ImageOperationBinding.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.json': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.sql': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.json': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.sql': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/schema-empty.sql': 'A', 'tests/fixtures/image-auto-operation/operation-v6/same-process-fixture.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/schema-v6.json': 'A', 'tests/fixtures/image-auto-operation/operation-v6/test_pdo.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/test_same_process.php': 'A', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/source.png.base64': 'A', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/variant.webp.base64': 'A', 'tests/fixtures/image-auto-pilot/ImageIntakeBinding.php': 'A', 'tests/fixtures/image-auto-pilot/ImagePilot.php': 'A', 'tests/fixtures/image-auto-pilot/ImageSourceIntake.php': 'A', 'tests/fixtures/image-auto-pilot/ImageSourceSelection.php': 'A', 'tests/fixtures/image-auto-pilot/ImportedImage.php': 'A', 'tests/fixtures/image-auto-pilot/PosixImageIntakeStore.php': 'A', 'tests/fixtures/image-auto-pilot/ResponsiveImage.php': 'A', 'tests/fixtures/image-auto-pilot/entrypoints/automatic-pilot.php': 'A', 'tests/fixtures/image-auto-pilot/entrypoints/intake.php': 'A', 'tests/fixtures/image-handoff-migration/ImageHandoffMigration.php': 'A', 'tests/fixtures/image-handoff-migration/schema-v2.json': 'A', 'tests/fixtures/image-handoff-migration/schema-v3.json': 'A', 'tests/fixtures/image-handoff-v2/ImageHandoffV2.php': 'A', 'tests/fixtures/image-handoff-v2/ImageSnapshotV2.php': 'A', 'tests/fixtures/image-handoff-v2/outbox.sql': 'A', 'tests/fixtures/image-handoff-v2/schema-manifest.json': 'A', 'tests/fixtures/image-handoff-v3/ImageHandoffV3.php': 'A', 'tests/fixtures/image-handoff-v3/ImageSnapshotV2.php': 'A', 'tests/fixtures/image-handoff-v3/schema-manifest.json': 'A', 'tests/fixtures/image-pilot/ImagePilot.php': 'A', 'tests/fixtures/image-pilot/ImportedImage.php': 'A', 'tests/fixtures/image-pilot/ResponsiveImage.php': 'A', 'tests/fixtures/image-pilot/pilot.php': 'A', 'tests/free-shipping-destination-api-test.py': 'A', 'tests/free-shipping-destination-test.php': 'A', 'tests/homepage-branding-test.py': 'M', 'tests/homepage-visual/check-layout.cjs': 'M', 'tests/homepage-visual/prepare-fixture.py': 'M', 'tests/image-auto-bootstrap-boundary-test.py': 'A', 'tests/image-auto-bootstrap-lifecycle-test.py': 'A', 'tests/image-auto-bounded-snapshot-test.py': 'A', 'tests/image-auto-controller-cycle-test.py': 'A', 'tests/image-auto-controller-quota-test.php': 'A', 'tests/image-auto-entrypoint-boundary-test.py': 'A', 'tests/image-auto-entrypoint-pdo-test.py': 'A', 'tests/image-auto-gallery-binding-test.php': 'A', 'tests/image-auto-gallery-pipeline-test.php': 'A', 'tests/image-auto-immutable-local-pipeline-test.php': 'A', 'tests/image-auto-immutable-snapshot-test.php': 'A', 'tests/image-auto-intake-binding-test.php': 'A', 'tests/image-auto-intake-pipeline-test.php': 'A', 'tests/image-auto-intake-test.php': 'A', 'tests/image-auto-operation-binding-test.php': 'A', 'tests/image-auto-operation-composition-test.php': 'A', 'tests/image-auto-operation-coordinator-test.php': 'A', 'tests/image-auto-operation-default-off-test.php': 'A', 'tests/image-auto-operation-front-end-test.php': 'A', 'tests/image-auto-operation-quota-test.php': 'A', 'tests/image-auto-operation-settlement-test.php': 'A', 'tests/image-auto-operation-superclass-test.php': 'A', 'tests/image-auto-pilot-intake-integration-test.php': 'A', 'tests/image-auto-posix-intake-store-test.php': 'A', 'tests/image-auto-source-selection-test.php': 'A', 'tests/image-auto-source-url-test.php': 'A', 'tests/image-handoff-migration-file-test.php': 'A', 'tests/image-handoff-migration-test.php': 'A', 'tests/image-handoff-v2-concurrency-test.php': 'A', 'tests/image-handoff-v2-integration-test.php': 'A', 'tests/image-handoff-v2-pdo-test.php': 'A', 'tests/image-handoff-v2-writer-contract-test.php': 'A', 'tests/image-handoff-v3-quarantine-test.php': 'A', 'tests/image-handoff-v3-race-test.php': 'A', 'tests/image-pilot-cli-test.py': 'A', 'tests/image-pilot-test.php': 'A', 'tests/keyboard-browser/README.md': 'M', 'tests/keyboard-browser/catalog-scroll-guard-test.py': 'A', 'tests/keyboard-browser/catalog-scroll-identity.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy.cjs': 'A', 'tests/keyboard-browser/catalog-scroll.cjs': 'A', 'tests/keyboard-browser/email-restore.cjs': 'M', 'tests/keyboard-browser/heading-unit-test.py': 'M', 'tests/keyboard-browser/heading_runner.py': 'M', 'tests/keyboard-browser/malformed-hydration.cjs': 'M', 'tests/keyboard-browser/navbar.cjs': 'M', 'tests/keyboard-browser/run.py': 'M', 'tests/keyboard-browser/shipping-identity-test.py': 'A', 'tests/keyboard-browser/shipping_identity.py': 'A', 'tests/keyboard-browser/storage-unavailable.cjs': 'M', 'tests/keyboard-browser/storage_identity.py': 'M', 'tests/newsletter-pending-edits-test.cjs': 'A', 'tests/product-gallery-identity-test.py': 'A', 'tests/product-gallery-selection-test.php': 'A', 'tests/purgestore-csrf-test.py': 'A', 'tests/rotor-reviewed-facts-test.php': 'A', 'tests/sale-schedule-http-test.py': 'A', 'tests/sale-schedule-test.py': 'A', 'tests/saved-search-rendering-test.cjs': 'A', 'tests/shipping-parcel-total-boundary-test.php': 'A', 'tests/stale-inventory-csrf-http-test.py': 'A'}
EXPECTED_RUNTIME = {'about.php': {'before': '47c399d13b31cb6439ec46b8901c68961d06e529', 'after': 'ededab775a76bb3c1443f0cde713b3903b34fbbf'}, 'admin/banners.php': {'before': '0d029994a786a312be85b4a1882c17fd3d9310de', 'after': '06d348970d6e175285c2ab6adfa8501d730d269b'}, 'admin/coupons.php': {'before': '72426da91e2a916079408cf0008dead8bdc69a00', 'after': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098'}, 'admin/ebay-sync-health.php': {'before': '218e24714173b4a992cf2e898071471599b0d872', 'after': '4fd912aad6e5e2d513360a369a33b4018087df34'}, 'admin/purgestore.php': {'before': '89c9fcf9d64fc2607a841f9253b3aeab8d865054', 'after': '36f6d450157a9f68ceee971cb5e9da705bd0dbac'}, 'admin/sale.php': {'before': '5075fa0ca55c51aa3177ae265a31bbecdb264d60', 'after': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6'}, 'admin/stale-inventory.php': {'before': 'b43798f11a2c822de1aaa559f97844c0344ef36e', 'after': '484b9a5c65d167b342e7eecc81591adadba43b01'}, 'api/contact-form.php': {'before': 'aec0ec7a0c32819070ef41c22bf64a9db9a8a41c', 'after': '6f97709f0b1770fc27e136fb20e6066cd12e1b37'}, 'api/products.php': {'before': 'a284c99de99c7337e4cb8a1fa3f0a2a870aa4e8c', 'after': '6ea53e5d29f2b0d101e5e09acaeff067e1522d75'}, 'api/shipping-estimate.php': {'before': '187be706bac656a753fb18d42354e5838191a65f', 'after': '6f68638d9c961e75bfbb7594a616ffb868f4bc75'}, 'api/validate-coupon.php': {'before': '37e6e2f7f95ca8c2d8e86d27562079a5ab4d07c9', 'after': '652bea7a87733a16ecb27deb57367a3c2e036e48'}, 'cart.php': {'before': '0082e0fcabbc1be3678235e2a7e9c18585e419cb', 'after': 'badbd6ca9a53fef9672b0cf8b0ec6a2122c46aac'}, 'checkout.php': {'before': '7c98553a15d96a36620f7af1fcff363b3fa3ba16', 'after': '55fbb9cd5d9da429d5b7314d3de41de1bd995ad4'}, 'contact.php': {'before': '103ca56061c7d1e2e8b817ab1c6b4d3e3e0f267d', 'after': '52a106dbb898ecdf50c68dbaabfd7d08f80c089a'}, 'includes/catalog-meta.php': {'before': '7ad7672b9b8def171681b1fc7e476f41bbce6e9f', 'after': 'b77464e1cdf9f4b5684f1bde224dca683ab8a428'}, 'includes/header.php': {'before': 'b8ecc74f67f306afa142339b1641fc916a624e2c', 'after': '586913e8bb8eefcf79a5a74f39fd02b406bbc10d'}, 'includes/product-merchandising.php': {'before': '4579c1f0e1c14c35ffae58e6cf7f57c07989e700', 'after': 'db1e8b0434362b1ce12f723d83593556d5cc4299'}, 'includes/sale-helper.php': {'before': 'c2d56d9d6d80e5c5e87263b9b0d5c7bf516139d1', 'after': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7'}, 'product.php': {'before': 'ff1a31cc764c881f7acbd392b563276bab9747c9', 'after': 'd00036ad68cd4d01cf1ae254d8f34d53c4a62478'}, 'products.php': {'before': 'e03bc2480c05adf03ea602b6706bd68c0eec8afa', 'after': '82bef0dc76c40a5aaca005425d8856df30cf1b8d'}, 'public/css/style.css': {'before': '103706af92484631585fcc26553239b0b42681c4', 'after': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052'}, 'public/js/growth.js': {'before': '87dac7559458d16f9adc755dc404d535beeeb9eb', 'after': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692'}, 'public/js/shipping-estimator.js': {'before': '729a22d1b6e993ec904d29d9595ed45b6a268e76', 'after': '13b11995172cabbf7be019232b45eed941c97a02'}, 'src/integrations/EbayAPI.php': {'before': 'd059b65637f6602c8ec21c52882e2c52890b2493', 'after': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7'}, 'src/models/Banner.php': {'before': '39e97c109294b925d9aa2b1f51a4e9186ffe2120', 'after': '2adbeafae3c4f1373409949c532520e51619daae'}, 'src/models/Coupon.php': {'before': '53ad053d7110a1f4f6d6e136d22cb03bf4742d05', 'after': 'bb899ad8a612b4c38fd0d18c72339123d57105d8'}, 'src/shipping/CarrierRates.php': {'before': 'c58d6692808178342c4efbb0ea8b8ad9a61d9a03', 'after': '1de6765d76ab0ea11961971f9c70d53c8c019100'}, 'src/utils/ReviewedProductFacts.php': {'before': 'af5c5e4b277dd3005e825ec0f384756ed9441637', 'after': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'src/utils/ShippingRules.php': {'before': 'aecc28f5796850372f2892b2d8ef5645f0cbc6d7', 'after': 'ce56c43e6120af85c8e0a12f755d661619677c30'}, 'src/utils/Timezone.php': {'before': 'b0074c7dccfebcae5ee84db633b81967086941b3', 'after': 'deb1ee68601f2b4467460bca1c8bddfc863ab1aa'}}
EXPECTED_BASELINE_TREE = '00ebb2f16bea9655f81c88de754fee75b76c534f'
EXPECTED_STORAGE_BLOBS = {'.github/workflows/keyboard-browser.yml': 'c007f1556505b1e925d8d2774df6eab3139daa91', 'about.php': 'ededab775a76bb3c1443f0cde713b3903b34fbbf', 'admin/banners.php': '06d348970d6e175285c2ab6adfa8501d730d269b', 'admin/coupons.php': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098', 'admin/ebay-sync-health.php': '4fd912aad6e5e2d513360a369a33b4018087df34', 'admin/purgestore.php': '36f6d450157a9f68ceee971cb5e9da705bd0dbac', 'admin/sale.php': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6', 'admin/stale-inventory.php': '484b9a5c65d167b342e7eecc81591adadba43b01', 'api/contact-form.php': '6f97709f0b1770fc27e136fb20e6066cd12e1b37', 'api/products.php': '6ea53e5d29f2b0d101e5e09acaeff067e1522d75', 'api/shipping-estimate.php': '6f68638d9c961e75bfbb7594a616ffb868f4bc75', 'api/validate-coupon.php': '652bea7a87733a16ecb27deb57367a3c2e036e48', 'cart.php': 'badbd6ca9a53fef9672b0cf8b0ec6a2122c46aac', 'checkout.php': '55fbb9cd5d9da429d5b7314d3de41de1bd995ad4', 'contact.php': '52a106dbb898ecdf50c68dbaabfd7d08f80c089a', 'docs/reviewed-product-facts.md': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060', 'includes/catalog-meta.php': 'b77464e1cdf9f4b5684f1bde224dca683ab8a428', 'includes/header.php': '586913e8bb8eefcf79a5a74f39fd02b406bbc10d', 'includes/product-merchandising.php': 'db1e8b0434362b1ce12f723d83593556d5cc4299', 'includes/sale-helper.php': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7', 'product.php': 'd00036ad68cd4d01cf1ae254d8f34d53c4a62478', 'products.php': '82bef0dc76c40a5aaca005425d8856df30cf1b8d', 'public/css/style.css': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052', 'public/images/navbar-logo.png': 'cc39aa73e9c93bb75346bd96394e2f59184445f6', 'public/js/growth.js': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692', 'public/js/shipping-estimator.js': '13b11995172cabbf7be019232b45eed941c97a02', 'src/integrations/EbayAPI.php': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7', 'src/models/Banner.php': '2adbeafae3c4f1373409949c532520e51619daae', 'src/models/Coupon.php': 'bb899ad8a612b4c38fd0d18c72339123d57105d8', 'src/shipping/CarrierRates.php': '1de6765d76ab0ea11961971f9c70d53c8c019100', 'src/utils/ReviewedProductFacts.php': '1db5e4468b9730b12cad79f41f7094c2144571fb', 'src/utils/ShippingRules.php': 'ce56c43e6120af85c8e0a12f755d661619677c30', 'src/utils/Timezone.php': 'deb1ee68601f2b4467460bca1c8bddfc863ab1aa', 'tests/banner-csrf-http-test.py': 'd7ae700887e1daaf0fc57518f4bdd24606024c33', 'tests/banner-input-validation-http-test.py': '6c8e5274bce727af794fdfbf21d7f17afa995139', 'tests/banner-schedule-timezone-test.php': '18cd1384c0a26ca280a695ecbbb34e78583942e6', 'tests/campaign-code-case-test.php': '08007f693916900c8b72ebb5718fa7d7c825565b', 'tests/cart-malformed-hydration-test.cjs': 'd72d25178e2ab3f414f6581eb4a15c9554a939fb', 'tests/catalog-focus-restoration-test.cjs': 'ea5b7eaf6e5ff513ccf8b82827bdfacc45a5de0f', 'tests/catalog-heading-identity-test.py': 'da903694533a2ca00981fea5ef178a44a600cd9f', 'tests/catalog-loader-lifecycle-test.cjs': '44244918933b5bd8220c5c202403b2a8742f8a50', 'tests/catalog-scroll-offset-test.cjs': 'bd822d1f3cee43f13cec01e151685ad88134da58', 'tests/catalog-search-layout-test.cjs': '88326b077688f393fa45fd5f36436a53a884e303', 'tests/catalog-zero-search-test.php': 'c56d9d6668d3b9684c61c940b7317e94fb3fcb90', 'tests/checkout-coupon-lifecycle-test.cjs': 'c696139b8dd15d47ead2001ffa59353756bfe138', 'tests/condition-badge-contrast-test.py': '52c79b6074e8e85e4e2d9b5ac12eaee92cb3f703', 'tests/contact-delivery-test.py': '2e4e160cf6c62d4eb3eec8b8668fe88724f1722c', 'tests/contact-input-purpose-test.py': '34b00ab77be32623d2547badc70241dd69b55416', 'tests/contact-pending-edits-test.cjs': 'e20affefa282fe0bee690033cacf828fec54221a', 'tests/contact-provider-response-http-test.py': '1b1254fccf75ea69bc495cd9331bde8edd4be3cd', 'tests/contact-retry-test.cjs': 'dccea24eed65314386728bdf9c16937801830347', 'tests/coupon-active-checkbox-http-test.py': '41ed98b47c9f8553fb77d54764d66a876ebc8411', 'tests/coupon-expiry-timezone-test.php': 'ffd444d00624e127638180aebc857bd2a1c1e28c', 'tests/coupon-input-shape-http-test.py': 'b1031f0aa30c68774847f74aaafbb94df52fb479', 'tests/ebay-remaining-quantity-test.php': 'ac53e8cd9705355f1fdcd794e3adc2bd896d17ce', 'tests/fixtures/image-auto-bootstrap/FixedImageBootstrap.php': '2ee98ccd884eda672642b09037bd604a06fe9f1a', 'tests/fixtures/image-auto-local/ImmutableImageSnapshot.php': '4fcdee7e349a622c28f3e3b231a07ea0b15342ba', 'tests/fixtures/image-auto-local/ImmutableLocalPilot.php': '2b12295ccbb99c5823997794057028d35318d8ec', 'tests/fixtures/image-auto-operation/candidate/src/ImageEffectObservation.php': '6d039e17589eca14af3c3e710be026e7c3566fd1', 'tests/fixtures/image-auto-operation/candidate/src/ImageIntakeBinding.php': '435a1d12041f3e8852e479b03f84ce9c6dbeb3a3', 'tests/fixtures/image-auto-operation/candidate/src/ImagePilot.php': '12f5dce030ccde2f0121fd79a317751d9f628bdc', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceIntake.php': '438cf977c26224a15ddd2ec0f65d02fcf8884629', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceSelection.php': '0ac0bfb10e92d3aa28482e7565ab48e613c83b68', 'tests/fixtures/image-auto-operation/candidate/src/ImportedImage.php': '802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'tests/fixtures/image-auto-operation/candidate/src/PosixImageIntakeStore.php': '7016a52f8813bc4183b392fbef848cc3695db3b5', 'tests/fixtures/image-auto-operation/candidate/src/ResponsiveImage.php': 'd8bfa725d5aaf7320b3c1052966376129379fdd4', 'tests/fixtures/image-auto-operation/controller-cycle-next/ControllerQuotaWitness.php': 'db3989c69b5513544dc62e16b9172db4fbb45355', 'tests/fixtures/image-auto-operation/controller-cycle-next/CycleAdmissionBridge.php': 'f0c5c6c6ed254029fcd3ee96b492ce65a258a638', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-cohesive.php': 'ad4fa6b12e5d0c7529d556bdd4946107f259e3d7', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-retained-lifetime.php': '5ddfa9b7f819dd71b19ad787ffb6c29d1faa0b7a', 'tests/fixtures/image-auto-operation/controller-cycle-next/worker.php': '0fb075f219cd6ad05004dbff81d51f79d5b31294', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageOperationPilot.php': 'ff9157063aa11f1b8f79e21e95ecd77a3ab1c172', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageRemoteAcquisition.php': 'f3a1d88d9117f42fc7cecd5e60f1fed38f56a836', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_coordinator.php': 'f77f1694cc067c2163eec5acac4b1f5af6781370', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_default_off.php': 'fe08ee8baba6a30fc63d8535967dec284bf003a8', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_superclass_guard.php': 'b4a7c0adbe9dd849c5651a17c465272a859387bf', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageOperationFrontEnd.php': '642ea3fab0af49e0328ffe560c0deb61c995b0d6', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageQuotaAdmission.php': 'cc109321b9d2068ea45d40f20499beb3678756a2', 'tests/fixtures/image-auto-operation/front-end-vnext/test_front_end.php': 'aa3c10c6bd262e6f77de96c203dbe630e008c1b6', 'tests/fixtures/image-auto-operation/front-end-vnext/test_quota.php': '46df13e40eee48d1bf4a38c4f9b30dd2d61697ba', 'tests/fixtures/image-auto-operation/operation-v6/ImageObservedSettlement.php': '52b9fe81eb06e45074487770a42b1d5427496450', 'tests/fixtures/image-auto-operation/operation-v6/ImageOperationBinding.php': '67d8472f352b0607e8d500f6fb598b6e91ab5c4f', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.json': '900941fa18a3b695b8747e6f6dba7e1b5a39ec68', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.sql': 'b366c0dce410401c70768d9e16728804c8308754', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.json': '4216181889012f184a65410dc6ba6966251f12c8', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.sql': 'd15f29b6ac1e0be9bc40c962aa69a4af938d359e', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/schema-empty.sql': '0247fec25555f84b434173fa10d49083cf99d322', 'tests/fixtures/image-auto-operation/operation-v6/same-process-fixture.php': '9183e29e7ce75f2bede4546d18586fc9e66e7797', 'tests/fixtures/image-auto-operation/operation-v6/schema-v6.json': '22dfc047d2ccdedc2fc6015b8264aee27634bd14', 'tests/fixtures/image-auto-operation/operation-v6/test_pdo.php': 'f165c88a9a1aa119b2ac19f27f62db15e530c621', 'tests/fixtures/image-auto-operation/operation-v6/test_same_process.php': '16001eeff37b2a5094ab985e0739c6375955262d', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/source.png.base64': '2d801cefd40e3e6a1744b71f3396622997899f78', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/variant.webp.base64': '7618e59ce1aea6b0a706ef5b24e2da2d180685af', 'tests/fixtures/image-auto-pilot/ImageIntakeBinding.php': '435a1d12041f3e8852e479b03f84ce9c6dbeb3a3', 'tests/fixtures/image-auto-pilot/ImagePilot.php': '12f5dce030ccde2f0121fd79a317751d9f628bdc', 'tests/fixtures/image-auto-pilot/ImageSourceIntake.php': '438cf977c26224a15ddd2ec0f65d02fcf8884629', 'tests/fixtures/image-auto-pilot/ImageSourceSelection.php': '0ac0bfb10e92d3aa28482e7565ab48e613c83b68', 'tests/fixtures/image-auto-pilot/ImportedImage.php': '802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'tests/fixtures/image-auto-pilot/PosixImageIntakeStore.php': '7016a52f8813bc4183b392fbef848cc3695db3b5', 'tests/fixtures/image-auto-pilot/ResponsiveImage.php': 'd8bfa725d5aaf7320b3c1052966376129379fdd4', 'tests/fixtures/image-auto-pilot/entrypoints/automatic-pilot.php': '57fdf7a6ef22d6ab866f5fb23c75c05db0925cd3', 'tests/fixtures/image-auto-pilot/entrypoints/intake.php': '648e6fd08c9e2a107c0497a7154b0584b5b94a4f', 'tests/fixtures/image-handoff-migration/ImageHandoffMigration.php': 'd993d991e74c46f4ca076a67743f2aa5272be77d', 'tests/fixtures/image-handoff-migration/schema-v2.json': '5be589dccc6ee804a056bd14c7f38de450467064', 'tests/fixtures/image-handoff-migration/schema-v3.json': 'bc453b2f25d1fe44b9d4d7821e0f6533b09eb3c7', 'tests/fixtures/image-handoff-v2/ImageHandoffV2.php': '16f74aa66ea76843a92a56b2cd7408fab98bb91d', 'tests/fixtures/image-handoff-v2/ImageSnapshotV2.php': '74a45b4adc0eec2dd005355596ef0a176e852649', 'tests/fixtures/image-handoff-v2/outbox.sql': '95e34ea9c435c3214e2c36935b469b5b0ffb41d2', 'tests/fixtures/image-handoff-v2/schema-manifest.json': '5be589dccc6ee804a056bd14c7f38de450467064', 'tests/fixtures/image-handoff-v3/ImageHandoffV3.php': '46a893a1fb744b49764c6a3aaf7b95b30f93c9c0', 'tests/fixtures/image-handoff-v3/ImageSnapshotV2.php': '74a45b4adc0eec2dd005355596ef0a176e852649', 'tests/fixtures/image-handoff-v3/schema-manifest.json': 'bc453b2f25d1fe44b9d4d7821e0f6533b09eb3c7', 'tests/fixtures/image-pilot/ImagePilot.php': '19423938b5a720ed36556198798580436a72229b', 'tests/fixtures/image-pilot/ImportedImage.php': '802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'tests/fixtures/image-pilot/ResponsiveImage.php': 'd8bfa725d5aaf7320b3c1052966376129379fdd4', 'tests/fixtures/image-pilot/pilot.php': 'a4c796f0c4ecc78ee0886cce778d46ffaed452e6', 'tests/free-shipping-destination-api-test.py': '7461b88e9cf1cf1d04cd16c61e0ec999a0a7ac52', 'tests/free-shipping-destination-test.php': '4ebb3ba83755803c7bdd7effa8858a4f169607ba', 'tests/homepage-branding-test.py': 'b1710429404acd153838dd5d40a11f3a4c0e61a3', 'tests/homepage-visual/check-layout.cjs': '6eb3e15e58768ca3070fd80f4a26f0891bb26463', 'tests/homepage-visual/prepare-fixture.py': '7b0828c6e7c49d654362a72657e23cc6ba1b85ff', 'tests/image-auto-bootstrap-boundary-test.py': '9ba7d1d139b5ae8961280aa3e070badcb8010756', 'tests/image-auto-bootstrap-lifecycle-test.py': 'e36e581842abc1c56accca88ccf6e27884d7dda0', 'tests/image-auto-bounded-snapshot-test.py': 'ab27e918a1c253229d2016b8b87adc4d48a4efc1', 'tests/image-auto-controller-cycle-test.py': '0f411f86e16285167b46a81cce902e44dc050d30', 'tests/image-auto-controller-quota-test.php': 'b418ecbf83cdca6ab69e12628f19885fed8b15fe', 'tests/image-auto-entrypoint-boundary-test.py': 'dd5ec99dfac1b46c864548c8bced528f06315242', 'tests/image-auto-entrypoint-pdo-test.py': '08add7f6122d3c062eb30801695c5e83d1723da3', 'tests/image-auto-gallery-binding-test.php': 'b8671582a487b6137a133874cf5e8e13512f788e', 'tests/image-auto-gallery-pipeline-test.php': 'cbc23d06a9400afd148eaf5c93a956c6c748fcb2', 'tests/image-auto-immutable-local-pipeline-test.php': '3bd24dde79c16eef25f48cc15b899b1bc4d2d847', 'tests/image-auto-immutable-snapshot-test.php': '24011ae6e96c2056a5836c3abdc0934d2fb2d97b', 'tests/image-auto-intake-binding-test.php': '4b248e30ce00ed94f117b33240410aa31eea19ce', 'tests/image-auto-intake-pipeline-test.php': 'd192eda9eb8e394dc6f60a919c1c58512c05eb54', 'tests/image-auto-intake-test.php': 'f1e2842becf3d44fc3b1e46e3eb320e7fbbf63ff', 'tests/image-auto-operation-binding-test.php': 'e1a31cbfd7273421b3377ee3c8164ba283a60b62', 'tests/image-auto-operation-composition-test.php': '818353a05391326c285c2cb46a7c078f96337714', 'tests/image-auto-operation-coordinator-test.php': '9e582a1d98a68d9d02c38701415b130d463326ad', 'tests/image-auto-operation-default-off-test.php': '9233ce6ff190e7b0924ea2988b6d031ab4234deb', 'tests/image-auto-operation-front-end-test.php': '9e932a45060d23e10bdb6c76663aaf5e1fcfa470', 'tests/image-auto-operation-quota-test.php': '3d3c65e58b881eacc4765ac35d3508adf94fc5ee', 'tests/image-auto-operation-settlement-test.php': 'ecf19ceca51552daf44e3e94ee0b0dacf85aa3af', 'tests/image-auto-operation-superclass-test.php': '4567574d60ef67d7939714f7ca010a8b5e48ed0c', 'tests/image-auto-pilot-intake-integration-test.php': 'fe4fc24cc4d282f863266c9be0c83ffbf780d250', 'tests/image-auto-posix-intake-store-test.php': '3e2f0ccda866466fe8d844762e511f882c9763c6', 'tests/image-auto-source-selection-test.php': '374ab050ad4af3cc32b33fdd52b802a8a7a362ed', 'tests/image-auto-source-url-test.php': '11e6c22a94bf6f57162633625b06abdabbf0dcaf', 'tests/image-handoff-migration-file-test.php': '8dcf46f87061ef48468410e25239f856147a28e5', 'tests/image-handoff-migration-test.php': 'd04bf0e94e77033e54dc237e7434f814d874a636', 'tests/image-handoff-v2-concurrency-test.php': '0a58ec82cf1eb88a64f1247ef80a39a370278218', 'tests/image-handoff-v2-integration-test.php': '8663f599ba29599ebd35d682438137eba47c5a37', 'tests/image-handoff-v2-pdo-test.php': '64baac41dc06a9902097e28e596669b580f0052a', 'tests/image-handoff-v2-writer-contract-test.php': '699101dad61129a2cefbea2bb90fb7026800685d', 'tests/image-handoff-v3-quarantine-test.php': 'a65aa371ebe865e3c5bcb8fe88ff2938e6d45c70', 'tests/image-handoff-v3-race-test.php': 'a24e1ed0a4617b3dfdb99f0b1b8ebad4a5e6e4c2', 'tests/image-pilot-cli-test.py': 'ed3ec5cc2d9b8b60994387c5f26629bb931fcb23', 'tests/image-pilot-test.php': 'e9f3098d2cef89e39abb6ea9dbfe1e8503db47a7', 'tests/keyboard-browser/README.md': '567982a7ebf4a4ef8a42ce5c5c3eb7cd53888f91', 'tests/keyboard-browser/catalog-scroll-guard-test.py': '821bd44f751b11830931ed67b1a34a5157206f10', 'tests/keyboard-browser/catalog-scroll-identity.cjs': '42aac464164ce8231fe6ff0e81445a5b2db48bdd', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': '29c05ea25c44544ffc1fd1653aea83e1e8474b03', 'tests/keyboard-browser/catalog-scroll-policy.cjs': '7716c602d7640c58286964940b2f69f8a5150256', 'tests/keyboard-browser/catalog-scroll.cjs': 'c189ae8cee2b45af2ef9c51d677415415ba39203', 'tests/keyboard-browser/email-restore.cjs': 'ac21fdaafba504551fd4faaaa741f2b7d245f884', 'tests/keyboard-browser/heading-unit-test.py': 'f0c6aaecdfceee85128a38c68590facbc5cee5ba', 'tests/keyboard-browser/malformed-hydration.cjs': '7100046f80307c2fb698be747bfcb085b4e67327', 'tests/keyboard-browser/navbar.cjs': '4f93133adbd8f9b12c54461713a12c6b909c3004', 'tests/keyboard-browser/run.py': '75ad7e455e8dfced062d568eb1defd0bee40241a', 'tests/keyboard-browser/shipping-identity-test.py': 'dfc71b4c80215ac9e18b36239f30d9956722114b', 'tests/keyboard-browser/shipping_identity.py': '075be901e2f24af392495bb3d9977fe1586e0edc', 'tests/keyboard-browser/storage-unavailable.cjs': '4af982adb74194c9678ebdf10360eefe1acd40ed', 'tests/keyboard-browser/storage_identity.py': '38da96168a315f18613c3e02e9a48176b879138f', 'tests/newsletter-pending-edits-test.cjs': 'b7d6b6cee085eb4b515615014e2b0c99ac82c299', 'tests/product-gallery-identity-test.py': '2a27ceb5ed77bb36b8b88d4594836a5e914fbf4d', 'tests/product-gallery-selection-test.php': '6fb8ea818e9cb3ce6e3653d303c9e0874d8068a2', 'tests/purgestore-csrf-test.py': '48dfc923f4f666cceb48e74e2384fbdb497a0966', 'tests/rotor-reviewed-facts-test.php': 'ee9ce4400c99262ae33857245350e2806649a94e', 'tests/sale-schedule-http-test.py': '1c39b832510e4f399cf6c2809b96df5233fe5cab', 'tests/sale-schedule-test.py': '8dd204600f8bf213974353b07a837793dabd0f15', 'tests/saved-search-rendering-test.cjs': 'afb8575f7ed86101a96f510526863c210622941d', 'tests/shipping-parcel-total-boundary-test.php': 'cf8d410001a652d4ecaf9817fdb2820335771513', 'tests/stale-inventory-csrf-http-test.py': 'b8026c225f21bd37615fc9ef939c96dfdfc81c63'}
RUNNER = Path(__file__).with_name("run.py")
GIT = shutil.which("git")


def load_guard() -> dict:
    tree = ast.parse(RUNNER.read_text(), filename=str(RUNNER))
    constants = {}
    functions = []
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            name = node.targets[0].id
            if name in {"BASELINE", "BASELINE_TREE", "ALLOWED_CHANGES", "EXPECTED_RUNTIME", "EXPECTED_STORAGE_BLOBS"}:
                if name in constants:
                    raise AssertionError("Duplicate guard constant")
                constants[name] = ast.literal_eval(node.value)
        if isinstance(node, ast.FunctionDef) and node.name in {"require", "verify_candidate"}:
            if node.decorator_list:
                raise AssertionError("Guard decorators are forbidden")
            functions.append(node)
    assert constants == {"BASELINE": EXPECTED_BASELINE, "BASELINE_TREE": EXPECTED_BASELINE_TREE, "ALLOWED_CHANGES": EXPECTED_PATHS, "EXPECTED_RUNTIME": EXPECTED_RUNTIME, "EXPECTED_STORAGE_BLOBS": EXPECTED_STORAGE_BLOBS}
    assert sorted(node.name for node in functions) == ["require", "verify_candidate"]
    namespace = dict(constants, hashlib=hashlib)
    exec(compile(ast.Module(body=functions, type_ignores=[]), str(RUNNER), "exec"), namespace)
    return namespace


class Repository:
    def __init__(self, base: Path):
        self.root = base / "repo"
        self.root.mkdir()
        home = base / "home"
        home.mkdir()
        self.env = {"PATH": os.environ["PATH"], "HOME": str(home), "LC_ALL": "C",
                    "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": os.devnull,
                    "GIT_AUTHOR_NAME": "Synthetic guard", "GIT_COMMITTER_NAME": "Synthetic guard",
                    "GIT_AUTHOR_EMAIL": "fixture@example.invalid", "GIT_COMMITTER_EMAIL": "fixture@example.invalid",
                    "GIT_AUTHOR_DATE": "2026-11-07T00:00:00Z", "GIT_COMMITTER_DATE": "2026-11-07T00:00:00Z",
                    "GIT_TERMINAL_PROMPT": "0"}
        self.command("init", "-q")
        self.command("config", "core.hooksPath", str(base / "no-hooks"))
        self.command("config", "core.autocrlf", "false")
        self.write("admin/products.php", "<?php /* inert marker, never executed */\n")
        self.write(".gitignore", "ignored-marker\n")
        self.write("excluded/config.php", "inert sparse marker, never executed\n")
        for path, status in EXPECTED_PATHS.items():
            if status == "M":
                self.write(path, "synthetic baseline\n")
        self.commit("baseline")
        self.baseline = self.command("rev-parse", "HEAD").stdout.strip()

    def command(self, *args: str, check: bool = True) -> subprocess.CompletedProcess:
        result = subprocess.run([GIT, *args], cwd=self.root, env=self.env,
                                capture_output=True, text=True, timeout=10)
        if check and result.returncode:
            raise RuntimeError("Synthetic Git command failed: " + args[0])
        return result

    def write(self, path: str, text: str) -> None:
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)

    def commit(self, label: str) -> None:
        self.command("add", "--all")
        self.command("commit", "-qm", label)

    def candidate(self) -> None:
        for path in EXPECTED_PATHS:
            self.write(path, "synthetic reviewed candidate\n")
        self.commit("test-only candidate")

    def evaluate(self, overrides: dict | None = None) -> dict:
        guard = load_guard()
        def command(args, *, cwd):
            assert args[0] == "git" and cwd == self.root
            return self.command(*args[1:])
        guard.update(ROOT=self.root, BASELINE=self.baseline,
                     BASELINE_TREE=self.command("rev-parse", self.baseline+"^{tree}").stdout.strip(),
                     EXPECTED_STORAGE_BLOBS={p: hashlib.sha1(b"blob " + str(len(b"synthetic reviewed candidate\n")).encode() + b"\0synthetic reviewed candidate\n").hexdigest() for p in EXPECTED_STORAGE_BLOBS},
                     EXPECTED_RUNTIME={p: {"before": self.command("rev-parse", self.baseline+":"+p).stdout.strip(),
                                           "after": hashlib.sha1(b"blob " + str(len(b"synthetic reviewed candidate\n")).encode() + b"\0synthetic reviewed candidate\n").hexdigest()} for p in EXPECTED_RUNTIME},
                     git=lambda *args: self.command(*args).stdout.strip(),
                     run_command=command)
        guard.update(overrides or {})
        return guard["verify_candidate"]()


def main() -> None:
    assert GIT, "Installed Git required; no dependency installation is attempted"
    results = []

    def case(name, modify, *, accepted=False, overrides=None, reason=None):
        with tempfile.TemporaryDirectory(prefix="fas-admin-guard-") as temporary:
            repo = Repository(Path(temporary))
            repo.candidate()
            modify(repo)
            try:
                receipt = repo.evaluate(overrides(repo) if overrides else None)
            except RuntimeError as error:
                if accepted:
                    raise AssertionError(name + " unexpectedly rejected") from error
                if reason is not None:
                    assert str(error) == reason, name + " rejected for the wrong reason: " + str(error)
                results.append({"case": name, "expected": "reject", "passed": True})
            else:
                assert accepted, name + " unexpectedly accepted"
                assert receipt["runtime_diff_from_baseline"] == sorted(EXPECTED_RUNTIME)
                assert receipt["added_paths"] == [path for path, state in EXPECTED_PATHS.items() if state == "A"]
                assert receipt["modified_paths"] == sorted(path for path, state in EXPECTED_PATHS.items() if state == "M")
                results.append({"case": name, "expected": "accept", "passed": True})

    def changed(path, text="unexpected\n"):
        def apply(repo):
            repo.write(path, text)
            repo.commit("unexpected tracked change")
        return apply

    case("exact-integration-paths", lambda repo: None, accepted=True)
    case("no-diff", lambda repo: repo.command("reset", "--hard", repo.baseline))
    case("runtime-change", changed("admin/products.php"))
    case("changed-approved-main", changed("public/css/style.css"))
    case("wrong-main-preimage-pin", lambda repo: None, overrides=lambda repo: {"EXPECTED_RUNTIME": {"public/css/style.css": {"before": "0"*40, "after": repo.command("rev-parse", "HEAD:public/css/style.css").stdout.strip()}}})
    case("wrong-baseline-tree", lambda repo: None, overrides=lambda repo: {"BASELINE_TREE": "0"*40})
    def wrong_main_preimage(repo):
        pins = {path: {"before": repo.command("rev-parse", repo.baseline+":"+path).stdout.strip(),
                       "after": repo.command("rev-parse", "HEAD:"+path).stdout.strip()}
                for path in EXPECTED_RUNTIME}
        pins["public/css/style.css"]["before"] = "0"*40
        return {"EXPECTED_RUNTIME": pins}
    case("wrong-only-main-preimage-pin", lambda repo: None, overrides=wrong_main_preimage,
         reason="Reviewed runtime preimage mismatch")
    for scoped in EXPECTED_STORAGE_BLOBS:
        case("wrong-scoped-blob:"+scoped, changed(scoped))
        case("missing-scoped-path:"+scoped, lambda repo, scoped=scoped: (
            repo.command("rm", scoped), repo.commit("remove scoped file")))
        case("wrong-scoped-status:"+scoped, lambda repo: None,
             overrides=lambda repo, scoped=scoped: {"ALLOWED_CHANGES": dict(EXPECTED_PATHS, **{scoped: "M" if EXPECTED_PATHS[scoped] == "A" else "A"})})
    case("extra-test", changed("tests/extra.txt"))
    case("newline-path", changed("tests/extra\npath.txt"))
    case("tab-path", changed("tests/extra\tpath.txt"))
    case("space-path", changed("tests/space path.txt"))
    case("missing-modification", lambda repo: (
        repo.command("checkout", repo.baseline, "--", "tests/keyboard-browser/heading_runner.py"),
        repo.commit("restore allowed path")))
    case("deleted-guard-test", lambda repo: (
        repo.command("rm", "tests/admin-product-browser/guard-test.py"), repo.commit("remove new test")))
    case("deleted-allowed-path", lambda repo: (
        repo.command("rm", "tests/keyboard-browser/heading_runner.py"), repo.commit("delete README")))
    case("renamed-allowed-path", lambda repo: (
        repo.command("mv", "tests/keyboard-browser/heading_runner.py", "tests/admin-product-browser/moved.md"),
        repo.commit("rename README")))
    case("executable-allowed-path", lambda repo: (
        repo.command("update-index", "--chmod=+x", "tests/admin-product-browser/run.py"),
        repo.command("commit", "-qm", "mode change")))
    def symlink(repo):
        path = repo.root / "tests/admin-product-browser/guard-test.py"
        path.unlink()
        path.symlink_to("run.py")
        repo.commit("symlink new test")
    case("symlink-allowed-path", symlink)
    case("unstaged-runtime", lambda repo: repo.write("admin/products.php", "dirty\n"))
    case("staged-runtime", lambda repo: (
        repo.write("admin/products.php", "dirty\n"), repo.command("add", "admin/products.php")))
    def index_only(repo):
        original = (repo.root / "admin/products.php").read_text()
        repo.write("admin/products.php", "staged dirty\n")
        repo.command("add", "admin/products.php")
        repo.write("admin/products.php", original)
    case("index-dirt-hidden-by-worktree", index_only)
    for flag in ["--skip-worktree", "--assume-unchanged"]:
        def hidden(repo, flag=flag):
            repo.command("update-index", flag, "admin/products.php")
            repo.write("admin/products.php", "hidden dirty\n")
        case(flag[2:] + "-hidden-runtime", hidden)
    case("legitimate-absent-sparse-exclusion", lambda repo: (
        repo.command("update-index", "--skip-worktree", "excluded/config.php"),
        (repo.root / "excluded/config.php").unlink()), accepted=True)
    case("missing-nonsparse-file", lambda repo: (repo.root / "excluded/config.php").unlink())
    case("hidden-executable-mode", lambda repo: (
        repo.command("config", "core.filemode", "false"),
        (repo.root / "admin/products.php").chmod(0o755)))
    case("unstaged-allowed-path", lambda repo: repo.write("tests/admin-product-browser/run.py", "dirty\n"))
    case("untracked-file", lambda repo: repo.write("untracked.txt", "inert\n"))
    case("ignored-untracked-file", lambda repo: repo.write("ignored-marker", "inert\n"))
    case("missing-baseline", lambda repo: None, overrides=lambda repo: {"BASELINE": "0" * 40})
    def nonancestor(repo):
        parent = repo.command("rev-parse", "HEAD").stdout.strip()
        repo.command("checkout", "--orphan", "unrelated")
        repo.command("commit", "-qm", "unrelated root")
        assert repo.command("merge-base", "--is-ancestor", repo.baseline, "HEAD", check=False).returncode != 0
    case("nonancestor-baseline", nonancestor)

    # Corrupt transport results are explicitly rejected, not silently truncated.
    for name, value in [("unterminated-diff", "M\0tests/admin-product-browser/run.py"),
                        ("odd-diff-field-count", "M\0"),
                        ("unknown-status", "X\0tests/admin-product-browser/run.py\0"),
                        ("empty-diff", "")]:
        def override(repo, value=value):
            def git(*args):
                if args[:2] == ("diff", "--no-renames"):
                    return value
                return repo.command(*args).stdout.strip()
            return {"git": git}
        case(name, lambda repo: None, overrides=override)
    print(json.dumps({"status": "passed", "guard_cases": len(results), "results": results,
                      "scope": "guard-only synthetic Git fixtures", "browser_run": False,
                      "production_verified": False}, indent=2))


if __name__ == "__main__":
    main()
