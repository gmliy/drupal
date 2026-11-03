"""One command to back up, and one command to restore.

xedc-backup reads the live site, writes an encrypted package, uploads it, and
downloads it again before reporting success. xedc-restore installs a new
machine from that package. Neither command prints secret values.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.parse
from datetime import datetime, timezone
from pathlib import Path

from . import __version__
from .backup import BackupError, Lock, create_backup, new_id, prune_backups, verify_backup
from .bundle import create_full_package, install_bundle, pack_backup, verify_package
from .config import load_config
from .crypto import CryptoError
from .gitmeta import collect_identity
from .redact import redact
from .remote import client_from_config, download_package, prune_remote_packages, upload_package
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
    database = config["database"]
    defaults = Path(tempfile.mkdtemp(prefix="xedc-db-")) / "client.cnf"
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
        defaults.parent.rmdir()
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

    digest = verify_package(archive)
    stage = Path(tempfile.mkdtemp(prefix="xedc-roundtrip-"))
    try:
        _extract_package(archive, stage)
        material = stage / "material"
        material.mkdir()
        backup_id = _install_material(stage, material)
        checked = verify_backup({**config, "backup_root": str(material)}, backup_id)
        if checked.get("status") != "PASS":
            raise BackupError("重新下载后的解密校验没有通过")
        return {"sha256": digest, "backup_id": backup_id, "decrypt": "PASS"}
    finally:
        shutil.rmtree(stage, ignore_errors=True)


def run_backup(config: dict, *, dry_run: bool = False, resume: bool = False, output: str = "") -> dict:
    started = time.time()
    Path(config["backup_root"]).mkdir(parents=True, exist_ok=True)
    os.chmod(Path(config["backup_root"]), 0o700)
    key_path = Path(config["key_file"])
    if not dry_run and not key_path.is_file():
        from .credentials import init_key
        created = init_key(str(key_path))
        _log(config, f"已生成解密密钥 {created['path']}。请立刻把它复制到密码管理器。它不会上传，也不会进入 Git。")
    report = preflight(config)
    _log(config, f"系统 {report['os']}，磁盘可用 {_human(report['disk_free'])}，内存可用 {_human(report['memory_available'])}")
    _log(config, f"数据库 {report['database']}，Drupal {report['drupal_version'] or '未知'}，提交 {report['git'].get('commit') or '无'}")
    remote = prepare_remote(config, required=not dry_run)
    if remote:
        _log(config, f"检查对象存储 {remote['bucket']} 前缀 {remote['prefix']}")
        client_from_config(config).list_keys("packages/")
    if dry_run:
        planned = create_backup(config, dry_run=True, allow_live_read=False)
        report.update({"status": "DRY-RUN", "planned": planned, "remote_ready": bool(remote)})
        return report
    output_dir = output or str(Path(config["backup_root"]).parent / "packages")
    state = _read_state(config) if resume else {}
    live = production_webroot_present() and Path(config["source_root"]).resolve() == Path("/var/www/drupal").resolve()
    lock = Lock(Path(config["backup_root"]) / ".oneshot.lock")
    with lock:
        if state.get("backup_id") and not state.get("packed"):
            _log(config, f"继续未完成的备份 {state['backup_id']}")
            created = create_backup(config, allow_live_read=live, resume=True, backup_id=state["backup_id"], label="xedc-backup")
            backup_id = created["id"]
        elif state.get("packed") and state.get("backup_id"):
            backup_id = state["backup_id"]
            _log(config, f"继续上传已打包的备份 {backup_id}")
        else:
            backup_id = new_id()
            _write_state(config, {"backup_id": backup_id, "started": _now(), "packed": False})
            _log(config, f"开始完整备份 {backup_id}")
            created = create_backup(config, allow_live_read=live, backup_id=backup_id, label="xedc-backup")
            backup_id = created["id"]
        if not (state.get("packed") and state.get("archive") and Path(state.get("archive", "")).is_file()):
            _log(config, "生成压缩包、校验和与 manifest")
            packed = pack_backup(config, backup_id, output_dir)
            state = {"backup_id": backup_id, "packed": True, "archive": packed["archive"], "sha256": packed["sha256"]}
            _write_state(config, state)
        else:
            packed = {"archive": state["archive"], "sha256": state["sha256"], "backup_id": backup_id, "bytes": Path(state["archive"]).stat().st_size}
        archive = Path(packed["archive"])
        local_digest = verify_package(archive)
        if local_digest != packed["sha256"]:
            raise BackupError("本地恢复包在上传前校验失败，没有上传")
        _log(config, f"上传 {_human(archive.stat().st_size)}")
        uploaded = upload_package(config, str(archive))
        incoming = Path(output_dir) / "roundtrip"
        if incoming.exists():
            shutil.rmtree(incoming)
        fetched = download_package(config, archive.name, str(incoming))
        if fetched["sha256"] != local_digest:
            raise BackupError("从对象存储下载的 SHA256 与本地不一致，不标记成功")
        verified = _verify_download(config, Path(fetched["archive"]))
        prune = prune_backups(config)
        remote_prune = {"kept": [], "removed": [], "error": ""}
        try:
            remote_prune = prune_remote_packages(config, keep=int(config.get("retention", {}).get("daily", 7)))
        except (BackupError, S3Error) as exc:
            remote_prune["error"] = redact(str(exc))
            _log(config, f"保留策略清理没有完成：{remote_prune['error']}。已校验的备份仍然保留。")
        _write_state(config, {"backup_id": backup_id, "packed": True, "archive": str(archive), "status": "PASS", "finished": _now()})
        shutil.rmtree(incoming, ignore_errors=True)
    return {
        "status": "PASS",
        "id": backup_id,
        "archive": str(archive),
        "bytes": archive.stat().st_size,
        "sha256": local_digest,
        "remote_prefix": config["remote"]["prefix"],
        "bucket": config["remote"]["bucket"],
        "uploaded": [item["key"] for item in uploaded["uploaded"]],
        "roundtrip": verified,
        "key_file": str(key_path),
        "key_included": False,
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


def run_restore(config: dict, *, backup: str = "", file: str = "", domain: str = "", yes: bool = False, role: str = "lab", target: str = "/srv/xedc", dry_run: bool = False) -> dict:
    if production_webroot_present():
        raise ProductionGuard("生产网站仍在本机，拒绝一键恢复")
    if role not in {"lab", "replacement"}:
        raise BackupError("role 只能是 lab 或 replacement")
    if not domain:
        domain = _prompt("新域名: ")
    if not _valid_domain(domain):
        raise BackupError("域名无效")
    if not yes:
        answer = _prompt("将安装运行环境并恢复网站。确认请输入 yes: ")
        if answer != "yes":
            raise BackupError("未确认，已停止")
    key_path = Path(config["key_file"])
    if not key_path.is_file():
        typed = _prompt("解密密钥文件路径: ")
        key_path = Path(typed)
        if not key_path.is_file():
            raise BackupError("找不到解密密钥")
        config["key_file"] = str(key_path)
    if dry_run:
        return {"status": "DRY-RUN", "domain": domain, "role": role, "target": target, "file": file, "backup": backup}
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
        destination = str(Path(config["backup_root"]).parent / "incoming")
        fetched = download_package(config, backup or "latest", destination)
        archive = fetched["archive"]
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
    r2 = {"status": "NOT TESTED", "reason": "未提供对象存储凭据"}
    try:
        if prepare_remote(config, required=False):
            client_from_config(config).list_keys("packages/")
            r2 = {"status": "PASS", "mode": "list-backup-prefix", "writes": role == "replacement"}
    except (BackupError, S3Error) as exc:
        r2 = {"status": "FAIL", "reason": redact(str(exc))[:240]}
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
    git = result.get("git") or {}
    dirty = "工作区有未提交改动，恢复时以备份包中的代码为准。" if git.get("dirty") else "工作区与这次提交一致。恢复时会优先取出这个提交。"
    print("备份完成")
    print(f"编号: {result.get('id')}")
    print(f"包: {result.get('archive')}")
    print(f"大小: {_human(int(result.get('bytes') or 0))}")
    print(f"SHA256: {result.get('sha256')}")
    print(f"远端: {result.get('bucket')} / {result.get('remote_prefix')}")
    print(f"Git: {git.get('commit') or '无'}。{dirty}")
    print(f"解密密钥: {result.get('key_file')}")
    print("解密密钥不在备份包里，也没有上传。请单独保存。")
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


def main_backup(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv and argv[0] in {"create", "upload", "fetch"}:
        from .cli import main
        return main(["bundle", *argv])
    parser = argparse.ArgumentParser(prog="xedc-backup", description="一键完整备份并上传到 Cloudflare R2")
    parser.add_argument("--version", action="store_true")
    parser.add_argument("--config", default="")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--output", default="")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--force", action="store_true", help="接受该参数，但不能绕过生产环境保护")
    args = parser.parse_args(argv)
    if args.version:
        print(__version__)
        return 0
    try:
        config = load_runtime_config(args.config or None)
        result = run_backup(config, dry_run=args.dry_run, resume=args.resume, output=args.output)
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
    parser.add_argument("--backup", default="", help="远端恢复包名称或前缀，例如 xedc-full-20261104")
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
    try:
        config = load_runtime_config(args.config or None)
        result = run_restore(
            config,
            backup=args.backup,
            file=args.file,
            domain=args.domain,
            yes=args.yes,
            role=args.role,
            target=args.target,
            dry_run=args.dry_run,
        )
        _print_restore(result, args.json)
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
