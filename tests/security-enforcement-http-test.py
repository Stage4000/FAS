"""Exercise the dashboard enforcement switch against disposable local databases."""
from pathlib import Path
from contextlib import closing
import http.cookiejar
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


def client():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect
    )


with tempfile.TemporaryDirectory(prefix="fas-enforcement-") as tmp:
    base = Path(tmp)
    site = base / "site"
    site.mkdir()
    for folder in ("src", "admin", "includes"):
        shutil.copytree(
            ROOT / folder, site / folder,
            ignore=shutil.ignore_patterns("config.php", "config.local.php", "config.production.php", "uploads", "*.log", "*.db", "*.sqlite"),
        )
    shutil.copy2(ROOT / "src/config/config.example.php", site / "src/config/config.php")
    dbfile = site / "database/flipandstrip.db"
    dbfile.parent.mkdir()
    password = subprocess.check_output(
        ["php", "-r", 'echo password_hash("Local-test-only", PASSWORD_DEFAULT);'], text=True
    )
    with closing(sqlite3.connect(dbfile)) as db, db:
        db.execute("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT UNIQUE,password_hash TEXT,email TEXT,role TEXT,is_active INTEGER,last_login TEXT)")
        db.execute("INSERT INTO admin_users(id,username,password_hash,email,role,is_active) VALUES(9001,'security-fixture',?,'fixture@example.invalid','admin',1)", (password,))

    env = os.environ.copy()
    env["FAS_SECURITY_DB_PATH"] = str(base / "private/security.sqlite")
    env.pop("FAS_TRUSTED_PROXY_CIDRS", None)
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    origin = f"http://127.0.0.1:{port}"
    with open(base / "server.log", "w", encoding="utf-8") as log:
        server = subprocess.Popen(["php", "-d", "disable_functions=mail", "-S", f"127.0.0.1:{port}", "-t", str(site)], env=env, stdout=log, stderr=log)
        admin = client()

        def request(path, data=None, who=admin):
            if isinstance(data, dict):
                data = urllib.parse.urlencode(data).encode()
            req = urllib.request.Request(origin + path, data=data)
            try:
                response = who.open(req, timeout=10)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, dict(response.headers), response.read().decode()

        def token(page):
            return re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)

        try:
            for attempt in range(30):
                try:
                    request("/admin/login.php")
                    break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Local PHP server did not start")

            status, headers, body = request("/admin/security.php")
            check(status == 302 and headers.get("Location") == "login.php", "Security page requires an administrator")
            csrf = token(request("/admin/login.php")[2])
            status, _, _ = request("/admin/login.php", {"username": "security-fixture", "password": "Local-test-only", "csrf_token": csrf})
            check(status == 302, "Fixture administrator signs in")
            status, _, body = request("/admin/security.php")
            csrf = token(body)
            check(status == 200 and "Observation mode" in body and "Unlock changes" in body, "Overview shows observation and password control")

            toggle = {"action": "set_enforcement", "mode": "enforce", "expected": "0", "readiness_confirmed": "1", "csrf_token": csrf}
            check(request("/admin/security.php", toggle)[0] == 403, "Activation requires recent password verification")
            status, _, _ = request("/admin/security.php", {"action": "reauth", "password": "Local-test-only", "csrf_token": csrf})
            check(status == 303, "Password unlocks security changes")
            body = request("/admin/security.php")[2]
            check("Start enforcing limits" in body and "readiness-confirmed" in body, "Overview presents the activation control")
            without_readiness = toggle.copy()
            del without_readiness["readiness_confirmed"]
            status, _, body = request("/admin/security.php", without_readiness)
            check(status == 200 and "Confirm the server checks" in body, "Server requires readiness confirmation")
            status, _, _ = request("/admin/security.php", toggle)
            check(status == 303, "Administrator can activate enforcement")
            with closing(sqlite3.connect(env["FAS_SECURITY_DB_PATH"])) as db, db:
                check(db.execute("SELECT value FROM security_meta WHERE name='active'").fetchone()[0] == "1", "Activation persists to private storage")
                check(db.execute("SELECT actor,detail FROM security_events WHERE outcome='activated'").fetchone() == (9001, "Admin panel"), "Activation is attributed in the audit log")
            status, _, body = request("/admin/security.php", toggle)
            check(status == 200 and "Enforcement changed since this page loaded" in body, "Stale activation form cannot change state")

            code = 'require '+repr(str(ROOT / "src/security/SecurityStore.php").replace("\\", "/"))+'; $s=\\FAS\\Security\\SecurityStore::open(); $s->saveRule("login_pair",1,3600,"enforce","127.0.0.1",9001);'
            subprocess.check_call(["php", "-r", code], env=env)
            visitor = client()
            visitor_csrf = token(request("/admin/login.php", who=visitor)[2])
            bad_login = {"username": "unknown-fixture", "password": "wrong", "csrf_token": visitor_csrf}
            check(request("/admin/login.php", bad_login, visitor)[0] == 200, "First invalid login reaches password check")
            check(request("/admin/login.php", bad_login, visitor)[0] == 429, "Enforced login budget returns HTTP 429")

            body = request("/admin/security.php")[2]
            check("Return to observation" in body and "Limits enforced" in body, "Overview shows live enforcement and rollback")
            rollback = {"action": "set_enforcement", "mode": "observe", "expected": "1", "csrf_token": csrf}
            check(request("/admin/security.php", rollback)[0] == 303, "Administrator can return to observation")
            with closing(sqlite3.connect(env["FAS_SECURITY_DB_PATH"])) as db, db:
                check(db.execute("SELECT value FROM security_meta WHERE name='active'").fetchone()[0] == "0", "Rollback persists")
                check(db.execute("SELECT COUNT(*) FROM security_buckets").fetchone()[0] == 0, "Mode change clears counters")
                check(db.execute("SELECT actor,detail FROM security_events WHERE outcome='deactivated'").fetchone() == (9001, "Admin panel"), "Rollback is attributed in the audit log")
            check(request("/admin/login.php", bad_login, visitor)[0] == 200, "Observed first invalid login is allowed through")
            check(request("/admin/login.php", bad_login, visitor)[0] == 200, "Observed exhausted login budget does not block")
            print(f"PASS {checks} enforcement HTTP assertions; isolated local databases only.")
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
