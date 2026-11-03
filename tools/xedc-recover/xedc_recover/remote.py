"""Upload and download encrypted artifacts. Local files stay until the remote copy is verified."""

from __future__ import annotations

import os
from pathlib import Path

from .backup import BackupError, sha256_file, show_backup
from .config import env_secret
from .s3 import S3Client, S3Error
from .safety import assert_backup_destination


def client_from_config(config: dict) -> S3Client:
    remote = config["remote"]
    prefix = remote.get("prefix") or ""
    if prefix in ("", "/", "s3://") or ".." in prefix:
        raise BackupError("对象存储前缀无效")
    if not prefix.endswith("/"):
        prefix += "/"
    access = env_secret(remote.get("access_key_env") or "") or remote.get("access_key") or ""
    secret = env_secret(remote.get("secret_key_env") or "") or remote.get("secret_key") or ""
    return S3Client(
        endpoint=remote.get("endpoint", ""),
        bucket=remote.get("bucket", ""),
        region=remote.get("region") or "us-east-1",
        access_key=access,
        secret_key=secret,
        prefix=prefix,
        timeout=int(remote.get("timeout") or 60),
        retries=int(remote.get("retries") or 5),
        path_style=bool(remote.get("path_style", True)),
    )


def upload_backup(config: dict, backup_id: str) -> dict:
    root = Path(config["backup_root"])
    directory = root / backup_id
    manifest = show_backup(root, backup_id)
    client = client_from_config(config)
    uploaded = []
    files = [directory / "manifest.json", directory / "manifest.sha256"]
    for artifact in manifest["artifacts"].values():
        if artifact.get("plain_sha256"):
            files.append(directory / artifact["file"])
    for path in files:
        key = f"{backup_id}/{path.relative_to(directory).as_posix()}"
        if path.stat().st_size > 5 * 1024 * 1024:
            client.put_file(key, str(path))
        else:
            client.put_bytes(key, path.read_bytes())
        uploaded.append({"key": client.object_key(key), "size": path.stat().st_size, "sha256": sha256_file(path)})
    listed = set(client.list_keys(f"{backup_id}/"))
    for item in uploaded:
        if item["key"] not in listed:
            raise BackupError(f"上传后远端缺少 {item['key']}")
    return {"id": backup_id, "uploaded": uploaded, "local_kept": True}


def download_backup(config: dict, backup_id: str, destination: str | None = None) -> dict:
    target_root = assert_backup_destination(destination or config["backup_root"])
    directory = target_root / backup_id
    directory.mkdir(parents=True, exist_ok=True)
    os.chmod(directory, 0o700)
    client = client_from_config(config)
    keys = client.list_keys(f"{backup_id}/")
    if not keys:
        raise BackupError(f"远端没有备份 {backup_id}")
    prefix = client.prefix
    saved = []
    for key in keys:
        relative = key[len(prefix):] if key.startswith(prefix) else key
        relative = relative[len(backup_id) + 1:]
        if relative.startswith("/") or ".." in Path(relative).parts:
            raise BackupError(f"远端对象路径不安全: {relative}")
        output = directory / relative
        output.parent.mkdir(parents=True, exist_ok=True)
        client.get_file(key[len(prefix):] if key.startswith(prefix) else key, str(output))
        saved.append(str(output))
    manifest = directory / "manifest.json"
    if not manifest.is_file():
        raise BackupError("下载结果缺少 manifest")
    recorded = (directory / "manifest.sha256").read_text(encoding="utf-8").strip()
    from .backup import sha256_file as digest
    if digest(manifest) != recorded:
        raise BackupError("下载的 manifest 校验失败")
    return {"id": backup_id, "files": saved}


def delete_remote_backup(config: dict, backup_id: str) -> None:
    raise S3Error("为避免误删对象，当前版本不提供远端删除。请在测试桶里按备份 ID 手工清理。")
