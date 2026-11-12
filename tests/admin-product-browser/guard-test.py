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
EXPECTED_PATHS = {'admin/banners.php': 'M', 'admin/purgestore.php': 'M', 'admin/sale.php': 'M', 'admin/stale-inventory.php': 'M', 'api/contact-form.php': 'M', 'contact.php': 'M', 'docs/reviewed-product-facts.md': 'M', 'includes/sale-helper.php': 'M', 'public/css/style.css': 'M', 'public/js/growth.js': 'M', 'src/models/Banner.php': 'M', 'src/models/Coupon.php': 'M', 'src/utils/ReviewedProductFacts.php': 'M', 'src/utils/ShippingRules.php': 'M', 'tests/admin-product-browser/guard-test.py': 'M', 'tests/admin-product-browser/run.py': 'M', 'tests/banner-csrf-http-test.py': 'A', 'tests/banner-input-validation-http-test.py': 'A', 'tests/banner-schedule-timezone-test.php': 'A', 'tests/campaign-code-case-test.php': 'A', 'tests/catalog-scroll-offset-test.cjs': 'A', 'tests/contact-delivery-test.py': 'A', 'tests/contact-pending-edits-test.cjs': 'A', 'tests/contact-provider-response-http-test.py': 'A', 'tests/contact-retry-test.cjs': 'A', 'tests/coupon-expiry-timezone-test.php': 'A', 'tests/free-shipping-destination-api-test.py': 'A', 'tests/free-shipping-destination-test.php': 'A', 'tests/keyboard-browser/README.md': 'M', 'tests/keyboard-browser/catalog-scroll-guard-test.py': 'A', 'tests/keyboard-browser/catalog-scroll-identity.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy.cjs': 'A', 'tests/keyboard-browser/catalog-scroll.cjs': 'A', 'tests/keyboard-browser/heading_runner.py': 'M', 'tests/keyboard-browser/run.py': 'M', 'tests/newsletter-pending-edits-test.cjs': 'A', 'tests/purgestore-csrf-test.py': 'A', 'tests/rotor-reviewed-facts-test.php': 'A', 'tests/sale-schedule-http-test.py': 'A', 'tests/sale-schedule-test.py': 'A', 'tests/stale-inventory-csrf-http-test.py': 'A'}
EXPECTED_RUNTIME = {'admin/banners.php': {'before': '0d029994a786a312be85b4a1882c17fd3d9310de', 'after': '66eb77cef5e6688a8627d4cdd0476d25c20faca2'}, 'admin/purgestore.php': {'before': '89c9fcf9d64fc2607a841f9253b3aeab8d865054', 'after': '36f6d450157a9f68ceee971cb5e9da705bd0dbac'}, 'admin/sale.php': {'before': '5075fa0ca55c51aa3177ae265a31bbecdb264d60', 'after': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6'}, 'admin/stale-inventory.php': {'before': 'b43798f11a2c822de1aaa559f97844c0344ef36e', 'after': '484b9a5c65d167b342e7eecc81591adadba43b01'}, 'api/contact-form.php': {'before': 'aec0ec7a0c32819070ef41c22bf64a9db9a8a41c', 'after': '6f97709f0b1770fc27e136fb20e6066cd12e1b37'}, 'contact.php': {'before': '103ca56061c7d1e2e8b817ab1c6b4d3e3e0f267d', 'after': 'a304e3225c6ed63175811bd2d74cc1308fa69e50'}, 'includes/sale-helper.php': {'before': 'c2d56d9d6d80e5c5e87263b9b0d5c7bf516139d1', 'after': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7'}, 'public/css/style.css': {'before': '103706af92484631585fcc26553239b0b42681c4', 'after': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052'}, 'public/js/growth.js': {'before': '87dac7559458d16f9adc755dc404d535beeeb9eb', 'after': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692'}, 'src/models/Banner.php': {'before': '39e97c109294b925d9aa2b1f51a4e9186ffe2120', 'after': '2adbeafae3c4f1373409949c532520e51619daae'}, 'src/models/Coupon.php': {'before': '53ad053d7110a1f4f6d6e136d22cb03bf4742d05', 'after': 'bb899ad8a612b4c38fd0d18c72339123d57105d8'}, 'src/utils/ReviewedProductFacts.php': {'before': 'af5c5e4b277dd3005e825ec0f384756ed9441637', 'after': '1db5e4468b9730b12cad79f41f7094c2144571fb'}, 'src/utils/ShippingRules.php': {'before': 'aecc28f5796850372f2892b2d8ef5645f0cbc6d7', 'after': '758e2adec9069fa5f8684597becdefaea9460a87'}}
EXPECTED_BASELINE_TREE = '00ebb2f16bea9655f81c88de754fee75b76c534f'
EXPECTED_STORAGE_BLOBS = {'admin/banners.php': '66eb77cef5e6688a8627d4cdd0476d25c20faca2', 'admin/purgestore.php': '36f6d450157a9f68ceee971cb5e9da705bd0dbac', 'admin/sale.php': '799e8532d16fbec93fb51e3cf9ffede6fc8873c6', 'admin/stale-inventory.php': '484b9a5c65d167b342e7eecc81591adadba43b01', 'api/contact-form.php': '6f97709f0b1770fc27e136fb20e6066cd12e1b37', 'contact.php': 'a304e3225c6ed63175811bd2d74cc1308fa69e50', 'docs/reviewed-product-facts.md': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060', 'includes/sale-helper.php': '5a5eeb5dfbb652d4e975b38eaa70b8a0e212fbb7', 'public/css/style.css': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052', 'public/js/growth.js': '1c84708e8cc8cf021f943f8c6f17b537d2eb2692', 'src/models/Banner.php': '2adbeafae3c4f1373409949c532520e51619daae', 'src/models/Coupon.php': 'bb899ad8a612b4c38fd0d18c72339123d57105d8', 'src/utils/ReviewedProductFacts.php': '1db5e4468b9730b12cad79f41f7094c2144571fb', 'src/utils/ShippingRules.php': '758e2adec9069fa5f8684597becdefaea9460a87', 'tests/banner-csrf-http-test.py': 'd7ae700887e1daaf0fc57518f4bdd24606024c33', 'tests/banner-input-validation-http-test.py': '6c8e5274bce727af794fdfbf21d7f17afa995139', 'tests/banner-schedule-timezone-test.php': '18cd1384c0a26ca280a695ecbbb34e78583942e6', 'tests/campaign-code-case-test.php': '08007f693916900c8b72ebb5718fa7d7c825565b', 'tests/catalog-scroll-offset-test.cjs': 'bd822d1f3cee43f13cec01e151685ad88134da58', 'tests/contact-delivery-test.py': '2e4e160cf6c62d4eb3eec8b8668fe88724f1722c', 'tests/contact-pending-edits-test.cjs': 'e20affefa282fe0bee690033cacf828fec54221a', 'tests/contact-provider-response-http-test.py': '1b1254fccf75ea69bc495cd9331bde8edd4be3cd', 'tests/contact-retry-test.cjs': 'dccea24eed65314386728bdf9c16937801830347', 'tests/coupon-expiry-timezone-test.php': 'ffd444d00624e127638180aebc857bd2a1c1e28c', 'tests/free-shipping-destination-api-test.py': 'd283aed369602f9015f833f3262ca9c40c3e2cd5', 'tests/free-shipping-destination-test.php': 'cb80e4cdda5d7a9bcd245b86ab7ed5bd40fb52ff', 'tests/keyboard-browser/README.md': '567982a7ebf4a4ef8a42ce5c5c3eb7cd53888f91', 'tests/keyboard-browser/catalog-scroll-guard-test.py': '821bd44f751b11830931ed67b1a34a5157206f10', 'tests/keyboard-browser/catalog-scroll-identity.cjs': '42aac464164ce8231fe6ff0e81445a5b2db48bdd', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': '29c05ea25c44544ffc1fd1653aea83e1e8474b03', 'tests/keyboard-browser/catalog-scroll-policy.cjs': '7716c602d7640c58286964940b2f69f8a5150256', 'tests/keyboard-browser/catalog-scroll.cjs': 'c189ae8cee2b45af2ef9c51d677415415ba39203', 'tests/keyboard-browser/run.py': '75ad7e455e8dfced062d568eb1defd0bee40241a', 'tests/newsletter-pending-edits-test.cjs': 'b7d6b6cee085eb4b515615014e2b0c99ac82c299', 'tests/purgestore-csrf-test.py': '48dfc923f4f666cceb48e74e2384fbdb497a0966', 'tests/rotor-reviewed-facts-test.php': 'ee9ce4400c99262ae33857245350e2806649a94e', 'tests/sale-schedule-http-test.py': '1c39b832510e4f399cf6c2809b96df5233fe5cab', 'tests/sale-schedule-test.py': '8dd204600f8bf213974353b07a837793dabd0f15', 'tests/stale-inventory-csrf-http-test.py': 'b8026c225f21bd37615fc9ef939c96dfdfc81c63'}
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

    case("exact-12-path-catalog-refresh", lambda repo: None, accepted=True)
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
