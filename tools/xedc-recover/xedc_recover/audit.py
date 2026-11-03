"""Read-only environment collection. It does not print secret values."""

from __future__ import annotations

import os
import platform
import shutil
import subprocess
from pathlib import Path

from .backup import extract_settings, public_storage_note


def _out(command: list[str]) -> str:
    try:
        completed = subprocess.run(command, text=True, capture_output=True, timeout=20)
    except (OSError, subprocess.TimeoutExpired):
        return ""
    return (completed.stdout or completed.stderr or "").strip().splitlines()[0] if completed.stdout or completed.stderr else ""


def collect() -> dict:
    settings_path = Path("/var/www/drupal/web/sites/default/settings.php")
    settings = extract_settings(settings_path) if settings_path.is_file() else {"present": False}
    safe_settings = {
        "present": settings.get("present", False),
        "database_name": settings.get("database", ""),
        "host": settings.get("host", ""),
        "port": settings.get("port", ""),
        "username_present": bool(settings.get("username")),
        "password_present": bool(settings.get("password")),
        "hash_salt_present": bool(settings.get("hash_salt")),
        "private_path": settings.get("private_path", ""),
        "trusted_host_patterns": settings.get("trusted_host_patterns", []),
    }
    private = Path(safe_settings["private_path"] or "/var/www/drupal/private")
    binaries = {}
    for name in ("php", "nginx", "mariadb", "mysqldump", "composer", "drush", "node", "npm", "ffmpeg", "ffprobe", "7z", "certbot", "openssl", "python3", "redis-server"):
        path = shutil.which(name)
        binaries[name] = {"path": path or "", "present": bool(path)}
    return {
        "os": platform.platform(),
        "machine": platform.machine(),
        "python": platform.python_version(),
        "php": _out(["php", "-r", "echo PHP_VERSION;"]),
        "nginx": _out(["nginx", "-v"]),
        "mariadb": _out(["mariadb", "--version"]),
        "openssl": _out(["openssl", "version"]),
        "ffmpeg": _out(["ffmpeg", "-version"]),
        "settings": safe_settings,
        "storage": public_storage_note(private) if private.exists() else {"present": False},
        "binaries": binaries,
        "production_webroot": Path("/var/www/drupal/web/core").is_dir(),
        "uid": os.geteuid(),
    }
