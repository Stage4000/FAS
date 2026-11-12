"""Guarded blog CLI tests. All databases, config traps, and backups are synthetic.

POSIX-only success and filesystem-race tests are intentionally skipped on Windows.
Never patch the production permission guards to make this suite pass there.
"""
from pathlib import Path
import hashlib
import json
import os
import shutil
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
POSIX = os.name == "posix"


class BlogMaintenanceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="fas-blog-maintenance-")
        self.base = Path(self.temp.name).resolve()
        self.site = self.base / "site"
        for name in ["scripts/blog-maintenance.php", "src/blog/BlogContent.php",
                     "src/blog/BlogRichDocument.php", "src/blog/BlogRepository.php"]:
            target = self.site / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / name, target)
        (self.site / "src/config").mkdir()
        (self.site / "src/config/config.php").write_text("<?php throw new RuntimeException('CONFIG MUST NOT LOAD');")
        self.script = self.site / "scripts/blog-maintenance.php"
        self.private = self.base / "private"
        self.private.mkdir(mode=0o700)
        self.backup = self.private / "before-init.sqlite"
        self.dbpath = self.site / "synthetic.sqlite"
        self.db = sqlite3.connect(self.dbpath)
        self.db.executescript("""
            CREATE TABLE products(id INTEGER PRIMARY KEY, description TEXT, is_active INTEGER);
            INSERT INTO products VALUES(1,'Synthetic source description',1);
            CREATE TABLE admin_users(id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, role TEXT, is_active INTEGER);
            CREATE TABLE orders(id INTEGER PRIMARY KEY, note TEXT);
            INSERT INTO orders VALUES(1,'Synthetic order');
        """)
        self.db.commit()

    def tearDown(self):
        self.db.close()
        self.temp.cleanup()

    def run_cli(self, *args, options=()):
        return subprocess.run(["php", *options, str(self.script), *map(str, args)],
                              text=True, capture_output=True, timeout=20)

    def dump(self):
        return list(self.db.iterdump())

    def rejected(self, *args, options=()):
        before = self.dump()
        result = self.run_cli(*args, options=options)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(before, self.dump())
        return result

    def php(self, code):
        result = subprocess.run(["php", "-r", code], text=True, capture_output=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result

    def install_synthetic(self, content=False, rich=False):
        """Exercise repository directly only against this test's disposable database."""
        code = "require " + json.dumps(str(self.site / "src/blog/BlogRepository.php")) + ";"
        code += "$db=new PDO('sqlite:'." + json.dumps(str(self.dbpath)) + ");"
        code += "$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');"
        code += "\\FAS\\Blog\\BlogRepository::install($db);"
        if content:
            code += """
                $repo=new \\FAS\\Blog\\BlogRepository($db,static function($actor,$action){return $actor===17;});
                $data=['title'=>'Synthetic post','slug'=>'synthetic-post','excerpt'=>'Synthetic summary',
                       'body'=>'Synthetic reviewed body','author_name'=>'Test editor'];
            """
            if rich:
                code += """
                    $data['body_format']='rich_v1';
                    $data['body']=json_encode(['version'=>1,'blocks'=>[
                        ['type'=>'paragraph','children'=>[['text'=>'Synthetic rich reviewed body','bold'=>true]]]
                    ]],JSON_THROW_ON_ERROR);
                """
            code += """
                $post=$repo->createDraft($data,17,str_repeat('k',32));
                $data['reviewed']=true;
                $repo->save($post['id'],1,'publish',$data,17);
                $data['title']='Private edited title';
                $repo->save($post['id'],2,'draft',$data,17);
            """
        self.php(code)

    def init(self, backup=None):
        result = self.run_cli("init", self.dbpath, backup or self.backup)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_help_needs_no_database_or_config(self):
        result = self.run_cli()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("status|check", result.stdout)
        self.assertNotIn("CONFIG MUST NOT LOAD", result.stderr)

    def test_http_request_returns_404_before_config_or_database_access(self):
        before = self.dump()
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        process = subprocess.Popen(["php", "-S", "127.0.0.1:" + str(port), "-t", str(self.site)],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        # No proxies: this test talks only to its disposable loopback PHP server.
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            for attempt in range(50):
                try:
                    response = opener.open("http://127.0.0.1:" + str(port) + "/scripts/blog-maintenance.php?init=1", timeout=1)
                except urllib.error.HTTPError as error:
                    self.assertEqual(error.code, 404)
                    self.assertEqual(error.read(), b"")
                    break
                except urllib.error.URLError:
                    if process.poll() is not None:
                        self.fail("Synthetic PHP HTTP server stopped before the guard check")
                    time.sleep(0.05)
                else:
                    response.close()
                    self.fail("Maintenance script unexpectedly allowed HTTP access")
            else:
                self.fail("Synthetic PHP HTTP server did not become available")
            self.assertEqual(before, self.dump())
            self.assertFalse(self.backup.exists())
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

    def test_explicit_database_and_exact_argument_count_required(self):
        for args in [("status",), ("check",), ("init",), ("init", self.dbpath),
                     ("status", self.dbpath, "extra"), ("restore", self.dbpath)]:
            with self.subTest(args=args):
                self.rejected(*args)
        self.assertFalse(self.backup.exists())

    def test_status_is_read_only_and_missing_storage_is_not_health(self):
        before = self.dbpath.read_bytes()
        result = self.run_cli("status", self.dbpath)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {"installed": False, "posts": 0, "published": 0, "history": 0})
        self.assertEqual(before, self.dbpath.read_bytes())
        self.assertIn("not been initialized", self.rejected("check", self.dbpath).stderr)

    def test_missing_database_is_never_created(self):
        missing = self.base / "never-create.sqlite"
        for command in ["status", "check", "init"]:
            result = self.run_cli(command, missing, *([self.backup] if command == "init" else []))
            self.assertNotEqual(result.returncode, 0, result.stdout)
            self.assertFalse(missing.exists())
        self.assertFalse(self.backup.exists())

    def test_relative_uri_directory_and_traversal_paths_are_rejected(self):
        for path in ["relative.sqlite", "file:" + str(self.dbpath), ":memory:", self.site,
                     str(self.site) + "/../site/" + self.dbpath.name]:
            with self.subTest(path=path):
                self.rejected("status", path)
        self.assertFalse(self.backup.exists())

    def test_corrupt_database_is_rejected_before_backup(self):
        corrupt = self.base / "corrupt.sqlite"
        corrupt.write_bytes(b"not a database")
        result = self.run_cli("init", corrupt, self.backup)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(corrupt.read_bytes(), b"not a database")
        self.assertFalse(self.backup.exists())

    def test_wrong_inventory_schema_is_rejected_before_backup(self):
        self.db.execute("DROP TABLE admin_users")
        self.db.commit()
        self.assertIn("admin_users", self.rejected("init", self.dbpath, self.backup).stderr)
        self.assertFalse(self.backup.exists())

    def test_auth_view_does_not_count_as_real_table(self):
        self.db.executescript("DROP TABLE admin_users; CREATE VIEW admin_users AS SELECT 1 AS id, '' AS username, '' AS password_hash, 'admin' AS role, 1 AS is_active;")
        self.assertIn("admin_users", self.rejected("status", self.dbpath).stderr)

    def test_partial_or_spoofed_blog_schema_is_rejected(self):
        for sql in ["CREATE TABLE blog_posts(id INTEGER PRIMARY KEY)",
                    "CREATE VIEW blog_posts AS SELECT id FROM products"]:
            with self.subTest(sql=sql):
                self.db.execute(sql)
                self.db.commit()
                self.rejected("status", self.dbpath)
                self.rejected("init", self.dbpath, self.backup)
                self.assertFalse(self.backup.exists())
                self.db.execute("DROP " + ("VIEW" if "VIEW" in sql else "TABLE") + " blog_posts")
                self.db.commit()

    def test_status_and_check_preserve_private_drafts_and_public_snapshots(self):
        self.install_synthetic(content=True)
        before = self.dbpath.read_bytes()
        for command in ["status", "check"]:
            result = self.run_cli(command, self.dbpath)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), {"installed": True, "posts": 1, "published": 1, "history": 3})
            self.assertNotIn("Private edited title", result.stdout)
            self.assertNotIn("Synthetic reviewed body", result.stdout)
        self.assertEqual(before, self.dbpath.read_bytes())

    def test_legacy_payload_without_format_is_checked_without_rewriting(self):
        self.install_synthetic(content=True)
        for field in ["draft_json", "published_json"]:
            content = json.loads(self.db.execute("SELECT " + field + " FROM blog_posts").fetchone()[0])
            content.pop("body_format")
            self.db.execute("UPDATE blog_posts SET " + field + "=?", [json.dumps(content)])
        self.db.commit()
        before = self.dump()
        for command in ["status", "check"]:
            result = self.run_cli(command, self.dbpath)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout)["history"], 3)
        self.assertEqual(before, self.dump())
        for field in ["draft_json", "published_json"]:
            self.assertNotIn("body_format", json.loads(self.db.execute("SELECT " + field + " FROM blog_posts").fetchone()[0]))

    def test_rich_payload_is_checked_without_rewriting_or_exposing_content(self):
        self.install_synthetic(content=True, rich=True)
        before = self.dbpath.read_bytes()
        schema = list(self.db.execute("SELECT name,sql FROM sqlite_master ORDER BY name"))
        for command in ["status", "check"]:
            result = self.run_cli(command, self.dbpath)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), {"installed": True, "posts": 1, "published": 1, "history": 3})
            self.assertNotIn("Private edited title", result.stdout)
            self.assertNotIn("Synthetic rich reviewed body", result.stdout)
        self.assertEqual(before, self.dbpath.read_bytes())
        self.assertEqual(schema, list(self.db.execute("SELECT name,sql FROM sqlite_master ORDER BY name")))

    def test_malformed_rich_snapshot_is_rejected_before_backup(self):
        self.install_synthetic(content=True, rich=True)
        content = json.loads(self.db.execute("SELECT published_json FROM blog_posts").fetchone()[0])
        for malformed in ['not JSON', '{"version":1,"blocks":[{"type":"html","html":"unsafe"}]}',
                          '{"version":2,"blocks":[]}']:
            with self.subTest(malformed=malformed):
                content["body"] = malformed
                self.db.execute("UPDATE blog_posts SET published_json=?", [json.dumps(content)])
                self.db.commit()
                self.rejected("check", self.dbpath)
                result = self.rejected("init", self.dbpath, self.backup)
                self.assertNotIn("private POSIX", result.stderr)
                self.assertFalse(self.backup.exists())

    def test_malformed_stored_content_is_rejected_without_repair(self):
        self.install_synthetic(content=True)
        original = self.db.execute("SELECT draft_json FROM blog_posts").fetchone()[0]
        for malformed in ['not JSON', '[]', 'null', '{"title":17}']:
            with self.subTest(malformed=malformed):
                self.db.execute("UPDATE blog_posts SET draft_json=?", [malformed])
                self.db.commit()
                self.rejected("check", self.dbpath)
                self.rejected("init", self.dbpath, self.backup)
                self.assertFalse(self.backup.exists())
        self.db.execute("UPDATE blog_posts SET draft_json=?", [original])
        self.db.commit()

    def test_inconsistent_canonical_slug_is_rejected(self):
        self.install_synthetic(content=True)
        self.db.execute("UPDATE blog_posts SET slug='wrong-canonical-slug'")
        self.db.commit()
        self.assertIn("slug", self.rejected("check", self.dbpath).stderr)

    def test_incomplete_history_is_rejected(self):
        self.install_synthetic(content=True)
        self.db.execute("DELETE FROM blog_history WHERE revision=2")
        self.db.commit()
        self.assertIn("history", self.rejected("check", self.dbpath).stderr)

    def test_foreign_key_violation_is_rejected(self):
        self.install_synthetic(content=True)
        self.db.execute("UPDATE blog_history SET post_id=99 WHERE revision=1")
        self.db.commit()
        self.assertIn("foreign-key", self.rejected("check", self.dbpath).stderr)

    def test_unexpected_trigger_is_rejected_without_running_it(self):
        self.install_synthetic()
        self.db.execute("CREATE TRIGGER unexpected AFTER INSERT ON blog_posts BEGIN DELETE FROM orders; END")
        self.db.commit()
        self.assertIn("triggers", self.rejected("check", self.dbpath).stderr)
        self.rejected("init", self.dbpath, self.backup)
        self.assertFalse(self.backup.exists())

    def test_incompatible_extra_column_is_rejected(self):
        self.install_synthetic()
        self.db.execute("ALTER TABLE blog_posts ADD COLUMN unsupported TEXT")
        self.db.commit()
        self.rejected("check", self.dbpath)
        self.rejected("init", self.dbpath, self.backup)
        self.assertFalse(self.backup.exists())

    def test_install_owns_transaction_and_rejects_ambient_transaction(self):
        code = "require " + json.dumps(str(self.site / "src/blog/BlogRepository.php")) + ";"
        code += """
            $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA foreign_keys=ON');$db->beginTransaction();
            try { \\FAS\\Blog\\BlogRepository::install($db); exit(2); }
            catch (LogicException $expected) {}
            if (!$db->inTransaction()) exit(3);
            $db->rollBack();
            if (\\FAS\\Blog\\BlogRepository::installed($db)) exit(4);
            \\FAS\\Blog\\BlogRepository::install($db);
            if ($db->inTransaction() || !\\FAS\\Blog\\BlogRepository::installed($db)) exit(5);
        """
        self.php(code)

    @unittest.skipUnless(os.name == "nt", "Windows fail-closed guard")
    def test_windows_init_fails_closed_without_creating_backup(self):
        result = self.rejected("init", self.dbpath, self.backup)
        self.assertIn("private POSIX", result.stderr)
        self.assertFalse(self.backup.exists())

    def test_existing_backup_is_never_overwritten(self):
        for content in [b"", b"Keep this existing backup"]:
            self.backup.write_bytes(content)
            self.rejected("init", self.dbpath, self.backup)
            self.assertEqual(content, self.backup.read_bytes())

    @unittest.skipUnless(POSIX, "Real POSIX backup permissions are required")
    def test_new_private_backup_preserves_inventory_and_auth_schema(self):
        before = self.dump()
        report = self.init()
        self.assertTrue(report["installed"])
        self.assertEqual(report["posts"], 0)
        self.assertEqual(report["backup_bytes"], self.backup.stat().st_size)
        self.assertEqual(report["backup_sha256"], hashlib.sha256(self.backup.read_bytes()).hexdigest())
        self.assertEqual(self.backup.stat().st_mode & 0o777, 0o600)
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(backup.execute("PRAGMA integrity_check").fetchall(), [("ok",)])
            self.assertEqual(before, list(backup.iterdump()))
        self.assertEqual(self.db.execute("SELECT description FROM products").fetchone()[0], "Synthetic source description")
        self.assertEqual(self.db.execute("SELECT note FROM orders").fetchone()[0], "Synthetic order")
        self.assertEqual(self.run_cli("check", self.dbpath).returncode, 0)

    @unittest.skipUnless(POSIX, "Real POSIX backup permissions are required")
    def test_wal_snapshot_contains_committed_uncheckpointed_data(self):
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA wal_autocheckpoint=0")
        self.db.execute("UPDATE products SET description='Committed WAL content'")
        self.db.commit()
        self.assertGreater(Path(str(self.dbpath) + "-wal").stat().st_size, 0)
        self.init()
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(backup.execute("SELECT description FROM products").fetchone()[0], "Committed WAL content")

    @unittest.skipUnless(POSIX, "Real POSIX backup permissions are required")
    def test_repeated_init_preserves_drafts_snapshots_and_history(self):
        self.install_synthetic(content=True)
        before = self.dump()
        report = self.init()
        self.assertEqual(report["history"], 3)
        self.assertEqual(before, self.dump())
        report = self.init(self.private / "second.sqlite")
        self.assertEqual(report["posts"], 1)
        self.assertEqual(before, self.dump())

    @unittest.skipUnless(POSIX, "Real POSIX backup permissions are required")
    def test_rich_init_keeps_existing_schema_payloads_and_history(self):
        self.install_synthetic(content=True, rich=True)
        before = self.dump()
        report = self.init()
        self.assertEqual(report["history"], 3)
        self.assertEqual(report["published"], 1)
        self.assertEqual(before, self.dump())
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(before, list(backup.iterdump()))

    @unittest.skipUnless(POSIX, "POSIX symlink and permission fixtures")
    def test_unsafe_backup_paths_are_rejected(self):
        public = self.site / "private-looking"
        public.mkdir(mode=0o700)
        shared = self.base / "shared"
        shared.mkdir(mode=0o755)
        link = self.base / "private-link"
        link.symlink_to(self.private, target_is_directory=True)
        for path in ["relative.sqlite", public / "backup.sqlite", shared / "backup.sqlite",
                     self.base / "missing/backup.sqlite", link / "backup.sqlite",
                     str(self.private) + "/../private/traversal.sqlite", self.private]:
            with self.subTest(path=path):
                self.rejected("init", self.dbpath, path)
        dangling = self.private / "dangling.sqlite"
        dangling.symlink_to(self.private / "missing.sqlite")
        self.rejected("init", self.dbpath, dangling)
        self.assertTrue(dangling.is_symlink())
        self.assertEqual(list(self.private.iterdir()), [dangling])

    @unittest.skipUnless(POSIX, "POSIX symlink fixture")
    def test_database_symlink_is_rejected(self):
        link = self.base / "source-link.sqlite"
        link.symlink_to(self.dbpath)
        self.rejected("status", link)
        self.rejected("init", link, self.backup)
        self.assertFalse(self.backup.exists())

    @unittest.skipUnless(POSIX, "POSIX ancestry fixture")
    def test_replaceable_backup_ancestor_is_rejected(self):
        shared = self.base / "replaceable"
        shared.mkdir()
        shared.chmod(0o777)
        private = shared / "private"
        private.mkdir(mode=0o700)
        self.rejected("init", self.dbpath, private / "backup.sqlite")
        self.assertEqual(list(private.iterdir()), [])

    @unittest.skipUnless(POSIX, "POSIX umask fixture")
    def test_permissive_umask_still_creates_private_backup(self):
        old = os.umask(0)
        try:
            self.init()
        finally:
            os.umask(old)
        self.assertEqual(self.backup.stat().st_mode & 0o777, 0o600)

    @unittest.skipUnless(POSIX, "POSIX snapshot/checksum failure fixture")
    def test_checksum_failure_leaves_unverified_backup_without_install(self):
        fault = self.base / "checksum-fault.php"
        fault.write_text("<?php function hash_file($algorithm,$path) { return false; }")
        result = self.rejected("init", self.dbpath, self.backup,
                               options=("-d", "disable_functions=hash_file", "-d", "auto_prepend_file=" + str(fault)))
        self.assertTrue(self.backup.exists())
        self.assertIn("Unverified backup file retained", result.stderr)

    @unittest.skipUnless(POSIX, "POSIX synchronization failure fixture")
    def test_backup_sync_failure_never_installs_schema(self):
        fault = self.base / "sync-fault.php"
        fault.write_text("<?php function fsync($handle) { return false; }")
        result = self.rejected("init", self.dbpath, self.backup,
                               options=("-d", "disable_functions=fsync", "-d", "auto_prepend_file=" + str(fault)))
        self.assertIn("synchronization failed", result.stderr)
        self.assertIn("Unverified backup file retained", result.stderr)
        self.assertTrue(self.backup.exists())

    @unittest.skipUnless(POSIX, "POSIX concurrent-write fixture")
    def test_write_after_backup_rejects_install_and_retains_snapshot(self):
        fault = self.base / "concurrent-write.php"
        fault.write_text("<?php function hash_file($algorithm,$path) { $db=new PDO('sqlite:'."
                         + json.dumps(str(self.dbpath))
                         + "); $db->exec(\"UPDATE orders SET note='Later synthetic write'\"); return hash($algorithm,file_get_contents($path)); }")
        result = self.run_cli("init", self.dbpath, self.backup,
                              options=("-d", "disable_functions=hash_file", "-d", "auto_prepend_file=" + str(fault)))
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("changed after preflight", result.stderr)
        self.assertIn("Backup retained", result.stderr)
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='blog_posts'").fetchone()[0], 0)
        self.assertEqual(self.db.execute("SELECT note FROM orders").fetchone()[0], "Later synthetic write")
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(backup.execute("SELECT note FROM orders").fetchone()[0], "Synthetic order")

    @unittest.skipUnless(POSIX, "POSIX write-lock failure fixture")
    def test_install_lock_failure_retains_verified_backup(self):
        self.db.execute("PRAGMA journal_mode=WAL")
        before = self.dump()
        self.db.execute("BEGIN IMMEDIATE")
        result = self.run_cli("init", self.dbpath, self.backup)
        self.db.rollback()
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("Backup retained", result.stderr)
        self.assertEqual(before, self.dump())
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(before, list(backup.iterdump()))

    def test_exclusive_lock_failure_has_no_schema_changes(self):
        before = self.dump()
        self.db.execute("BEGIN EXCLUSIVE")
        result = self.run_cli("init", self.dbpath, self.backup)
        self.db.rollback()
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(before, self.dump())
        self.assertFalse(self.backup.exists())

    @unittest.skipUnless(POSIX, "POSIX file-size failure fixture")
    def test_backup_write_failure_leaves_source_unchanged(self):
        import resource
        before = self.dump()
        def limit_backup_size():
            signal.signal(signal.SIGXFSZ, signal.SIG_IGN)
            resource.setrlimit(resource.RLIMIT_FSIZE, (4096, 4096))
        result = subprocess.run(["php", str(self.script), "init", str(self.dbpath), str(self.backup)],
                                text=True, capture_output=True, timeout=15, preexec_fn=limit_backup_size)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(before, self.dump())
        self.assertIn("Unverified backup file retained", result.stderr)
        self.assertTrue(self.backup.exists())
        self.assertEqual(self.backup.stat().st_mode & 0o777, 0o600)

    @unittest.skipUnless(POSIX, "POSIX restore rehearsal fixture")
    def test_restore_rehearsal_only_uses_new_disposable_copy(self):
        before = self.dump()
        self.init()
        restored = self.base / "restored-copy.sqlite"
        shutil.copy2(self.backup, restored)
        with sqlite3.connect(restored) as db:
            self.assertEqual(db.execute("PRAGMA integrity_check").fetchone()[0], "ok")
            self.assertEqual(before, list(db.iterdump()))
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='blog_posts'").fetchone()[0], 1)

    @unittest.skipUnless(sys.platform.startswith("linux"), "Linux directory replacement race fixture")
    def test_backup_parent_symlink_swap_rejects_initialization(self):
        self.check_parent_replacement(symlink=True)

    @unittest.skipUnless(sys.platform.startswith("linux"), "Linux directory replacement race fixture")
    def test_backup_parent_inode_swap_rejects_initialization(self):
        self.check_parent_replacement(symlink=False)

    def check_parent_replacement(self, symlink):
        import ctypes
        import ctypes.util
        import select
        self.db.execute("CREATE TABLE payload(value BLOB)")
        self.db.execute("INSERT INTO payload VALUES(zeroblob(64000000))")
        self.db.commit()
        before = list(self.db.execute("SELECT name,sql FROM sqlite_master ORDER BY name"))
        libc = ctypes.CDLL(ctypes.util.find_library("c"), use_errno=True)
        fd = libc.inotify_init1(os.O_CLOEXEC)
        self.assertGreaterEqual(fd, 0)
        self.assertGreaterEqual(libc.inotify_add_watch(fd, os.fsencode(self.private), 0x100), 0)
        process = None
        try:
            process = subprocess.Popen(["php", str(self.script), "init", str(self.dbpath), str(self.backup)],
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            self.assertTrue(select.select([fd], [], [], 15)[0], "Backup reservation is observed")
            os.read(fd, 65536)
            os.kill(process.pid, signal.SIGSTOP)
            moved = self.site / "served-backups" if symlink else self.base / "old-private"
            self.private.rename(moved)
            if symlink:
                self.private.symlink_to(moved, target_is_directory=True)
            else:
                self.private.mkdir(mode=0o700)
                os.link(moved / self.backup.name, self.backup)
            os.kill(process.pid, signal.SIGCONT)
            out, err = process.communicate(timeout=20)
            self.assertNotEqual(process.returncode, 0, out + err)
            self.assertEqual(before, list(self.db.execute("SELECT name,sql FROM sqlite_master ORDER BY name")))
        finally:
            os.close(fd)
            if process is not None and process.poll() is None:
                process.kill()
                process.communicate()


if __name__ == "__main__":
    unittest.main(verbosity=2)
