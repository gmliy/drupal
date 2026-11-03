"""One command to back up, and one command to restore.

xedc-backup writes one tar.zst locally and can upload that same file to R2.
New packages have no separate decryption key. Old AES packages still need
primary.key. Neither command prints secret values.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.parse
from datetime import datetime, timezone
from pathlib import Path

from . import __version__
from .backup import BackupError, Lock, create_backup, new_id, verify_backup
from .bundle import create_full_package, install_bundle, pack_backup, verify_package
from .catalog import (
    assert_archive_manifest,
    delete_local_package,
    find_local_package,
    list_local_packages,
    package_directory,
    package_needs_legacy_key,
    write_meta,
)
from .config import load_config
from .crypto import CryptoError
from .gitmeta import collect_identity
from .redact import redact
from .remote import client_from_config, download_package, upload_package
from .s3 import S3Error
from .safety import ProductionGuard, production_webroot_present

_DOMAIN = re.compile(
    r"^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$"
)


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _human(size: int) -> str:
    value = float(size)
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if value < 1024 or unit == "TB":
            return f"{value:.1f} {unit}"
        value /= 1024
    return f"{size} B"


def load_runtime_config(path: str | None) -> dict:
    if path:
        return load_config(path)
    default = Path("/var/lib/xedc-recover/config.json")
    if default.is_file():
        return load_config(str(default))
    return load_config(None)


def _log(config: dict, message: str) -> None:
    text = redact(message)
    print(text, file=sys.stderr)
    try:
        path = Path(config["backup_root"]).parent / "logs" / "xedc-backup.log"
        path.parent.mkdir(parents=True, exist_ok=True)
        with open(path, "a", encoding="utf-8") as handle:
            handle.write(f"{_now()} {text}\n")
        os.chmod(path, 0o600)
    except OSError:
        return


def _os_release() -> dict:
    path = Path("/etc/os-release")
    info = {}
    if not path.is_file():
        return info
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        info[key] = value.strip().strip('"')
    return info


def _memory_available() -> int:
    info = Path("/proc/meminfo")
    if not info.is_file():
        return 0
    for line in info.read_text(encoding="utf-8", errors="replace").splitlines():
        if line.startswith("MemAvailable:"):
            return int(line.split()[1]) * 1024
    return 0


def assert_remote_safe(remote: dict) -> None:
    prefix = str(remote.get("prefix") or "")
    if not prefix.endswith("/"):
        prefix += "/"
    if not prefix.startswith("xedc-recover/") or ".." in prefix.split("/"):
        raise BackupError("远端前缀必须位于 xedc-recover/ 下，不能使用网站媒体前缀")
    if any(part in {"public", "private"} for part in prefix.split("/") if part):
        raise BackupError("远端前缀不能包含 public 或 private")
    remote["prefix"] = prefix
    endpoint = str(remote.get("endpoint") or "")
    parsed = urllib.parse.urlparse(endpoint)
    host = parsed.hostname or ""
    if parsed.scheme == "http" and host in {"127.0.0.1", "localhost"}:
        return
    if parsed.scheme == "https" and host.endswith(".r2.cloudflarestorage.com"):
        return
    raise BackupError("对象存储只接受 Cloudflare R2，或 127.0.0.1 上的隔离测试服务")


def prepare_remote(config: dict, *, required: bool) -> dict:
    remote = dict(config.get("remote") or {})
    access_env = remote.get("access_key_env") or "XEDC_S3_ACCESS_KEY"
    secret_env = remote.get("secret_key_env") or "XEDC_S3_SECRET_KEY"
    has_keys = bool(os.environ.get(access_env) or remote.get("access_key")) and bool(os.environ.get(secret_env) or remote.get("secret_key"))
    if remote.get("endpoint") and remote.get("bucket") and has_keys:
        remote["enabled"] = True
        remote.setdefault("prefix", "xedc-recover/")
        remote.setdefault("region", "auto")
        remote.setdefault("path_style", True)
        remote["timeout"] = int(remote.get("timeout") or 120)
        remote["retries"] = int(remote.get("retries") or 5)
        assert_remote_safe(remote)
        config["remote"] = remote
        return remote
    private = Path(config.get("private_files") or "")
    discovered = None
    root = private / "xedc-storage-keys"
    if root.is_dir():
        for runtime in sorted(root.glob("*/runtime.json")):
            try:
                data = json.loads(runtime.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if data.get("provider") == "cloudflare_r2" and data.get("bucket") and data.get("endpoint"):
                discovered = (runtime.parent, data)
                break
    if discovered is None:
        if not required:
            return {}
        raise BackupError("没有 R2 配置。请设置环境变量 XEDC_S3_ACCESS_KEY 和 XEDC_S3_SECRET_KEY，并在配置中填写 endpoint 与 bucket。备份只会写入 xedc-recover/ 前缀。")
    folder, data = discovered
    access_path = folder / "access_key"
    secret_path = folder / "secret_key"
    if not access_path.is_file() or not secret_path.is_file():
        raise BackupError("找到了 R2 桶配置，但没有读到访问密钥文件。")
    os.environ["XEDC_S3_ACCESS_KEY"] = access_path.read_text(encoding="utf-8").strip()
    os.environ["XEDC_S3_SECRET_KEY"] = secret_path.read_text(encoding="utf-8").strip()
    remote = {
        "enabled": True,
        "endpoint": str(data["endpoint"]),
        "bucket": str(data["bucket"]),
        "region": str(data.get("region") or "auto"),
        "prefix": "xedc-recover/",
        "access_key_env": "XEDC_S3_ACCESS_KEY",
        "secret_key_env": "XEDC_S3_SECRET_KEY",
        "path_style": True,
        "timeout": 120,
        "retries": 5,
    }
    assert_remote_safe(remote)
    config["remote"] = remote
    return remote


def _names(path: Path) -> list[str]:
    if not path.is_dir():
        return []
    return sorted(child.name for child in path.iterdir() if child.is_dir() and not child.name.startswith("."))


def preflight(config: dict) -> dict:
    os_info = _os_release()
    system_id = os_info.get("ID", "")
    if system_id not in {"ubuntu", "debian"}:
        raise BackupError(f"当前系统不受支持: {system_id or 'unknown'}")
    source = Path(config["source_root"])
    if not (source / "composer.json").is_file() or not (source / "web/sites/default/settings.php").is_file():
        raise BackupError(f"这不是可备份的 Drupal 项目目录: {source}")
    memory = _memory_available()
    if memory and memory < 512 * 1024 * 1024:
        raise BackupError("可用内存低于 512 MB，拒绝开始备份")
    disk = shutil.disk_usage(config["backup_root"] if Path(config["backup_root"]).exists() else "/")
    identity = collect_identity(source)
    database = config.get("database") or {}
    db_status = "skipped"
    if database.get("mode", "mysqldump") == "mysqldump":
        db_status = _probe_database(config)
    site = _probe_site()
    return {
        "os": os_info.get("PRETTY_NAME") or system_id,
        "source": str(source),
        "disk_free": disk.free,
        "memory_available": memory,
        "database": db_status,
        "site": site,
        "modules": _names(source / "web/modules/custom"),
        "themes": _names(source / "web/themes/custom"),
        "git": {key: identity.get(key) for key in ("commit", "branch", "remote", "dirty", "dirty_entries")},
        "drupal_version": identity.get("drupal_version") or "",
        "php_version": identity.get("php_version") or "",
        "composer_lock_sha256": identity.get("composer_lock_sha256") or "",
    }


def _probe_database(config: dict) -> str:
    from .workspace import work_root
    database = config["database"]
    defaults = work_root(config) / "db-probe.cnf"
    lines = ["[client]"]
    if database.get("user"):
        lines.append(f"user={database['user']}")
    password = os.environ.get(database.get("password_env") or "", "")
    if password:
        lines.append(f"password={password}")
    if database.get("socket"):
        lines.append(f"socket={database['socket']}")
    else:
        lines.append(f"host={database.get('host') or '127.0.0.1'}")
        lines.append(f"port={int(database.get('port') or 3306)}")
    defaults.write_text("\n".join(lines) + "\n", encoding="utf-8")
    os.chmod(defaults, 0o600)
    try:
        completed = subprocess.run(
            ["mariadb", f"--defaults-extra-file={defaults}", "-N", "-e", "SELECT 1", database.get("name") or ""],
            text=True,
            capture_output=True,
            timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise BackupError(f"数据库连接检查失败: {exc.__class__.__name__}") from exc
    finally:
        defaults.unlink(missing_ok=True)
    if completed.returncode != 0:
        raise BackupError("数据库连接检查失败。没有读取或修改数据。")
    return "connected"


def _probe_site() -> dict:
    result = {}
    checks = (
        ("public", ["curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "15", "https://gitxx.xyz/"]),
        ("local", ["curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "10", "-H", "Host: gitxx.xyz", "http://127.0.0.1/"]),
    )
    for name, command in checks:
        try:
            completed = subprocess.run(command, text=True, capture_output=True, timeout=20)
            result[name] = (completed.stdout or "").strip()[:3]
        except (OSError, subprocess.TimeoutExpired):
            result[name] = ""
    return result


def _state_path(config: dict) -> Path:
    return Path(config["backup_root"]).parent / "oneshot-state.json"


def _read_state(config: dict) -> dict:
    path = _state_path(config)
    if not path.is_file():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def _write_state(config: dict, state: dict) -> None:
    path = _state_path(config)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.chmod(path, 0o600)


def _verify_download(config: dict, archive: Path) -> dict:
    from .bundle import _extract_package, _install_material
    from .workspace import WorkspaceError, assert_disk, make_work_dir

    identity = assert_archive_manifest(archive, config)
    digest = identity["sha256"]
    try:
        assert_disk(Path(config["backup_root"]), archive.stat().st_size * 3, "校验备份")
    except WorkspaceError as exc:
        raise BackupError(str(exc)) from exc
    stage = make_work_dir(config, "roundtrip")
    try:
        _extract_package(archive, stage)
        material = stage / "material"
        material.mkdir()
        backup_id = _install_material(stage, material)
        checked = verify_backup({**config, "backup_root": str(material)}, backup_id)
        if checked.get("status") != "PASS":
            raise BackupError("备份包完整性校验没有通过")
        encrypted = identity.get("encryption") not in ("", "none")
        result = {
            "sha256": digest,
            "backup_id": backup_id,
            "integrity": "PASS",
            "decrypt": "PASS" if encrypted else "not-required",
            "encryption": identity.get("encryption") or "",
            "manifest_sha256": identity.get("manifest_sha256") or "",
            "git_commit": identity.get("git_commit") or "",
            "drupal_version": identity.get("drupal_version") or "",
            "artifacts": identity.get("artifacts") or {},
        }
        shutil.rmtree(stage, ignore_errors=True)
        return result
    except Exception:
        raise


def _mode_name(mode: str) -> str:
    if mode not in {"both", "local", "r2"}:
        raise BackupError("备份模式只能是 both、local 或 r2")
    return mode


def _publish_remote(config: dict, archive: Path, local_digest: str) -> dict:
    from .workspace import download_root
    uploaded = upload_package(config, str(archive))
    incoming = download_root(config) / "roundtrip"
    if incoming.exists():
        shutil.rmtree(incoming)
    try:
        fetched = download_package(config, archive.name, str(incoming))
        if fetched["sha256"] != local_digest:
            raise BackupError("从对象存储下载的 SHA256 与本地不一致，不标记成功")
        verified = _verify_download(config, Path(fetched["archive"]))
        if verified["sha256"] != local_digest:
            raise BackupError("远端校验值与本地备份不一致")
        return {"uploaded": uploaded, "verified": verified, "incoming": incoming}
    except Exception:
        shutil.rmtree(incoming, ignore_errors=True)
        raise


def run_backup(config: dict, *, dry_run: bool = False, resume: bool = False, output: str = "", mode: str = "both") -> dict:
    started = time.time()
    mode = _mode_name(mode)
    Path(config["backup_root"]).mkdir(parents=True, exist_ok=True)
    os.chmod(Path(config["backup_root"]), 0o700)
    report = preflight(config)
    _log(config, f"系统 {report['os']}，磁盘可用 {_human(report['disk_free'])}，内存可用 {_human(report['memory_available'])}")
    if report["disk_free"] < 8 * 1024 * 1024 * 1024:
        _log(config, f"磁盘空间预警：可用 {_human(report['disk_free'])}。空间不足以容纳完整备份时会拒绝写入。")
    _log(config, f"数据库 {report['database']}，Drupal {report['drupal_version'] or '未知'}，提交 {report['git'].get('commit') or '无'}")
    if dry_run:
        planned = create_backup(config, dry_run=True, allow_live_read=False, client_encryption=False)
        report.update({"status": "DRY-RUN", "planned": planned, "mode": mode, "remote_ready": mode == "local"})
        return report
    long_term = Path(output) if output else package_directory(config)
    long_term.mkdir(parents=True, exist_ok=True)
    os.chmod(long_term, 0o700)
    from .workspace import work_root
    output_dir = work_root(config) / "r2-only" if mode == "r2" else long_term
    output_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(output_dir, 0o700)
    state = _read_state(config) if resume else {}
    live = production_webroot_present() and Path(config["source_root"]).resolve() == Path("/var/www/drupal").resolve()
    lock = Lock(Path(config["backup_root"]) / ".oneshot.lock")
    with lock:
        if state.get("backup_id") and not state.get("packed"):
            _log(config, f"继续未完成的备份 {state['backup_id']}")
            created = create_backup(
                config,
                allow_live_read=live,
                resume=True,
                backup_id=state["backup_id"],
                label="xedc-backup",
                client_encryption=False,
            )
            backup_id = created["id"]
        elif state.get("packed") and state.get("backup_id") and state.get("archive") and Path(state["archive"]).is_file():
            backup_id = state["backup_id"]
            _log(config, f"继续已打包的备份 {backup_id}")
        else:
            backup_id = new_id()
            _write_state(config, {"backup_id": backup_id, "started": _now(), "packed": False, "mode": mode})
            _log(config, f"开始完整备份 {backup_id}")
            created = create_backup(config, allow_live_read=live, backup_id=backup_id, label="xedc-backup", client_encryption=False)
            backup_id = created["id"]
        if not (state.get("packed") and state.get("archive") and Path(state.get("archive", "")).is_file()):
            _log(config, "生成本地完整备份包并计算 SHA256")
            packed = pack_backup(config, backup_id, str(output_dir))
            state = {"backup_id": backup_id, "packed": True, "archive": packed["archive"], "sha256": packed["sha256"], "mode": mode}
            _write_state(config, state)
        else:
            packed = {"archive": state["archive"], "sha256": state["sha256"], "backup_id": backup_id, "bytes": Path(state["archive"]).stat().st_size}
        archive = Path(packed["archive"])
        local_checked = _verify_download(config, archive)
        local_digest = local_checked["sha256"]
        if local_digest != packed["sha256"]:
            raise BackupError("本地恢复包校验失败，没有上传")
        directory = Path(config["backup_root"]) / backup_id
        if directory.is_dir():
            shutil.rmtree(directory)
        meta = {
            "backup_id": backup_id,
            "archive": archive.name,
            "sha256": local_digest,
            "bytes": archive.stat().st_size,
            "created": _now(),
            "r2": "skipped" if mode == "local" else "pending",
            "git_commit": (report.get("git") or {}).get("commit") or "",
            "drupal_version": report.get("drupal_version") or "",
            "encryption": "none",
            "manifest_sha256": local_checked.get("manifest_sha256") or "",
        }
        write_meta(archive, meta)
        uploaded_keys: list[str] = []
        verified = {"integrity": "SKIPPED"}
        remote_prefix = ""
        bucket = ""
        remote_error = ""
        remote_prune = {"kept": [], "removed": [], "error": ""}
        status = "PASS"
        if mode != "local":
            try:
                remote = prepare_remote(config, required=True)
                remote_prefix = remote["prefix"]
                bucket = remote["bucket"]
                _log(config, f"上传 {_human(archive.stat().st_size)} 到 {bucket} {remote_prefix}")
                published = _publish_remote(config, archive, local_digest)
                verified = published["verified"]
                if verified.get("backup_id") != backup_id or verified.get("sha256") != local_digest:
                    raise BackupError("本地备份与 R2 备份的编号或 SHA256 不一致")
                if verified.get("git_commit") != local_checked.get("git_commit") or verified.get("drupal_version") != local_checked.get("drupal_version"):
                    raise BackupError("本地备份与 R2 备份的版本记录不一致")
                if verified.get("manifest_sha256") != local_checked.get("manifest_sha256"):
                    raise BackupError("本地备份与 R2 备份的 manifest 不一致")
                uploaded_keys = [item["key"] for item in published["uploaded"]["uploaded"]]
                meta["r2"] = "uploaded"
                meta["r2_key"] = f"{remote_prefix}packages/{archive.name}"
                write_meta(archive, meta)
                shutil.rmtree(published["incoming"], ignore_errors=True)
                _log(config, "R2 下载校验通过")
            except (BackupError, S3Error) as exc:
                remote_error = explain_storage_error(exc)
                meta["r2"] = "failed"
                meta["r2_error"] = remote_error
                write_meta(archive, meta)
                status = "PARTIAL" if mode == "both" else "FAIL"
                _log(config, f"R2 失败，本地文件保留在 {archive}。{remote_error}")
        if status == "PASS" and mode == "r2":
            for path in (archive, Path(str(archive) + ".sha256"), Path(str(archive) + ".meta.json")):
                path.unlink(missing_ok=True)
            if output_dir.is_dir() and output_dir.name == "r2-only":
                shutil.rmtree(output_dir, ignore_errors=True)
            archive_text = ""
        else:
            archive_text = str(archive)
        prune = {"removed": [], "kept": [], "automatic": False}
        remote_prune = {"kept": [], "removed": [], "automatic": False}
        _write_state(config, {"backup_id": backup_id, "packed": True, "archive": archive_text, "status": status, "finished": _now(), "mode": mode})
    return {
        "status": status,
        "mode": mode,
        "id": backup_id,
        "archive": archive_text,
        "bytes": meta["bytes"],
        "sha256": local_digest,
        "local_integrity": "PASS",
        "remote_prefix": remote_prefix,
        "bucket": bucket,
        "r2_key": meta.get("r2_key") or "",
        "r2_error": remote_error,
        "uploaded": uploaded_keys,
        "roundtrip": verified,
        "key_included": False,
        "encryption": "none",
        "git": report["git"],
        "drupal_version": report["drupal_version"],
        "php_version": report["php_version"],
        "composer_lock_sha256": report["composer_lock_sha256"],
        "modules": report["modules"],
        "themes": report["themes"],
        "database": report["database"],
        "site": report["site"],
        "os": report["os"],
        "prune": prune,
        "remote_prune": remote_prune,
        "elapsed_seconds": round(time.time() - started, 1),
        "finished": _now(),
    }


def upload_existing(config: dict, spec: str) -> dict:
    item = find_local_package(config, spec)
    archive = Path(item["path"])
    before = archive.stat().st_mtime_ns
    identity = assert_archive_manifest(archive, config)
    prepare_remote(config, required=True)
    published = _publish_remote(config, archive, identity["sha256"])
    verified = published["verified"]
    if verified.get("sha256") != identity["sha256"] or verified.get("backup_id") != identity["backup_id"]:
        raise BackupError("重新上传后的远端备份与本地不一致")
    if archive.stat().st_mtime_ns != before:
        raise BackupError("重新上传改动了本地备份文件")
    meta = dict(item)
    meta["r2"] = "uploaded"
    meta["r2_key"] = f"{config['remote']['prefix']}packages/{archive.name}"
    meta["sha256"] = identity["sha256"]
    write_meta(archive, meta)
    shutil.rmtree(published["incoming"], ignore_errors=True)
    return {
        "status": "PASS",
        "id": identity["backup_id"],
        "archive": str(archive),
        "bytes": archive.stat().st_size,
        "sha256": identity["sha256"],
        "repacked": False,
        "bucket": config["remote"]["bucket"],
        "remote_prefix": config["remote"]["prefix"],
        "r2_key": meta["r2_key"],
        "roundtrip": verified,
        "uploaded": [row["key"] for row in published["uploaded"]["uploaded"]],
    }


def explain_storage_error(exc: BaseException) -> str:
    text = redact(str(exc))
    lowered = text.lower()
    if "403" in text or "invalidaccesskeyid" in lowered or "signaturedoesnotmatch" in lowered:
        return "R2 拒绝访问。请检查 Access Key、Secret 和 Bucket 是否正确。"
    if "nosuchbucket" in lowered or "404" in text:
        return "没有找到这个 Bucket 或备份。请检查 Bucket 名称和备份编号。"
    if "could not resolve" in lowered or "name or service not known" in lowered or "nodename nor servname" in lowered:
        return "连不上 R2。请检查 Endpoint 是否正确，并且以 https:// 开头。"
    if "connection refused" in lowered or "timed out" in lowered:
        return f"连接 R2 失败。请检查 Endpoint、网络和 Bucket。{text[:180]}"
    return text[:400]


def _valid_domain(domain: str) -> bool:
    return domain == "localhost" or _DOMAIN.fullmatch(domain) is not None


def _prompt(text: str, *, secret: bool = False) -> str:
    if not sys.stdin.isatty():
        raise BackupError(text + "当前不是交互终端，请改用参数或环境变量。")
    if secret:
        import getpass
        value = getpass.getpass(text)
    else:
        value = input(text)
    value = value.strip()
    if not value:
        raise BackupError("缺少必要输入，已停止")
    return value


def _ensure_db_password() -> str:
    current = os.environ.get("XEDC_RESTORE_DB_PASSWORD", "")
    if current:
        return current
    import secrets
    password = secrets.token_hex(16)
    path = Path("/var/lib/xedc-recover/restore-db.password")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(password + "\n", encoding="utf-8")
    os.chmod(path, 0o600)
    os.environ["XEDC_RESTORE_DB_PASSWORD"] = password
    return password


def _extra_checks(domain: str, target: Path) -> dict:
    def fetch(path: str) -> str:
        completed = subprocess.run(
            ["curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "20", "-H", f"Host: {domain}", f"http://127.0.0.1{path}"],
            text=True,
            capture_output=True,
        )
        return (completed.stdout or "").strip()[:3]

    public = target / "public"
    sample = ""
    sample_code = ""
    if public.is_dir():
        for path in public.rglob("*"):
            if path.is_file() and path.suffix.lower() in {".png", ".jpg", ".jpeg", ".webp", ".gif"}:
                sample = "/sites/default/files/" + path.relative_to(public).as_posix()
                sample_code = fetch(sample)
                break
    private = target / "private"
    private_mode = oct(private.stat().st_mode & 0o777) if private.is_dir() else ""
    return {
        "home": fetch("/"),
        "login": fetch("/user/login"),
        "admin_anonymous": fetch("/admin"),
        "sample_file": sample,
        "sample_status": sample_code,
        "private_mode": private_mode,
        "private_not_world_accessible": bool(private.is_dir() and (private.stat().st_mode & 0o007) == 0),
    }


def run_restore(config: dict, *, backup: str = "", file: str = "", domain: str = "", yes: bool = False, role: str = "lab", target: str = "/srv/xedc", dry_run: bool = False, source: str = "") -> dict:
    if production_webroot_present():
        raise ProductionGuard("生产网站仍在本机，拒绝一键恢复")
    if role not in {"lab", "replacement"}:
        raise BackupError("role 只能是 lab 或 replacement")
    if role == "replacement" and sys.stdin.isatty():
        answer = _prompt("正式替换需要第二次确认。请输入：替换正式网站\n")
        if answer != "替换正式网站":
            raise BackupError("没有第二次确认，已停止。")
    if not domain:
        domain = _prompt("新域名: ")
    if not _valid_domain(domain):
        raise BackupError("域名无效")
    if not yes:
        answer = _prompt("将安装 Nginx、PHP 和 MariaDB，并把网站恢复到指定目录。确认请输入 yes: ")
        if answer != "yes":
            raise BackupError("未确认，已停止")
    if file and source == "r2":
        raise BackupError("指定了本地文件，不能同时从 R2 读取")
    if not source:
        source = "local" if file else "r2"
    if source not in {"local", "r2"}:
        raise BackupError("来源只能是 local 或 r2")
    if source == "local" and not file:
        if not backup:
            raise BackupError("本地恢复需要 --backup 或 --file")
        file = find_local_package(config, backup)["path"]
    if dry_run:
        return {"status": "DRY-RUN", "domain": domain, "role": role, "target": target, "file": file, "backup": backup, "source": source}
    archive = ""
    if file:
        archive = str(Path(file))
        if not Path(archive).is_file():
            raise BackupError(f"找不到本地恢复包 {archive}")
    else:
        if not (os.environ.get("XEDC_S3_ACCESS_KEY") or config.get("remote", {}).get("access_key")):
            os.environ["XEDC_S3_ACCESS_KEY"] = _prompt("R2 Access Key: ", secret=True)
            os.environ["XEDC_S3_SECRET_KEY"] = _prompt("R2 Secret: ", secret=True)
            if not config.get("remote", {}).get("endpoint"):
                config.setdefault("remote", {})["endpoint"] = _prompt("R2 endpoint: ")
                config["remote"]["bucket"] = _prompt("R2 bucket: ")
        prepare_remote(config, required=True)
        from .workspace import WorkspaceError, assert_disk, download_root
        destination_path = download_root(config)
        try:
            assert_disk(destination_path, 1024 * 1024 * 1024, "下载备份")
        except WorkspaceError as exc:
            raise BackupError(str(exc)) from exc
        fetched = download_package(config, backup or "latest", str(destination_path))
        archive = fetched["archive"]
    identity = assert_archive_manifest(Path(archive), config)
    if package_needs_legacy_key(Path(archive)):
        key_path = Path(config["key_file"])
        if not key_path.is_file():
            typed = _prompt("这份旧备份需要原来的解密密钥。密钥文件路径: ")
            key_path = Path(typed)
            if not key_path.is_file():
                raise BackupError("这份备份使用旧的加密格式，需要原来的 primary.key")
            config["key_file"] = str(key_path)
    else:
        _log(config, "这份备份没有单独的解密密钥。")
    _ensure_db_password()
    report = install_bundle(
        config,
        archive,
        target,
        yes=True,
        role=role,
        domain=domain,
        database_password=os.environ["XEDC_RESTORE_DB_PASSWORD"],
    )
    checks = _extra_checks(domain, Path(target))
    report["checks"] = checks
    if source == "local":
        r2 = {"status": "SKIPPED", "reason": "本地恢复不连接对象存储"}
    else:
        r2 = {"status": "NOT TESTED", "reason": "未提供对象存储凭据"}
        try:
            if prepare_remote(config, required=False):
                client_from_config(config).list_keys("packages/")
                r2 = {"status": "PASS", "mode": "list-backup-prefix", "writes": role == "replacement"}
        except (BackupError, S3Error) as exc:
            r2 = {"status": "FAIL", "reason": redact(str(exc))[:240]}
    report["source"] = source
    report["backup_identity"] = {key: identity.get(key) for key in ("backup_id", "sha256", "manifest_sha256", "encryption", "git_commit", "drupal_version")}
    report["r2"] = r2
    report["database_password_file"] = "/var/lib/xedc-recover/restore-db.password"
    report_path = Path(target) / "INSTALL_REPORT.json"
    if report_path.is_file():
        report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    home_ok = checks["home"] in {"200", "301", "302", "303"}
    login_ok = checks["login"] == "200"
    admin_ok = checks["admin_anonymous"] in {"403", "302", "301"}
    if report.get("status") == "PASS" and not (home_ok and login_ok and admin_ok):
        report["status"] = "FAIL"
        report["check_error"] = "首页、登录页或后台检查未通过"
    return report


def _print_backup(result: dict, as_json: bool) -> None:
    if as_json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return
    if result.get("status") == "DRY-RUN":
        print("检查完成，没有写入备份。")
        print(json.dumps({key: result[key] for key in ("os", "database", "site", "git", "disk_free", "remote_ready") if key in result}, ensure_ascii=False, indent=2))
        return
    if result.get("repacked") is False and result.get("status") == "PASS" and result.get("mode") is None:
        print("已重新上传现有本地备份，没有重新打包。")
        print(f"编号: {result.get('id')}")
        print(f"本地: {result.get('archive')}")
        print(f"R2: {result.get('bucket')} / {result.get('r2_key')}")
        print(f"大小: {_human(int(result.get('bytes') or 0))}")
        print(f"SHA256: {result.get('sha256')}")
        print("R2 校验: PASS")
        return
    git = result.get("git") or {}
    dirty = "工作区有未提交改动，恢复时以备份包中的代码为准。" if git.get("dirty") else "工作区与这次提交一致。恢复时会优先取出这个提交。"
    status = result.get("status")
    if status == "PARTIAL":
        print("本地备份成功，R2 上传失败。本次不是完全成功。")
    elif status == "FAIL":
        print("备份没有完成。")
    else:
        print("备份完成")
    print(f"编号: {result.get('id')}")
    print(f"本地: {result.get('archive') or '未保留长期副本'}")
    print(f"R2: {result.get('r2_key') or result.get('r2_error') or '未上传'}")
    print(f"大小: {_human(int(result.get('bytes') or 0))}")
    print(f"SHA256: {result.get('sha256')}")
    print(f"本地校验: {result.get('local_integrity') or 'PASS'}")
    roundtrip = result.get("roundtrip") or {}
    print(f"R2 校验: {roundtrip.get('integrity') or ('FAIL' if result.get('r2_error') else 'SKIPPED')}")
    print(f"Git: {git.get('commit') or '无'}。{dirty}")
    print("新备份没有单独的解密密钥。本地目录权限为 0700/0600。R2 使用私有桶、HTTPS 和服务端加密，这不是端到端加密。")
    if status == "PARTIAL":
        print(f"本地文件已保留。可以稍后执行: xedc-backup upload --backup {result.get('id')}")
    print(f"用时: {result.get('elapsed_seconds')} 秒")


def _print_restore(result: dict, as_json: bool) -> None:
    if as_json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return
    print(f"恢复结果: {result.get('status')}")
    print(f"目标: {result.get('target')}")
    print(f"代码来源: {(result.get('code') or {}).get('code_source', '')}")
    print(f"提交: {result.get('git_commit') or '包内快照'}")
    checks = result.get("checks") or {}
    if checks:
        print(f"首页: {checks.get('home')}  登录: {checks.get('login')}  后台匿名: {checks.get('admin_anonymous')}")
    print(f"报告: {result.get('target')}/INSTALL_REPORT.json")
    if result.get("database_password_file"):
        print(f"新数据库口令文件: {result['database_password_file']}（权限 600，不在报告正文中）")


def _print_list(items: list[dict], as_json: bool) -> None:
    if as_json:
        print(json.dumps(items, ensure_ascii=False, indent=2))
        return
    if not items:
        print("本地没有完整备份。")
        return
    print(f"{'编号':<32} {'时间':<22} {'大小':>10}  {'R2':<10} SHA256")
    for item in items:
        print(
            f"{item.get('backup_id') or item['archive']:<32} "
            f"{item.get('created', ''):<22} "
            f"{_human(int(item.get('bytes') or 0)):>10}  "
            f"{item.get('r2', ''):<10} "
            f"{item.get('sha256', '')}"
        )


def _local_command(argv: list[str]) -> int:
    command = argv[0]
    parser = argparse.ArgumentParser(prog=f"xedc-backup {command}")
    parser.add_argument("--config", default="")
    parser.add_argument("--json", action="store_true")
    if command != "list":
        parser.add_argument("--backup", required=True)
    args = parser.parse_args(argv[1:])
    config = load_runtime_config(args.config or None)
    if command == "list":
        _print_list(list_local_packages(config), args.json)
        return 0
    if command == "verify":
        item = find_local_package(config, args.backup)
        checked = _verify_download(config, Path(item["path"]))
        payload = {"status": "PASS", "archive": item["path"], **checked}
        if args.json:
            print(json.dumps(payload, ensure_ascii=False, indent=2))
        else:
            print(f"完整性: PASS")
            print(f"编号: {checked.get('backup_id')}")
            print(f"SHA256: {checked.get('sha256')}")
            print(f"Manifest: {checked.get('manifest_sha256')}")
        return 0
    if command == "delete":
        removed = delete_local_package(config, args.backup, automatic=False)
        print(json.dumps(removed, ensure_ascii=False) if args.json else f"已删除 {removed['backup_id']}")
        return 0
    result = upload_existing(config, args.backup)
    _print_backup(result, args.json)
    return 0 if result.get("status") == "PASS" else 1


def main_backup(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv and argv[0] == "upload" and "--archive" in argv:
        from .cli import main
        return main(["bundle", *argv])
    if argv and argv[0] in {"list", "verify", "delete", "upload"}:
        try:
            return _local_command(argv)
        except ProductionGuard as exc:
            print(redact(str(exc)), file=sys.stderr)
            return 3
        except (BackupError, S3Error) as exc:
            print(redact(str(exc)), file=sys.stderr)
            return getattr(exc, "exit_code", 1)
        except Exception as exc:
            print(redact(f"{exc.__class__.__name__}: {exc}"), file=sys.stderr)
            return 1
    if argv and argv[0] in {"create", "fetch"}:
        from .cli import main
        return main(["bundle", *argv])
    parser = argparse.ArgumentParser(prog="xedc-backup", description="生成本地完整备份，并可以上传到 Cloudflare R2")
    parser.add_argument("--version", action="store_true")
    parser.add_argument("--config", default="")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--output", default="")
    parser.add_argument("--local-only", action="store_true")
    parser.add_argument("--r2-only", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--force", action="store_true", help="接受该参数，但不能绕过生产环境保护")
    args = parser.parse_args(argv)
    if args.version:
        print(__version__)
        return 0
    if args.local_only and args.r2_only:
        print("不能同时使用 --local-only 和 --r2-only", file=sys.stderr)
        return 2
    mode = "local" if args.local_only else "r2" if args.r2_only else "both"
    try:
        config = load_runtime_config(args.config or None)
        result = run_backup(config, dry_run=args.dry_run, resume=args.resume, output=args.output, mode=mode)
        _print_backup(result, args.json)
        return 0 if result.get("status") in {"PASS", "DRY-RUN"} else 1
    except ProductionGuard as exc:
        print(redact(str(exc)), file=sys.stderr)
        return 3
    except CryptoError as exc:
        print(redact(str(exc)), file=sys.stderr)
        return 4
    except (BackupError, S3Error) as exc:
        print(redact(str(exc)), file=sys.stderr)
        return getattr(exc, "exit_code", 1)
    except Exception as exc:
        print(redact(f"{exc.__class__.__name__}: {exc}"), file=sys.stderr)
        return 1


def _collect_restore(config: dict) -> dict:
    from .remote import list_remote_backups
    from .workspace import WorkspaceError, assert_disk, download_root
    from .installer import assert_restore_os

    info = assert_restore_os()
    print(f"操作系统: Ubuntu {info['version_id']} ({info['codename']})")
    assert_disk(download_root(config), 8 * 1024 * 1024 * 1024, "恢复")
    print("这次按测试恢复（lab）。网站会装到 /srv/xedc。")
    print("程序会安装 Nginx、PHP 8.5 和 MariaDB，导入数据库和网站文件。")
    print("不会修改 Cloudflare DNS，也不会删除已有备份。")
    remote = config.setdefault("remote", {})
    os.environ["XEDC_S3_ACCESS_KEY"] = _prompt("R2 Access Key: ", secret=True)
    os.environ["XEDC_S3_SECRET_KEY"] = _prompt("R2 Secret: ", secret=True)
    remote["endpoint"] = _prompt("R2 Endpoint: ")
    remote["bucket"] = _prompt("Bucket: ")
    if not str(remote.get("prefix") or "").strip():
        remote["prefix"] = "xedc-recover/"
    prepare_remote(config, required=True)
    items = list_remote_backups(config)
    if not items:
        raise BackupError("R2 上没有可恢复的备份。")
    print("可恢复的备份：")
    for index, item in enumerate(items, start=1):
        print(f"{index}. {item['backup_id']}  {item['created']}")
    picked = _prompt("请输入序号: ")
    if not picked.isdigit() or not 1 <= int(picked) <= len(items):
        raise BackupError("没有选中有效的备份。")
    domain = _prompt("新网站域名: ")
    if not _valid_domain(domain):
        raise BackupError("域名无效")
    chosen = items[int(picked) - 1]
    print(f"将下载 {chosen['backup_id']}，恢复到 /srv/xedc，域名为 {domain}。")
    answer = _prompt("确认开始请输入 yes: ")
    if answer != "yes":
        raise BackupError("未确认，已停止")
    return {"backup": chosen["backup_id"], "domain": domain}


def main_restore(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv and argv[0] in {"install", "fetch"}:
        from .cli import main
        return main(["bundle", *argv])
    parser = argparse.ArgumentParser(prog="xedc-restore", description="从 R2 或本地恢复包一键恢复网站")
    parser.add_argument("--version", action="store_true")
    parser.add_argument("--config", default="")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--source", default="", choices=["", "local", "r2"], help="local 从本地备份恢复，r2 从 Cloudflare R2 恢复")
    parser.add_argument("--backup", default="", help="备份编号或 xedc-full- 包名")
    parser.add_argument("--file", default="", help="本地 xedc-full-*.tar.zst")
    parser.add_argument("--domain", default="")
    parser.add_argument("--yes", action="store_true")
    parser.add_argument("--role", default="lab", choices=["lab", "replacement"])
    parser.add_argument("--target", default="/srv/xedc")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--force", action="store_true", help="接受该参数，但不能绕过生产环境保护")
    args = parser.parse_args(argv)
    if args.version:
        print(__version__)
        return 0
    if args.backup and args.file:
        print("不要同时指定 --backup 和 --file", file=sys.stderr)
        return 2
    interactive = not any([args.file, args.backup, args.domain, args.source, args.yes, args.dry_run])
    try:
        config = load_runtime_config(args.config or None)
        if interactive:
            if production_webroot_present():
                print("这台机器上还有生产网站，不能在这里恢复。", file=sys.stderr)
                return 3
            if not sys.stdin.isatty():
                print("当前不是交互终端。从 R2 恢复请执行：", file=sys.stderr)
                print("xedc-restore --source r2 --backup 备份编号 --domain 新域名 --role lab --yes", file=sys.stderr)
                return 2
            chosen = _collect_restore(config)
            args.backup = chosen["backup"]
            args.domain = chosen["domain"]
            args.source = "r2"
            args.yes = True
            args.role = "lab"
        result = run_restore(
            config,
            backup=args.backup,
            file=args.file,
            domain=args.domain,
            yes=args.yes,
            role=args.role,
            target=args.target,
            dry_run=args.dry_run,
            source=args.source,
        )
        _print_restore(result, args.json)
        return 0 if result.get("status") in {"PASS", "DRY-RUN"} else 1
    except ProductionGuard as exc:
        print(redact(str(exc)), file=sys.stderr)
        return 3
    except CryptoError as exc:
        print(redact(str(exc)), file=sys.stderr)
        return 4
    except S3Error as exc:
        print(explain_storage_error(exc), file=sys.stderr)
        return 1
    except BackupError as exc:
        print(redact(str(exc)), file=sys.stderr)
        return getattr(exc, "exit_code", 1)
    except Exception as exc:
        from .installer import InstallError
        from .workspace import WorkspaceError
        if isinstance(exc, (InstallError, WorkspaceError)):
            print(str(exc), file=sys.stderr)
            return getattr(exc, "exit_code", 1)
        print(redact(f"{exc.__class__.__name__}: {exc}"), file=sys.stderr)
        return 1
