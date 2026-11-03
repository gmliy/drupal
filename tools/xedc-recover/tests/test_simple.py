"""Checks for the simplified restore workspace and OS gate."""

import os
import unittest
from pathlib import Path

from xedc_recover.fileset import compare_uris
from xedc_recover.installer import InstallError, assert_restore_os
from xedc_recover.oneshot import explain_storage_error, main_restore
from xedc_recover.s3 import S3Error
from xedc_recover.workspace import WorkspaceError, assert_disk, make_work_dir


class OsGateTests(unittest.TestCase):
    def test_ubuntu_2404_is_allowed_and_2604_is_blocked(self):
        root = Path(self.id().replace(".", "-"))
        base = Path(os.environ["XEDC_SIMPLE_TMP"]) / root.name
        base.mkdir(parents=True, exist_ok=True)
        noble = base / "noble"
        noble.write_text(
            'ID=ubuntu\nVERSION_ID="24.04"\nVERSION_CODENAME=noble\nPRETTY_NAME="Ubuntu 24.04.4 LTS"\n',
            encoding="utf-8",
        )
        self.assertEqual(assert_restore_os(noble)["codename"], "noble")
        resolute = base / "resolute"
        resolute.write_text(
            'ID=ubuntu\nVERSION_ID="26.04"\nVERSION_CODENAME=resolute\nPRETTY_NAME="Ubuntu 26.04"\n',
            encoding="utf-8",
        )
        with self.assertRaises(InstallError) as caught:
            assert_restore_os(resolute)
        self.assertIn("BLOCKED", str(caught.exception))
        self.assertNotIn("ppa:ondrej/php", str(caught.exception))


class WorkspaceTests(unittest.TestCase):
    def test_low_disk_stops_before_writing(self):
        with self.assertRaises(WorkspaceError) as caught:
            assert_disk(Path("/"), 10**18, "测试")
        self.assertIn("磁盘空间不足", str(caught.exception))

    def test_named_work_dir_is_not_the_system_tmp(self):
        path = make_work_dir({"backup_root": "/var/lib/xedc-recover/backups"}, "unit-probe")
        self.addCleanup(lambda: __import__("shutil").rmtree(path, ignore_errors=True))
        self.assertEqual(path, Path("/var/lib/xedc-recover/work/unit-probe"))
        self.assertEqual(path.stat().st_mode & 0o777, 0o700)
        self.assertFalse(str(path).startswith("/tmp"))


class SourceTests(unittest.TestCase):
    def test_restore_modules_do_not_call_tempfile(self):
        root = Path(__file__).resolve().parents[1] / "xedc_recover"
        for name in ("bundle.py", "backup.py", "restore.py", "catalog.py", "oneshot.py", "gitmeta.py", "domain.py"):
            text = (root / name).read_text(encoding="utf-8")
            self.assertNotIn("tempfile", text, name)


class FileAuditTests(unittest.TestCase):
    def test_missing_public_file_is_reported(self):
        root = Path(os.environ["XEDC_SIMPLE_TMP"]) / "files"
        (root / "public").mkdir(parents=True)
        (root / "public" / "photo.jpg").write_bytes(b"img")
        result = compare_uris(root, ["public://photo.jpg", "public://gone.jpg", "https://cdn.example/a.jpg"])
        self.assertEqual(result["checked"], 2)
        self.assertEqual(result["missing"], 1)
        self.assertEqual(result["sample"], ["public://gone.jpg"])


class CommandTests(unittest.TestCase):
    def test_storage_errors_are_chinese(self):
        self.assertIn("Access Key", explain_storage_error(S3Error("403 SignatureDoesNotMatch")))
        self.assertIn("Endpoint", explain_storage_error(S3Error("Could not resolve host")))
        self.assertIn("Bucket", explain_storage_error(S3Error("404 NoSuchBucket")))

    def test_bare_restore_refuses_production_host(self):
        code = main_restore([])
        self.assertEqual(code, 3)


def setUpModule():
    import tempfile
    os.environ["XEDC_SIMPLE_TMP"] = tempfile.mkdtemp(prefix="xedc-simple-")
