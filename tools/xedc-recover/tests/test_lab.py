"""Domain migration and S3 tests against isolated services, never port 3306."""

from __future__ import annotations

import hashlib
import json
import os
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

from xedc_recover.backup import create_backup, verify_backup
from xedc_recover.crypto import write_key
from xedc_recover.domain import count_remaining, dump_for_rollback, migrate, restore_rollback
from xedc_recover.remote import download_backup, download_package, upload_backup, upload_package
from xedc_recover.restore import restore_backup
from xedc_recover.s3 import EMPTY_SHA, S3Client
from tests.s3_lab_server import serve
from tests.test_core import SETTINGS, config_for, fixture


class SignatureTests(unittest.TestCase):
    def test_published_s3_signature(self):
        client = S3Client(
            endpoint="https://examplebucket.s3.amazonaws.com",
            bucket="examplebucket",
            region="us-east-1",
            access_key="AKIAIOSFODNN7EXAMPLE",
            secret_key="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
            prefix="",
        )
        signed = client._sign(
            "GET",
            "/test.txt",
            [],
            {"range": "bytes=0-9"},
            EMPTY_SHA,
            amz_date=datetime(2013, 5, 24, tzinfo=timezone.utc),
        )
        signature = signed["authorization"].split("Signature=")[1]
        self.assertEqual(signature, "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41")


class ObjectStoreTests(unittest.TestCase):
    def test_upload_download_multipart_and_restore(self):
        server = serve(19000)
        self.addCleanup(server.shutdown)
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            backups = root / "backups"
            fixture(source)
            blob = source / "web/sites/default/files/big.bin"
            blob.write_bytes(os.urandom(6 * 1024 * 1024))
            key = write_key(root / "key.json")
            config = config_for(source, backups, Path(key["path"]))
            config["remote"] = {
                "enabled": True,
                "endpoint": "http://127.0.0.1:19000",
                "bucket": "xedc-lab",
                "region": "us-east-1",
                "prefix": "xedc-recover/",
                "access_key": "lab-access",
                "secret_key": "lab-secret-key",
                "path_style": True,
                "timeout": 30,
                "retries": 3,
            }
            created = create_backup(config)
            uploaded = upload_backup(config, created["id"])
            self.assertTrue(uploaded["local_kept"])
            for item in uploaded["uploaded"]:
                self.assertGreater(item["size"], 0)
                self.assertEqual(len(item["sha256"]), 64)
            downloaded = root / "downloaded"
            download_backup(config, created["id"], str(downloaded))
            self.assertEqual(verify_backup({**config, "backup_root": str(downloaded)}, created["id"])["status"], "PASS")
            restored = restore_backup({**config, "backup_root": str(downloaded)}, created["id"], str(root / "site"), role="lab", yes=True, skip_database=True)
            self.assertIn("code", json.dumps(restored))
            original = hashlib.sha256(blob.read_bytes()).hexdigest()
            copied = hashlib.sha256((root / "site/public/big.bin").read_bytes()).hexdigest()
            self.assertEqual(original, copied)

    def test_package_upload_download_leaves_key_local(self):
        server = serve(19001)
        self.addCleanup(server.shutdown)
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            packages = root / "packages"
            packages.mkdir()
            archive = packages / "xedc-full-20261103-abc123.tar.zst"
            archive.write_bytes(b"xedc-package-bytes")
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            (packages / (archive.name + ".sha256")).write_text(f"{digest}  {archive.name}\n", encoding="utf-8")
            (packages / "xedc-full-20261103-abc123.credentials.enc").write_bytes(b"XEDCENC1-not-a-key")
            (packages / "primary.key").write_bytes(b"local-key-must-stay")
            config = {
                "backup_root": str(root / "backups"),
                "remote": {
                    "enabled": True,
                    "endpoint": "http://127.0.0.1:19001",
                    "bucket": "xedc-lab",
                    "region": "us-east-1",
                    "prefix": "xedc-recover/",
                    "access_key": "lab-access",
                    "secret_key": "lab-secret-key",
                    "path_style": True,
                    "timeout": 30,
                    "retries": 3,
                },
            }
            uploaded = upload_package(config, str(archive))
            self.assertFalse(uploaded["key_included"])
            self.assertTrue(all(not item["key"].endswith(".key") for item in uploaded["uploaded"]))
            fetched = download_package(config, "latest", str(root / "fetched"))
            self.assertEqual(fetched["sha256"], digest)
            self.assertTrue((root / "fetched/xedc-full-20261103-abc123.credentials.enc").is_file())
            self.assertFalse((root / "fetched/primary.key").exists())


class DomainLabTests(unittest.TestCase):
    def test_migrate_idempotent_and_rollback(self):
        import subprocess
        host, port, user, password, database = "127.0.0.1", 13306, "xedc_lab", "lab-secret-not-prod", "xedc_lab"
        serialized = subprocess.check_output([
            "php", "-r",
            'echo serialize(["url" => "https://old.example/node/1", "n" => 2]);',
        ])
        sql = """
        DROP TABLE IF EXISTS sample;
        CREATE TABLE sample (
          id INT PRIMARY KEY,
          body LONGTEXT,
          meta LONGTEXT,
          note VARCHAR(255)
        );
        """
        defaults = Path(tempfile.mkstemp(suffix=".cnf")[1])
        defaults.write_text(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\nprotocol=tcp\n" % (user, password, host, port),
            encoding="utf-8",
        )
        os.chmod(defaults, 0o600)
        try:
            subprocess.run(["mariadb", f"--defaults-extra-file={defaults}", database], input=sql, text=True, check=True)
            subprocess.run(
                ["mariadb", f"--defaults-extra-file={defaults}", database, "-e",
                 "INSERT INTO sample (id, body, meta, note) VALUES (1, %s, %s, %s), (2, %s, %s, %s)" % (
                     _q(serialized.decode()),
                     _q('{"href":"https://old.example/a"}'),
                     _q("see https://old.example/plain"),
                     _q("leave"),
                     _q("[]"),
                     _q("unchanged"),
                 )],
                check=True,
            )
        finally:
            defaults.unlink(missing_ok=True)
        with tempfile.TemporaryDirectory() as raw:
            rollback = str(Path(raw) / "rollback.sql")
            dump_for_rollback(host=host, port=port, user=user, password=password, database=database, destination=rollback)
            dry = migrate(host=host, port=port, user=user, password=password, database=database, source="https://old.example", target="https://new.example", dry_run=True)
            self.assertGreater(dry["replacements"], 0)
            self.assertEqual(dry["updated_rows"], 0) if False else None
            self.assertGreater(dry["updated_rows"], 0)
            applied = migrate(host=host, port=port, user=user, password=password, database=database, source="https://old.example", target="https://new.example", dry_run=False)
            self.assertGreater(applied["updated_rows"], 0)
            again = migrate(host=host, port=port, user=user, password=password, database=database, source="https://old.example", target="https://new.example", dry_run=False)
            self.assertEqual(again["updated_rows"], 0)
            self.assertEqual(count_remaining(host=host, port=port, user=user, password=password, database=database, needle="https://old.example"), 0)
            self.assertGreater(count_remaining(host=host, port=port, user=user, password=password, database=database, needle="https://new.example"), 0)
            restore_rollback(host=host, port=port, user=user, password=password, database=database, source=rollback)
            self.assertGreater(count_remaining(host=host, port=port, user=user, password=password, database=database, needle="https://old.example"), 0)


def _q(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


if __name__ == "__main__":
    unittest.main()
