"""One-command backup and restore. Nothing here touches production data."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

from xedc_recover.crypto import write_key
from xedc_recover.gitmeta import align_code, remote_allowed, sanitize_remote
from xedc_recover.oneshot import assert_remote_safe, main_backup, main_restore, run_backup
from xedc_recover.remote import is_prunable_package_key, prune_remote_packages, resolve_remote_package_name
from xedc_recover.s3 import S3Client, S3Error
from tests.s3_lab_server import serve
from tests.test_core import config_for, fixture


class GuardTests(unittest.TestCase):
    def test_commands_refuse_restore_and_accept_version(self):
        self.assertEqual(main_backup(["--version"]), 0)
        self.assertEqual(main_restore(["--version"]), 0)
        self.assertEqual(main_restore(["--file", "/tmp/xedc-nope.tar.zst", "--domain", "lab.xedc.test", "--yes", "--force"]), 3)

    def test_remote_prefix_cannot_target_media(self):
        with self.assertRaises(Exception):
            assert_remote_safe({"prefix": "public/", "endpoint": "https://example.r2.cloudflarestorage.com", "bucket": "dclili"})
        assert_remote_safe({"prefix": "xedc-recover/unit/", "endpoint": "http://127.0.0.1:9", "bucket": "lab"})
        self.assertFalse(is_prunable_package_key("public/photo.jpg"))
        self.assertFalse(is_prunable_package_key("packages/notes.txt"))
        self.assertTrue(is_prunable_package_key("packages/xedc-full-20261103T010203Z-abc123.tar.zst"))
        names = [
            "xedc-full-20261103T010203Z-abc123.tar.zst",
            "xedc-full-20261104T010203Z-def456.tar.zst",
        ]
        self.assertEqual(resolve_remote_package_name(names, "xedc-full-20261104"), names[1])
        self.assertEqual(resolve_remote_package_name(names, "latest"), names[1])
        self.assertEqual(
            resolve_remote_package_name(["xedc-full-20261103T170434Z-764a29.tar.zst"], "xedc-20261103T170434Z-764a29"),
            "xedc-full-20261103T170434Z-764a29.tar.zst",
        )
        self.assertEqual(
            resolve_remote_package_name(["xedc-full-20261103-3cd3bf.tar.zst"], "xedc-20261103T135450Z-3cd3bf"),
            "xedc-full-20261103-3cd3bf.tar.zst",
        )

    def test_remote_url_loses_credentials(self):
        cleaned = sanitize_remote("https://xxedc:ghp_exampleexampleexampleex@github.com/xxedc/drupal.git")
        self.assertNotIn("ghp_", cleaned)
        self.assertEqual(cleaned, "https://github.com/xxedc/drupal.git")
        self.assertTrue(remote_allowed(cleaned))
        self.assertFalse(remote_allowed("https://example.com/xxedc/drupal.git"))


class GitFallbackTests(unittest.TestCase):
    def test_clean_commit_overlays_and_unavailable_commit_keeps_package(self):
        os.environ["XEDC_RECOVER_ALLOW_LOCAL_GIT"] = "1"
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            repo = root / "repo"
            repo.mkdir()
            subprocess.run(["git", "init", "-q", str(repo)], check=True)
            (repo / "keep.txt").write_text("from-git\n", encoding="utf-8")
            (repo / "composer.lock").write_text('{"locked":true}\n', encoding="utf-8")
            subprocess.run(["git", "-C", str(repo), "add", "keep.txt", "composer.lock"], check=True)
            subprocess.run(
                ["git", "-C", str(repo), "-c", "user.email=test@example.com", "-c", "user.name=test", "commit", "-q", "-m", "init"],
                check=True,
            )
            commit = subprocess.run(["git", "-C", str(repo), "rev-parse", "HEAD"], text=True, capture_output=True, check=True).stdout.strip()
            lock_sha = __import__("hashlib").sha256((repo / "composer.lock").read_bytes()).hexdigest()
            code = root / "code"
            (code / "vendor").mkdir(parents=True)
            (code / "keep.txt").write_text("from-package\n", encoding="utf-8")
            (code / "vendor/extra.txt").write_text("vendor\n", encoding="utf-8")
            shutil_copy = (repo / "composer.lock").read_text(encoding="utf-8")
            (code / "composer.lock").write_text(shutil_copy, encoding="utf-8")
            aligned = align_code(code, {"commit": commit, "remote": str(repo), "dirty": False, "composer_lock_sha256": lock_sha})
            self.assertEqual(aligned["code_source"], "github-commit")
            self.assertEqual((code / "keep.txt").read_text(encoding="utf-8"), "from-git\n")
            self.assertEqual((code / "vendor/extra.txt").read_text(encoding="utf-8"), "vendor\n")
            (code / "keep.txt").write_text("from-package\n", encoding="utf-8")
            dirty = align_code(code, {"commit": commit, "remote": str(repo), "dirty": True, "composer_lock_sha256": lock_sha})
            self.assertEqual(dirty["code_source"], "package-snapshot")
            self.assertEqual((code / "keep.txt").read_text(encoding="utf-8"), "from-package\n")
            missing = align_code(code, {"commit": commit, "remote": str(root / "missing"), "dirty": False, "composer_lock_sha256": lock_sha})
            self.assertEqual(missing["code_source"], "package-fallback")
            self.assertEqual((code / "keep.txt").read_text(encoding="utf-8"), "from-package\n")


class PipelineTests(unittest.TestCase):
    def test_backup_roundtrip_and_retention_do_not_touch_media_keys(self):
        server = serve(19021)
        self.addCleanup(server.shutdown)
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            source = root / "src"
            backups = root / "backups"
            fixture(source)
            (source / "composer.json").write_text('{"name":"xedc/fixture"}\n', encoding="utf-8")
            key = write_key(root / "key.json")
            config = config_for(source, backups, Path(key["path"]))
            config["remote"] = {
                "enabled": True,
                "endpoint": "http://127.0.0.1:19021",
                "bucket": "xedc-lab",
                "region": "us-east-1",
                "prefix": "xedc-recover/unit-oneshot/",
                "access_key": "lab-access",
                "secret_key": "lab-secret-key",
                "path_style": True,
                "timeout": 30,
                "retries": 2,
            }
            config["retention"] = {"daily": 7, "weekly": 4}
            result = run_backup(config, output=str(root / "packages"))
            self.assertEqual(result["status"], "PASS")
            self.assertFalse(result["key_included"])
            self.assertTrue(result["archive"].endswith(".tar.zst"))
            self.assertEqual(result["roundtrip"]["integrity"], "PASS")
            self.assertEqual(result["encryption"], "none")
            self.assertEqual(result["roundtrip"]["decrypt"], "not-required")
            self.assertTrue(all("xedc-recover/unit-oneshot/packages/" in item for item in result["uploaded"]))
            self.assertFalse(any(item.endswith(".key") for item in result["uploaded"]))
            client = S3Client(
                endpoint="http://127.0.0.1:19021",
                bucket="xedc-lab",
                region="us-east-1",
                access_key="lab-access",
                secret_key="lab-secret-key",
                prefix="xedc-recover/unit-oneshot/",
            )
            client.put_bytes("public/keep.jpg", b"media")
            client.put_bytes("packages/public-image.jpg", b"not-a-package")
            client.put_bytes("packages/xedc-full-20200101T000000Z-aaaaaa.tar.zst", b"old")
            client.put_bytes("packages/xedc-full-20200101T000000Z-aaaaaa.tar.zst.sha256", b"old-sha")
            with self.assertRaises(S3Error):
                client.delete_key("public/keep.jpg")
            pruned = prune_remote_packages(config, keep=1)
            self.assertIn("packages/xedc-full-20200101T000000Z-aaaaaa.tar.zst", pruned["removed"])
            listed = set(client.list_keys(""))
            self.assertIn("xedc-recover/unit-oneshot/public/keep.jpg", listed)
            self.assertIn("xedc-recover/unit-oneshot/packages/public-image.jpg", listed)
            self.assertNotIn("xedc-recover/unit-oneshot/packages/xedc-full-20200101T000000Z-aaaaaa.tar.zst", listed)
            listing = subprocess.run(["tar", "--zstd", "-tf", result["archive"]], text=True, capture_output=True, check=True)
            member = next(line for line in listing.stdout.splitlines() if line.endswith("PACKAGE.json"))
            package = json.loads(subprocess.run(["tar", "--zstd", "-xOf", result["archive"], member], text=True, capture_output=True, check=True).stdout)
            self.assertEqual(package["schema"], 4)
            self.assertEqual(package["encryption"]["alg"], "none")
            self.assertFalse(package["key_included"])
            self.assertNotIn("key_b64", json.dumps(package))


if __name__ == "__main__":
    unittest.main()
