"""Local and R2 copies of the same package. Uses a temp directory and a local S3 server only."""

from __future__ import annotations

import hashlib
import io
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from unittest.mock import patch

from xedc_recover.backup import BackupError, assert_free_space, create_backup
from xedc_recover.bundle import _extract_package, _install_material, pack_backup
from xedc_recover.catalog import package_needs_legacy_key, prune_local_packages
from xedc_recover.crypto import write_key
from xedc_recover.oneshot import main_backup, run_backup, upload_existing
from xedc_recover.remote import download_package
from xedc_recover.restore import restore_backup
from xedc_recover.backup import verify_backup
from tests.s3_lab_server import serve
from tests.test_core import config_for, fixture

PORT = 19041


def _sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _remote(prefix: str, *, port: int = PORT, timeout: int = 30, retries: int = 2) -> dict:
    return {
        "enabled": True,
        "endpoint": f"http://127.0.0.1:{port}",
        "bucket": "xedc-lab",
        "region": "us-east-1",
        "prefix": prefix,
        "access_key": "lab-access",
        "secret_key": "lab-secret-key",
        "path_style": True,
        "timeout": timeout,
        "retries": retries,
    }


def _config(source: Path, backups: Path) -> dict:
    config = config_for(source, backups, backups.parent / "missing.key")
    config["package_dir"] = str(backups)
    config["retention"] = {"daily": 7, "weekly": 4, "keep": 7, "days": 0}
    return config


def _fingerprint(target: Path) -> dict:
    names = ("public/pic.txt", "private/note.txt", "code/web/index.php", "database.sql.gz")
    return {name: _sha(target / name) for name in names}


def _restore_archive(config: dict, archive: Path, target: Path) -> None:
    stage = target.parent / f"{target.name}-stage"
    stage.mkdir(parents=True, exist_ok=True)
    _extract_package(archive, stage)
    root = stage / "backup-root"
    root.mkdir()
    backup_id = _install_material(stage, root)
    restore_config = dict(config)
    restore_config["backup_root"] = str(root)
    checked = verify_backup(restore_config, backup_id)
    if checked.get("status") != "PASS":
        raise AssertionError(checked)
    restore_backup(
        restore_config,
        backup_id,
        str(target),
        role="lab",
        yes=True,
        skip_database=False,
        import_database_now=False,
    )


class DualStorageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = serve(PORT)
        cls.tmp = Path(tempfile.mkdtemp(prefix="xedc-dual-"))
        cls.source = cls.tmp / "src"
        fixture(cls.source)
        (cls.source / "composer.json").write_text('{"name":"xedc/fixture"}\n', encoding="utf-8")

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def _backups(self, name: str) -> Path:
        path = self.tmp / name
        path.mkdir(parents=True, exist_ok=True)
        os.chmod(path, 0o700)
        return path

    def _assert_isolated(self, path: str) -> None:
        self.assertTrue(str(path).startswith(str(self.tmp)))
        self.assertNotIn("/var/www/drupal", str(path))
        self.assertNotIn("/var/lib/xedc-recover", str(path))

    def test_disk_refusal(self):
        with self.assertRaises(BackupError):
            assert_free_space(1, 1024 * 1024 * 1024)

    def test_local_only_does_not_contact_r2(self):
        backups = self._backups("local")
        config = _config(self.source, backups)
        config["remote"] = _remote("xedc-recover/dual-local/", port=9, timeout=1, retries=1)

        def refused(*_args, **_kwargs):
            raise AssertionError("仅本地备份不应连接对象存储")

        with patch("xedc_recover.oneshot.prepare_remote", refused), patch("xedc_recover.oneshot.upload_package", refused):
            result = run_backup(config, mode="local")
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["encryption"], "none")
        self.assertEqual(result["local_integrity"], "PASS")
        self.assertEqual(result["uploaded"], [])
        self.assertFalse(Path(config["key_file"]).exists())
        archive = Path(result["archive"])
        self._assert_isolated(archive)
        self.assertEqual(archive.stat().st_mode & 0o077, 0)
        self.assertEqual(archive.parent.stat().st_mode & 0o077, 0)
        self.assertEqual(_sha(archive), result["sha256"])
        self.assertFalse(package_needs_legacy_key(archive))
        listing = subprocess.run(["tar", "--zstd", "-tf", str(archive)], text=True, capture_output=True, check=True).stdout
        self.assertNotIn("primary.key", listing)
        self.assertNotIn(".enc", listing)
        cfg = backups.parent / "local-config.json"
        cfg.write_text(json.dumps(config), encoding="utf-8")
        os.chmod(cfg, 0o600)
        listed = io.StringIO()
        with redirect_stdout(listed), redirect_stderr(io.StringIO()):
            code = main_backup(["list", "--config", str(cfg)])
        self.assertEqual(code, 0)
        self.assertIn(result["id"], listed.getvalue())
        self.assertIn(result["sha256"], listed.getvalue())
        verified = io.StringIO()
        with redirect_stdout(verified), redirect_stderr(io.StringIO()):
            code = main_backup(["verify", "--backup", result["id"], "--config", str(cfg)])
        self.assertEqual(code, 0)
        self.assertIn("PASS", verified.getvalue())
        self.assertIn(result["sha256"], verified.getvalue())
        with redirect_stderr(io.StringIO()):
            refused_delete = main_backup(["delete", "--backup", result["id"], "--config", str(cfg)])
        self.assertEqual(refused_delete, 1)
        self.assertTrue(archive.is_file())

    def test_r2_failure_keeps_local_and_reupload_does_not_repack(self):
        backups = self._backups("partial")
        config = _config(self.source, backups)
        config["remote"] = _remote("xedc-recover/dual-retry/", port=9, timeout=1, retries=1)
        result = run_backup(config, mode="both")
        self.assertEqual(result["status"], "PARTIAL")
        self.assertEqual(result["local_integrity"], "PASS")
        archive = Path(result["archive"])
        self.assertTrue(archive.is_file())
        self.assertEqual(_sha(archive), result["sha256"])
        before = archive.stat().st_mtime_ns
        config["remote"] = _remote("xedc-recover/dual-retry/")
        with patch("xedc_recover.oneshot.create_backup", side_effect=AssertionError("重新打包了数据库")), patch(
            "xedc_recover.oneshot.pack_backup", side_effect=AssertionError("重新打包了文件")
        ):
            uploaded = upload_existing(config, result["id"])
        self.assertEqual(uploaded["status"], "PASS")
        self.assertFalse(uploaded["repacked"])
        self.assertEqual(uploaded["sha256"], result["sha256"])
        self.assertEqual(uploaded["id"], result["id"])
        self.assertEqual(archive.stat().st_mtime_ns, before)
        self.assertEqual(uploaded["roundtrip"]["sha256"], result["sha256"])
        self.assertEqual(uploaded["roundtrip"]["backup_id"], result["id"])

    def test_both_and_independent_restores_match(self):
        backups = self._backups("both")
        config = _config(self.source, backups)
        config["remote"] = _remote("xedc-recover/dual-both/")
        result = run_backup(config, mode="both")
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["roundtrip"]["sha256"], result["sha256"])
        self.assertEqual(result["roundtrip"]["backup_id"], result["id"])
        self.assertEqual(result["roundtrip"]["manifest_sha256"], result["roundtrip"]["manifest_sha256"])
        self.assertEqual(result["roundtrip"]["git_commit"], (result.get("git") or {}).get("commit") or "")
        self.assertTrue(result["r2_key"].endswith(Path(result["archive"]).name))
        local_target = self.tmp / "restore-local"
        remote_target = self.tmp / "restore-r2"
        _restore_archive(config, Path(result["archive"]), local_target)
        fetched = download_package(config, result["id"], str(self.tmp / "downloaded"))
        self.assertEqual(fetched["sha256"], result["sha256"])
        _restore_archive(config, Path(fetched["archive"]), remote_target)
        self.assertEqual(_fingerprint(local_target), _fingerprint(remote_target))
        self.assertFalse((local_target / "private/xedc-storage-keys").exists())
        self.assertFalse((remote_target / "private/xedc-storage-keys").exists())
        self.assertGreater((local_target / "database.sql.gz").stat().st_size, 0)

    def test_r2_only_removes_temp_after_remote_verify(self):
        backups = self._backups("r2")
        config = _config(self.source, backups)
        config["remote"] = _remote("xedc-recover/dual-r2only/")
        result = run_backup(config, mode="r2")
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["archive"], "")
        self.assertEqual(result["roundtrip"]["sha256"], result["sha256"])
        self.assertFalse(list(backups.glob("xedc-full-*.tar.zst")))
        self.assertFalse((backups / ".r2-only-tmp").exists())
        fetched = download_package(config, result["id"], str(self.tmp / "r2-fetched"))
        self.assertEqual(fetched["sha256"], result["sha256"])
        failed = self._backups("r2-failed")
        failed_config = _config(self.source, failed)
        failed_config["remote"] = _remote("xedc-recover/dual-r2fail/", port=9, timeout=1, retries=1)
        failed_result = run_backup(failed_config, mode="r2")
        self.assertEqual(failed_result["status"], "FAIL")
        self.assertTrue(Path(failed_result["archive"]).is_file())
        self.assertTrue((failed / ".r2-only-tmp").is_dir())

    def test_old_encrypted_backup_still_needs_its_key(self):
        backups = self._backups("legacy")
        key = write_key(backups.parent / "legacy.key")
        config = config_for(self.source, backups, Path(key["path"]))
        created = create_backup(config)
        packed = pack_backup(config, created["id"], str(backups / "packages"))
        archive = Path(packed["archive"])
        self.assertTrue(package_needs_legacy_key(archive))
        listing = subprocess.run(["tar", "--zstd", "-tf", str(archive)], text=True, capture_output=True, check=True).stdout
        self.assertIn(".enc", listing)
        target = self.tmp / "legacy-restore"
        stage = self.tmp / "legacy-stage"
        _extract_package(archive, stage)
        root = stage / "backup-root"
        root.mkdir()
        backup_id = _install_material(stage, root)
        restore_config = dict(config)
        restore_config["backup_root"] = str(root)
        restore_backup(restore_config, backup_id, str(target), role="lab", yes=True, skip_database=False, import_database_now=False)
        self.assertEqual((target / "public/pic.txt").read_text(encoding="utf-8"), "picture\n")
        restore_config["key_file"] = str(backups / "missing.key")
        with self.assertRaises(BackupError) as caught:
            restore_backup(restore_config, backup_id, str(self.tmp / "legacy-nokey"), role="lab", yes=True)
        self.assertIn("primary.key", str(caught.exception))

    def test_prune_keeps_only_copy_and_sole_pending_upload(self):
        backups = self._backups("prune")
        config = _config(self.source, backups)
        config["retention"] = {"keep": 1, "days": 1, "daily": 1, "weekly": 0}

        def plant(name: str, created: str, r2: str) -> None:
            archive = backups / name
            archive.write_bytes(b"package")
            os.chmod(archive, 0o600)
            meta = {
                "backup_id": name.removesuffix(".tar.zst"),
                "created": created,
                "r2": r2,
                "sha256": "abc",
                "bytes": 7,
            }
            (backups / f"{name}.meta.json").write_text(json.dumps(meta), encoding="utf-8")
            (backups / f"{name}.sha256").write_text("abc  " + name + "\n", encoding="utf-8")

        plant("xedc-full-20200101T000000Z-aaaaaa.tar.zst", "2020-01-01T00:00:00Z", "uploaded")
        only = prune_local_packages(config)
        self.assertEqual(only["removed"], [])
        self.assertTrue((backups / "xedc-full-20200101T000000Z-aaaaaa.tar.zst").is_file())
        plant("xedc-full-20210101T000000Z-bbbbbb.tar.zst", "2021-01-01T00:00:00Z", "failed")
        plant("xedc-full-20220101T000000Z-cccccc.tar.zst", "2022-01-01T00:00:00Z", "uploaded")
        plant("xedc-full-20230101T000000Z-dddddd.tar.zst", "2023-01-01T00:00:00Z", "uploaded")
        pruned = prune_local_packages(config)
        kept = set(pruned["kept"])
        self.assertIn("xedc-full-20210101T000000Z-bbbbbb", kept)
        self.assertIn("xedc-full-20230101T000000Z-dddddd", kept)
        self.assertNotIn("xedc-full-20200101T000000Z-aaaaaa", kept)
        log = (backups / "prune.log").read_text(encoding="utf-8")
        self.assertIn("delete auto", log)
        self.assertEqual((backups / "prune.log").stat().st_mode & 0o077, 0)


if __name__ == "__main__":
    unittest.main()
