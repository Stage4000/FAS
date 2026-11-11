"""Independent exact-candidate gate. Historical acceptance gates are unchanged."""
import hashlib, ipaddress, json, os, re, stat, subprocess, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
PINS = json.loads((Path(__file__).with_name('sources.json')).read_text())
BASELINE = PINS['baseline']
BASELINE_TREE = PINS['baseline_tree']
ALLOWED_CHANGES = PINS['allowed_changes']
EXPECTED_RUNTIME = PINS['runtime']
EXPECTED_STORAGE_BLOBS = PINS['reviewed_blobs']
ASSET_HOSTS = {'cdn.jsdelivr.net','cdnjs.cloudflare.com','unpkg.com','fonts.googleapis.com','fonts.gstatic.com'}
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
            "Diff must match the exact reviewed zoom paths and statuses")
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


