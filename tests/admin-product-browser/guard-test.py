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


EXPECTED_BASELINE = "0094abfa7701ff52cb89c6ba28eb951262abada5"
EXPECTED_PATHS = {
    "tests/admin-product-browser/run.py": "M",
    "tests/admin-product-browser/README.md": "M",
    ".github/workflows/admin-product-acceptance.yml": "M",
    "tests/admin-product-browser/guard-test.py": "A",
}
RUNNER = Path(__file__).with_name("run.py")
GIT = shutil.which("git")


def load_guard() -> dict:
    tree = ast.parse(RUNNER.read_text(), filename=str(RUNNER))
    constants = {}
    functions = []
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            name = node.targets[0].id
            if name in {"BASELINE", "ALLOWED_CHANGES"}:
                if name in constants:
                    raise AssertionError("Duplicate guard constant")
                constants[name] = ast.literal_eval(node.value)
        if isinstance(node, ast.FunctionDef) and node.name in {"require", "verify_candidate"}:
            if node.decorator_list:
                raise AssertionError("Guard decorators are forbidden")
            functions.append(node)
    assert constants == {"BASELINE": EXPECTED_BASELINE, "ALLOWED_CHANGES": EXPECTED_PATHS}
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
                     git=lambda *args: self.command(*args).stdout.strip(),
                     run_command=command)
        guard.update(overrides or {})
        return guard["verify_candidate"]()


def main() -> None:
    assert GIT, "Installed Git required; no dependency installation is attempted"
    results = []

    def case(name, modify, *, accepted=False, overrides=None):
        with tempfile.TemporaryDirectory(prefix="fas-admin-guard-") as temporary:
            repo = Repository(Path(temporary))
            repo.candidate()
            modify(repo)
            try:
                receipt = repo.evaluate(overrides(repo) if overrides else None)
            except RuntimeError as error:
                if accepted:
                    raise AssertionError(name + " unexpectedly rejected") from error
                results.append({"case": name, "expected": "reject", "passed": True})
            else:
                assert accepted, name + " unexpectedly accepted"
                assert receipt["runtime_diff_from_baseline"] == []
                assert receipt["added_paths"] == [path for path, state in EXPECTED_PATHS.items() if state == "A"]
                assert receipt["modified_paths"] == sorted(path for path, state in EXPECTED_PATHS.items() if state == "M")
                results.append({"case": name, "expected": "accept", "passed": True})

    def changed(path, text="unexpected\n"):
        def apply(repo):
            repo.write(path, text)
            repo.commit("unexpected tracked change")
        return apply

    case("exact-four-path-refresh", lambda repo: None, accepted=True)
    case("no-diff", lambda repo: repo.command("reset", "--hard", repo.baseline))
    case("runtime-change", changed("admin/products.php"))
    case("extra-test", changed("tests/extra.txt"))
    case("newline-path", changed("tests/extra\npath.txt"))
    case("tab-path", changed("tests/extra\tpath.txt"))
    case("space-path", changed("tests/space path.txt"))
    case("missing-modification", lambda repo: (
        repo.command("checkout", repo.baseline, "--", "tests/admin-product-browser/README.md"),
        repo.commit("restore allowed path")))
    case("missing-addition", lambda repo: (
        repo.command("rm", "tests/admin-product-browser/guard-test.py"), repo.commit("remove new test")))
    case("deleted-allowed-path", lambda repo: (
        repo.command("rm", "tests/admin-product-browser/README.md"), repo.commit("delete README")))
    case("renamed-allowed-path", lambda repo: (
        repo.command("mv", "tests/admin-product-browser/README.md", "tests/admin-product-browser/moved.md"),
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
