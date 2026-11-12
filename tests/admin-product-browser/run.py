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
ALLOWED_CHANGES = {'api/products.php': 'M', 'docs/reviewed-product-facts.md': 'M', 'product.php': 'M', 'products.php': 'M', 'public/css/style.css': 'M', 'src/utils/ReviewedProductFacts.php': 'M', 'tests/admin-product-browser/guard-test.py': 'M', 'tests/admin-product-browser/run.py': 'M', 'tests/catalog-focus-restoration-test.cjs': 'A', 'tests/catalog-heading-identity-test.py': 'A', 'tests/catalog-loader-lifecycle-test.cjs': 'M', 'tests/catalog-scroll-offset-test.cjs': 'A', 'tests/catalog-zero-search-test.php': 'A', 'tests/keyboard-browser/README.md': 'M', 'tests/keyboard-browser/catalog-scroll-guard-test.py': 'A', 'tests/keyboard-browser/catalog-scroll-identity.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': 'A', 'tests/keyboard-browser/catalog-scroll-policy.cjs': 'A', 'tests/keyboard-browser/catalog-scroll.cjs': 'A', 'tests/keyboard-browser/heading-unit-test.py': 'M', 'tests/keyboard-browser/heading_runner.py': 'M', 'tests/keyboard-browser/run.py': 'M', 'tests/product-gallery-identity-test.py': 'A', 'tests/product-gallery-selection-test.php': 'A', 'tests/rotor-reviewed-facts-test.php': 'A', 'tests/saved-search-rendering-test.cjs': 'A'}
EXPECTED_RUNTIME = {'api/products.php': {'before': 'a284c99de99c7337e4cb8a1fa3f0a2a870aa4e8c', 'after': '357aaf382cd2b201e2b0169d20a6f071da21251e'}, 'product.php': {'before': 'ff1a31cc764c881f7acbd392b563276bab9747c9', 'after': 'e57d1b3aad20283525d7bb159bc9a9d55563b659'}, 'products.php': {'before': 'e03bc2480c05adf03ea602b6706bd68c0eec8afa', 'after': '35571501a7a828d3f53cc9f30ccc7d79632d08a4'}, 'public/css/style.css': {'before': '103706af92484631585fcc26553239b0b42681c4', 'after': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052'}, 'src/utils/ReviewedProductFacts.php': {'before': 'af5c5e4b277dd3005e825ec0f384756ed9441637', 'after': '1db5e4468b9730b12cad79f41f7094c2144571fb'}}
BASELINE_TREE = '00ebb2f16bea9655f81c88de754fee75b76c534f'
EXPECTED_STORAGE_BLOBS = {'api/products.php': '357aaf382cd2b201e2b0169d20a6f071da21251e', 'docs/reviewed-product-facts.md': '515fd5142bd04eaed3d03da5b3b86f25f8b0a060', 'product.php': 'e57d1b3aad20283525d7bb159bc9a9d55563b659', 'products.php': '35571501a7a828d3f53cc9f30ccc7d79632d08a4', 'public/css/style.css': 'b6c36199b40dc6af75d6a5433671b01cf9c8b052', 'src/utils/ReviewedProductFacts.php': '1db5e4468b9730b12cad79f41f7094c2144571fb', 'tests/catalog-focus-restoration-test.cjs': 'ea5b7eaf6e5ff513ccf8b82827bdfacc45a5de0f', 'tests/catalog-heading-identity-test.py': '31f2a60d5d4646c54521d0776f7f960be019983c', 'tests/catalog-loader-lifecycle-test.cjs': '44244918933b5bd8220c5c202403b2a8742f8a50', 'tests/catalog-scroll-offset-test.cjs': 'bd822d1f3cee43f13cec01e151685ad88134da58', 'tests/catalog-zero-search-test.php': 'c56d9d6668d3b9684c61c940b7317e94fb3fcb90', 'tests/keyboard-browser/README.md': '567982a7ebf4a4ef8a42ce5c5c3eb7cd53888f91', 'tests/keyboard-browser/catalog-scroll-guard-test.py': '821bd44f751b11830931ed67b1a34a5157206f10', 'tests/keyboard-browser/catalog-scroll-identity.cjs': '42aac464164ce8231fe6ff0e81445a5b2db48bdd', 'tests/keyboard-browser/catalog-scroll-policy-test.cjs': '29c05ea25c44544ffc1fd1653aea83e1e8474b03', 'tests/keyboard-browser/catalog-scroll-policy.cjs': '7716c602d7640c58286964940b2f69f8a5150256', 'tests/keyboard-browser/catalog-scroll.cjs': 'c189ae8cee2b45af2ef9c51d677415415ba39203', 'tests/keyboard-browser/heading-unit-test.py': 'ba6a66467fb54ab388a7b517308838e900cd50b6', 'tests/keyboard-browser/run.py': '75ad7e455e8dfced062d568eb1defd0bee40241a', 'tests/product-gallery-identity-test.py': '2a27ceb5ed77bb36b8b88d4594836a5e914fbf4d', 'tests/product-gallery-selection-test.php': '6fb8ea818e9cb3ce6e3653d303c9e0874d8068a2', 'tests/rotor-reviewed-facts-test.php': 'ee9ce4400c99262ae33857245350e2806649a94e', 'tests/saved-search-rendering-test.cjs': 'afb8575f7ed86101a96f510526863c210622941d'}
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
            "Diff must match the 12 reviewed catalog paths and exact statuses")
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
