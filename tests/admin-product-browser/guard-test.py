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
EXPECTED_PATHS = {'about.php': 'M', 'admin/banners.php': 'M', 'admin/coupons.php': 'M', 'admin/ebay-sync-health.php': 'M', 'admin/purgestore.php': 'M', 'admin/sale.php': 'M', 'admin/stale-inventory.php': 'M', 'api/contact-form.php': 'M', 'api/products.php': 'M', 'api/validate-coupon.php': 'M', 'checkout.php': 'M', 'contact.php': 'M', 'docs/reviewed-product-facts.md': 'M', 'includes/header.php': 'M', 'includes/sale-helper.php': 'M', 'product.php': 'M', 'products.php': 'M', 'public/css/style.css': 'M', 'public/images/navbar-logo.png': 'A', 'public/js/growth.js': 'M', 'src/integrations/EbayAPI.php': 'M', 'src/models/Banner.php': 'M', 'src/models/Coupon.php': 'M', 'src/utils/ReviewedProductFacts.php': 'M', 'src/utils/ShippingRules.php': 'M', 'tests/admin-product-browser/guard-test.py': 'M', 'tests/admin-product-browser/run.py': 'M', 'tests/banner-csrf-http-test.py': 'A', 'tests/banner-input-validation-http-test.py': 'A', 'tests/banner-schedule-timezone-test.php': 'A', 'tests/campaign-code-case-test.php': 'A', 'tests/cart-malformed-hydration-test.cjs': 'M', 'tests/catalog-focus-restoration-test.cjs': 'A', 'tests/catalog-heading-identity-test.py': 'A', 'tests/catalog-loader-lifecycle-test.cjs': 'M', 'tests/catalog-scroll-offset-test.cjs': 'A', 'tests/catalog-search-layout-test.cjs': 'M', 'tests/catalog-zero-search-test.php': 'A', 'tests/checkout-coupon-lifecycle-test.cjs': 'A', 'tests/contact-delivery-test.py': 'A', 'tests/contact-pending-edits-test.cjs': 'A', 'tests/contact-provider-response-http-test.py': 'A', 'tests/contact-retry-test.cjs': 'A', 'tests/coupon-active-checkbox-http-test.py': 'A', 'tests/coupon-expiry-timezone-test.php': 'A', 'tests/coupon-input-shape-http-test.py': 'A', 'tests/ebay-remaining-quantity-test.php': 'A', 'tests/free-shipping-destination-api-test.py': 'A', 'tests/free-shipping-destination-test.php': 'A', 'tests/homepage-branding-test.py': 'M', 'tests/homepage-visual/check-layout.cjs': 'M', 'tests/homepage-visual/prepare-fixture.py': 'M', 'tests/keyboard-browser/README.md': 'M', 'tests/keyboard-browser/catalog-scroll-guard-test.py': 'A', 'tests/keyboard-browser/catalog-scroll-identity.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy.cjs': 'A', 'tests/keyboard-browser/catalog-scroll.cjs': 'A', 'tests/keyboard-browser/email-restore.cjs': 'M', 'tests/keyboard-browser/heading-unit-test.py': 'M', 'tests/keyboard-browser/heading_runner.py': 'M', 'tests/keyboard-browser/navbar.cjs': 'M', 'tests/keyboard-browser/run.py': 'M', 'tests/newsletter-pending-edits-test.cjs': 'A', 'tests/product-gallery-identity-test.py': 'A', 'tests/product-gallery-selection-test.php': 'A', 'tests/purgestore-csrf-test.py': 'A', 'tests/rotor-reviewed-facts-test.php': 'A', 'tests/sale-schedule-http-test.py': 'A', 'tests/sale-schedule-test.py': 'A', 'tests/saved-search-rendering-test.cjs': 'A', 'tests/stale-inventory-csrf-http-test.py': 'A'}
EXPECTED_RUNTIME = {'about.php': {'before': '47c399d13b31cb6439ec46b8901c68961d06e529', 'after': 'ededab775a76bb3c1443f0cde713b3903b34fbbf'}, 'admin/banners.php': {'before': '0d029994a786a312be85b4a1882c17fd3d9310de', 'after': '66eb77cef5e6688a8627d4cdd0476d25c20faca2'}, 'admin/coupons.php': {'before': '72426da91e2a916079408cf0008dead8bdc69a00', 'after': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098'}, 'admin/ebay-sync-health.php': {'before': '218e24714173b4a992cf2e898071471599b0d872', 'after': '4fd912aad6e5e2d513360a369a33b4018087df34'}, 'admin/purgestore.php': {'before': '89c9fcf9d64fc2607a841f9253b3aeab8d865054', 'after': '36f6d450157a9f68ceee971cb5e9da705bd0dbac'}, 'admin/sale.php': {'before': '5075fa0ca55c51aa3177ae265a31bbecdb264d60', 'after': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6'}, 'admin/stale-inventory.php': {'before': 'b43798f11a2c822de1aaa559f97844c0344ef36e', 'after': '484b9a5c65d167b342e7eecc81591adadba43b01'}, 'api/contact-form.php': {'before': 'aec0ec7a0c32819070ef41c22bf64a9db9a8a41c', 'after': '6f97709f0b1770fc27e136fb20e6066cd12e1b37'}, 'api/products.php': {'before': 'a284c99de99c7337e4cb8a1fa3f0a2a870aa4e8c', 'after': '357aaf382cd2b201e2b0169d20a6f071da21251e'}, 'api/validate-coupon.php': {'before': '37e6e2f7f95ca8c2d8e86d27562079a5ab4d07c9', 'after': '652bea7a87733a16ecb27deb57367a3c2e036e48'}, 'checkout.php': {'before': '7c98553a15d96a36620f7af1fcff363b3fa3ba16', 'after': 'cb9896037c05ce66c4e31fead6ee85595bf85529'}, 'contact.php': {'before': '103ca56061c7d1e2e8b817ab1c6b4d3e3e0f267d', 'after': '87886a0f564f01522ebf48ceecc7e15e5eee76fc'}, 'includes/header.php': {'before': 'b8ecc74f67f306afa142339b1641fc916a624e2c', 'after': '83ed8300bd5aaaf7121232e80b5b1af7dda45231'}, 'includes/sale-helper.php': {'before': 'c2d56d9d6d80e5c5e87263b9b0d5c7bf516139d1', 'after': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7'}, 'product.php': {'before': 'ff1a31cc764c881f7acbd392b563276bab9747c9', 'after': 'e57d1b3aad20283525d7bb159bc9a9d55563b659'}, 'products.php': {'before': 'e03bc2480c05adf03ea602b6706bd68c0eec8afa', 'after': '35571501a7a828d3f53cc9f30ccc7d79632d08a4'}, 'public/css/style.css': {'before': '103706af92484631585fcc26553239b0b42681c4', 'after': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052'}, 'public/js/growth.js': {'before': '87dac7559458d16f9adc755dc404d535beeeb9eb', 'after': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692'}, 'src/integrations/EbayAPI.php': {'before': 'd059b65637f6602c8ec21c52882e2c52890b2493', 'after': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7'}, 'src/models/Banner.php': {'before': '39e97c109294b925d9aa2b1f51a4e9186ffe2120', 'after': '2adbeafae3c4f1373409949c532520e51619daae'}, 'src/models/Coupon.php': {'before': '53ad053d7110a1f4f6d6e136d22cb03bf4742d05', 'after': 'bb899ad8a612b4c38fd0d18c72339123d57105d8'}, 'src/utils/ReviewedProductFacts.php': {'before': 'af5c5e4b277dd3005e825ec0f384756ed9441637', 'after': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'src/utils/ShippingRules.php': {'before': 'aecc28f5796850372f2892b2d8ef5645f0cbc6d7', 'after': '758e2adec9069fa5f8684597becdefaea9460a87'}}
EXPECTED_BASELINE_TREE = '00ebb2f16bea9655f81c88de754fee75b76c534f'
EXPECTED_STORAGE_BLOBS = {'about.php': 'ededab775a76bb3c1443f0cde713b3903b34fbbf', 'admin/banners.php': '66eb77cef5e6688a8627d4cdd0476d25c20faca2', 'admin/coupons.php': 'd6648193b2b05bf7f1d4affbe3b6160df9d00098', 'admin/ebay-sync-health.php': '4fd912aad6e5e2d513360a369a33b4018087df34', 'admin/purgestore.php': '36f6d450157a9f68ceee971cb5e9da705bd0dbac', 'admin/sale.php': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6', 'admin/stale-inventory.php': '484b9a5c65d167b342e7eecc81591adadba43b01', 'api/contact-form.php': '6f97709f0b1770fc27e136fb20e6066cd12e1b37', 'api/products.php': '357aaf382cd2b201e2b0169d20a6f071da21251e', 'api/validate-coupon.php': '652bea7a87733a16ecb27deb57367a3c2e036e48', 'checkout.php': 'cb9896037c05ce66c4e31fead6ee85595bf85529', 'contact.php': '87886a0f564f01522ebf48ceecc7e15e5eee76fc', 'docs/reviewed-product-facts.md': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060', 'includes/header.php': '83ed8300bd5aaaf7121232e80b5b1af7dda45231', 'includes/sale-helper.php': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7', 'product.php': 'e57d1b3aad20283525d7bb159bc9a9d55563b659', 'products.php': '35571501a7a828d3f53cc9f30ccc7d79632d08a4', 'public/css/style.css': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052', 'public/images/navbar-logo.png': 'cc39aa73e9c93bb75346bd96394e2f59184445f6', 'public/js/growth.js': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692', 'src/integrations/EbayAPI.php': '0fcf9c088e4ed6c12c98b2021273a1f66de47cf7', 'src/models/Banner.php': '2adbeafae3c4f1373409949c532520e51619daae', 'src/models/Coupon.php': 'bb899ad8a612b4c38fd0d18c72339123d57105d8', 'src/utils/ReviewedProductFacts.php': '1db5e4468b9730b12cad79f41f7094c2144571fb', 'src/utils/ShippingRules.php': '758e2adec9069fa5f8684597becdefaea9460a87', 'tests/banner-csrf-http-test.py': 'd7ae700887e1daaf0fc57518f4bdd24606024c33', 'tests/banner-input-validation-http-test.py': '6c8e5274bce727af794fdfbf21d7f17afa995139', 'tests/banner-schedule-timezone-test.php': '18cd1384c0a26ca280a695ecbbb34e78583942e6', 'tests/campaign-code-case-test.php': '08007f693916900c8b72ebb5718fa7d7c825565b', 'tests/cart-malformed-hydration-test.cjs': '7261f62a77364c7064930e232aa604ccacd65bf3', 'tests/catalog-focus-restoration-test.cjs': 'ea5b7eaf6e5ff513ccf8b82827bdfacc45a5de0f', 'tests/catalog-heading-identity-test.py': '31f2a60d5d4646c54521d0776f7f960be019983c', 'tests/catalog-loader-lifecycle-test.cjs': '44244918933b5bd8220c5c202403b2a8742f8a50', 'tests/catalog-scroll-offset-test.cjs': 'bd822d1f3cee43f13cec01e151685ad88134da58', 'tests/catalog-search-layout-test.cjs': '88326b077688f393fa45fd5f36436a53a884e303', 'tests/catalog-zero-search-test.php': 'c56d9d6668d3b9684c61c940b7317e94fb3fcb90', 'tests/checkout-coupon-lifecycle-test.cjs': '97896bf75ea4af85c12f800da671da75c8480dd2', 'tests/contact-delivery-test.py': '2e4e160cf6c62d4eb3eec8b8668fe88724f1722c', 'tests/contact-pending-edits-test.cjs': 'e20affefa282fe0bee690033cacf828fec54221a', 'tests/contact-provider-response-http-test.py': '1b1254fccf75ea69bc495cd9331bde8edd4be3cd', 'tests/contact-retry-test.cjs': 'dccea24eed65314386728bdf9c16937801830347', 'tests/coupon-active-checkbox-http-test.py': '41ed98b47c9f8553fb77d54764d66a876ebc8411', 'tests/coupon-expiry-timezone-test.php': 'ffd444d00624e127638180aebc857bd2a1c1e28c', 'tests/coupon-input-shape-http-test.py': 'b1031f0aa30c68774847f74aaafbb94df52fb479', 'tests/ebay-remaining-quantity-test.php': 'ac53e8cd9705355f1fdcd794e3adc2bd896d17ce', 'tests/free-shipping-destination-api-test.py': 'd283aed369602f9015f833f3262ca9c40c3e2cd5', 'tests/free-shipping-destination-test.php': 'cb80e4cdda5d7a9bcd245b86ab7ed5bd40fb52ff', 'tests/homepage-branding-test.py': 'b1710429404acd153838dd5d40a11f3a4c0e61a3', 'tests/homepage-visual/check-layout.cjs': '6eb3e15e58768ca3070fd80f4a26f0891bb26463', 'tests/homepage-visual/prepare-fixture.py': '7b0828c6e7c49d654362a72657e23cc6ba1b85ff', 'tests/keyboard-browser/README.md': '567982a7ebf4a4ef8a42ce5c5c3eb7cd53888f91', 'tests/keyboard-browser/catalog-scroll-guard-test.py': '821bd44f751b11830931ed67b1a34a5157206f10', 'tests/keyboard-browser/catalog-scroll-identity.cjs': '42aac464164ce8231fe6ff0e81445a5b2db48bdd', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': '29c05ea25c44544ffc1fd1653aea83e1e8474b03', 'tests/keyboard-browser/catalog-scroll-policy.cjs': '7716c602d7640c58286964940b2f69f8a5150256', 'tests/keyboard-browser/catalog-scroll.cjs': 'c189ae8cee2b45af2ef9c51d677415415ba39203', 'tests/keyboard-browser/email-restore.cjs': 'ac21fdaafba504551fd4faaaa741f2b7d245f884', 'tests/keyboard-browser/heading-unit-test.py': 'ba6a66467fb54ab388a7b517308838e900cd50b6', 'tests/keyboard-browser/navbar.cjs': '4f93133adbd8f9b12c54461713a12c6b909c3004', 'tests/keyboard-browser/run.py': '75ad7e455e8dfced062d568eb1defd0bee40241a', 'tests/newsletter-pending-edits-test.cjs': 'b7d6b6cee085eb4b515615014e2b0c99ac82c299', 'tests/product-gallery-identity-test.py': '2a27ceb5ed77bb36b8b88d4594836a5e914fbf4d', 'tests/product-gallery-selection-test.php': '6fb8ea818e9cb3ce6e3653d303c9e0874d8068a2', 'tests/purgestore-csrf-test.py': '48dfc923f4f666cceb48e74e2384fbdb497a0966', 'tests/rotor-reviewed-facts-test.php': 'ee9ce4400c99262ae33857245350e2806649a94e', 'tests/sale-schedule-http-test.py': '1c39b832510e4f399cf6c2809b96df5233fe5cab', 'tests/sale-schedule-test.py': '8dd204600f8bf213974353b07a837793dabd0f15', 'tests/saved-search-rendering-test.cjs': 'afb8575f7ed86101a96f510526863c210622941d', 'tests/stale-inventory-csrf-http-test.py': 'b8026c225f21bd37615fc9ef939c96dfdfc81c63'}
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
