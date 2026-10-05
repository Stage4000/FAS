"""Test 404 logging in an isolated storefront; Apache redirects are simulated."""
from pathlib import Path
from contextlib import closing
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="fas-404-monitor-") as temporary:
    base = Path(temporary)
    site = base / "site"
    site.mkdir()
    for folder in ["src", "includes"]:
        shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns(
            "config.php", "config.local.php", "config.production.php", "*.db", "*.log"))
    for name in ["404.php", "product.php", "products.php"]:
        shutil.copy2(ROOT / name, site / name)
    shutil.copy2(ROOT / "src/config/config.example.php", site / "src/config/config.php")
    (site / "database").mkdir()
    database = site / "database/flipandstrip.db"
    with closing(sqlite3.connect(database)) as db, db:
        db.executescript((ROOT / "tests/fixtures/security-catalog.sql").read_text())
    (site / "src/integrations/EbayAPI.php").write_text(r"""<?php namespace FAS\Integrations;
class EbayAPI {
    public function __construct($config) {}
    public function getStoreCategoriesHierarchical(){return [];}
    public function getStoreCategories(){return [];}
}
""")
    # PHP's built-in server does not implement .htaccess. Reproduce a local
    # ErrorDocument redirect to exercise preservation of the original URL.
    assert "ErrorDocument 404 /404.php" in (ROOT / ".htaccess").read_text()
    router = base / "router.php"
    router.write_text("""<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($_SERVER['DOCUMENT_ROOT'] . $path)) return false;
if (preg_match('~^/product/([0-9]+)$~', $path, $m)) {
    $_GET['id'] = $m[1]; require $_SERVER['DOCUMENT_ROOT'].'/product.php';
}
if ($path === '/products') require $_SERVER['DOCUMENT_ROOT'].'/products.php';
$_SERVER['REDIRECT_STATUS'] = '404';
$_SERVER['REDIRECT_URL'] = $path;
$_SERVER['REDIRECT_QUERY_STRING'] = $_SERVER['QUERY_STRING'] ?? '';
$_SERVER['REQUEST_URI'] = '/404.php';
require $_SERVER['DOCUMENT_ROOT'].'/404.php';
""")
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    env = os.environ.copy()
    env["FAS_SECURITY_DB_PATH"] = str(base / "security.sqlite")
    env["FAS_GROWTH_DB_PATH"] = str(base / "growth.sqlite")
    with (base / "server.log").open("w") as log:
        server = subprocess.Popen([
            "php", "-d", "disable_functions=mail,curl_exec,curl_multi_exec", "-S",
            f"127.0.0.1:{port}", "-t", str(site), str(router)
        ], env=env, stdout=log, stderr=log,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        try:
            for attempt in range(50):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Fixture server did not start")

            def request(path, method="GET"):
                req = urllib.request.Request(f"http://127.0.0.1:{port}" + path,
                    method=method, headers={"Referer": "https://example.invalid/parts?search=seat",
                                            "User-Agent": "FAS-404-test"})
                try:
                    response = urllib.request.urlopen(req, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.status, response.headers, response.read().decode()

            paths = ["/missing/nested-page?from=test", "/product/999999",
                     "/product.php?id=invalid", "/products?category=invalid-category", "/404.php"]
            for index, path in enumerate(paths, 1):
                status, headers, body = request(path)
                assert status == 404 and "We couldn't find that page" in body, path
                assert headers["Cache-Control"] == "no-store" and "noindex" in headers["X-Robots-Tag"]
                with closing(sqlite3.connect(database)) as db, db:
                    db.row_factory = sqlite3.Row
                    assert db.execute("SELECT COUNT(*) FROM error_monitor_events").fetchone()[0] == index
                    event = dict(db.execute("SELECT * FROM error_monitor_events ORDER BY id DESC LIMIT 1").fetchone())
                assert event["area"] == "not_found" and event["error_code"] == "404"
                assert event["url"] == path and event["request_method"] == "GET", event
                assert event["severity"] == "warning" and event["status"] == "open"
                assert event["user_agent"] == "FAS-404-test" and event["ip_address"] == "127.0.0.1"
                assert json.loads(event["metadata"])["referrer"] == "https://example.invalid/parts?search=seat"

            status, _, body = request("/missing-head", "HEAD")
            assert status == 404 and body == ""
            with closing(sqlite3.connect(database)) as db, db:
                assert db.execute("SELECT request_method FROM error_monitor_events ORDER BY id DESC LIMIT 1").fetchone()[0] == "HEAD"
            status, _, _ = request("/products.php")
            assert status == 200
            with closing(sqlite3.connect(database)) as db, db:
                assert db.execute("SELECT COUNT(*) FROM error_monitor_events").fetchone()[0] == 6

            # Exercise the existing filter, summary and resolution APIs for the new area.
            verify = site / "verify.php"
            verify.write_text(r"""<?php
require __DIR__.'/src/config/Database.php';
require __DIR__.'/src/utils/ErrorMonitor.php';
$monitor = new FAS\Utils\ErrorMonitor(FAS\Config\Database::getInstance()->getConnection());
$events = $monitor->getRecentEvents(30, 100, 'not_found', 'open');
if (count($events) !== 6 || $monitor->getSummary()['open'] !== 6) exit(1);
if ($monitor->markAreaResolved('not_found') !== 6) exit(2);
if ($monitor->getSummary()['resolved'] !== 6) exit(3);
if (count($monitor->getRecentEvents(30, 100, 'checkout')) !== 0) exit(4);
""")
            subprocess.run(["php", str(verify)], env=env, check=True, capture_output=True)

            # A broken logging table must not break the visitor's 404 landing.
            with closing(sqlite3.connect(database)) as db, db:
                db.execute("DROP TABLE error_monitor_events")
                db.execute("CREATE TABLE error_monitor_events (id INTEGER)")
            status, _, body = request("/logging-unavailable")
            assert status == 404 and "We couldn't find that page" in body
            assert "ErrorMonitor failed" not in body
            print("PASS: 5 GET 404 routes, HEAD, exact event counts/context, 200 exclusion, filtering, resolution and logging failure fallback.")
            print("Local PHP HTTP fixture only; Apache ErrorDocument behavior simulated, production not tested.")
        finally:
            server.terminate()
            server.wait(timeout=10)
