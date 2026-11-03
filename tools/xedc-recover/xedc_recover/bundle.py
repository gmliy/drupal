"""One portable archive around an existing encrypted backup. The key stays outside."""

from __future__ import annotations

import hashlib
import json
import os
import platform
import re
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path

from . import __version__
from .audit import collect
from .backup import BackupError, canonical, create_backup, show_backup, verify_backup
from .gitmeta import align_code, collect_identity
from .installer import ensure_marker, install_pinned
from .restore import restore_backup
from .safety import ProductionGuard, assert_backup_destination, assert_restore_target, production_webroot_present
from .ssltool import render_nginx

EXCLUDED_SERVICES = ("matomo", "netdata", "vpn", "wireguard", "openvpn", "proxy")
TOOL_ROOT = Path(__file__).resolve().parents[1]


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _memory_available() -> int:
    info = Path("/proc/meminfo")
    if not info.is_file():
        return 0
    for line in info.read_text(encoding="utf-8", errors="replace").splitlines():
        if line.startswith("MemAvailable:"):
            return int(line.split()[1]) * 1024
    return 0


def _detected_unrelated() -> list[str]:
    try:
        completed = subprocess.run(["docker", "ps", "--format", "{{.Names}}"], text=True, capture_output=True, timeout=10)
    except (OSError, subprocess.TimeoutExpired):
        return []
    found = []
    for name in (completed.stdout or "").splitlines():
        lowered = name.lower()
        if any(token in lowered for token in EXCLUDED_SERVICES):
            found.append(name)
    return found


def _runtime_versions() -> dict:
    """Prefer distribution binaries. A developer PATH must not replace them."""
    versions = {}
    for name, argv in (
        ("node", ["/usr/bin/node", "-v"]),
        ("npm", ["/usr/bin/npm", "-v"]),
        ("composer", ["composer", "--version"]),
    ):
        if name != "composer" and not Path(argv[0]).is_file():
            versions[name] = ""
            continue
        try:
            completed = subprocess.run(argv, text=True, capture_output=True, timeout=15)
        except (OSError, subprocess.TimeoutExpired):
            versions[name] = ""
            continue
        line = (completed.stdout or completed.stderr or "").strip().splitlines()
        versions[name] = line[0][:160] if line else ""
    return versions


def environment_document(private_files: str) -> dict:
    """Runtime inventory. Secret values from settings are not copied."""
    base = collect()
    settings = dict(base.get("settings") or {})
    settings.pop("password", None)
    settings.pop("hash_salt", None)
    settings.pop("username", None)
    php_modules = []
    try:
        completed = subprocess.run(["php", "-m"], text=True, capture_output=True, timeout=20)
        php_modules = [line.strip() for line in (completed.stdout or "").splitlines() if line.strip() and not line.startswith("[")]
    except (OSError, subprocess.TimeoutExpired):
        php_modules = []
    lock = Path("/var/www/drupal/composer.lock")
    composer = {"present": lock.is_file()}
    if lock.is_file():
        composer["sha256"] = _sha256(lock)
        composer["bytes"] = lock.stat().st_size
    versions = _runtime_versions()
    binaries = dict(base.get("binaries") or {})
    if Path("/usr/bin/node").is_file():
        binaries["node"] = {"path": "/usr/bin/node", "present": True, "version": versions.get("node", "")}
    if Path("/usr/bin/npm").is_file():
        binaries["npm"] = {"path": "/usr/bin/npm", "present": True, "version": versions.get("npm", "")}
    return {
        "collected_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "tool_version": __version__,
        "os": base.get("os"),
        "machine": base.get("machine"),
        "php": base.get("php"),
        "php_modules": php_modules,
        "nginx": base.get("nginx"),
        "mariadb": base.get("mariadb"),
        "ffmpeg": base.get("ffmpeg"),
        "binaries": binaries,
        "composer_lock": composer,
        "runtime_versions": versions,
        "settings_presence": settings,
        "storage": base.get("storage") if private_files else {"present": False},
        "excluded_unrelated_services": _detected_unrelated(),
        "excluded_on_purpose": list(EXCLUDED_SERVICES),
        "cloudflare_dns": "manual-confirmation-required",
        "node_is_optional": True,
    }


def _restore_readme(backup_id: str, key_id: str, alg: str = "AES-256-GCM") -> str:
    if alg == "none":
        secret_lines = [
            "这份备份没有单独的解密密钥。",
            "本地文件只靠目录权限保护（目录 0700，文件 0600），没有客户端加密。",
            "R2 上的同一份文件放在私有桶里，通过 HTTPS 传输，并使用服务端静态加密。",
            "这不是端到端加密。拿到服务器或 R2 凭据的人可以读取备份内容。",
        ]
    else:
        secret_lines = [
            "这个压缩包使用旧的 AES-256-GCM 格式，不包含解密密钥。",
            f"密钥编号：{key_id}",
            "恢复这份旧备份必须提供原来的 primary.key。",
        ]
    return "\n".join([
        "XEDC 完整恢复包",
        "",
        f"备份编号：{backup_id}",
        f"工具版本：{__version__}",
        "",
        *secret_lines,
        "",
        "新服务器需要：",
        "1. Ubuntu 24.04。",
        "2. 本恢复程序（压缩包内的 tool/，或单独复制的 xedc-recover）。",
        "3. 本压缩包和旁边的 .sha256 文件。",
        "",
        "不要从 GitHub 重新克隆来代替数据库和媒体文件。仓库里没有这些数据。",
        "",
        "恢复命令：",
        "  xedc-restore --file /path/to/xedc-full.tar.zst --domain 新域名",
        "或从 R2 取指定版本：",
        "  xedc-restore --source r2 --backup 备份编号 --domain 新域名",
        "",
        "程序不会修改 Cloudflare DNS。证书和域名指向需要你确认后再做。",
        "Matomo、Netdata、VPN 和代理不在这个包里。",
        "",
    ])


def _copy_tool(destination: Path) -> None:
    for path in TOOL_ROOT.rglob("*"):
        if "__pycache__" in path.parts or path.suffix == ".pyc":
            continue
        if path.is_symlink() or not path.is_file():
            continue
        relative = path.relative_to(TOOL_ROOT)
        if relative.parts[:1] == ("tests",):
            continue
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, target)


def _tar_zst(stage: Path, destination: Path) -> None:
    if shutil.which("zstd") is None or shutil.which("tar") is None:
        raise BackupError("系统没有 tar 或 zstd，不能生成完整恢复包")
    completed = subprocess.run(
        ["tar", "--zstd", "-cf", str(destination), "-C", str(stage), "."],
        text=True,
        capture_output=True,
    )
    if completed.returncode != 0:
        raise BackupError((completed.stderr or completed.stdout or "tar 失败")[-400:])


def pack_backup(config: dict, backup_id: str, output_dir: str) -> dict:
    root = Path(config["backup_root"])
    verified = verify_backup(config, backup_id)
    if verified.get("status") != "PASS":
        raise BackupError("备份校验未通过，不能打包")
    manifest = show_backup(root, backup_id)
    source = root / backup_id
    destination_root = assert_backup_destination(output_dir)
    destination_root.mkdir(parents=True, exist_ok=True)
    os.chmod(destination_root, 0o700)
    matched = re.fullmatch(r"xedc-([0-9]{8}T[0-9]{6}Z)-([0-9a-f]{6})", backup_id)
    if matched:
        base = f"xedc-full-{matched.group(1)}-{matched.group(2)}"
    else:
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        base = f"xedc-full-{stamp}-{backup_id[-6:]}"
    archive = destination_root / f"{base}.tar.zst"
    from .workspace import WorkspaceError, assert_disk, make_work_dir
    try:
        assert_disk(destination_root, 64 * 1024 * 1024, "打包")
    except WorkspaceError as exc:
        raise BackupError(str(exc)) from exc
    stage = make_work_dir(config, "pack")
    packed = False
    try:
        payload = stage / "payload"
        payload.mkdir()
        for artifact in manifest["artifacts"].values():
            src = source / artifact["file"]
            if not src.is_file():
                raise BackupError(f"缺少备份文件 {artifact['file']}")
            target = stage / artifact["file"]
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src, target)
        (stage / "manifest.json").write_text((source / "manifest.json").read_text(encoding="utf-8"), encoding="utf-8")
        (stage / "manifest.sha256").write_text((source / "manifest.sha256").read_text(encoding="utf-8"), encoding="utf-8")
        environment = environment_document(config.get("private_files", ""))
        (stage / "environment.json").write_text(json.dumps(environment, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        encryption = manifest.get("encryption") or {}
        key_id = encryption.get("key_id", "")
        alg = encryption.get("alg") or "AES-256-GCM"
        (stage / "RESTORE.txt").write_text(_restore_readme(backup_id, key_id, alg), encoding="utf-8")
        service = stage / "service"
        service.mkdir()
        (service / "nginx.conf.template").write_text(
            render_nginx(server_name="example.invalid", webroot="/var/www/drupal/web", prefix="/var/lib/xedc-recover/work/nginx", port=80),
            encoding="utf-8",
        )
        (service / "NOTES.txt").write_text(
            "PHP-FPM 使用 php8.5-fpm。官方 Nginx 的工作用户是 nginx，套接字组需要设为 nginx，模式 0660。\n"
            "MariaDB 使用 utf8mb4。不要把 Matomo、Netdata、VPN 或代理的数据放进这个包。\n"
            "Cloudflare DNS 需要人工确认，程序不会自动修改。\n",
            encoding="utf-8",
        )
        _copy_tool(stage / "tool")
        file_rows = manifest.get("files") or []
        identity = collect_identity(Path(config.get("source_root") or "/var/www/drupal"))
        counts = {
            "manifest_files": len(file_rows),
            "file_bytes": sum(int(item.get("size") or 0) for item in file_rows if isinstance(item, dict)),
            "database": (manifest.get("database") or {}).get("counts", {}),
        }
        package_manifest = {
            "schema": 4 if alg == "none" else 3,
            "manifest_version": 4 if alg == "none" else 3,
            "package_format": "xedc-full-tar-zst",
            "backup_id": backup_id,
            "backup_type": manifest.get("type"),
            "created": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "tool_version": __version__,
            "key_id": key_id,
            "key_included": False,
            "encryption": encryption,
            "counts": counts,
            "file_drift": manifest.get("file_drift") or [],
            "git": {
                "commit": identity.get("commit") or "",
                "branch": identity.get("branch") or "",
                "remote": identity.get("remote") or "",
                "dirty": bool(identity.get("dirty")),
                "dirty_entries": identity.get("dirty_entries") or 0,
            },
            "drupal_version": identity.get("drupal_version") or "",
            "php_version": identity.get("php_version") or "",
            "composer_lock_sha256": identity.get("composer_lock_sha256") or "",
            "artifacts": manifest.get("artifacts"),
            "code_recovery": "github-commit-when-clean-else-package-snapshot",
            "cloudflare_dns": "manual-confirmation-required",
        }
        (stage / "PACKAGE.json").write_text(json.dumps(package_manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        _tar_zst(stage, archive)
        packed = True
    finally:
        if packed:
            shutil.rmtree(stage, ignore_errors=True)
    digest = _sha256(archive)
    checksum = destination_root / f"{archive.name}.sha256"
    checksum.write_text(f"{digest}  {archive.name}\n", encoding="utf-8")
    os.chmod(archive, 0o600)
    os.chmod(checksum, 0o600)
    credentials = destination_root / f"{base}.credentials.enc"
    secret_artifact = manifest["artifacts"].get("secrets", {})
    secret_src = source / secret_artifact.get("file", "")
    if alg != "none" and secret_src.is_file():
        shutil.copy2(secret_src, credentials)
        os.chmod(credentials, 0o600)
    return {
        "archive": str(archive),
        "sha256": digest,
        "checksum": str(checksum),
        "credentials": str(credentials) if credentials.is_file() else "",
        "backup_id": backup_id,
        "bytes": archive.stat().st_size,
        "key_included": False,
    }


def create_full_package(config: dict, output_dir: str, *, from_backup: str = "", allow_live_read: bool = False, label: str = "") -> dict:
    if from_backup:
        backup_id = from_backup
    else:
        created = create_backup(config, backup_type="full", allow_live_read=allow_live_read, label=label or "full-package")
        backup_id = created["id"]
    packed = pack_backup(config, backup_id, output_dir)
    packed["created_new_backup"] = not bool(from_backup)
    return packed


def resolve_package(spec: str, search_dir: str) -> Path:
    if spec != "latest":
        path = Path(spec)
        if not path.is_file():
            raise BackupError(f"找不到恢复包 {spec}")
        return path
    directory = Path(search_dir)
    found = sorted(directory.glob("xedc-full-*.tar.zst"), key=lambda item: item.stat().st_mtime)
    if not found:
        raise BackupError(f"{search_dir} 里没有 xedc-full-*.tar.zst")
    return found[-1]


def verify_package(archive: Path) -> str:
    checksum = Path(str(archive) + ".sha256")
    if not checksum.is_file():
        raise BackupError("缺少 SHA256 文件，不能恢复")
    recorded = checksum.read_text(encoding="utf-8").split()[0]
    actual = _sha256(archive)
    if recorded != actual:
        raise BackupError("恢复包 SHA256 不一致")
    return actual


def _extract_package(archive: Path, destination: Path) -> None:
    destination.mkdir(parents=True, exist_ok=True)
    completed = subprocess.run(
        ["tar", "--zstd", "-xf", str(archive), "-C", str(destination)],
        text=True,
        capture_output=True,
    )
    if completed.returncode != 0:
        raise BackupError((completed.stderr or completed.stdout or "解包失败")[-400:])
    for path in destination.rglob("*"):
        if path.is_symlink():
            raise BackupError(f"恢复包包含符号链接: {path.name}")
        try:
            path.resolve().relative_to(destination.resolve())
        except ValueError as exc:
            raise BackupError(f"恢复包试图写出目录: {path}") from exc


def _install_material(stage: Path, backup_root: Path) -> str:
    manifest = json.loads((stage / "manifest.json").read_text(encoding="utf-8"))
    backup_id = manifest["id"]
    directory = backup_root / backup_id
    directory.mkdir(parents=True, exist_ok=True)
    os.chmod(directory, 0o700)
    shutil.copy2(stage / "manifest.json", directory / "manifest.json")
    shutil.copy2(stage / "manifest.sha256", directory / "manifest.sha256")
    payload = directory / "payload"
    if payload.exists():
        shutil.rmtree(payload)
    shutil.copytree(stage / "payload", payload)
    return backup_id


def _services(target: Path, domain: str) -> dict:
    from .ssltool import nginx_test, self_sign, write_config
    webroot = target / "code" / "web"
    from .workspace import work_root
    prefix = work_root(None) / "nginx"
    prefix.mkdir(parents=True, exist_ok=True)
    certs = self_sign(domain, "/etc/xedc-recover/ssl")
    text = render_nginx(
        server_name=f"{domain} localhost",
        webroot=str(webroot),
        prefix=str(prefix),
        port=80,
        cert=certs["cert"],
        key=certs["key"],
    )
    config_path = write_config("/etc/nginx/nginx.conf", text)
    ok, output = nginx_test(str(config_path), str(prefix))
    if not ok:
        raise BackupError(f"Nginx 配置测试失败: {output[-300:]}")
    subprocess.run(["service", "nginx", "stop"], text=True, capture_output=True)
    subprocess.run(["nginx", "-s", "quit", "-p", str(prefix), "-c", str(config_path)], text=True, capture_output=True)
    pid_file = prefix / "nginx.pid"
    if pid_file.is_file():
        pid = pid_file.read_text(encoding="utf-8", errors="replace").strip()
        if pid.isdigit():
            subprocess.run(["kill", "-QUIT", pid], text=True, capture_output=True)
    subprocess.run(["sleep", "0.5"], check=False)
    started = subprocess.run(["service", "nginx", "start"], text=True, capture_output=True)
    if started.returncode != 0:
        raise BackupError((started.stderr or started.stdout or "nginx 启动失败")[-300:])
    pool = Path("/etc/php/8.5/fpm/pool.d/www.conf")
    if pool.is_file() and shutil.which("id") and subprocess.run(["id", "nginx"], capture_output=True).returncode == 0:
        lines = pool.read_text(encoding="utf-8", errors="replace").splitlines()
        replaced = False
        rendered = []
        for line in lines:
            stripped = line.lstrip()
            if stripped.startswith("listen.group") or stripped.startswith(";listen.group"):
                rendered.append("listen.group = nginx")
                replaced = True
            elif stripped.startswith("listen.mode") or stripped.startswith(";listen.mode"):
                rendered.append("listen.mode = 0660")
            else:
                rendered.append(line)
        if not replaced:
            rendered.append("listen.group = nginx")
            rendered.append("listen.mode = 0660")
        pool.write_text("\n".join(rendered) + "\n", encoding="utf-8")
        restarted = subprocess.run(["service", "php8.5-fpm", "restart"], text=True, capture_output=True)
        if restarted.returncode != 0:
            raise BackupError("PHP-FPM 没有启动，恢复已停止。")
    settings = webroot / "sites/default/settings.php"
    private = target / "private"
    if private.is_dir():
        subprocess.run(["chown", "-R", "www-data:www-data", str(private)], check=False)
    temp_dir = target / "tmp"
    if temp_dir.is_dir():
        subprocess.run(["chown", "www-data:www-data", str(temp_dir)], check=False)
    if settings.is_file():
        subprocess.run(["chown", "www-data:www-data", str(settings)], check=False)
        os.chmod(settings, 0o440)
    return {
        "nginx": "started",
        "domain": domain,
        "ssl": "self-signed",
        "public_certificate": "manual-confirmation-required",
        "cloudflare_dns": "manual-confirmation-required",
    }


def _health(domain: str, target: Path) -> dict:
    from .workspace import work_root
    login = subprocess.run(
        ["curl", "-fsS", "-H", f"Host: {domain}", "http://127.0.0.1/user/login", "-o", str(work_root(None) / "login.html")],
        text=True,
        capture_output=True,
    )
    page = work_root(None) / "login.html"
    body = page.read_text(encoding="utf-8", errors="replace") if page.is_file() else ""
    drush = target / "code/vendor/bin/drush"
    drush_ok = False
    if drush.is_file() and os.access(drush, os.X_OK):
        status = subprocess.run(
            ["runuser", "-u", "www-data", "--", str(drush), "--root", str(target / "code/web"), "status", "--uri", f"http://{domain}"],
            text=True,
            capture_output=True,
        )
        drush_ok = status.returncode == 0 and "Successful" in (status.stdout or "")
    return {
        "login_http": login.returncode == 0,
        "login_form": "user-login-form" in body or 'name="name"' in body,
        "drush_bootstrap": drush_ok,
    }


def install_bundle(
    config: dict,
    package: str,
    target: str,
    *,
    yes: bool,
    role: str = "lab",
    domain: str = "",
    database_name: str = "xedc_restore",
    database_user: str = "xedc_restore",
    database_password: str = "",
    database_host: str = "127.0.0.1",
    database_port: int = 3306,
    skip_services: bool = False,
    skip_database: bool = False,
    search_dir: str = "",
) -> dict:
    if production_webroot_present() and (not skip_services or not skip_database):
        raise ProductionGuard("生产网站仍在本机，拒绝一键恢复")
    if not yes:
        raise BackupError("一键恢复会写入目标目录，需要 --yes")
    if role not in ("lab", "replacement"):
        raise BackupError("role 只能是 lab 或 replacement")
    destination = assert_restore_target(target)
    archive = resolve_package(package, search_dir or str(Path(config.get("backup_root", ".")) / "packages"))
    digest = verify_package(archive)
    report_path = destination / "INSTALL_REPORT.json"
    if report_path.is_file():
        previous = json.loads(report_path.read_text(encoding="utf-8"))
        if previous.get("status") == "PASS" and previous.get("package_sha256") == digest:
            previous["idempotent"] = True
            return previous
    core = destination / "code/web/core"
    state_path = destination.parent / f".{destination.name}.xedc-install.json"
    if core.is_dir() and not state_path.is_file() and not report_path.is_file():
        raise BackupError("目标目录已有网站，拒绝覆盖")
    state = json.loads(state_path.read_text(encoding="utf-8")) if state_path.is_file() else {"steps": {}}
    from .workspace import WorkspaceError, assert_disk, keep_message, make_work_dir, work_root
    need = archive.stat().st_size * 4
    space_parent = destination.parent if destination.parent.exists() else Path("/")
    try:
        assert_disk(space_parent, need, "恢复网站")
        assert_disk(work_root(config), archive.stat().st_size * 3, "解包")
    except WorkspaceError as exc:
        raise BackupError(str(exc)) from exc
    free = shutil.disk_usage(space_parent).free
    available = _memory_available()
    if available and available < 1024 * 1024 * 1024 and not skip_services:
        raise BackupError("可用内存不足 1 GB，拒绝安装运行环境")
    preflight = {
        "os": platform.platform(),
        "machine": platform.machine(),
        "disk_free": free,
        "memory_available": available,
        "cloudflare_dns": "manual-confirmation-required",
    }
    work = make_work_dir(config, f"install-{digest[:12]}")
    succeeded = False
    try:
        if "extracted" not in state["steps"]:
            _extract_package(archive, work)
            if not (work / "manifest.json").is_file() or not (work / "payload").is_dir():
                raise BackupError("恢复包缺少 manifest 或 payload")
            state["steps"]["extracted"] = True
            state["stage"] = str(work)
            state_path.write_text(canonical(state), encoding="utf-8")
        else:
            _extract_package(archive, work)
        backup_root = work / "backup-root"
        backup_root.mkdir(parents=True, exist_ok=True)
        backup_id = _install_material(work, backup_root)
        restore_config = dict(config)
        restore_config["backup_root"] = str(backup_root)
        verified = verify_backup(restore_config, backup_id)
        if verified.get("status") != "PASS":
            raise BackupError("包内备份校验失败")
        if not skip_services:
            ensure_marker()
            install_pinned(yes=True)
            database_service = subprocess.run(["service", "mariadb", "start"], text=True, capture_output=True)
            if database_service.returncode != 0:
                raise BackupError("MariaDB 没有启动，恢复已停止。")
            subprocess.run(["service", "redis-server", "start"], text=True, capture_output=True)
        hosts = []
        if domain:
            escaped = domain.replace(".", r"\.")
            hosts = [f"^{escaped}$", "^localhost$"]
        package_doc = {}
        package_path = work / "PACKAGE.json"
        if package_path.is_file():
            package_doc = json.loads(package_path.read_text(encoding="utf-8"))
        restored = restore_backup(
            restore_config,
            backup_id,
            str(destination),
            role=role,
            yes=True,
            skip_database=skip_database,
            import_database_now=not skip_database,
            database_name=database_name,
            database_user=database_user,
            database_password=database_password,
            database_host=database_host,
            database_port=database_port,
            trusted_hosts=hosts or None,
        )
        git_info = dict(package_doc.get("git") or {})
        git_info["composer_lock_sha256"] = package_doc.get("composer_lock_sha256") or ""
        code_align = align_code(destination / "code", git_info, work=work / "git")
        files = {}
        if restored.get("database_imported"):
            from .fileset import audit_database_files
            files = audit_database_files(destination, database_name)
        services = {}
        health = {}
        if not skip_services:
            if not domain:
                raise BackupError("配置 Web 服务需要 --domain")
            services = _services(destination, domain)
            health = _health(domain, destination)
            if not health["login_form"]:
                raise BackupError("登录页健康检查失败")
        file_error = ""
        if files.get("missing"):
            sample = "、".join(files.get("sample") or [])
            file_error = f"数据库里有 {files['missing']} 个文件在磁盘上不存在。例如：{sample}"
        report = {
            "status": "PASS",
            "package": str(archive),
            "package_sha256": digest,
            "backup_id": backup_id,
            "target": str(destination),
            "role": role,
            "preflight": preflight,
            "code": code_align,
            "git_commit": (package_doc.get("git") or {}).get("commit", ""),
            "drupal_version": package_doc.get("drupal_version", ""),
            "restored": {"database_imported": restored.get("database_imported"), "chain": restored.get("chain")},
            "files": files,
            "services": services,
            "health": health,
            "cloudflare_dns": "需要人工确认后，再把域名指向新服务器。程序没有修改 Cloudflare。",
        }
        if file_error:
            report["status"] = "FAIL"
            report["file_error"] = file_error
        destination.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        if file_error:
            raise BackupError(file_error)
        state_path.unlink(missing_ok=True)
        succeeded = True
        return report
    except Exception as exc:
        state["failed"] = True
        try:
            state_path.write_text(canonical(state), encoding="utf-8")
        except OSError:
            pass
        if keep_message(work) not in str(exc):
            raise BackupError(f"{exc}\n{keep_message(work)}") from exc
        raise
    finally:
        if succeeded:
            shutil.rmtree(work, ignore_errors=True)
