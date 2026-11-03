"""Runtime configuration. Secrets come from the environment or a 0600 file."""

from __future__ import annotations

import json
import os
from pathlib import Path

DEFAULT_BACKUP_ROOT = "/var/lib/xedc-recover/backups"
DEFAULT_KEY = "/var/lib/xedc-recover/keys/primary.key"


def default_config() -> dict:
    return {
        "backup_root": DEFAULT_BACKUP_ROOT,
        "key_file": DEFAULT_KEY,
        "source_root": "/var/www/drupal",
        "public_files": "web/sites/default/files",
        "private_files": "/var/www/drupal/private",
        "exclude": [".git", "_server", "node_modules"],
        "database": {
            "mode": "mysqldump",
            "name": "drupal",
            "host": "127.0.0.1",
            "port": 3306,
            "user": "",
            "password_env": "XEDC_DB_PASSWORD",
            "socket": "",
            "fixture_sql": "",
        },
        "remote": {
            "enabled": False,
            "endpoint": "",
            "bucket": "",
            "region": "us-east-1",
            "prefix": "xedc-recover/",
            "access_key_env": "XEDC_S3_ACCESS_KEY",
            "secret_key_env": "XEDC_S3_SECRET_KEY",
            "path_style": True,
            "timeout": 60,
            "retries": 5,
        },
        "retention": {"daily": 7, "weekly": 4},
        "nice": 19,
        "strategy": "pinned",
        "production_guard": True,
    }


def load_config(path: str | None) -> dict:
    config = default_config()
    if not path:
        return config
    loaded = json.loads(Path(path).read_text(encoding="utf-8"))
    return _merge(config, loaded)


def _merge(base: dict, overlay: dict) -> dict:
    result = dict(base)
    for key, value in overlay.items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = _merge(result[key], value)
        else:
            result[key] = value
    return result


def env_secret(name: str) -> str:
    if not name:
        return ""
    return os.environ.get(name, "")
