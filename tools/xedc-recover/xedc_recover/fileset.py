"""Compare database file records with files restored on disk."""

from __future__ import annotations

import subprocess
from pathlib import Path

from .backup import BackupError


def managed_file(target: Path, uri: str) -> Path | None:
    if uri.startswith("public://"):
        relative = uri[len("public://"):]
    elif uri.startswith("private://"):
        relative = uri[len("private://"):]
        return _safe(target / "private", relative)
    else:
        return None
    return _safe(target / "public", relative)


def _safe(root: Path, relative: str) -> Path | None:
    if not relative or relative.startswith("/") or ".." in Path(relative).parts:
        return None
    path = root / relative
    try:
        path.resolve().relative_to(root.resolve())
    except ValueError:
        return None
    return path


def compare_uris(target: Path, uris: list[str]) -> dict:
    checked = 0
    missing: list[str] = []
    for uri in uris:
        path = managed_file(target, uri)
        if path is None:
            continue
        checked += 1
        if not path.is_file():
            missing.append(uri)
    return {"checked": checked, "missing": len(missing), "sample": missing[:8]}


def _file_table(database: str) -> str:
    completed = subprocess.run(
        ["mariadb", "-N", "-e", "SHOW TABLES LIKE '%file_managed'", database],
        text=True,
        capture_output=True,
    )
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout or "查询失败")[-240:]
        raise BackupError(f"无法读取数据库中的文件记录，不能把恢复当成成功。{detail}")
    names = [line.strip() for line in completed.stdout.splitlines() if line.strip().endswith("file_managed")]
    if len(names) != 1 or not names[0].replace("_", "").isalnum():
        raise BackupError("数据库里没有唯一的文件记录表，不能把恢复当成成功。")
    return names[0]


def audit_database_files(target: Path, database: str) -> dict:
    table = _file_table(database)
    completed = subprocess.run(
        ["mariadb", "-N", "-e", f"SELECT uri FROM {table} WHERE status=1", database],
        text=True,
        capture_output=True,
    )
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout or "查询失败")[-240:]
        raise BackupError(f"无法读取数据库中的文件记录，不能把恢复当成成功。{detail}")
    uris = [line.strip() for line in completed.stdout.splitlines() if line.strip()]
    result = compare_uris(target, uris)
    result["records"] = len(uris)
    code = target / "code"
    result["layout"] = {
        "composer": (code / "composer.json").is_file(),
        "core": (code / "web/core").is_dir(),
        "custom_modules": sorted(path.name for path in (code / "web/modules/custom").glob("*") if path.is_dir()) if (code / "web/modules/custom").is_dir() else [],
        "custom_themes": sorted(path.name for path in (code / "web/themes/custom").glob("*") if path.is_dir()) if (code / "web/themes/custom").is_dir() else [],
        "settings": (code / "web/sites/default/settings.php").is_file(),
        "public": (target / "public").is_dir(),
        "private": (target / "private").is_dir(),
    }
    if not result["layout"]["core"] or not result["layout"]["settings"]:
        raise BackupError("Drupal 代码或站点配置没有恢复到目标目录。")
    return result
