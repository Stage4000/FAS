"""Disposable real-admin browser acceptance. Run only in the guarded hosted job.

No production state or provider transport is used. This runner intentionally
refuses a normal network namespace, root execution, or an unreviewed runtime tree.
"""
from __future__ import annotations

import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import selectors
import shutil
import signal
import socket
import sqlite3
import stat
import struct
import subprocess
import sys
import tempfile
import time
import zlib


ROOT = Path(__file__).resolve().parents[2]
BASELINE = '81dc308e6a7830f31ea52260a7122cee4c6d5222'
ALLOWED_CHANGES = {'.github/workflows/keyboard-browser.yml': 'M', 'about.php': 'M', 'admin/banners.php': 'M', 'admin/coupons.php': 'M', 'admin/ebay-sync-health.php': 'M', 'admin/purgestore.php': 'M', 'admin/sale.php': 'M', 'admin/stale-inventory.php': 'M', 'api/contact-form.php': 'M', 'api/products.php': 'M', 'api/shipping-estimate.php': 'M', 'api/validate-coupon.php': 'M', 'cart.php': 'M', 'checkout.php': 'M', 'contact.php': 'M', 'docs/reviewed-product-facts.md': 'M', 'includes/catalog-meta.php': 'M', 'includes/header.php': 'M', 'includes/product-merchandising.php': 'M', 'includes/sale-helper.php': 'M', 'product.php': 'M', 'products.php': 'M', 'public/css/style.css': 'M', 'public/images/navbar-logo.png': 'A', 'public/js/growth.js': 'M', 'public/js/shipping-estimator.js': 'M', 'src/integrations/EbayAPI.php': 'M', 'src/models/Banner.php': 'M', 'src/models/Coupon.php': 'M', 'src/shipping/CarrierRates.php': 'M', 'src/utils/ReviewedProductFacts.php': 'M', 'src/utils/ShippingRules.php': 'M', 'tests/admin-product-browser/guard-test.py': 'M', 'tests/admin-product-browser/run.py': 'M', 'tests/banner-csrf-http-test.py': 'A', 'tests/banner-input-validation-http-test.py': 'A', 'tests/banner-schedule-timezone-test.php': 'A', 'tests/campaign-code-case-test.php': 'A', 'tests/cart-malformed-hydration-test.cjs': 'M', 'tests/catalog-focus-restoration-test.cjs': 'A', 'tests/catalog-heading-identity-test.py': 'A', 'tests/catalog-loader-lifecycle-test.cjs': 'M', 'tests/catalog-scroll-offset-test.cjs': 'A', 'tests/catalog-search-layout-test.cjs': 'M', 'tests/catalog-zero-search-test.php': 'A', 'tests/checkout-coupon-lifecycle-test.cjs': 'A', 'tests/condition-badge-contrast-test.py': 'A', 'tests/contact-delivery-test.py': 'A', 'tests/contact-input-purpose-test.py': 'A', 'tests/contact-pending-edits-test.cjs': 'A', 'tests/contact-provider-response-http-test.py': 'A', 'tests/contact-retry-test.cjs': 'A', 'tests/coupon-active-checkbox-http-test.py': 'A', 'tests/coupon-expiry-timezone-test.php': 'A', 'tests/coupon-input-shape-http-test.py': 'A', 'tests/ebay-remaining-quantity-test.php': 'A', 'tests/fixtures/image-auto-bootstrap/FixedImageBootstrap.php': 'A', 'tests/fixtures/image-auto-local/ImmutableImageSnapshot.php': 'A', 'tests/fixtures/image-auto-local/ImmutableLocalPilot.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageEffectObservation.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageIntakeBinding.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImagePilot.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceIntake.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceSelection.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ImportedImage.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/PosixImageIntakeStore.php': 'A', 'tests/fixtures/image-auto-operation/candidate/src/ResponsiveImage.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/ControllerQuotaWitness.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/CycleAdmissionBridge.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-cohesive.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-retained-lifetime.php': 'A', 'tests/fixtures/image-auto-operation/controller-cycle-next/worker.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageOperationPilot.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageRemoteAcquisition.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_coordinator.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_default_off.php': 'A', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_superclass_guard.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageOperationFrontEnd.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageQuotaAdmission.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/test_front_end.php': 'A', 'tests/fixtures/image-auto-operation/front-end-vnext/test_quota.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/ImageObservedSettlement.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/ImageOperationBinding.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.json': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.sql': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.json': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.sql': 'A', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/schema-empty.sql': 'A', 'tests/fixtures/image-auto-operation/operation-v6/same-process-fixture.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/schema-v6.json': 'A', 'tests/fixtures/image-auto-operation/operation-v6/test_pdo.php': 'A', 'tests/fixtures/image-auto-operation/operation-v6/test_same_process.php': 'A', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/source.png.base64': 'A', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/variant.webp.base64': 'A', 'tests/fixtures/image-auto-pilot/ImageIntakeBinding.php': 'A', 'tests/fixtures/image-auto-pilot/ImagePilot.php': 'A', 'tests/fixtures/image-auto-pilot/ImageSourceIntake.php': 'A', 'tests/fixtures/image-auto-pilot/ImageSourceSelection.php': 'A', 'tests/fixtures/image-auto-pilot/ImportedImage.php': 'A', 'tests/fixtures/image-auto-pilot/PosixImageIntakeStore.php': 'A', 'tests/fixtures/image-auto-pilot/ResponsiveImage.php': 'A', 'tests/fixtures/image-auto-pilot/entrypoints/automatic-pilot.php': 'A', 'tests/fixtures/image-auto-pilot/entrypoints/intake.php': 'A', 'tests/fixtures/image-handoff-migration/ImageHandoffMigration.php': 'A', 'tests/fixtures/image-handoff-migration/schema-v2.json': 'A', 'tests/fixtures/image-handoff-migration/schema-v3.json': 'A', 'tests/fixtures/image-handoff-v2/ImageHandoffV2.php': 'A', 'tests/fixtures/image-handoff-v2/ImageSnapshotV2.php': 'A', 'tests/fixtures/image-handoff-v2/outbox.sql': 'A', 'tests/fixtures/image-handoff-v2/schema-manifest.json': 'A', 'tests/fixtures/image-handoff-v3/ImageHandoffV3.php': 'A', 'tests/fixtures/image-handoff-v3/ImageSnapshotV2.php': 'A', 'tests/fixtures/image-handoff-v3/schema-manifest.json': 'A', 'tests/fixtures/image-pilot/ImagePilot.php': 'A', 'tests/fixtures/image-pilot/ImportedImage.php': 'A', 'tests/fixtures/image-pilot/ResponsiveImage.php': 'A', 'tests/fixtures/image-pilot/pilot.php': 'A', 'tests/free-shipping-destination-api-test.py': 'A', 'tests/free-shipping-destination-test.php': 'A', 'tests/homepage-branding-test.py': 'M', 'tests/homepage-visual/check-layout.cjs': 'M', 'tests/homepage-visual/prepare-fixture.py': 'M', 'tests/image-auto-bootstrap-boundary-test.py': 'A', 'tests/image-auto-bootstrap-lifecycle-test.py': 'A', 'tests/image-auto-bounded-snapshot-test.py': 'A', 'tests/image-auto-controller-cycle-test.py': 'A', 'tests/image-auto-controller-quota-test.php': 'A', 'tests/image-auto-entrypoint-boundary-test.py': 'A', 'tests/image-auto-entrypoint-pdo-test.py': 'A', 'tests/image-auto-gallery-binding-test.php': 'A', 'tests/image-auto-gallery-pipeline-test.php': 'A', 'tests/image-auto-immutable-local-pipeline-test.php': 'A', 'tests/image-auto-immutable-snapshot-test.php': 'A', 'tests/image-auto-intake-binding-test.php': 'A', 'tests/image-auto-intake-pipeline-test.php': 'A', 'tests/image-auto-intake-test.php': 'A', 'tests/image-auto-operation-binding-test.php': 'A', 'tests/image-auto-operation-composition-test.php': 'A', 'tests/image-auto-operation-coordinator-test.php': 'A', 'tests/image-auto-operation-default-off-test.php': 'A', 'tests/image-auto-operation-front-end-test.php': 'A', 'tests/image-auto-operation-quota-test.php': 'A', 'tests/image-auto-operation-settlement-test.php': 'A', 'tests/image-auto-operation-superclass-test.php': 'A', 'tests/image-auto-pilot-intake-integration-test.php': 'A', 'tests/image-auto-posix-intake-store-test.php': 'A', 'tests/image-auto-source-selection-test.php': 'A', 'tests/image-auto-source-url-test.php': 'A', 'tests/image-handoff-migration-file-test.php': 'A', 'tests/image-handoff-migration-test.php': 'A', 'tests/image-handoff-v2-concurrency-test.php': 'A', 'tests/image-handoff-v2-integration-test.php': 'A', 'tests/image-handoff-v2-pdo-test.php': 'A', 'tests/image-handoff-v2-writer-contract-test.php': 'A', 'tests/image-handoff-v3-quarantine-test.php': 'A', 'tests/image-handoff-v3-race-test.php': 'A', 'tests/image-pilot-cli-test.py': 'A', 'tests/image-pilot-test.php': 'A', 'tests/keyboard-browser/README.md': 'M', 'tests/keyboard-browser/catalog-scroll-guard-test.py': 'A', 'tests/keyboard-browser/catalog-scroll-identity.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy.cjs': 'A', 'tests/keyboard-browser/catalog-scroll.cjs': 'A', 'tests/keyboard-browser/email-restore.cjs': 'M', 'tests/keyboard-browser/heading-unit-test.py': 'M', 'tests/keyboard-browser/heading_runner.py': 'M', 'tests/keyboard-browser/malformed-hydration.cjs': 'M', 'tests/keyboard-browser/navbar.cjs': 'M', 'tests/keyboard-browser/run.py': 'M', 'tests/keyboard-browser/shipping-identity-test.py': 'A', 'tests/keyboard-browser/shipping_identity.py': 'A', 'tests/keyboard-browser/storage-unavailable.cjs': 'M', 'tests/keyboard-browser/storage_identity.py': 'M', 'tests/newsletter-pending-edits-test.cjs': 'A', 'tests/product-gallery-identity-test.py': 'A', 'tests/product-gallery-selection-test.php': 'A', 'tests/purgestore-csrf-test.py': 'A', 'tests/rotor-reviewed-facts-test.php': 'A', 'tests/sale-schedule-http-test.py': 'A', 'tests/sale-schedule-test.py': 'A', 'tests/saved-search-rendering-test.cjs': 'A', 'tests/shipping-parcel-total-boundary-test.php': 'A', 'tests/stale-inventory-csrf-http-test.py': 'A'}
EXPECTED_RUNTIME = {'about.php': {'before': '47c399d13b31cb6439ec46b8901c68961d06e529', 'after': 'ededab775a76bb3c1443f0cde713b3903b34fbbf'}, 'admin/banners.php': {'before': '0d029994a786a312be85b4a1882c17fd3d9310de', 'after': '66eb77cef5e6688a8627d4cdd0476d25c20faca2'}, 'admin/coupons.php': {'before': '72426da91e2a916079408cf0008dead8bdc69a00', 'after': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098'}, 'admin/ebay-sync-health.php': {'before': '218e24714173b4a992cf2e898071471599b0d872', 'after': '4fd912aad6e5e2d513360a369a33b4018087df34'}, 'admin/purgestore.php': {'before': '89c9fcf9d64fc2607a841f9253b3aeab8d865054', 'after': '36f6d450157a9f68ceee971cb5e9da705bd0dbac'}, 'admin/sale.php': {'before': '5075fa0ca55c51aa3177ae265a31bbecdb264d60', 'after': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6'}, 'admin/stale-inventory.php': {'before': 'b43798f11a2c822de1aaa559f97844c0344ef36e', 'after': '484b9a5c65d167b342e7eecc81591adadba43b01'}, 'api/contact-form.php': {'before': 'aec0ec7a0c32819070ef41c22bf64a9db9a8a41c', 'after': '6f97709f0b1770fc27e136fb20e6066cd12e1b37'}, 'api/products.php': {'before': 'a284c99de99c7337e4cb8a1fa3f0a2a870aa4e8c', 'after': '6ea53e5d29f2b0d101e5e09acaeff067e1522d75'}, 'api/shipping-estimate.php': {'before': '187be706bac656a753fb18d42354e5838191a65f', 'after': '6f68638d9c961e75bfbb7594a616ffb868f4bc75'}, 'api/validate-coupon.php': {'before': '37e6e2f7f95ca8c2d8e86d27562079a5ab4d07c9', 'after': '652bea7a87733a16ecb27deb57367a3c2e036e48'}, 'cart.php': {'before': '0082e0fcabbc1be3678235e2a7e9c18585e419cb', 'after': 'badbd6ca9a53fef9672b0cf8b0ec6a2122c46aac'}, 'checkout.php': {'before': '7c98553a15d96a36620f7af1fcff363b3fa3ba16', 'after': 'f9e80306880977e15f289caeee032e3ee969574f'}, 'contact.php': {'before': '103ca56061c7d1e2e8b817ab1c6b4d3e3e0f267d', 'after': '52a106dbb898ecdf50c68dbaabfd7d08f80c089a'}, 'includes/catalog-meta.php': {'before': '7ad7672b9b8def171681b1fc7e476f41bbce6e9f', 'after': 'b77464e1cdf9f4b5684f1bde224dca683ab8a428'}, 'includes/header.php': {'before': 'b8ecc74f67f306afa142339b1641fc916a624e2c', 'after': '83ed8300bd5aaaf7121232e80b5b1af7dda45231'}, 'includes/product-merchandising.php': {'before': '4579c1f0e1c14c35ffae58e6cf7f57c07989e700', 'after': 'db1e8b0434362b1ce12f723d83593556d5cc4299'}, 'includes/sale-helper.php': {'before': 'c2d56d9d6d80e5c5e87263b9b0d5c7bf516139d1', 'after': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7'}, 'product.php': {'before': 'ff1a31cc764c881f7acbd392b563276bab9747c9', 'after': 'd00036ad68cd4d01cf1ae254d8f34d53c4a62478'}, 'products.php': {'before': 'e03bc2480c05adf03ea602b6706bd68c0eec8afa', 'after': '82bef0dc76c40a5aaca005425d8856df30cf1b8d'}, 'public/css/style.css': {'before': '103706af92484631585fcc26553239b0b42681c4', 'after': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052'}, 'public/js/growth.js': {'before': '87dac7559458d16f9adc755dc404d535beeeb9eb', 'after': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692'}, 'public/js/shipping-estimator.js': {'before': '729a22d1b6e993ec904d29d9595ed45b6a268e76', 'after': '13b11995172cabbf7be019232b45eed941c97a02'}, 'src/integrations/EbayAPI.php': {'before': 'd059b65637f6602c8ec21c52882e2c52890b2493', 'after': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7'}, 'src/models/Banner.php': {'before': '39e97c109294b925d9aa2b1f51a4e9186ffe2120', 'after': '2adbeafae3c4f1373409949c532520e51619daae'}, 'src/models/Coupon.php': {'before': '53ad053d7110a1f4f6d6e136d22cb03bf4742d05', 'after': 'bb899ad8a612b4c38fd0d18c72339123d57105d8'}, 'src/shipping/CarrierRates.php': {'before': 'c58d6692808178342c4efbb0ea8b8ad9a61d9a03', 'after': '1de6765d76ab0ea11961971f9c70d53c8c019100'}, 'src/utils/ReviewedProductFacts.php': {'before': 'af5c5e4b277dd3005e825ec0f384756ed9441637', 'after': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'src/utils/ShippingRules.php': {'before': 'aecc28f5796850372f2892b2d8ef5645f0cbc6d7', 'after': 'ce56c43e6120af85c8e0a12f755d661619677c30'}}
BASELINE_TREE = '00ebb2f16bea9655f81c88de754fee75b76c534f'
EXPECTED_STORAGE_BLOBS = {'about.php': 'ededab775a76bb3c1443f0cde713b3903b34fbbf', 'admin/banners.php': '66eb77cef5e6688a8627d4cdd0476d25c20faca2', 'admin/coupons.php': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098', 'admin/ebay-sync-health.php': '4fd912aad6e5e2d513360a369a33b4018087df34', 'admin/purgestore.php': '36f6d450157a9f68ceee971cb5e9da705bd0dbac', 'admin/sale.php': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6', 'admin/stale-inventory.php': '484b9a5c65d167b342e7eecc81591adadba43b01', 'api/contact-form.php': '6f97709f0b1770fc27e136fb20e6066cd12e1b37', 'api/products.php': '6ea53e5d29f2b0d101e5e09acaeff067e1522d75', 'api/shipping-estimate.php': '6f68638d9c961e75bfbb7594a616ffb868f4bc75', 'api/validate-coupon.php': '652bea7a87733a16ecb27deb57367a3c2e036e48', 'cart.php': 'badbd6ca9a53fef9672b0cf8b0ec6a2122c46aac', 'checkout.php': 'f9e80306880977e15f289caeee032e3ee969574f', 'contact.php': '52a106dbb898ecdf50c68dbaabfd7d08f80c089a', 'docs/reviewed-product-facts.md': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060', 'includes/catalog-meta.php': 'b77464e1cdf9f4b5684f1bde224dca683ab8a428', 'includes/header.php': '83ed8300bd5aaaf7121232e80b5b1af7dda45231', 'includes/product-merchandising.php': 'db1e8b0434362b1ce12f723d83593556d5cc4299', 'includes/sale-helper.php': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7', 'product.php': 'd00036ad68cd4d01cf1ae254d8f34d53c4a62478', 'products.php': '82bef0dc76c40a5aaca005425d8856df30cf1b8d', 'public/css/style.css': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052', 'public/images/navbar-logo.png': 'cc39aa73e9c93bb75346bd96394e2f59184445f6', 'public/js/growth.js': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692', 'public/js/shipping-estimator.js': '13b11995172cabbf7be019232b45eed941c97a02', 'src/integrations/EbayAPI.php': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7', 'src/models/Banner.php': '2adbeafae3c4f1373409949c532520e51619daae', 'src/models/Coupon.php': 'bb899ad8a612b4c38fd0d18c72339123d57105d8', 'src/shipping/CarrierRates.php': '1de6765d76ab0ea11961971f9c70d53c8c019100', 'src/utils/ReviewedProductFacts.php': '1db5e4468b9730b12cad79f41f7094c2144571fb', 'src/utils/ShippingRules.php': 'ce56c43e6120af85c8e0a12f755d661619677c30', 'tests/banner-csrf-http-test.py': 'd7ae700887e1daaf0fc57518f4bdd24606024c33', 'tests/banner-input-validation-http-test.py': '6c8e5274bce727af794fdfbf21d7f17afa995139', 'tests/banner-schedule-timezone-test.php': '18cd1384c0a26ca280a695ecbbb34e78583942e6', 'tests/campaign-code-case-test.php': '08007f693916900c8b72ebb5718fa7d7c825565b', 'tests/cart-malformed-hydration-test.cjs': 'd72d25178e2ab3f414f6581eb4a15c9554a939fb', 'tests/catalog-focus-restoration-test.cjs': 'ea5b7eaf6e5ff513ccf8b82827bdfacc45a5de0f', 'tests/catalog-heading-identity-test.py': 'da903694533a2ca00981fea5ef178a44a600cd9f', 'tests/catalog-loader-lifecycle-test.cjs': '44244918933b5bd8220c5c202403b2a8742f8a50', 'tests/catalog-scroll-offset-test.cjs': 'bd822d1f3cee43f13cec01e151685ad88134da58', 'tests/catalog-search-layout-test.cjs': '88326b077688f393fa45fd5f36436a53a884e303', 'tests/catalog-zero-search-test.php': 'c56d9d6668d3b9684c61c940b7317e94fb3fcb90', 'tests/checkout-coupon-lifecycle-test.cjs': '97896bf75ea4af85c12f800da671da75c8480dd2', 'tests/condition-badge-contrast-test.py': '52c79b6074e8e85e4e2d9b5ac12eaee92cb3f703', 'tests/contact-delivery-test.py': '2e4e160cf6c62d4eb3eec8b8668fe88724f1722c', 'tests/contact-input-purpose-test.py': '34b00ab77be32623d2547badc70241dd69b55416', 'tests/contact-pending-edits-test.cjs': 'e20affefa282fe0bee690033cacf828fec54221a', 'tests/contact-provider-response-http-test.py': '1b1254fccf75ea69bc495cd9331bde8edd4be3cd', 'tests/contact-retry-test.cjs': 'dccea24eed65314386728bdf9c16937801830347', 'tests/coupon-active-checkbox-http-test.py': '41ed98b47c9f8553fb77d54764d66a876ebc8411', 'tests/coupon-expiry-timezone-test.php': 'ffd444d00624e127638180aebc857bd2a1c1e28c', 'tests/coupon-input-shape-http-test.py': 'b1031f0aa30c68774847f74aaafbb94df52fb479', 'tests/ebay-remaining-quantity-test.php': 'ac53e8cd9705355f1fdcd794e3adc2bd896d17ce', 'tests/fixtures/image-auto-bootstrap/FixedImageBootstrap.php': '2ee98ccd884eda672642b09037bd604a06fe9f1a', 'tests/fixtures/image-auto-local/ImmutableImageSnapshot.php': '4fcdee7e349a622c28f3e3b231a07ea0b15342ba', 'tests/fixtures/image-auto-local/ImmutableLocalPilot.php': '2b12295ccbb99c5823997794057028d35318d8ec', 'tests/fixtures/image-auto-operation/candidate/src/ImageEffectObservation.php': '6d039e17589eca14af3c3e710be026e7c3566fd1', 'tests/fixtures/image-auto-operation/candidate/src/ImageIntakeBinding.php': '435a1d12041f3e8852e479b03f84ce9c6dbeb3a3', 'tests/fixtures/image-auto-operation/candidate/src/ImagePilot.php': '12f5dce030ccde2f0121fd79a317751d9f628bdc', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceIntake.php': '438cf977c26224a15ddd2ec0f65d02fcf8884629', 'tests/fixtures/image-auto-operation/candidate/src/ImageSourceSelection.php': '0ac0bfb10e92d3aa28482e7565ab48e613c83b68', 'tests/fixtures/image-auto-operation/candidate/src/ImportedImage.php': '802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'tests/fixtures/image-auto-operation/candidate/src/PosixImageIntakeStore.php': '7016a52f8813bc4183b392fbef848cc3695db3b5', 'tests/fixtures/image-auto-operation/candidate/src/ResponsiveImage.php': 'd8bfa725d5aaf7320b3c1052966376129379fdd4', 'tests/fixtures/image-auto-operation/controller-cycle-next/ControllerQuotaWitness.php': 'db3989c69b5513544dc62e16b9172db4fbb45355', 'tests/fixtures/image-auto-operation/controller-cycle-next/CycleAdmissionBridge.php': 'f0c5c6c6ed254029fcd3ee96b492ce65a258a638', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-cohesive.php': 'ad4fa6b12e5d0c7529d556bdd4946107f259e3d7', 'tests/fixtures/image-auto-operation/controller-cycle-next/test-retained-lifetime.php': '5ddfa9b7f819dd71b19ad787ffb6c29d1faa0b7a', 'tests/fixtures/image-auto-operation/controller-cycle-next/worker.php': '0fb075f219cd6ad05004dbff81d51f79d5b31294', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageOperationPilot.php': 'ff9157063aa11f1b8f79e21e95ecd77a3ab1c172', 'tests/fixtures/image-auto-operation/coordinator-vnext/ImageRemoteAcquisition.php': 'f3a1d88d9117f42fc7cecd5e60f1fed38f56a836', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_coordinator.php': 'f77f1694cc067c2163eec5acac4b1f5af6781370', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_default_off.php': 'fe08ee8baba6a30fc63d8535967dec284bf003a8', 'tests/fixtures/image-auto-operation/coordinator-vnext/test_superclass_guard.php': 'b4a7c0adbe9dd849c5651a17c465272a859387bf', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageOperationFrontEnd.php': '642ea3fab0af49e0328ffe560c0deb61c995b0d6', 'tests/fixtures/image-auto-operation/front-end-vnext/ImageQuotaAdmission.php': 'cc109321b9d2068ea45d40f20499beb3678756a2', 'tests/fixtures/image-auto-operation/front-end-vnext/test_front_end.php': 'aa3c10c6bd262e6f77de96c203dbe630e008c1b6', 'tests/fixtures/image-auto-operation/front-end-vnext/test_quota.php': '46df13e40eee48d1bf4a38c4f9b30dd2d61697ba', 'tests/fixtures/image-auto-operation/operation-v6/ImageObservedSettlement.php': '52b9fe81eb06e45074487770a42b1d5427496450', 'tests/fixtures/image-auto-operation/operation-v6/ImageOperationBinding.php': '67d8472f352b0607e8d500f6fb598b6e91ab5c4f', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.json': '900941fa18a3b695b8747e6f6dba7e1b5a39ec68', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/gallery.sql': 'b366c0dce410401c70768d9e16728804c8308754', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.json': '4216181889012f184a65410dc6ba6966251f12c8', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/primary.sql': 'd15f29b6ac1e0be9bc40c962aa69a4af938d359e', 'tests/fixtures/image-auto-operation/operation-v6/fixtures/schema-empty.sql': '0247fec25555f84b434173fa10d49083cf99d322', 'tests/fixtures/image-auto-operation/operation-v6/same-process-fixture.php': '9183e29e7ce75f2bede4546d18586fc9e66e7797', 'tests/fixtures/image-auto-operation/operation-v6/schema-v6.json': '22dfc047d2ccdedc2fc6015b8264aee27634bd14', 'tests/fixtures/image-auto-operation/operation-v6/test_pdo.php': 'f165c88a9a1aa119b2ac19f27f62db15e530c621', 'tests/fixtures/image-auto-operation/operation-v6/test_same_process.php': '16001eeff37b2a5094ab985e0739c6375955262d', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/source.png.base64': '2d801cefd40e3e6a1744b71f3396622997899f78', 'tests/fixtures/image-auto-operation/tests/effect-fixtures/variant.webp.base64': '7618e59ce1aea6b0a706ef5b24e2da2d180685af', 'tests/fixtures/image-auto-pilot/ImageIntakeBinding.php': '435a1d12041f3e8852e479b03f84ce9c6dbeb3a3', 'tests/fixtures/image-auto-pilot/ImagePilot.php': '12f5dce030ccde2f0121fd79a317751d9f628bdc', 'tests/fixtures/image-auto-pilot/ImageSourceIntake.php': '438cf977c26224a15ddd2ec0f65d02fcf8884629', 'tests/fixtures/image-auto-pilot/ImageSourceSelection.php': '0ac0bfb10e92d3aa28482e7565ab48e613c83b68', 'tests/fixtures/image-auto-pilot/ImportedImage.php': '802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'tests/fixtures/image-auto-pilot/PosixImageIntakeStore.php': '7016a52f8813bc4183b392fbef848cc3695db3b5', 'tests/fixtures/image-auto-pilot/ResponsiveImage.php': 'd8bfa725d5aaf7320b3c1052966376129379fdd4', 'tests/fixtures/image-auto-pilot/entrypoints/automatic-pilot.php': '57fdf7a6ef22d6ab866f5fb23c75c05db0925cd3', 'tests/fixtures/image-auto-pilot/entrypoints/intake.php': '648e6fd08c9e2a107c0497a7154b0584b5b94a4f', 'tests/fixtures/image-handoff-migration/ImageHandoffMigration.php': 'd993d991e74c46f4ca076a67743f2aa5272be77d', 'tests/fixtures/image-handoff-migration/schema-v2.json': '5be589dccc6ee804a056bd14c7f38de450467064', 'tests/fixtures/image-handoff-migration/schema-v3.json': 'bc453b2f25d1fe44b9d4d7821e0f6533b09eb3c7', 'tests/fixtures/image-handoff-v2/ImageHandoffV2.php': '16f74aa66ea76843a92a56b2cd7408fab98bb91d', 'tests/fixtures/image-handoff-v2/ImageSnapshotV2.php': '74a45b4adc0eec2dd005355596ef0a176e852649', 'tests/fixtures/image-handoff-v2/outbox.sql': '95e34ea9c435c3214e2c36935b469b5b0ffb41d2', 'tests/fixtures/image-handoff-v2/schema-manifest.json': '5be589dccc6ee804a056bd14c7f38de450467064', 'tests/fixtures/image-handoff-v3/ImageHandoffV3.php': '46a893a1fb744b49764c6a3aaf7b95b30f93c9c0', 'tests/fixtures/image-handoff-v3/ImageSnapshotV2.php': '74a45b4adc0eec2dd005355596ef0a176e852649', 'tests/fixtures/image-handoff-v3/schema-manifest.json': 'bc453b2f25d1fe44b9d4d7821e0f6533b09eb3c7', 'tests/fixtures/image-pilot/ImagePilot.php': '19423938b5a720ed36556198798580436a72229b', 'tests/fixtures/image-pilot/ImportedImage.php': '802a203112acd7caaa2fc0a7ef13d051fb843fb4', 'tests/fixtures/image-pilot/ResponsiveImage.php': 'd8bfa725d5aaf7320b3c1052966376129379fdd4', 'tests/fixtures/image-pilot/pilot.php': 'a4c796f0c4ecc78ee0886cce778d46ffaed452e6', 'tests/free-shipping-destination-api-test.py': '7461b88e9cf1cf1d04cd16c61e0ec999a0a7ac52', 'tests/free-shipping-destination-test.php': '4ebb3ba83755803c7bdd7effa8858a4f169607ba', 'tests/homepage-branding-test.py': 'b1710429404acd153838dd5d40a11f3a4c0e61a3', 'tests/homepage-visual/check-layout.cjs': '6eb3e15e58768ca3070fd80f4a26f0891bb26463', 'tests/homepage-visual/prepare-fixture.py': '7b0828c6e7c49d654362a72657e23cc6ba1b85ff', 'tests/image-auto-bootstrap-boundary-test.py': '9ba7d1d139b5ae8961280aa3e070badcb8010756', 'tests/image-auto-bootstrap-lifecycle-test.py': 'e36e581842abc1c56accca88ccf6e27884d7dda0', 'tests/image-auto-bounded-snapshot-test.py': 'ab27e918a1c253229d2016b8b87adc4d48a4efc1', 'tests/image-auto-controller-cycle-test.py': '0f411f86e16285167b46a81cce902e44dc050d30', 'tests/image-auto-controller-quota-test.php': 'b418ecbf83cdca6ab69e12628f19885fed8b15fe', 'tests/image-auto-entrypoint-boundary-test.py': 'dd5ec99dfac1b46c864548c8bced528f06315242', 'tests/image-auto-entrypoint-pdo-test.py': '08add7f6122d3c062eb30801695c5e83d1723da3', 'tests/image-auto-gallery-binding-test.php': 'b8671582a487b6137a133874cf5e8e13512f788e', 'tests/image-auto-gallery-pipeline-test.php': 'cbc23d06a9400afd148eaf5c93a956c6c748fcb2', 'tests/image-auto-immutable-local-pipeline-test.php': '3bd24dde79c16eef25f48cc15b899b1bc4d2d847', 'tests/image-auto-immutable-snapshot-test.php': '24011ae6e96c2056a5836c3abdc0934d2fb2d97b', 'tests/image-auto-intake-binding-test.php': '4b248e30ce00ed94f117b33240410aa31eea19ce', 'tests/image-auto-intake-pipeline-test.php': 'd192eda9eb8e394dc6f60a919c1c58512c05eb54', 'tests/image-auto-intake-test.php': 'f1e2842becf3d44fc3b1e46e3eb320e7fbbf63ff', 'tests/image-auto-operation-binding-test.php': 'e1a31cbfd7273421b3377ee3c8164ba283a60b62', 'tests/image-auto-operation-composition-test.php': '818353a05391326c285c2cb46a7c078f96337714', 'tests/image-auto-operation-coordinator-test.php': '9e582a1d98a68d9d02c38701415b130d463326ad', 'tests/image-auto-operation-default-off-test.php': '9233ce6ff190e7b0924ea2988b6d031ab4234deb', 'tests/image-auto-operation-front-end-test.php': '9e932a45060d23e10bdb6c76663aaf5e1fcfa470', 'tests/image-auto-operation-quota-test.php': '3d3c65e58b881eacc4765ac35d3508adf94fc5ee', 'tests/image-auto-operation-settlement-test.php': 'ecf19ceca51552daf44e3e94ee0b0dacf85aa3af', 'tests/image-auto-operation-superclass-test.php': '4567574d60ef67d7939714f7ca010a8b5e48ed0c', 'tests/image-auto-pilot-intake-integration-test.php': 'fe4fc24cc4d282f863266c9be0c83ffbf780d250', 'tests/image-auto-posix-intake-store-test.php': '3e2f0ccda866466fe8d844762e511f882c9763c6', 'tests/image-auto-source-selection-test.php': '374ab050ad4af3cc32b33fdd52b802a8a7a362ed', 'tests/image-auto-source-url-test.php': '11e6c22a94bf6f57162633625b06abdabbf0dcaf', 'tests/image-handoff-migration-file-test.php': '8dcf46f87061ef48468410e25239f856147a28e5', 'tests/image-handoff-migration-test.php': 'd04bf0e94e77033e54dc237e7434f814d874a636', 'tests/image-handoff-v2-concurrency-test.php': '0a58ec82cf1eb88a64f1247ef80a39a370278218', 'tests/image-handoff-v2-integration-test.php': '8663f599ba29599ebd35d682438137eba47c5a37', 'tests/image-handoff-v2-pdo-test.php': '64baac41dc06a9902097e28e596669b580f0052a', 'tests/image-handoff-v2-writer-contract-test.php': '699101dad61129a2cefbea2bb90fb7026800685d', 'tests/image-handoff-v3-quarantine-test.php': 'a65aa371ebe865e3c5bcb8fe88ff2938e6d45c70', 'tests/image-handoff-v3-race-test.php': 'a24e1ed0a4617b3dfdb99f0b1b8ebad4a5e6e4c2', 'tests/image-pilot-cli-test.py': 'ed3ec5cc2d9b8b60994387c5f26629bb931fcb23', 'tests/image-pilot-test.php': 'e9f3098d2cef89e39abb6ea9dbfe1e8503db47a7', 'tests/keyboard-browser/README.md': '567982a7ebf4a4ef8a42ce5c5c3eb7cd53888f91', 'tests/keyboard-browser/catalog-scroll-guard-test.py': '821bd44f751b11830931ed67b1a34a5157206f10', 'tests/keyboard-browser/catalog-scroll-identity.cjs': '42aac464164ce8231fe6ff0e81445a5b2db48bdd', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': '29c05ea25c44544ffc1fd1653aea83e1e8474b03', 'tests/keyboard-browser/catalog-scroll-policy.cjs': '7716c602d7640c58286964940b2f69f8a5150256', 'tests/keyboard-browser/catalog-scroll.cjs': 'c189ae8cee2b45af2ef9c51d677415415ba39203', 'tests/keyboard-browser/email-restore.cjs': 'ac21fdaafba504551fd4faaaa741f2b7d245f884', 'tests/keyboard-browser/heading-unit-test.py': 'f0c6aaecdfceee85128a38c68590facbc5cee5ba', 'tests/keyboard-browser/navbar.cjs': '4f93133adbd8f9b12c54461713a12c6b909c3004', 'tests/keyboard-browser/run.py': '75ad7e455e8dfced062d568eb1defd0bee40241a', 'tests/newsletter-pending-edits-test.cjs': 'b7d6b6cee085eb4b515615014e2b0c99ac82c299', 'tests/product-gallery-identity-test.py': '2a27ceb5ed77bb36b8b88d4594836a5e914fbf4d', 'tests/product-gallery-selection-test.php': '6fb8ea818e9cb3ce6e3653d303c9e0874d8068a2', 'tests/purgestore-csrf-test.py': '48dfc923f4f666cceb48e74e2384fbdb497a0966', 'tests/rotor-reviewed-facts-test.php': 'ee9ce4400c99262ae33857245350e2806649a94e', 'tests/sale-schedule-http-test.py': '1c39b832510e4f399cf6c2809b96df5233fe5cab', 'tests/sale-schedule-test.py': '8dd204600f8bf213974353b07a837793dabd0f15', 'tests/saved-search-rendering-test.cjs': 'afb8575f7ed86101a96f510526863c210622941d', 'tests/shipping-parcel-total-boundary-test.php': 'cf8d410001a652d4ecaf9817fdb2820335771513', 'tests/stale-inventory-csrf-http-test.py': 'b8026c225f21bd37615fc9ef939c96dfdfc81c63', 'tests/keyboard-browser/shipping_identity.py': '075be901e2f24af392495bb3d9977fe1586e0edc', 'tests/keyboard-browser/shipping-identity-test.py': 'dfc71b4c80215ac9e18b36239f30d9956722114b', 'tests/keyboard-browser/storage_identity.py': '38da96168a315f18613c3e02e9a48176b879138f', '.github/workflows/keyboard-browser.yml': 'c007f1556505b1e925d8d2774df6eab3139daa91', 'tests/keyboard-browser/storage-unavailable.cjs': '4af982adb74194c9678ebdf10360eefe1acd40ed', 'tests/keyboard-browser/malformed-hydration.cjs': '7100046f80307c2fb698be747bfcb085b4e67327'}
EXPECTED_CASES = {
    '01-authentication-and-csrf', '02-unchanged-and-title-only',
    '03-import-with-protected-title', '04-description-and-repeat-import',
    '05-selected-field-release', '06-multipart-images-and-modal',
    '07-two-tab-and-import-conflicts', '08-session-and-navigation',
}
ASSET_HOSTS = {"cdn.jsdelivr.net", "cdnjs.cloudflare.com", "unpkg.com",
               "fonts.googleapis.com", "fonts.gstatic.com"}
PNG_REMOTE = "https://images.example.invalid/imported.png?x=1&y=2"
RUNTIME_PREFIXES = ("src/", "admin/", "includes/", "public/")
PRIVATE_NAMES = {"uploads", "backups", "exports", "audit", "logs", ".git"}
DISABLED_PHP = ("mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,"
                "popen,proc_open,fsockopen,pfsockopen,stream_socket_client")
PHP_ERROR = re.compile(r"(?:PHP\s+)?(?:Warning|Fatal error|Parse error|Notice|Deprecated|Recoverable fatal error):|Uncaught\s", re.I)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def run_command(args: list[str], *, env: dict | None = None, cwd: Path | None = None,
                input_text: str | None = None, timeout: int = 60) -> subprocess.CompletedProcess:
    result = subprocess.run(args, input=input_text, text=True, capture_output=True,
                            env=env, cwd=cwd, timeout=timeout)
    require(result.returncode == 0, "Required command failed: " + Path(args[0]).name)
    return result


def git(*args: str) -> str:
    return run_command(["git", *args], cwd=ROOT).stdout.strip()


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def json_write(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def assert_isolation() -> dict:
    require(sys.platform == "linux", "Linux network namespace required")
    require(os.geteuid() != 0, "Application/browser must not run as root")
    expected_parent = os.environ.get("FAS_EXPECTED_PARENT_NETNS", "")
    current = os.readlink("/proc/self/ns/net")
    require(bool(re.fullmatch(r"net:\[\d+\]", expected_parent)), "Parent namespace receipt required")
    require(current != expected_parent, "Fresh kernel network namespace required")
    links = json.loads(run_command(["ip", "-j", "link", "show"]).stdout)
    require(len(links) == 1 and links[0]["ifname"] == "lo", "Only the loopback interface may exist")
    require("UP" in links[0].get("flags", []), "Loopback must be up")
    addresses = json.loads(run_command(["ip", "-j", "address", "show"]).stdout)
    for interface in addresses:
        for item in interface.get("addr_info", []):
            require(ipaddress.ip_address(item["local"]).is_loopback, "Non-loopback address detected")
    for family in ("-4", "-6"):
        routes = json.loads(run_command(["ip", family, "-j", "route", "show", "table", "all"]).stdout)
        for route in routes:
            require(route.get("dev") == "lo" and route.get("dst") != "default",
                    "Non-loopback route detected")
    for name in os.environ:
        require(not re.search(r"(?:TOKEN|SECRET|PASSWORD|CREDENTIAL|AWS_|AZURE_|EBAY|PAYPAL|SMTP)", name, re.I),
                "Credential-like environment variable is forbidden")
    return {"kernel_network_namespace": current, "different_from_parent": True,
            "interfaces": ["lo"], "unprivileged": True, "egress_available": False}


def verify_candidate() -> dict:
    require(git("rev-parse", BASELINE + "^{commit}") == BASELINE, "Approved baseline unavailable")
    require(git("rev-parse", BASELINE + "^{tree}") == BASELINE_TREE, "Approved baseline tree mismatch")
    require(run_command(["git", "merge-base", "--is-ancestor", BASELINE, "HEAD"], cwd=ROOT).returncode == 0,
            "Approved baseline must be an ancestor")
    # NUL framing and disabled rename detection make paths/statuses unambiguous.
    changed = git("diff", "--no-renames", "--name-status", "-z", BASELINE, "HEAD")
    fields = changed.split("\0")
    require(fields[-1] == "" and len(fields) % 2 == 1, "Malformed candidate diff")
    entries = list(zip(fields[0:-1:2], fields[1:-1:2]))
    require(len(entries) == len(ALLOWED_CHANGES)
            and len({path for _, path in entries}) == len(entries)
            and {path: status for status, path in entries} == ALLOWED_CHANGES,
            "Diff must match the reviewed integration paths and exact statuses")
    objects = git("ls-tree", "-r", "-z", "HEAD", "--", *sorted(ALLOWED_CHANGES)).split("\0")
    require(objects[-1] == "" and len(objects) - 1 == len(ALLOWED_CHANGES)
            and all(item.startswith("100644 blob ") for item in objects[:-1]),
            "Reviewed files must be regular non-executable Git blobs")
    # Pin exact reviewed storage runtime, tests and integration blobs (guard self/source excluded to avoid cycles).
    for path, expected in EXPECTED_STORAGE_BLOBS.items():
        require(git("rev-parse", "HEAD:" + path) == expected, "Reviewed storage blob mismatch: " + path)
    # Permit only the exact reviewed storage warning/runtime delta; no arbitrary runtime changes.
    for path, pins in EXPECTED_RUNTIME.items():
        require(git("rev-parse", BASELINE + ":" + path) == pins["before"],
                "Reviewed runtime preimage mismatch")
        require(git("rev-parse", "HEAD:" + path) == pins["after"],
                "Reviewed runtime candidate mismatch")
    require(not git("diff", "--name-only", "-z", "HEAD"), "Tracked working tree must be unchanged")
    require(not git("diff", "--cached", "--name-only", "-z", "HEAD"), "Tracked index must be unchanged")
    require(not git("ls-files", "--others", "-z"), "Untracked files are forbidden in the checkout")

    # Git diff may trust skip-worktree/assume-unchanged flags. Check actual bytes
    # independently; absent sparse exclusions are allowed, but never read.
    tagged = git("ls-files", "-t", "-z").split("\0")
    require(tagged[-1] == "", "Malformed tracked-index inventory")
    index = {}
    for item in tagged[:-1]:
        require(len(item) > 2 and item[1] == " " and item[2:] not in index,
                "Malformed or duplicate tracked-index path")
        index[item[2:]] = item[0]
    tracked = git("ls-tree", "-r", "-z", "HEAD").split("\0")
    require(tracked[-1] == "", "Malformed committed-tree inventory")
    checked, absent, tree_paths = 0, 0, set()
    for item in tracked[:-1]:
        row = item.split("\t", 1)
        require(len(row) == 2, "Malformed committed-tree entry")
        metadata, rel = row[0].split(), row[1]
        require(len(metadata) == 3 and rel in index and rel not in tree_paths,
                "Committed tree and index disagree")
        tree_paths.add(rel)
        mode, kind, oid = metadata
        path = ROOT / rel
        if not path.exists() and not path.is_symlink():
            require(index[rel] == "S", "Missing tracked file is not a sparse exclusion")
            absent += 1
            continue
        require(mode in {"100644", "100755"} and kind == "blob"
                and path.is_file() and not path.is_symlink()
                and path.resolve().is_relative_to(ROOT.resolve()),
                "Materialized tracked source is not a regular in-checkout blob")
        data = path.read_bytes()
        actual = hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()
        require(actual == oid, "Materialized tracked bytes differ from committed source")
        require(bool(path.stat().st_mode & 0o111) == (mode == "100755"),
                "Materialized tracked file mode differs from committed source")
        checked += 1
    require(tree_paths == set(index), "Committed tree and index path sets disagree")
    return {"baseline": BASELINE, "commit": git("rev-parse", "HEAD"),
            "tree": git("rev-parse", "HEAD^{tree}"),
            "checkout": "explicit PR head or workflow dispatch SHA, not a PR merge ref",
            "added_paths": sorted(path for path, status in ALLOWED_CHANGES.items() if status == "A"),
            "modified_paths": sorted(path for path, status in ALLOWED_CHANGES.items() if status == "M"),
            "materialized_tracked_blobs_verified": checked, "absent_sparse_paths": absent,
            "runtime_diff_from_baseline": sorted(EXPECTED_RUNTIME)}


def verify_assets(assets: Path) -> dict:
    from urllib.parse import urlsplit
    manifest = json.loads((assets / "manifest.json").read_text())
    require(isinstance(manifest, dict) and bool(manifest), "Cached presentation assets required")
    for url, entry in manifest.items():
        parsed = urlsplit(url)
        require(parsed.scheme == "https" and parsed.hostname in ASSET_HOSTS
                and not parsed.username and not parsed.password and not parsed.port,
                "Unapproved cached presentation URL")
        file = assets / entry["file"]
        require(file.is_file() and not file.is_symlink() and file.resolve().parent == assets,
                "Cached asset escapes its directory")
        require(sha(file) == entry["sha256"], "Cached presentation bytes differ from their manifest")
    return {"asset_count": len(manifest), "manifest_sha256": sha(assets / "manifest.json")}


def copy_runtime(site: Path) -> dict:
    sources = {}
    tracked = git("ls-files", "-z").split("\0")
    for rel in tracked:
        if not rel or not (rel.startswith(RUNTIME_PREFIXES) or rel == "scripts/security-maintenance.php"):
            continue
        parts = Path(rel).parts
        name = parts[-1]
        if any(part in PRIVATE_NAMES for part in parts):
            continue
        if re.match(r"config.*\.php$", name) or name in {"shipping.php", "applepay.php", "googlepay.php"}:
            continue
        if name.startswith(".") or re.search(r"\.(?:db|sqlite|sqlite3|log|pem|key|env|sql|zip|gz|bak)$", name, re.I):
            continue
        source = ROOT / rel
        require(source.is_file() and not source.is_symlink(), "Selected runtime source is not a regular file")
        require(source.resolve().is_relative_to(ROOT.resolve()), "Runtime source escapes checkout")
        destination = site / rel
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, destination)
        sources[rel] = sha(source)
        require(sha(destination) == sources[rel], "Runtime copy differs from pinned source")
    for rel in ["admin/login.php", "admin/logout.php", "admin/index.php", "admin/products.php",
                "admin/auth.php", "admin/includes/nav.php", "admin/includes/footer.php",
                "admin/js/admin-ajax.js", "src/utils/CSRF.php", "src/models/Product.php",
                "src/utils/ProductSyncOwnership.php", "src/utils/ImmutableProductUpload.php",
                "src/integrations/EbayAPI.php", "scripts/security-maintenance.php"]:
        require(rel in sources, "Required real application source missing: " + rel)
    return sources


def png_bytes() -> bytes:
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 2, 2, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(b"\0" + b"\xff\0\0" * 2 + b"\0" + b"\0\xff\0" * 2))
            + chunk(b"IEND", b""))


DRIVER = r'''<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(90); }
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$base = __DIR__;
$site = $base . '/site';
require_once $site . '/src/models/Product.php';
require_once $site . '/src/utils/ProductSyncOwnership.php';
require_once $site . '/src/utils/ProductContent.php';
$db = new PDO('sqlite:' . $base . '/private/catalog.sqlite', null, null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');
$model = new FAS\Models\Product($db);
function feed(string $id, int $phase): array {
    $prices = [1=>100,2=>80,3=>65,4=>60];
    $stock = [1=>5,2=>3,3=>1,4=>2];
    $remote = 'https://images.example.invalid/imported.png?x=1&y=2';
    return ['id'=>$id, 'title'=>'Imported name v'.$phase, 'description'=>'Imported description v'.$phase,
        'price'=>$prices[$phase], 'quantity'=>$stock[$phase], 'weight'=>1, 'length'=>2, 'width'=>3, 'height'=>4,
        'brand'=>'Fixture Brand', 'mpn'=>'Fixture MPN', 'image'=>$remote,
        'images'=>[$remote,'/gallery/uploads/shared.png',$remote,'gallery/legacy.png'],
        'url'=>'https://example.invalid/'.$id];
}
function details(array $fixture): object {
    return new class($fixture) {
        private array $fixture;
        public function __construct(array $fixture) { $this->fixture=$fixture; }
        public function getItemDetails($id): array {
            if ($id !== $this->fixture['id']) throw new RuntimeException('Unexpected fixture item');
            return array_merge($this->fixture, ['condition'=>'New other (see details)','sku'=>$id]);
        }
    };
}
try {
    if ($input['op'] === 'seed') {
        $q=$db->prepare("INSERT INTO admin_users(id,username,password_hash,email,full_name,role,is_active) VALUES(9001,?,?,?,'Synthetic administrator','admin',1)");
        $q->execute(['pr160-fixture', password_hash($input['password'], PASSWORD_DEFAULT), 'fixture@example.invalid']);
        $fixture=feed('SYNTHETIC-PR160-LEGACY',1);
        $legacy=(int)$model->syncFromEbay($fixture,details($fixture));
        $model->update($legacy,['name'=>'Legacy website name','description'=>'Legacy website description','category'=>'motorcycle']);
        FAS\Utils\ProductSyncOwnership::install($db);
        $fixture=feed('SYNTHETIC-PR160-NEW',1);
        $new=(int)$model->syncFromEbay($fixture,details($fixture));
        $model->update($new,['category'=>'motorcycle']);
        $q=$db->prepare('UPDATE products SET images=? WHERE id=?');
        $q->execute([json_encode($fixture['images'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$new]);
        $shared=(int)$model->create(['sku'=>'SYNTHETIC-PR160-SHARED','name'=>'Shared upload reference',
            'description'=>'Synthetic reference only','price'=>1,'quantity'=>1,'category'=>'motorcycle',
            'manufacturer'=>'Fixture Brand','model'=>'Fixture MPN','condition_name'=>'Used',
            'weight'=>1,'length'=>2,'width'=>3,'height'=>4,'image_url'=>'/gallery/uploads/shared.png',
            'images'=>[],'source'=>'manual']);
        FAS\Utils\ProductContent::install($db);
        $editorial=json_encode(['description'=>'Separate reviewed text','seo_title'=>'Reviewed search title',
            'seo_description'=>'Reviewed search summary'],JSON_THROW_ON_ERROR);
        $q=$db->prepare('INSERT INTO product_content_reviews(product_id,draft_json,published_json,updated_at) VALUES(?,?,?,?)');
        $q->execute([$legacy,$editorial,$editorial,'synthetic']);
        echo json_encode(['legacy'=>$legacy,'new'=>$new,'shared'=>$shared],JSON_THROW_ON_ERROR);
    } elseif ($input['op'] === 'import' && is_int($input['phase']) && $input['phase']>=1 && $input['phase']<=4) {
        foreach (['SYNTHETIC-PR160-LEGACY','SYNTHETIC-PR160-NEW'] as $id) {
            $fixture=feed($id,$input['phase']);
            if (!$model->syncFromEbay($fixture,details($fixture))) throw new RuntimeException('Fixture import did not persist');
        }
        echo json_encode(['phase'=>$input['phase'],'provider_loaded'=>class_exists('FAS\\Integrations\\EbayAPI',false)],JSON_THROW_ON_ERROR);
    } else { throw new RuntimeException('Unsupported fixture operation'); }
    if (class_exists('FAS\\Integrations\\EbayAPI',false)) throw new RuntimeException('Provider class loaded');
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture operation failed: '.get_class($error).PHP_EOL);
    exit(1);
}
'''


ROUTER = r'''<?php
declare(strict_types=1);
$rawPath=explode('?',(string)($_SERVER['REQUEST_URI']??''),2)[0];
// Real admin rendering prepends ../ even to /gallery paths. Normalize only
// that static double-slash spelling; never normalize a PHP route or traversal.
$path=preg_match('~^//+gallery/~D',$rawPath)?'/'.ltrim($rawPath,'/'):$rawPath;
$allowedPhp=['/admin/login.php','/admin/logout.php','/admin/index.php','/admin/products.php','/admin/mark-analytics-session.php'];
$root=realpath(__DIR__.'/site');
$allowedStatic=preg_match('~^/(?:admin/(?:css|js)/|public/js/|gallery/)[A-Za-z0-9_./-]+\.(?:css|js|png|jpg|jpeg|gif|webp|svg|woff2?|ttf|ico)$~D',$path)
    || in_array($path,['/admin/manifest.json','/admin/service-worker.js'],true);
$validPath=$path!=='' && !str_contains($path,'..') && !str_contains($path,'\\') && !str_contains($path,"\0") && !str_contains($path,'%');
$target=$validPath?realpath($root.$path):false;
$allowed=$target!==false && str_starts_with($target,$root.'/') && is_file($target)
    && (in_array($path,$allowedPhp,true)||$allowedStatic);
register_shutdown_function(static function() use ($path,$rawPath,$allowed,$root): void {
    $included=[];
    $provider=false;
    foreach(get_included_files() as $file) {
        if (str_starts_with($file,$root.'/')) $included[]=substr($file,strlen($root)+1);
        if (str_ends_with($file,'/src/integrations/EbayAPI.php')) $provider=true;
    }
    $provider=$provider||class_exists('FAS\\Integrations\\EbayAPI',false);
    $error=error_get_last();
    $fatal=$error && in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true);
    file_put_contents(__DIR__.'/private/request-receipts.jsonl',json_encode([
        'path'=>$path,'raw_path'=>$rawPath,'static_gallery_normalized'=>$rawPath!==$path,
        'allowed'=>(bool)$allowed,'status'=>http_response_code(),
        'provider_loaded'=>$provider,'included'=>$included,'fatal'=>(bool)$fatal
    ],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
});
if (!$allowed) { http_response_code(404); header('Content-Type: text/plain'); echo 'Fixture route unavailable'; return true; }
if (in_array($path,$allowedPhp,true)) { require $target; return true; }
// Serve only the validated static target, including the renderer's //gallery
// spelling. Never ask the PHP server to re-resolve a normalized request path.
$extension=strtolower(pathinfo($target,PATHINFO_EXTENSION));
$mime=['css'=>'text/css','js'=>'application/javascript','json'=>'application/json',
    'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
    'svg'=>'image/svg+xml','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','ico'=>'image/x-icon'];
if (in_array($extension,['png','jpg','jpeg','gif','webp'],true)) {
    $image=@getimagesize($target);
    if ($image && isset($image['mime'])) $mime[$extension]=$image['mime'];
}
header('Content-Type: '.($mime[$extension]??'application/octet-stream'));
header('Content-Length: '.filesize($target));
header('X-Content-Type-Options: nosniff');
readfile($target);
return true;
'''


class Fixture:
    def __init__(self, base: Path, php: str, env: dict, secrets_to_redact: list[str]):
        self.base, self.site, self.php, self.env = base, base / "site", php, env
        self.secrets = secrets_to_redact
        self.driver_errors: list[str] = []

    def php_args(self) -> list[str]:
        private = self.base / "private"
        return [self.php, "-d", "allow_url_fopen=0", "-d", "display_errors=0",
                "-d", "log_errors=1", "-d", "error_reporting=E_ALL",
                "-d", "disable_functions=" + DISABLED_PHP,
                "-d", "open_basedir=" + str(self.base),
                "-d", "session.save_path=" + str(private / "sessions"),
                "-d", "upload_tmp_dir=" + str(private / "http-upload"),
                "-d", "sys_temp_dir=" + str(private / "tmp"),
                "-d", "upload_max_filesize=21M", "-d", "post_max_size=50M"]

    def command(self, script: Path, *args: str, payload: dict | None = None) -> dict:
        result = subprocess.run(self.php_args() + [str(script), *args],
                                input=json.dumps(payload) if payload is not None else None,
                                text=True, capture_output=True, env=self.env, timeout=30)
        self.driver_errors.extend(line for line in result.stderr.splitlines() if PHP_ERROR.search(line))
        require(result.returncode == 0 and not self.driver_errors, "Synthetic CLI setup/import failed or emitted a PHP error")
        try:
            return json.loads(result.stdout)
        except ValueError as error:
            raise RuntimeError("Synthetic CLI returned an invalid receipt") from error

    def snapshot(self) -> dict:
        db_path = self.base / "private/catalog.sqlite"
        with sqlite3.connect(f"file:{db_path}?mode=ro", uri=True) as db:
            db.row_factory = sqlite3.Row
            result = {}
            for label, table, order in [("products", "products", "id"),
                                        ("ownership", "product_sync_ownership", "product_id,field"),
                                        ("history", "product_sync_ownership_history", "id"),
                                        ("reviews", "product_content_reviews", "product_id")]:
                result[label] = [dict(row) for row in db.execute(f"SELECT * FROM {table} ORDER BY {order}")]
            result["integrity"] = [row[0] for row in db.execute("PRAGMA integrity_check")]
            result["foreign_keys"] = [list(row) for row in db.execute("PRAGMA foreign_key_check")]
        require(result["integrity"] == ["ok"] and not result["foreign_keys"], "Synthetic database integrity failed")
        result["files"] = {}
        for path in sorted((self.site / "gallery").rglob("*")):
            require(not path.is_symlink(), "Unexpected symlink in fixture gallery")
            if path.is_file():
                result["files"][path.relative_to(self.site).as_posix()] = {"sha256": sha(path), "bytes": path.stat().st_size}
        return result

    def redact(self, text: str) -> str:
        for value in self.secrets:
            if value:
                text = text.replace(value, "[redacted]")
        return text.replace(str(self.base), "[fixture]")


def stop(process: subprocess.Popen | None) -> None:
    if process is None or process.poll() is not None:
        return
    process.terminate()
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)


def process_table() -> dict[int, tuple[int, str, str]]:
    """PID -> (parent PID, start identity, state); never read cmdline/env secrets."""
    result = {}
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        try:
            fields = (entry / 'stat').read_text().rsplit(') ', 1)[1].split()
            result[int(entry.name)] = (int(fields[1]), fields[19], fields[0])
        except (OSError, ValueError, IndexError):
            continue
    return result


def track_children(roots: list[int], tracked: dict[int, str]) -> None:
    table = process_table()
    descendants = {pid for pid in roots if pid in table and (pid not in tracked or tracked[pid] == table[pid][1])} | {pid for pid, identity in tracked.items()
                                if pid in table and table[pid][1] == identity}
    changed = True
    while changed:
        new = {pid for pid, (parent, _, _) in table.items() if parent in descendants}
        changed = bool(new - descendants)
        descendants |= new
    for pid in descendants:
        if pid in table:
            tracked[pid] = table[pid][1]


def tracked_running(tracked: dict[int, str]) -> list[int]:
    table = process_table()
    return [pid for pid, identity in tracked.items()
            if pid in table and table[pid][1] == identity and table[pid][2] != 'Z']


def stop_tracked(tracked: dict[int, str]) -> bool:
    for sig in (signal.SIGTERM, signal.SIGKILL):
        for pid in tracked_running(tracked):
            try:
                os.kill(pid, sig)
            except ProcessLookupError:
                pass
        deadline = time.monotonic() + 5
        while tracked_running(tracked) and time.monotonic() < deadline:
            time.sleep(.1)
    return not tracked_running(tracked)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--assets", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    require(not output.is_relative_to(ROOT.resolve()), "Evidence output must be outside the checkout")
    output.mkdir(parents=True, exist_ok=True)
    require(not any(output.iterdir()), "Evidence output must be empty")
    report = {"status": "not_run", "scope": "isolated real admin browser and inert model import",
              "production_verified": False, "provider_verified": False, "scheduler_verified": False}
    server = browser = None
    base_path = None
    server_log = browser_error_log = None
    fixture = None
    original_sources = {}
    failure = None
    tracked_processes: dict[int, str] = {}
    port = None
    try:
        report["isolation"] = assert_isolation()
        report["candidate"] = verify_candidate()
        assets = args.assets.resolve(strict=True)
        report["presentation_assets"] = verify_assets(assets)
        php = shutil.which("php")
        node = shutil.which("node")
        require(bool(php and node), "PHP and Node must already be installed by the trusted workflow")
        report['php_version'] = run_command([str(php), '--version']).stdout.splitlines()[0]
        require(bool(os.environ.get("NODE_PATH")) and bool(os.environ.get("PLAYWRIGHT_BROWSERS_PATH")),
                "Preinstalled Playwright module and browser paths are required")
        temporary = tempfile.mkdtemp(prefix="fas-admin-product-")
        base = Path(temporary).resolve()
        base_path = base
        site, private = base / "site", base / "private"
        site.mkdir(mode=0o700)
        private.mkdir(mode=0o700)
        for name in ["sessions", "http-upload", "tmp", "home"]:
            (private / name).mkdir(mode=0o700)
        original_sources = copy_runtime(site)
        json_write(output / "source-manifest.json", {"candidate": report["candidate"],
            "source_sha256": original_sources, "runtime_source_substitutions": [],
            "fixture_additions": ["private config", "private SQLite/session state", "generated PNGs", "guard router", "CLI fixture driver"]})
        schema = ROOT / "database/schema.sqlite.sql"
        require(schema.is_file() and not schema.is_symlink(), "Pinned SQLite schema is required")
        with sqlite3.connect(private / "catalog.sqlite") as db:
            db.executescript(schema.read_text())
        report["schema_sha256"] = sha(schema)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        origin = f"http://127.0.0.1:{port}"
        config = """<?php return [
'database'=>['path'=>%s],
'site'=>['name'=>'Synthetic FAS acceptance','url'=>%s,'timezone'=>'UTC','email'=>'fixture@example.invalid','from_email'=>'fixture@example.invalid'],
'ebay'=>['app_id'=>'','cert_id'=>'','dev_id'=>'','user_token'=>'','refresh_token'=>'','token_expires_at'=>null,'sandbox'=>true,'site_id'=>0,'store_name'=>''],
'paypal'=>['client_id'=>'','client_secret'=>'','mode'=>'sandbox','currency'=>'USD','webhook_id'=>''],
'easyship'=>['api_key'=>''],
'shipping'=>['free_shipping'=>['enabled'=>true,'product_flags_enabled'=>true,'auto_rules_enabled'=>false]],
'google_analytics'=>['enabled'=>false], 'tawk'=>['enabled'=>false], 'turnstile'=>['enabled'=>false],
'sale'=>['enabled'=>false], 'security'=>['sync_api_key'=>'disabled-synthetic-route','admin_password_salt'=>'synthetic-only']
];
""" % (json.dumps(str(private / "catalog.sqlite")), json.dumps(origin))
        (site / "src/config/config.php").write_text(config)
        os.chmod(site / "src/config/config.php", 0o600)
        images = png_bytes()
        for rel in ["gallery/uploads/shared.png", "gallery/legacy.png", "gallery/FLIPANDSTRIP.COM_d00a_018a.jpg", "gallery/favicons/favicon.png",
                    "gallery/favicons/favicon-180x180.png", "gallery/favicons/favicon-192x192.png",
                    "gallery/favicons/favicon-512x512.png"]:
            path = site / rel
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(images)
        upload_a, upload_b = private / "misleading-first.php", private / "misleading-second.txt"
        upload_a.write_bytes(images)
        upload_b.write_bytes(images)
        password = secrets.token_urlsafe(24)
        (base / "driver.php").write_text(DRIVER)
        (base / "router.php").write_text(ROUTER)
        env = {"PATH": os.environ["PATH"], "HOME": str(private / "home"),
               "TMPDIR": str(private / "tmp"), "TMP": str(private / "tmp"), "TEMP": str(private / "tmp"),
               "NODE_PATH": os.environ["NODE_PATH"],
               "PLAYWRIGHT_BROWSERS_PATH": os.environ["PLAYWRIGHT_BROWSERS_PATH"],
               "ANALYTICS_IP_GEO_ENABLED": "0",
               "FAS_SECURITY_DB_PATH": str(private / "security.sqlite"),
               "FAS_GROWTH_DB_PATH": str(private / "growth.sqlite")}
        fixture = Fixture(base, str(php), env, [password])
        health = fixture.command(site / "scripts/security-maintenance.php", "init")
        require(health.get("storage") == "healthy", "Private synthetic security store initialization failed")
        ids = fixture.command(base / "driver.php", payload={"op": "seed", "password": password})
        require(set(ids) == {"legacy", "new", "shared"}, "Synthetic seed receipt is incomplete")
        initial = fixture.snapshot()
        json_write(output / "fixture.json", {"ids": ids, "admin_id": 9001, "synthetic_only": True,
            "item_ids": ["SYNTHETIC-PR160-LEGACY", "SYNTHETIC-PR160-NEW"],
            "upload_sha256": hashlib.sha256(images).hexdigest(), "initial": initial,
            "human_account_used": False, "provider_client_constructed": False})
        server_log = (private / "server.log").open("w")
        server = subprocess.Popen(fixture.php_args() + ["-S", f"127.0.0.1:{port}", "-t", str(site), str(base / "router.php")],
                                  env=env, stdout=server_log, stderr=server_log, start_new_session=True)
        track_children([server.pid], tracked_processes)
        for _ in range(100):
            require(server.poll() is None, "PHP fixture server exited")
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=.2):
                    break
            except OSError:
                time.sleep(.1)
        else:
            raise RuntimeError("PHP fixture server failed to listen on loopback")
        browser_error_log = (private / "browser-stderr.log").open("w")
        browser = subprocess.Popen([str(node), str(ROOT / "tests/admin-product-browser/check.cjs")],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=browser_error_log,
                                   text=True, bufsize=1, env=env, start_new_session=True)
        track_children([server.pid, browser.pid], tracked_processes)
        browser.stdin.write(json.dumps({"type": "init", "origin": origin, "assets": str(assets),
            "output": str(output), "username": "pr160-fixture", "password": password, "ids": ids,
            "uploadA": str(upload_a), "uploadB": str(upload_b)}) + "\n")
        browser.stdin.flush()
        selector = selectors.DefaultSelector()
        selector.register(browser.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + 600
        done = None
        operations = []
        while done is None:
            track_children([server.pid, browser.pid], tracked_processes)
            require(time.monotonic() < deadline, "Browser acceptance timed out")
            events = selector.select(timeout=1)
            if not events:
                require(browser.poll() is None, "Browser worker exited without a result")
                continue
            line = browser.stdout.readline()
            require(bool(line), "Browser worker closed its result stream")
            require(len(line) < 1_000_000, "Oversized browser protocol message")
            message = json.loads(line)
            if message.get("type") == "done":
                done = message
                break
            require(message.get("type") == "rpc" and isinstance(message.get("id"), (int, str)), "Invalid browser protocol")
            op = message.get("op")
            response = {"type": "reply", "id": message["id"]}
            try:
                if op == "import":
                    phase = message.get("phase")
                    require(type(phase) is int and 1 <= phase <= 4, "Unexpected import phase")
                    receipt = fixture.command(base / "driver.php", payload={"op": "import", "phase": phase})
                    require(receipt.get("provider_loaded") is False, "Fixture import loaded the provider client")
                    operations.append({"op": op, "phase": phase})
                else:
                    require(op == "snapshot", "Unexpected fixture RPC operation")
                response.update(ok=True, result=fixture.snapshot())
            except Exception:
                response.update(ok=False, error="Fixture operation failed; see sanitized runner report")
                failure = "Fixture operation failed"
            browser.stdin.write(json.dumps(response) + "\n")
            browser.stdin.flush()
        selector.close()
        browser.stdin.close()
        require(browser.wait(timeout=20) == 0 and done.get("ok") is True and failure is None,
                "Browser acceptance reported failure")
        cases = done.get("summary", {}).get("cases", [])
        require(len(cases) == 8 and {item.get('name') for item in cases} == EXPECTED_CASES
                and all(item.get('status') == 'passed' for item in cases)
                and done.get('summary', {}).get('browser_errors') == 0
                and done.get('summary', {}).get('blocked_requests') == 0,
                "All eight case groups and clean browser/network evidence are required")
        browser_cleanup = done.get('summary', {}).get('cleanup', {})
        require(all(browser_cleanup.get(key) is True for key in
                    ('context_created', 'browser_created', 'context_closed', 'browser_closed')),
                "Browser and context cleanup must be confirmed")
        report["browser_summary"] = done.get("summary")
        report["inert_import_operations"] = operations
        final = fixture.snapshot()
        json_write(output / "final-fixture-state.json", final)
        stop(server)
        server_log.close()
        server_log = None
        request_path = private / "request-receipts.jsonl"
        require(request_path.is_file(), "Application include/provider receipts are missing")
        receipts = [json.loads(line) for line in request_path.read_text().splitlines() if line]
        require(bool(receipts) and all(row["allowed"] and not row["provider_loaded"] and not row["fatal"] for row in receipts),
                "Unexpected application route, provider load or PHP fatal error")
        json_write(output / "request-receipts.json", receipts)
        errors = [fixture.redact(line) for line in (private / "server.log").read_text().splitlines() if PHP_ERROR.search(line)]
        errors.extend(fixture.redact(line) for line in fixture.driver_errors)
        json_write(output / "php-errors.json", {"errors": errors})
        require(not errors, "Application emitted PHP errors")
        require(all(sha(site / rel) == digest and sha(ROOT / rel) == digest
                    for rel, digest in original_sources.items()), "Runtime source changed during acceptance")
        require((site / "src/config/config.php").read_text() == config, "Generated config changed during acceptance")
        report.update(status="passed", provider_not_loaded=True, runtime_sources_unchanged=True,
                      request_count=len(receipts), eight_case_groups_passed=True)
    except Exception as error:
        report["status"] = "failed"
        report["error_type"] = type(error).__name__
        report["error"] = fixture.redact(str(error)) if fixture else str(error)
        # Detailed application/browser state is not copied on failure. The browser
        # writes its own sanitized assertions/screenshots before reporting failure.
    finally:
        track_children([p.pid for p in (server, browser) if p is not None], tracked_processes)
        stop(browser)
        stop(server)
        children_stopped = stop_tracked(tracked_processes)
        if server_log is not None:
            server_log.close()
        if browser_error_log is not None:
            browser_error_log.close()
        # Collect only sanitized, structured receipts before destroying state,
        # including after a failed browser case. No DB/session/config is copied.
        if fixture is not None and base_path is not None and base_path.exists():
            try:
                receipt_path = base_path / 'private/request-receipts.jsonl'
                receipts = [json.loads(line) for line in receipt_path.read_text().splitlines() if line] if receipt_path.exists() else []
                json_write(output / 'request-receipts.json', receipts)
                report['image_routing'] = {
                    'fixture_static_gallery_normalizations': sum(row.get('static_gallery_normalized') is True for row in receipts),
                    'production_double_slash_serving_verified': False,
                    'boundary': 'Only generated fixture images are resolved from //gallery/... to /gallery/...; Plesk handling is unverified',
                }
                if any(not row['allowed'] or row['provider_loaded'] or row['fatal'] for row in receipts):
                    report['status'] = 'failed'
                    report['guard_failure'] = True
                log_path = base_path / 'private/server.log'
                errors = [fixture.redact(line) for line in log_path.read_text().splitlines() if PHP_ERROR.search(line)] if log_path.exists() else []
                errors += [fixture.redact(line) for line in fixture.driver_errors]
                json_write(output / 'php-errors.json', {'errors': errors})
                if errors:
                    report['status'] = 'failed'
                unchanged = all((fixture.site / rel).is_file() and sha(fixture.site / rel) == digest
                                and sha(ROOT / rel) == digest for rel, digest in original_sources.items())
                report['runtime_sources_unchanged'] = unchanged
                if not unchanged:
                    report['status'] = 'failed'
            except Exception:
                report['status'] = 'failed'
                report['evidence_collection_failed'] = True
        listener_closed = True
        if port is not None:
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.2):
                    listener_closed = False
            except OSError:
                pass
        if base_path is not None and base_path.exists():
            shutil.rmtree(base_path)
        report["cleanup"] = {"temporary_state_removed": base_path is None or not base_path.exists(),
                             "php_stopped": server is None or server.poll() is not None,
                             "browser_parent_stopped": browser is None or browser.poll() is not None,
                             "tracked_descendants_stopped": children_stopped,
                             "loopback_listener_closed": listener_closed}
        if not all(report["cleanup"].values()):
            report["status"] = "failed"
        json_write(output / "report.json", report)
        print(json.dumps({"status": report["status"], "scope": report["scope"],
                          "production_verified": False, "evidence": "report.json"}))
    return 0 if report["status"] == "passed" else 1


if __name__ == "__main__":
    raise SystemExit(main())
