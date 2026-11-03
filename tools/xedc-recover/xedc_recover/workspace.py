"""Persistent directories for downloads and restore work.

Large backups must not use /tmp. A small tmpfs there can fill up while the
root disk still has room. Tests can point XEDC_RECOVER_STATE_ROOT elsewhere.
"""

from __future__ import annotations

import os
import shutil
from pathlib import Path

DEFAULT_STATE = Path("/var/lib/xedc-recover")
DEFAULT_BACKUPS = DEFAULT_STATE / "backups"


def _ensure(path: Path) -> Path:
    path.mkdir(parents=True, exist_ok=True)
    os.chmod(path, 0o700)
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        try:
            os.chown(path, 0, 0)
        except OSError:
            pass
    return path


def state_dir(config: dict | None = None) -> Path:
    env = os.environ.get("XEDC_RECOVER_STATE_ROOT", "").strip()
    if env:
        return _ensure(Path(env))
    if config and config.get("state_root"):
        return _ensure(Path(str(config["state_root"])))
    backup_root_raw = str((config or {}).get("backup_root") or "").strip()
    backup_root = Path(backup_root_raw) if backup_root_raw else None
    if backup_root and backup_root != DEFAULT_BACKUPS:
        try:
            if backup_root.resolve() != DEFAULT_BACKUPS.resolve():
                return _ensure(backup_root.resolve().parent)
        except OSError:
            return _ensure(backup_root.parent)
    return _ensure(DEFAULT_STATE)


def work_root(config: dict | None = None) -> Path:
    return _ensure(state_dir(config) / "work")


def download_root(config: dict | None = None) -> Path:
    return _ensure(state_dir(config) / "downloads")


def make_work_dir(config: dict | None, name: str) -> Path:
    safe = "".join(ch if ch.isalnum() or ch in "._-" else "-" for ch in name).strip("-") or "job"
    path = work_root(config) / safe
    if path.exists():
        shutil.rmtree(path)
    path.mkdir(mode=0o700)
    os.chmod(path, 0o700)
    return path


class WorkspaceError(RuntimeError):
    exit_code = 1


def assert_disk(path: Path, need: int, action: str) -> None:
    path.mkdir(parents=True, exist_ok=True)
    free = shutil.disk_usage(path).free
    if free < need:
        raise WorkspaceError(
            f"磁盘空间不足，已停止，没有继续{action}。"
            f"大约需要 {need} 字节，{path} 所在分区只剩 {free} 字节。"
            "请在根分区腾出空间。备份和解包不会改用 /tmp。"
        )


def keep_message(path: Path) -> str:
    return f"中间文件保留在 {path}。这次没有记为成功。确认不再需要后，用 root 执行: rm -rf {path}"
