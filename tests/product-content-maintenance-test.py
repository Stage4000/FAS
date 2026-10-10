"""CLI backup/init regression checks. Every database and config is disposable."""
from pathlib import Path
import hashlib
import json
import os
import shutil
import signal
import sqlite3
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class EditorialMaintenanceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="fas-editorial-maintenance-")
        self.base = Path(self.temp.name)
        self.site = self.base / "site"
        for path in ["scripts/product-content-maintenance.php", "src/utils/ProductContent.php"]:
            target = self.site / path
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / path, target)
        # Keep the old implementation runnable so the tests expose its unsafe behavior.
        (self.site / "src/config").mkdir()
        shutil.copy2(ROOT / "src/config/Database.php", self.site / "src/config/Database.php")
        self.dbpath = self.site / "inventory.sqlite"
        (self.site / "src/config/config.php").write_text(
            "<?php return ['database'=>['path'=>" + json.dumps(str(self.dbpath)) + "]];")
        self.private = self.base / "private"
        self.private.mkdir(mode=0o700)
        self.backup = self.private / "before-init.sqlite"
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

    def run_cli(self, *args):
        return subprocess.run(["php", str(self.site / "scripts/product-content-maintenance.php"), *map(str, args)],
                              text=True, capture_output=True, timeout=15)

    def tables(self):
        return list(self.db.execute("SELECT name,sql FROM sqlite_master ORDER BY name"))

    def dump(self):
        return list(self.db.iterdump())

    def assert_rejected_unchanged(self, *args):
        before = self.dump()
        result = self.run_cli(*args)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(before, self.dump())
        return result

    def init(self, target=None):
        result = self.run_cli("init", target or self.backup)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_bare_init_requires_backup(self):
        self.assert_rejected_unchanged("init")

    def test_install_can_be_validated_and_rolled_back_by_its_caller(self):
        code = "require " + json.dumps(str(self.site / "src/utils/ProductContent.php")) + ";"
        code += """
            $db = new PDO('sqlite::memory:');
            $db->beginTransaction();
            \\FAS\\Utils\\ProductContent::install($db);
            if (!$db->inTransaction()) exit(2);
            $db->rollBack();
            if (\\FAS\\Utils\\ProductContent::installed($db)) exit(3);
        """
        result = subprocess.run(["php", "-r", code], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_preflight_is_read_only_and_reports_uninitialized(self):
        before = self.dbpath.read_bytes()
        result = self.run_cli("preflight")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(json.loads(result.stdout)["installed"])
        self.assertEqual(before, self.dbpath.read_bytes())
        self.assert_rejected_unchanged("health")

    def test_missing_database_does_not_create_one(self):
        self.db.close()
        self.dbpath.unlink()
        for command in ["preflight", "health", "init"]:
            result = self.run_cli(command, *([self.backup] if command == "init" else []))
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(self.dbpath.exists())
        self.assertFalse(self.backup.exists())

    def test_new_private_snapshot_preserves_original_data(self):
        self.assertEqual(self.db.execute("PRAGMA journal_mode").fetchone()[0], "delete")
        before = self.dump()
        report = self.init()
        self.assertTrue(report["installed"])
        self.assertEqual(report["backup_sha256"], hashlib.sha256(self.backup.read_bytes()).hexdigest())
        self.assertEqual(self.backup.stat().st_mode & 0o777, 0o600)
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(backup.execute("PRAGMA integrity_check").fetchall(), [("ok",)])
            self.assertEqual(before, list(backup.iterdump()))
        self.assertEqual(self.db.execute("SELECT description FROM products").fetchone()[0], "Synthetic source description")
        self.assertEqual(self.db.execute("SELECT note FROM orders").fetchone()[0], "Synthetic order")

    def test_wal_snapshot_includes_committed_uncheckpointed_data(self):
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA wal_autocheckpoint=0")
        self.db.execute("UPDATE products SET description='Committed WAL content'")
        self.db.commit()
        self.assertTrue(Path(str(self.dbpath) + "-wal").stat().st_size > 0)
        self.init()
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(backup.execute("SELECT description FROM products").fetchone()[0], "Committed WAL content")
            self.assertEqual(backup.execute("PRAGMA integrity_check").fetchone()[0], "ok")

    def test_existing_and_empty_targets_are_never_overwritten(self):
        for content in [b"", b"Keep this existing backup"]:
            self.backup.write_bytes(content)
            self.assert_rejected_unchanged("init", self.backup)
            self.assertEqual(self.backup.read_bytes(), content)

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
                self.assert_rejected_unchanged("init", path)
        dangling = self.private / "dangling.sqlite"
        dangling.symlink_to(self.private / "missing.sqlite")
        self.assert_rejected_unchanged("init", dangling)
        self.assertTrue(dangling.is_symlink())
        self.assertEqual(list(self.private.iterdir()), [dangling])

    def test_replaceable_backup_ancestor_is_rejected(self):
        shared = self.base / "replaceable"
        shared.mkdir()
        shared.chmod(0o777)
        private = shared / "private"
        private.mkdir(mode=0o700)
        self.assert_rejected_unchanged("init", private / "backup.sqlite")
        self.assertEqual(list(private.iterdir()), [])

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
        # Observe reservation externally; no test hooks in the production script.
        self.db.execute("CREATE TABLE payload(value BLOB)")
        self.db.execute("INSERT INTO payload VALUES(zeroblob(64000000))")
        self.db.commit()
        before = self.tables()
        libc = ctypes.CDLL(ctypes.util.find_library("c"), use_errno=True)
        fd = libc.inotify_init1(os.O_CLOEXEC)
        self.assertGreaterEqual(fd, 0)
        self.assertGreaterEqual(libc.inotify_add_watch(fd, os.fsencode(self.private), 0x100), 0)
        process = None
        try:
            process = subprocess.Popen(["php", str(self.site / "scripts/product-content-maintenance.php"),
                                        "init", str(self.backup)], stdout=subprocess.PIPE,
                                       stderr=subprocess.PIPE, text=True)
            self.assertTrue(select.select([fd], [], [], 15)[0], "Backup reservation event is observed")
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
            self.assertEqual(before, self.tables())
        finally:
            os.close(fd)
            if process is not None and process.poll() is None:
                process.kill()
                process.communicate()

    def test_corrupt_or_wrong_database_is_rejected_before_backup(self):
        self.db.close()
        self.dbpath.write_bytes(b"not sqlite")
        self.assertNotEqual(self.run_cli("init", self.backup).returncode, 0)
        self.assertFalse(self.backup.exists())
        self.dbpath.unlink()
        self.db = sqlite3.connect(self.dbpath)
        self.db.execute("CREATE TABLE unrelated(value TEXT)")
        self.db.commit()
        self.assert_rejected_unchanged("init", self.backup)
        self.assertFalse(self.backup.exists())

    def test_partial_editorial_schema_fails_before_changes(self):
        self.db.execute("CREATE TABLE product_content_reviews(product_id INTEGER PRIMARY KEY)")
        self.db.commit()
        self.assert_rejected_unchanged("init", self.backup)
        self.assertFalse(self.backup.exists())

    def test_conflicting_index_is_not_silently_accepted(self):
        self.db.execute("CREATE INDEX product_content_history_product ON orders(note)")
        self.db.commit()
        self.assert_rejected_unchanged("init", self.backup)

    def test_incompatible_extra_editorial_column_is_rejected(self):
        self.init()
        self.db.execute("ALTER TABLE product_content_reviews ADD COLUMN required_extra TEXT NOT NULL")
        self.db.commit()
        self.assert_rejected_unchanged("health")
        self.assert_rejected_unchanged("init", self.private / "second.sqlite")
        self.assertFalse((self.private / "second.sqlite").exists())

    def test_checksum_failure_never_initializes_inventory(self):
        before = self.dump()
        fault = self.base / "checksum-fault.php"
        fault.write_text("<?php function hash_file($algorithm, $path) { return false; }")
        result = subprocess.run(["php", "-d", "disable_functions=hash_file", "-d", "auto_prepend_file=" + str(fault),
                                 str(self.site / "scripts/product-content-maintenance.php"), "init", str(self.backup)],
                                text=True, capture_output=True, timeout=15)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(before, self.dump())
        self.assertIn("Unverified backup file retained", result.stderr)

    def test_repeat_init_and_health_preserve_reviews(self):
        self.init()
        draft = json.dumps({"description": "Private synthetic draft"})
        published = json.dumps({"description": "Previously reviewed synthetic text"})
        self.db.execute("INSERT INTO product_content_reviews VALUES(1,5,?,?,?,9,'2026-10-01','2026-10-01')", [draft, published, "source-hash"])
        self.db.execute("INSERT INTO product_content_history VALUES(1,1,5,'publish',9,'2026-10-01')")
        self.db.commit()
        before = self.dump()
        report = self.init(self.private / "second.sqlite")
        self.assertEqual(report["reviews"], 1)
        self.assertEqual(self.dump(), before)
        self.assertEqual(self.run_cli("health").returncode, 0)
        self.db.execute("UPDATE product_content_reviews SET published_json='corrupt'")
        self.db.commit()
        self.assert_rejected_unchanged("init", self.private / "third.sqlite")
        self.assertFalse((self.private / "third.sqlite").exists())
        self.assert_rejected_unchanged("health")

    def test_busy_database_fails_without_schema_changes(self):
        before = self.dump()
        self.db.execute("BEGIN EXCLUSIVE")
        result = self.run_cli("init", self.backup)
        self.db.rollback()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(before, self.dump())
        self.assertFalse(self.backup.exists())

    @unittest.skipUnless(os.name == "posix", "POSIX file-size failure fixture")
    def test_snapshot_write_failure_never_initializes_inventory(self):
        import resource
        before = self.dump()
        def limit_backup_size():
            signal.signal(signal.SIGXFSZ, signal.SIG_IGN)
            resource.setrlimit(resource.RLIMIT_FSIZE, (4096, 4096))
        result = subprocess.run(["php", str(self.site / "scripts/product-content-maintenance.php"),
                                 "init", str(self.backup)], text=True, capture_output=True,
                                timeout=15, preexec_fn=limit_backup_size)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(before, self.dump())
        self.assertIn("Unverified backup file retained", result.stderr)
        self.assertTrue(self.backup.exists())
        self.assertEqual(self.backup.stat().st_mode & 0o777, 0o600)

    def test_install_lock_failure_retains_verified_backup(self):
        self.db.execute("PRAGMA journal_mode=WAL")
        before = self.dump()
        self.db.execute("BEGIN IMMEDIATE")
        result = self.run_cli("init", self.backup)
        self.db.rollback()
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(self.backup.exists(), result.stderr)
        self.assertIn("Backup retained", result.stderr)
        self.assertEqual(before, self.dump())
        with sqlite3.connect(self.backup) as backup:
            self.assertEqual(before, list(backup.iterdump()))

    def test_restore_rehearsal_uses_a_new_disposable_copy(self):
        before = self.dump()
        self.init()
        restored = self.base / "restored-copy.sqlite"
        shutil.copy2(self.backup, restored)
        with sqlite3.connect(restored) as db:
            self.assertEqual(db.execute("PRAGMA integrity_check").fetchone()[0], "ok")
            self.assertEqual(before, list(db.iterdump()))
        self.assertTrue(any(row[0] == "product_content_reviews" for row in self.tables()))


if __name__ == "__main__":
    unittest.main(verbosity=2)
