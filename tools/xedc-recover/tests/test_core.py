"""Isolated tests. They do not write to /var/www/drupal or port 3306."""

from __future__ import annotations

import io
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from xedc_recover.backup import BackupError, archive_tree, create_backup, prune_backups, verify_backup
from xedc_recover.bundle import create_full_package, install_bundle, verify_package
from xedc_recover.cli import main
from xedc_recover.credentials import login_link, reset_admin_plan
from xedc_recover.crypto import CryptoError, decrypt_file, encrypt_file, write_key, load_key
from xedc_recover.domain import self_test
from xedc_recover.redact import redact
from xedc_recover.restore import extract_archive, restore_backup, rewrite_settings
from xedc_recover.safety import ProductionGuard, assert_db_write_target, assert_write_allowed
from xedc_recover.schedule import render
from xedc_recover.ssltool import nginx_test, render_nginx, rollback, self_sign, write_config


SETTINGS = """<?php
$databases['default']['default'] = array (
  'database' => 'fixture',
  'username' => 'fixture',
  'password' => 'fixture-secret',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
);
$settings['hash_salt'] = 'salt-value-not-real';
$settings['trusted_host_patterns'] = [
  '^example\\.test$',
];
$settings['file_private_path'] = '/tmp/private';
"""


def fixture(root: Path) -> None:
    (root / "web/sites/default/files").mkdir(parents=True)
    (root / "private/xedc-storage-keys").mkdir(parents=True)
    (root / "private/xedc-storage-runtime").mkdir(parents=True)
    (root / "web/index.php").write_text("<?php echo 'OLD';\n", encoding="utf-8")
    (root / "web/keep.txt").write_text("keep\n", encoding="utf-8")
    (root / "web/sites/default/settings.php").write_text(SETTINGS, encoding="utf-8")
    (root / "web/sites/default/files/pic.txt").write_text("picture\n", encoding="utf-8")
    (root / "private/note.txt").write_text("private-note\n", encoding="utf-8")
    (root / "private/xedc-storage-keys/access_key").write_text("SECRETKEY\n", encoding="utf-8")
    (root / "private/xedc-storage-runtime/active.json").write_text(
        json.dumps({"public_mode": "cloudflare_r2", "cdn_enabled": True, "cdn_base": "https://cdn.example.test"}) + "\n",
        encoding="utf-8",
    )
    (root / "db.sql").write_text("CREATE TABLE example (id INT);\n", encoding="utf-8")
    tool = root / "web/tool.sh"
    tool.write_text("#!/bin/sh\necho ok\n", encoding="utf-8")
    tool.chmod(0o755)


def config_for(root: Path, backup_root: Path, key: Path) -> dict:
    from xedc_recover.config import default_config
    config = default_config()
    config.update({
        "backup_root": str(backup_root),
        "key_file": str(key),
        "source_root": str(root),
        "public_files": "web/sites/default/files",
        "private_files": str(root / "private"),
    })
    config["database"]["mode"] = "fixture"
    config["database"]["fixture_sql"] = str(root / "db.sql")
    config["database"]["name"] = "fixture"
    return config


class SafetyTests(unittest.TestCase):
    def test_production_paths_and_force_do_not_matter(self):
        with self.assertRaises(ProductionGuard):
            assert_write_allowed("/var/www/drupal/web/new-file.php", purpose="test")
        with self.assertRaises(ProductionGuard):
            assert_db_write_target("127.0.0.1", 3306, "")
        with self.assertRaises(ProductionGuard):
            assert_db_write_target("localhost", 3306, "/run/mysqld/mysqld.sock")

    def test_install_and_schedule_refused_on_host(self):
        self.assertEqual(main(["install", "--yes", "--force"]), 3)
        self.assertEqual(main(["schedule", "install", "--prefix", "/etc/systemd/system", "--binary", "/bin/true", "--force"]), 3)
        self.assertEqual(main(["credentials", "reset-admin", "--force"]), 3)
        self.assertEqual(main(["--version"]), 0)

    def test_redact(self):
        self.assertNotIn("super-secret", redact("password=super-secret token=abc"))
        self.assertIn("***", redact("hash_salt=abc123"))


class CryptoTests(unittest.TestCase):
    def test_roundtrip_and_wrong_key(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "plain.bin"
            source.write_bytes(os.urandom(3 * 1024 * 1024))
            info = write_key(root / "key.json")
            key_id, key = load_key(info["path"])
            digest = encrypt_file(source, root / "data.enc", key, key_id)
            out = root / "out.bin"
            self.assertEqual(decrypt_file(root / "data.enc", out, key, key_id), digest)
            self.assertEqual(out.read_bytes(), source.read_bytes())
            other = write_key(root / "other.json")
            other_id, other_key = load_key(other["path"])
            with self.assertRaises(CryptoError):
                decrypt_file(root / "data.enc", root / "bad.bin", other_key, other_id)


class BackupRestoreTests(unittest.TestCase):
    def test_full_incremental_restore_and_manifest_has_no_secret(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            backups = root / "backups"
            fixture(source)
            key = write_key(root / "key.json")
            config = config_for(source, backups, Path(key["path"]))
            with patch("xedc_recover.backup._disk_free", return_value=1):
                with self.assertRaises(BackupError):
                    create_backup(config, allow_live_read=False)
            first = create_backup(config)
            manifest_text = (backups / first["id"] / "manifest.json").read_text(encoding="utf-8")
            self.assertNotIn("fixture-secret", manifest_text)
            self.assertNotIn("salt-value-not-real", manifest_text)
            self.assertEqual(verify_backup(config, first["id"])["status"], "PASS")
            (source / "web/index.php").write_text("<?php echo 'NEW';\n", encoding="utf-8")
            (source / "web/keep.txt").unlink()
            (source / "web/new.txt").write_text("added\n", encoding="utf-8")
            second = create_backup(config, backup_type="incremental")
            self.assertEqual(verify_backup(config, second["id"])["status"], "PASS")
            target = root / "restore"
            report = restore_backup(config, second["id"], str(target), role="lab", yes=True, skip_database=False, database_password="new-db-pass", trusted_hosts=["^lab\\.test$"])
            self.assertEqual(report["role"], "lab")
            self.assertEqual((target / "code/web/index.php").read_text(encoding="utf-8"), "<?php echo 'NEW';\n")
            self.assertFalse((target / "code/web/keep.txt").exists())
            self.assertEqual((target / "code/web/new.txt").read_text(encoding="utf-8"), "added\n")
            self.assertEqual((target / "public/pic.txt").read_text(encoding="utf-8"), "picture\n")
            self.assertEqual((target / "private/note.txt").read_text(encoding="utf-8"), "private-note\n")
            self.assertFalse((target / "private/xedc-storage-keys").exists())
            active = json.loads((target / "private/xedc-storage-runtime/active.json").read_text(encoding="utf-8"))
            self.assertEqual(active["public_mode"], "local")
            settings = (target / "code/web/sites/default/settings.php").read_text(encoding="utf-8")
            self.assertIn("new-db-pass", settings)
            self.assertIn("salt-value-not-real", settings)
            self.assertIn("^lab\\.test$", settings)
            self.assertTrue(os.access(target / "code/web/tool.sh", os.X_OK))
            self.assertEqual((target / "code/web/sites/default/settings.php").stat().st_mode & 0o777, 0o440)
            with self.assertRaises(ProductionGuard):
                restore_backup(config, second["id"], "/var/www/drupal", role="replacement", yes=True)

    def test_tar_slip_rejected(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            archive = root / "evil.tar.gz"
            with tarfile.open(archive, "w:gz") as handle:
                data = b"owned\n"
                info = tarfile.TarInfo("../evil.txt")
                info.size = len(data)
                handle.addfile(info, io.BytesIO(data))
            with self.assertRaises(BackupError):
                extract_archive(archive, root / "out")

    def test_escaping_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            archive = root / "link.tar.gz"
            with tarfile.open(archive, "w:gz") as handle:
                info = tarfile.TarInfo("inside/link")
                info.type = tarfile.SYMTYPE
                info.linkname = "/tmp/outside"
                handle.addfile(info)
            with self.assertRaises(BackupError):
                extract_archive(archive, root / "out")

    def test_hardlink_is_copied_inside_target(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            archive = root / "hard.tar.gz"
            payload = b"same\n"
            with tarfile.open(archive, "w:gz") as handle:
                info = tarfile.TarInfo("dir/a.txt")
                info.size = len(payload)
                handle.addfile(info, io.BytesIO(payload))
                link = tarfile.TarInfo("dir/b.txt")
                link.type = tarfile.LNKTYPE
                link.linkname = "dir/a.txt"
                handle.addfile(link)
            out = root / "out"
            extract_archive(archive, out)
            self.assertEqual((out / "dir/b.txt").read_bytes(), payload)
            self.assertFalse((out / "dir/b.txt").is_symlink())

    def test_nested_node_modules_and_hardlinks_are_regular_files(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            nested = source / "web/themes/custom/xedc/node_modules/pkg"
            nested.mkdir(parents=True)
            (nested / "skip.js").write_text("nope\n", encoding="utf-8")
            kept = source / "web/keep.txt"
            kept.write_text("yes\n", encoding="utf-8")
            other = source / "web/other.txt"
            os.link(kept, other)
            archive = root / "code.tar.gz"
            archive_tree(source, archive, [".git", "node_modules"])
            with tarfile.open(archive, "r:gz") as handle:
                members = {member.name: member for member in handle.getmembers()}
            self.assertIn("web/keep.txt", members)
            self.assertIn("web/other.txt", members)
            self.assertTrue(members["web/other.txt"].isreg())
            self.assertFalse(any("node_modules" in name for name in members))

    def test_settings_rewrite_uses_live_values(self):
        text = """<?php
 *   'database' => 'database_name',
 *   'username' => 'sql_username',
 *   'password' => 'sql_password',
 *   'host' => 'localhost',
 *   'port' => '3306',
$databases['default']['default'] = array (
  'database' => 'drupal',
  'username' => 'liveuser',
  'password' => 'live-secret',
  'host' => 'localhost',
  'port' => '3306',
);
# $settings['file_private_path'] = '';
# $settings['file_temp_path'] = '/tmp';
$settings['file_private_path'] = '/var/www/drupal/private';
 * $settings['trusted_host_patterns'] = [
 *   '^www\\.example\\.com$',
 * ];
$settings['trusted_host_patterns'] = [
  '^old\\.example$',
];
"""
        rewritten = rewrite_settings(
            text,
            database="xedc_restore",
            username="xedc_restore",
            password="new-db-pass",
            host="127.0.0.1",
            port=3306,
            trusted_hosts=["^lab\\.test$"],
            private_path="/srv/xedc/private",
        )
        self.assertIn("'password' => 'sql_password'", rewritten)
        self.assertIn("'password' => 'new-db-pass'", rewritten)
        self.assertNotIn("live-secret", rewritten)
        self.assertIn("'database' => 'database_name'", rewritten)
        self.assertIn("'database' => 'xedc_restore'", rewritten)
        self.assertIn("$settings['file_private_path'] = '/srv/xedc/private';", rewritten)
        self.assertIn("# $settings['file_temp_path'] = '/tmp';", rewritten)
        self.assertIn("$settings['file_temp_path'] = '/srv/xedc/tmp';", rewritten)
        self.assertIn("# $settings['file_private_path'] = '';", rewritten)
        self.assertIn("'^lab\\.test$'", rewritten)
        self.assertIn("'^www\\.example\\.com$'", rewritten)
        self.assertNotIn("^old\\.example$", rewritten)

    def test_resume_skips_completed_database_step(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            backups = root / "backups"
            fixture(source)
            key = write_key(root / "key.json")
            config = config_for(source, backups, Path(key["path"]))
            backup_id = "xedc-resume-audit"
            real_encrypt = encrypt_file
            calls = {"n": 0}

            def interrupt(source_path, destination, key_bytes, key_id):
                calls["n"] += 1
                if calls["n"] == 2:
                    raise BackupError("模拟备份在文件阶段中断")
                return real_encrypt(source_path, destination, key_bytes, key_id)

            with patch("xedc_recover.backup.encrypt_file", side_effect=interrupt):
                with self.assertRaises(BackupError):
                    create_backup(config, backup_id=backup_id)
            state = json.loads((backups / backup_id / "state.json").read_text(encoding="utf-8"))
            self.assertEqual(state["steps"]["database"]["status"], "done")
            self.assertNotIn("files", state["steps"])
            resumed = create_backup(config, resume=True, backup_id=backup_id)
            self.assertEqual(resumed["id"], backup_id)
            self.assertEqual(verify_backup(config, backup_id)["status"], "PASS")

    def test_prune_keeps_incremental_parent(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            backups = root / "backups"
            fixture(source)
            key = write_key(root / "key.json")
            config = config_for(source, backups, Path(key["path"]))
            config["retention"] = {"daily": 1, "weekly": 0}
            first = create_backup(config)
            (source / "web/new.txt").write_text("x\n", encoding="utf-8")
            second = create_backup(config, backup_type="incremental")
            result = prune_backups(config, dry_run=False)
            self.assertIn(second["id"], result["kept"])
            self.assertIn(first["id"], result["kept"])

    def test_portable_package_restores_without_github(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            backups = root / "backups"
            packages = root / "packages"
            fixture(source)
            key = write_key(root / "key.json")
            config = config_for(source, backups, Path(key["path"]))
            created = create_full_package(config, str(packages))
            archive = Path(created["archive"])
            self.assertTrue(archive.name.startswith("xedc-full-"))
            self.assertTrue(str(archive).endswith(".tar.zst"))
            self.assertFalse(created["key_included"])
            self.assertEqual(verify_package(archive), created["sha256"])
            listing = subprocess.run(["tar", "--zstd", "-tf", str(archive)], text=True, capture_output=True, check=True)
            names = listing.stdout.splitlines()
            self.assertTrue(any(name.endswith("manifest.json") for name in names))
            self.assertTrue(any("payload/" in name and name.endswith(".enc") for name in names))
            self.assertFalse(any(name.endswith("primary.key") or name.endswith(".key") for name in names))
            stage = root / "listed"
            stage.mkdir()
            subprocess.run(["tar", "--zstd", "-xf", str(archive), "-C", str(stage)], check=True)
            readable = "\n".join(
                path.read_text(encoding="utf-8", errors="replace")
                for path in stage.rglob("*")
                if path.is_file() and path.suffix not in {".enc"} and "payload" not in path.parts
            )
            self.assertNotIn("fixture-secret", readable)
            self.assertNotIn("salt-value-not-real", readable)
            self.assertIn("manual-confirmation-required", readable)
            self.assertIn("Matomo", readable)
            self.assertTrue(Path(created["credentials"]).read_bytes().startswith(b"XEDCENC1"))
            bad = packages / "bad.tar.zst"
            payload = bytearray(archive.read_bytes())
            payload[-20] ^= 1
            bad.write_bytes(payload)
            shutil.copy(created["checksum"], str(bad) + ".sha256")
            with self.assertRaises(BackupError):
                verify_package(bad)
            target = root / "site"
            report = install_bundle(
                config,
                str(archive),
                str(target),
                yes=True,
                role="lab",
                skip_services=True,
                skip_database=True,
            )
            self.assertEqual(report["status"], "PASS")
            self.assertEqual((target / "public/pic.txt").read_text(encoding="utf-8"), "picture\n")
            self.assertTrue(os.access(target / "code/web/tool.sh", os.X_OK))
            self.assertIn("cloudflare", report["cloudflare_dns"].lower())
            again = install_bundle(
                config,
                str(archive),
                str(target),
                yes=True,
                role="lab",
                skip_services=True,
                skip_database=True,
            )
            self.assertTrue(again["idempotent"])
            occupied = root / "occupied"
            (occupied / "code/web/core").mkdir(parents=True)
            (occupied / "code/web/sites/default/settings.php").parent.mkdir(parents=True)
            (occupied / "code/web/sites/default/settings.php").write_text("<?php\n", encoding="utf-8")
            with self.assertRaises(BackupError):
                install_bundle(config, str(archive), str(occupied), yes=True, role="lab", skip_services=True, skip_database=True)

    def test_one_shot_restore_refused_on_production_host(self):
        self.assertEqual(main(["bundle", "install", "--backup", "/tmp/xedc-missing.tar.zst", "--target", "/tmp/xedc-not-used", "--yes", "--force"]), 3)


class DomainSslTests(unittest.TestCase):
    def test_php_rewriter(self):
        self.assertEqual(self_test(), "PASS")

    def test_ssl_rollback(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            web = root / "web"
            web.mkdir()
            (web / "index.html").write_text("ok\n", encoding="utf-8")
            prefix = root / "nginx"
            certs = self_sign("lab.xedc.test", str(root / "certs"))
            text = render_nginx(server_name="lab.xedc.test", webroot=str(web), prefix=str(prefix), port=18081, cert=certs["cert"], key=certs["key"])
            path = write_config(str(prefix / "nginx.conf"), text)
            ok, output = nginx_test(str(path), str(prefix))
            self.assertTrue(ok, output)
            write_config(str(path), text + "\nthis is not valid nginx;\n")
            bad, _output = nginx_test(str(path), str(prefix))
            self.assertFalse(bad)
            rollback(str(path))
            ok, output = nginx_test(str(path), str(prefix))
            self.assertTrue(ok, output)
            self.assertIn("notAfter", "notAfter=" + __import__("xedc_recover.ssltool", fromlist=["expiry"]).expiry(certs["cert"]))

    def test_schedule_and_login_link_in_temp(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            rendered = render(str(root / "units"), binary="/usr/bin/xedc-recover", config="/etc/xedc-recover.json")
            self.assertTrue(Path(rendered["timer"]).is_file())
            with self.assertRaises(ProductionGuard):
                login_link(target=str(root / "link"), user="admin", explicit=False)
            with self.assertRaises(ProductionGuard):
                reset_admin_plan(explicit=True)


if __name__ == "__main__":
    unittest.main()
