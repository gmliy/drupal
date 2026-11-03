"""Full and incremental backups. Incremental file sets keep a verified parent chain."""

from __future__ import annotations

import fcntl
import gzip
import hashlib
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path

from . import __version__
from .config import env_secret
from .crypto import decrypt_file, encrypt_file, load_key
from .redact import redact
from .safety import (
    ProductionGuard,
    assert_backup_destination,
    production_webroot_present,
)

EXIT_OK = 0


class BackupError(RuntimeError):
    exit_code = 1


class Lock:
    def __init__(self, path: Path):
        self.path = path
        self.handle = None

    def __enter__(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.handle = open(self.path, "a", encoding="utf-8")
        try:
            fcntl.flock(self.handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise BackupError("已有备份任务在运行") from exc
        return self

    def __exit__(self, *_args):
        if self.handle:
            fcntl.flock(self.handle.fileno(), fcntl.LOCK_UN)
            self.handle.close()


def canonical(data: dict) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def new_id() -> str:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    return f"xedc-{stamp}-{os.urandom(3).hex()}"


def _disk_free(path: Path) -> int:
    path.mkdir(parents=True, exist_ok=True)
    return shutil.disk_usage(path).free


def _estimate_bytes(paths: list[Path]) -> int:
    total = 0
    for root in paths:
        if not root.exists():
            continue
        if root.is_file():
            total += root.stat().st_size
            continue
        for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
            dirnames[:] = [name for name in dirnames if name not in {".git", "node_modules"}]
            for name in filenames:
                try:
                    total += (Path(dirpath) / name).lstat().st_size
                except OSError:
                    continue
    return total


def _run_low(command: list[str], nice: int, env: dict | None = None) -> None:
    prefix = ["ionice", "-c", "3", "nice", "-n", str(nice)]
    completed = subprocess.run(prefix + command, env=env, text=True, capture_output=True)
    if completed.returncode != 0:
        detail = redact((completed.stderr or completed.stdout or "").strip())
        raise BackupError(f"命令失败 ({completed.returncode}): {detail[:400]}")


def _relative_private(source: Path, private: Path) -> str | None:
    try:
        return str(private.resolve().relative_to(source.resolve()))
    except ValueError:
        return None


def _excluded(relative: str, prefixes: list[str]) -> bool:
    parts = Path(relative).parts
    if not parts:
        return False
    names = set()
    rooted = []
    for item in prefixes:
        token = item.strip("/")
        if not token:
            continue
        if "/" in token:
            rooted.append(token)
        else:
            names.add(token)
    if any(part in names for part in parts):
        return True
    return any(relative == prefix or relative.startswith(prefix + "/") for prefix in rooted)


def _safe_link(path: Path, root: Path) -> bool:
    if not path.is_symlink():
        return True
    target = path.resolve()
    try:
        target.relative_to(root.resolve())
        return True
    except ValueError:
        return False


def archive_tree(root: Path, destination: Path, exclude: list[str], only: dict[str, str] | None = None) -> list[dict]:
    records = []
    root = root.resolve()
    with tarfile.open(destination, "w:gz", compresslevel=6) as archive:
        for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
            current = Path(dirpath)
            relative_dir = str(current.relative_to(root)) if current != root else ""
            kept = []
            for name in dirnames:
                rel = f"{relative_dir}/{name}" if relative_dir else name
                if _excluded(rel, exclude):
                    continue
                kept.append(name)
            dirnames[:] = kept
            for name in filenames:
                rel = f"{relative_dir}/{name}" if relative_dir else name
                if _excluded(rel, exclude):
                    continue
                path = current / name
                if only is not None and rel not in only:
                    continue
                if path.is_symlink() or not _safe_link(path, root):
                    records.append({"path": rel, "type": "skipped-symlink", "sha256": "", "size": 0})
                    continue
                if not path.is_file():
                    continue
                info = archive.gettarinfo(str(path), arcname=rel)
                if info is None or info.isdev() or info.issym():
                    records.append({"path": rel, "type": "skipped-symlink", "sha256": "", "size": 0})
                    continue
                if info.islnk() or not info.isreg():
                    info.type = tarfile.REGTYPE
                    info.linkname = ""
                    info.size = path.stat().st_size
                digest = hashlib.sha256()
                with open(path, "rb") as handle:
                    class _Proxy:
                        def read(self, amount=-1):
                            block = handle.read(amount)
                            digest.update(block)
                            return block
                    archive.addfile(info, _Proxy())
                records.append({"path": rel, "type": "file", "sha256": digest.hexdigest(), "size": info.size})
    return records


def extract_settings(path: Path) -> dict:
    if not path.is_file():
        return {"present": False}
    text = path.read_text(encoding="utf-8", errors="replace")
    import re
    def last(pattern: str) -> str:
        found = list(re.finditer(pattern, text, re.S))
        return found[-1].group(1) if found else ""
    hosts = []
    host_blocks = list(re.finditer(r"\$settings\['trusted_host_patterns'\]\s*=\s*\[(.*?)\];", text, re.S))
    if host_blocks:
        hosts = re.findall(r"'([^']+)'", host_blocks[-1].group(1))
    return {
        "present": True,
        "database": last(r"'database'\s*=>\s*'([^']*)'"),
        "username": last(r"'username'\s*=>\s*'([^']*)'"),
        "password": last(r"'password'\s*=>\s*'([^']*)'"),
        "host": last(r"'host'\s*=>\s*'([^']*)'"),
        "port": last(r"'port'\s*=>\s*'([^']*)'"),
        "hash_salt": last(r"\$settings\['hash_salt'\]\s*=\s*'([^']*)'"),
        "private_path": last(r"\$settings\['file_private_path'\]\s*=\s*'([^']*)'"),
        "trusted_host_patterns": hosts,
    }


def public_storage_note(private_root: Path) -> dict:
    active = private_root / "xedc-storage-runtime" / "active.json"
    if not active.is_file():
        return {"present": False}
    try:
        data = json.loads(active.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {"present": True, "readable": False}
    allowed = ("version", "public_mode", "public_provider", "cdn_enabled", "cdn_base", "writes_paused", "status", "read_policy")
    return {"present": True, "readable": True, **{key: data.get(key) for key in allowed}}


def dump_database(config: dict, destination: Path, allow_live_read: bool) -> dict:
    database = config["database"]
    mode = database.get("mode", "mysqldump")
    if mode == "skip":
        destination.write_text("-- xedc-recover skipped database\n", encoding="utf-8")
        return {"mode": "skip", "name": ""}
    if mode == "fixture":
        source = Path(database["fixture_sql"])
        if not source.is_file():
            raise BackupError("找不到测试 SQL 文件")
        with open(source, "rb") as handle, gzip.open(destination, "wb", compresslevel=6) as output:
            shutil.copyfileobj(handle, output)
        return {"mode": "fixture", "name": database.get("name", "fixture")}
    name = database.get("name", "")
    if not name or not re_safe_ident(name):
        raise BackupError("数据库名无效")
    if production_webroot_present() and name == "drupal" and not allow_live_read:
        raise ProductionGuard("读取生产数据库需要显式 --allow-live-read")
    port = int(database.get("port") or 3306)
    socket_path = database.get("socket") or ""
    # Dump is read-only. A socket dump of the live database is allowed only with --allow-live-read.
    if socket_path and production_webroot_present() and not allow_live_read:
        raise ProductionGuard("读取生产数据库套接字需要显式 --allow-live-read")
    defaults = destination.parent / "client.cnf"
    user = database.get("user") or ""
    password = env_secret(database.get("password_env") or "")
    lines = ["[client]"]
    if user:
        lines.append(f"user={user}")
    if password:
        lines.append(f"password={password}")
    if socket_path:
        lines.append(f"socket={socket_path}")
    else:
        lines.append(f"host={database.get('host') or '127.0.0.1'}")
        lines.append(f"port={port}")
    defaults.write_text("\n".join(lines) + "\n", encoding="utf-8")
    os.chmod(defaults, 0o600)
    command = [
        "mysqldump",
        f"--defaults-extra-file={defaults}",
        "--single-transaction",
        "--routines",
        "--triggers",
        "--default-character-set=utf8mb4",
        "--hex-blob",
        "--no-tablespaces",
        name,
    ]
    raw = destination.with_suffix(".sql.tmp")
    counts = {}
    try:
        with open(raw, "wb") as handle:
            prefix = ["ionice", "-c", "3", "nice", "-n", str(config.get("nice", 19))]
            completed = subprocess.run(prefix + command, stdout=handle, stderr=subprocess.PIPE)
        if completed.returncode != 0:
            raise BackupError(redact(completed.stderr.decode("utf-8", "replace")[:400]))
        with open(raw, "rb") as source, gzip.open(destination, "wb", compresslevel=6) as output:
            shutil.copyfileobj(source, output)
        count_cmd = prefix + [
            "mariadb",
            f"--defaults-extra-file={defaults}",
            "-N",
            "-e",
            "SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'node', COUNT(*) FROM node UNION ALL SELECT 'file_managed', COUNT(*) FROM file_managed",
            name,
        ]
        counted = subprocess.run(count_cmd, text=True, capture_output=True)
        if counted.returncode == 0:
            for line in counted.stdout.splitlines():
                bits = line.split()
                if len(bits) == 2 and bits[1].isdigit():
                    counts[bits[0]] = int(bits[1])
    finally:
        defaults.unlink(missing_ok=True)
        raw.unlink(missing_ok=True)
    return {"mode": "mysqldump", "name": name, "host": database.get("host") or "socket", "port": port, "counts": counts}


def re_safe_ident(value: str) -> bool:
    import re
    return re.fullmatch(r"[A-Za-z0-9_]+", value) is not None


def _load_manifest(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def resolve_file_map(backup_root: Path, backup_id: str) -> dict[str, str]:
    chain = []
    seen = set()
    current = backup_id
    while current:
        if current in seen:
            raise BackupError("增量备份链出现循环")
        seen.add(current)
        manifest_path = backup_root / current / "manifest.json"
        if not manifest_path.is_file():
            raise BackupError(f"备份链缺少 {current}")
        manifest = _load_manifest(manifest_path)
        chain.append(manifest)
        current = manifest.get("parent_id") or ""
    chain.reverse()
    files: dict[str, str] = {}
    for manifest in chain:
        for record in manifest.get("files", []):
            if record.get("type") == "file":
                files[record["path"]] = record["sha256"]
        for deleted in manifest.get("deleted", []):
            files.pop(deleted, None)
    return files


def create_backup(config: dict, *, backup_type: str = "full", dry_run: bool = False, allow_live_read: bool = False, resume: bool = False, backup_id: str = "", label: str = "") -> dict:
    if backup_type not in ("full", "incremental"):
        raise BackupError("备份类型只能是 full 或 incremental")
    root = assert_backup_destination(config["backup_root"])
    source = Path(config["source_root"]).resolve()
    if not source.is_dir():
        raise BackupError(f"源目录不存在: {source}")
    private = Path(config["private_files"])
    public_rel = config["public_files"]
    exclude = list(config.get("exclude") or [])
    exclude.append(public_rel.strip("/"))
    private_rel = _relative_private(source, private) if private.exists() else None
    if private_rel:
        exclude.append(private_rel)
    paths = [source]
    if private.exists():
        paths.append(private)
    public_path = source / public_rel
    if public_path.exists():
        paths.append(public_path)
    estimate = _estimate_bytes(paths)
    free = _disk_free(root)
    if free < int(estimate * 2.2) + 512 * 1024 * 1024:
        raise BackupError(f"磁盘空间不足。估计源数据 {estimate} 字节，可用 {free} 字节")
    parent_id = ""
    previous_map: dict[str, str] = {}
    if backup_type == "incremental":
        parent_id = latest_backup_id(root)
        if not parent_id:
            raise BackupError("没有可用的完整备份，不能创建增量备份")
        previous_map = resolve_file_map(root, parent_id)
    if dry_run:
        return {
            "dry_run": True,
            "type": backup_type,
            "source": str(source),
            "estimate_bytes": estimate,
            "free_bytes": free,
            "parent_id": parent_id,
            "would_write": str(root),
        }
    key_id, key = load_key(config["key_file"])
    backup_id = backup_id or new_id()
    directory = assert_backup_destination(root / backup_id)
    directory.mkdir(parents=True, exist_ok=True)
    os.chmod(directory, 0o700)
    payload = directory / "payload"
    payload.mkdir(mode=0o700, exist_ok=True)
    state_path = directory / "state.json"
    state = json.loads(state_path.read_text(encoding="utf-8")) if resume and state_path.is_file() else {"steps": {}}
    lock_path = root / ".lock"
    with Lock(lock_path):
        def done(step: str, **info):
            state["steps"][step] = {"status": "done", **info}
            state_path.write_text(canonical(state), encoding="utf-8")

        def step_done(step: str) -> bool:
            return resume and state["steps"].get(step, {}).get("status") == "done"

        plain_dir = directory / "plain"
        plain_dir.mkdir(mode=0o700, exist_ok=True)
        db_plain = plain_dir / "database.sql.gz"
        if not step_done("database"):
            meta = dump_database(config, db_plain, allow_live_read)
            db_sha = encrypt_file(db_plain, payload / "database.sql.gz.enc", key, key_id)
            db_plain.unlink(missing_ok=True)
            done("database", sha256=db_sha, meta=meta)
        code_records: list[dict] = []
        public_records: list[dict] = []
        private_records: list[dict] = []
        deleted: list[str] = []
        if not step_done("files"):
            only = None
            current_map = {}
            # Hash by archiving once. Incremental filters after a lightweight walk.
            if backup_type == "incremental":
                current_map = _hash_tree(source, exclude)
                if public_path.exists():
                    current_map.update({f"public/{k}": v for k, v in _hash_tree(public_path, []).items()})
                if private.exists():
                    current_map.update({f"private/{k}": v for k, v in _hash_tree(private, ["xedc-storage-keys"]).items()})
                    # Keys are required for a real replacement backup, so include them in the map.
                    current_map.update({f"private/{k}": v for k, v in _hash_tree(private / "xedc-storage-keys", []).items()}) if (private / "xedc-storage-keys").exists() else None
                changed = {path: digest for path, digest in current_map.items() if previous_map.get(path) != digest}
                deleted = sorted(path for path in previous_map if path not in current_map)
                only = changed
            code_records = archive_tree(source, plain_dir / "code.tar.gz", exclude, _strip_prefix(only, "") if only is not None else None)
            code_sha = encrypt_file(plain_dir / "code.tar.gz", payload / "code.tar.gz.enc", key, key_id)
            (plain_dir / "code.tar.gz").unlink(missing_ok=True)
            public_records = []
            if public_path.exists():
                public_only = _strip_prefix(only, "public/") if only is not None else None
                public_records = archive_tree(public_path, plain_dir / "public.tar.gz", [], public_only)
                public_sha = encrypt_file(plain_dir / "public.tar.gz", payload / "public.tar.gz.enc", key, key_id)
                (plain_dir / "public.tar.gz").unlink(missing_ok=True)
            else:
                public_sha = ""
            private_records = []
            private_sha = ""
            if private.exists():
                private_only = _strip_prefix(only, "private/") if only is not None else None
                private_records = archive_tree(private, plain_dir / "private.tar.gz", [], private_only)
                private_sha = encrypt_file(plain_dir / "private.tar.gz", payload / "private.tar.gz.enc", key, key_id)
                (plain_dir / "private.tar.gz").unlink(missing_ok=True)
            done("files", code_sha256=code_sha, public_sha256=public_sha, private_sha256=private_sha, deleted=deleted, code_records=code_records, public_records=public_records, private_records=private_records)
        else:
            code_sha = state["steps"]["files"]["code_sha256"]
            public_sha = state["steps"]["files"]["public_sha256"]
            private_sha = state["steps"]["files"]["private_sha256"]
            deleted = state["steps"]["files"].get("deleted", [])
            code_records = state["steps"]["files"].get("code_records", [])
            public_records = state["steps"]["files"].get("public_records", [])
            private_records = state["steps"]["files"].get("private_records", [])
        if not step_done("secrets"):
            settings = extract_settings(source / "web/sites/default/settings.php")
            secret_doc = {
                "settings": settings,
                "storage": public_storage_note(private) if private.exists() else {"present": False},
            }
            secret_path = plain_dir / "secrets.json"
            secret_path.write_text(canonical(secret_doc), encoding="utf-8")
            os.chmod(secret_path, 0o600)
            secret_sha = encrypt_file(secret_path, payload / "secrets.json.enc", key, key_id)
            secret_path.unlink(missing_ok=True)
            done("secrets", sha256=secret_sha, trusted_hosts=settings.get("trusted_host_patterns", []), storage_mode=(secret_doc["storage"].get("public_mode") if isinstance(secret_doc["storage"], dict) else ""))
        secret_sha = state["steps"]["secrets"]["sha256"]
        files = []
        for prefix, records in (("", code_records), ("public/", public_records), ("private/", private_records)):
            for record in records:
                item = dict(record)
                if prefix and item.get("path"):
                    item["path"] = prefix + item["path"]
                files.append(item)
        db_meta = state["steps"]["database"].get("meta", {})
        manifest = {
            "schema": 1,
            "id": backup_id,
            "type": backup_type,
            "parent_id": parent_id,
            "created": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ"),
            "tool_version": __version__,
            "label": label,
            "strategy": config.get("strategy", "pinned"),
            "source_root": str(source),
            "database": {k: v for k, v in db_meta.items() if k != "password"},
            "encryption": {"alg": "AES-256-GCM", "key_id": key_id},
            "artifacts": {
                "database": {"file": "payload/database.sql.gz.enc", "plain_sha256": state["steps"]["database"]["sha256"]},
                "code": {"file": "payload/code.tar.gz.enc", "plain_sha256": code_sha},
                "public": {"file": "payload/public.tar.gz.enc", "plain_sha256": public_sha},
                "private": {"file": "payload/private.tar.gz.enc", "plain_sha256": private_sha},
                "secrets": {"file": "payload/secrets.json.enc", "plain_sha256": secret_sha},
            },
            "files": files,
            "deleted": deleted,
            "storage": {"mode": state["steps"]["secrets"].get("storage_mode", ""), "credentials_in_backup": True},
            "trusted_hosts": state["steps"]["secrets"].get("trusted_hosts", []),
            "restore_steps": [
                "verify",
                "decrypt",
                "extract code, public files, private files",
                "create an empty database and user",
                "import database.sql.gz",
                "keep hash_salt, rewrite database credentials",
                "install pinned PHP, Nginx, MariaDB and media tools",
                "configure Nginx and permissions",
                "health check",
            ],
        }
        for name, artifact in manifest["artifacts"].items():
            artifact_path = directory / artifact["file"]
            if artifact["plain_sha256"] and artifact_path.is_file():
                artifact["cipher_sha256"] = sha256_file(artifact_path)
                artifact["size"] = artifact_path.stat().st_size
            elif not artifact["plain_sha256"]:
                artifact["cipher_sha256"] = ""
                artifact["size"] = 0
        manifest_path = directory / "manifest.json"
        manifest_path.write_text(canonical(manifest), encoding="utf-8")
        (directory / "manifest.sha256").write_text(sha256_file(manifest_path) + "\n", encoding="utf-8")
        shutil.rmtree(plain_dir, ignore_errors=True)
        state["status"] = "complete"
        state_path.write_text(canonical(state), encoding="utf-8")
        os.chmod(directory, 0o700)
        return {"id": backup_id, "path": str(directory), "type": backup_type, "parent_id": parent_id}


def _strip_prefix(only: dict[str, str] | None, prefix: str) -> dict[str, str] | None:
    if only is None:
        return None
    if not prefix:
        return {path: digest for path, digest in only.items() if not path.startswith("public/") and not path.startswith("private/")}
    return {path[len(prefix):]: digest for path, digest in only.items() if path.startswith(prefix)}


def _hash_tree(root: Path, exclude: list[str]) -> dict[str, str]:
    hashes = {}
    if not root.exists():
        return hashes
    root = root.resolve()
    for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
        current = Path(dirpath)
        relative_dir = str(current.relative_to(root)) if current != root else ""
        dirnames[:] = [name for name in dirnames if not _excluded(f"{relative_dir}/{name}" if relative_dir else name, exclude)]
        for name in filenames:
            rel = f"{relative_dir}/{name}" if relative_dir else name
            if _excluded(rel, exclude):
                continue
            path = current / name
            if path.is_file() and not path.is_symlink():
                hashes[rel] = sha256_file(path)
    return hashes


def latest_backup_id(root: Path) -> str:
    items = list_backups(root)
    return items[0]["id"] if items else ""


def list_backups(root: Path) -> list[dict]:
    root = Path(root)
    found = []
    if not root.is_dir():
        return found
    for child in root.iterdir():
        manifest = child / "manifest.json"
        if manifest.is_file():
            data = _load_manifest(manifest)
            found.append({"id": data.get("id", child.name), "type": data.get("type"), "created": data.get("created"), "parent_id": data.get("parent_id", "")})
    found.sort(key=lambda item: item.get("created") or "", reverse=True)
    return found


def show_backup(root: Path, backup_id: str) -> dict:
    path = Path(root) / backup_id / "manifest.json"
    if not path.is_file():
        raise BackupError(f"找不到备份 {backup_id}")
    return _load_manifest(path)


def verify_backup(config: dict, backup_id: str, deep: bool = True) -> dict:
    root = Path(config["backup_root"])
    directory = root / backup_id
    manifest_path = directory / "manifest.json"
    if not manifest_path.is_file():
        raise BackupError(f"找不到备份 {backup_id}")
    recorded = (directory / "manifest.sha256").read_text(encoding="utf-8").strip()
    actual = sha256_file(manifest_path)
    if recorded != actual:
        raise BackupError("manifest 校验失败")
    manifest = _load_manifest(manifest_path)
    if manifest.get("parent_id"):
        resolve_file_map(root, backup_id)
    checked = []
    key = None
    key_id = ""
    if deep:
        key_id, key = load_key(config["key_file"])
        if key_id != manifest["encryption"]["key_id"]:
            raise BackupError("密钥编号与 manifest 不一致")
    for name, artifact in manifest["artifacts"].items():
        if not artifact.get("plain_sha256"):
            continue
        path = directory / artifact["file"]
        if not path.is_file():
            raise BackupError(f"缺少备份文件 {artifact['file']}")
        cipher = sha256_file(path)
        if cipher != artifact.get("cipher_sha256"):
            raise BackupError(f"{name} 密文校验失败")
        if deep and key is not None:
            plain_dir = Path(tempfile.mkdtemp(prefix="xedc-verify-"))
            plain = plain_dir / (Path(artifact["file"]).name + ".plain")
            try:
                digest = decrypt_file(path, plain, key, key_id)
                if digest != artifact["plain_sha256"]:
                    raise BackupError(f"{name} 明文校验失败")
            finally:
                shutil.rmtree(plain_dir, ignore_errors=True)
        checked.append(name)
    return {"id": backup_id, "status": "PASS", "checked": checked, "parent_id": manifest.get("parent_id", "")}


def prune_backups(config: dict, *, dry_run: bool = False) -> dict:
    root = assert_backup_destination(config["backup_root"])
    daily = int(config.get("retention", {}).get("daily", 7))
    weekly = int(config.get("retention", {}).get("weekly", 4))
    items = list_backups(root)
    keep = set()
    daily_seen = 0
    weekly_seen = set()
    for item in items:
        created = item.get("created") or ""
        day = created[:10]
        week = created[:4] + "-" + created[5:7] + "-" + created[8:10]
        if daily_seen < daily:
            keep.add(item["id"])
            daily_seen += 1
        if week not in weekly_seen and len(weekly_seen) < weekly:
            weekly_seen.add(week)
            keep.add(item["id"])
    # Keep every ancestor of a kept backup so incremental chains stay valid.
    changed = True
    while changed:
        changed = False
        for item in items:
            if item["id"] in keep and item.get("parent_id") and item["parent_id"] not in keep:
                keep.add(item["parent_id"])
                changed = True
    removed = []
    for item in items:
        if item["id"] in keep:
            continue
        target = root / item["id"]
        removed.append(item["id"])
        if not dry_run:
            shutil.rmtree(target)
    return {"kept": sorted(keep), "removed": removed, "dry_run": dry_run}
