"""Production write guards. --force cannot bypass these checks."""

from __future__ import annotations

import os
import socket
from pathlib import Path

PRODUCTION_ROOT = Path("/var/www/drupal")
PRODUCTION_SETTINGS = PRODUCTION_ROOT / "web/sites/default/settings.php"
PRODUCTION_CORE = PRODUCTION_ROOT / "web/core"
LAB_MARKER = Path("/etc/xedc-recover-lab")
DENY_WRITE_PREFIXES = (
    Path("/var/www/drupal"),
    Path("/etc/nginx"),
    Path("/etc/php"),
    Path("/etc/mysql"),
    Path("/etc/letsencrypt"),
    Path("/etc/cron.d"),
    Path("/etc/cron.daily"),
    Path("/var/lib/mysql"),
    Path("/var/lib/docker"),
    Path("/root"),
    Path("/lib/systemd/system"),
    Path("/etc/systemd/system"),
)
PRODUCTION_SOCKETS = (
    "/run/mysqld/mysqld.sock",
    "/var/run/mysqld/mysqld.sock",
    "/tmp/mysql.sock",
)


class ProductionGuard(RuntimeError):
    """Raised when an operation would change the live site."""

    exit_code = 3


def production_webroot_present() -> bool:
    return PRODUCTION_CORE.is_dir() and PRODUCTION_SETTINGS.is_file()


def lab_marker_present() -> bool:
    return LAB_MARKER.is_file()


def real(path: str | Path) -> Path:
    candidate = Path(path)
    try:
        return candidate.resolve(strict=False)
    except OSError as exc:
        raise ProductionGuard(f"无法解析路径 {path}: {exc}") from exc


def _contained(child: Path, parent: Path) -> bool:
    try:
        child.relative_to(parent)
        return True
    except ValueError:
        return False


def assert_write_allowed(path: str | Path, *, purpose: str) -> Path:
    """Refuse writes into the production tree. A new machine may write service config."""
    target = real(path)
    if target == Path("/etc/xedc-recover-lab") or _contained(target, Path("/etc/xedc-recover-lab")):
        if production_webroot_present():
            raise ProductionGuard("生产网站仍在本机，拒绝创建实验室标记")
        return target
    if production_webroot_present():
        for prefix in DENY_WRITE_PREFIXES:
            prefix_real = real(prefix)
            if target == prefix_real or _contained(target, prefix_real):
                raise ProductionGuard(f"拒绝写入生产路径 ({purpose}): {target}")
        if target == real("/root/backup.sh"):
            raise ProductionGuard("拒绝修改 /root/backup.sh")
        return target
    system_prefixes = ("/etc", "/lib/systemd", "/usr/lib/systemd", "/var/spool/cron")
    if any(str(target).startswith(prefix) for prefix in system_prefixes) and not lab_marker_present():
        raise ProductionGuard(f"新机器还没有恢复标记，拒绝写入系统路径 ({purpose})")
    if str(target).startswith("/var/lib/docker") or str(target) == "/var/run/docker.sock":
        raise ProductionGuard("拒绝访问 Docker 套接字或生产容器数据")
    return target


def assert_backup_destination(path: str | Path) -> Path:
    target = assert_write_allowed(path, purpose="backup")
    source = real(PRODUCTION_ROOT)
    if target == source or _contained(target, source):
        raise ProductionGuard("备份不能写入生产网站目录")
    worktree = real("/opt/xedc-migration")
    if target == worktree or _contained(target, worktree):
        raise ProductionGuard("备份不能写入 Git 工作区")
    return target


def assert_restore_target(path: str | Path) -> Path:
    target = assert_write_allowed(path, purpose="restore")
    if production_webroot_present() and (target == real(PRODUCTION_ROOT) or _contained(target, real(PRODUCTION_ROOT))):
        raise ProductionGuard("拒绝把恢复目标指向生产目录")
    return target


def assert_host_mutation_allowed(purpose: str) -> None:
    """Package install, service changes, and local DB writes."""
    if production_webroot_present():
        raise ProductionGuard(f"生产网站目录存在，拒绝在本机执行变更操作: {purpose}")
    if not lab_marker_present():
        raise ProductionGuard(f"缺少 /etc/xedc-recover-lab，拒绝变更操作: {purpose}")


def assert_db_write_target(host: str, port: int, unix_socket: str = "") -> None:
    """Block writes to the production MariaDB, including when --force is set."""
    if unix_socket:
        socket_real = str(real(unix_socket)) if unix_socket else ""
        if production_webroot_present() or socket_real in PRODUCTION_SOCKETS or unix_socket in PRODUCTION_SOCKETS:
            raise ProductionGuard("拒绝通过本机数据库套接字写入")
    if production_webroot_present() and int(port) in (0, 3306) and host in ("", "localhost", "127.0.0.1", "::1"):
        raise ProductionGuard("拒绝连接生产数据库端口执行写入")
    if production_webroot_present() and not unix_socket and int(port) == 3306:
        raise ProductionGuard("拒绝向 3306 端口执行写入")


def assert_remote_hostname_is_not_local(hostname: str) -> None:
    local = socket.gethostname().strip().lower()
    remote = (hostname or "").strip().lower()
    if production_webroot_present() and remote and remote == local:
        raise ProductionGuard("数据库服务器主机名与生产主机相同，拒绝写入")


def assert_cron_install_target(path: str | Path) -> Path:
    target = real(path)
    if production_webroot_present() and (str(target).startswith("/etc") or str(target).startswith("/lib/systemd") or str(target).startswith("/var/spool/cron")):
        raise ProductionGuard("拒绝在生产主机安装定时任务")
    if str(target).startswith("/etc") or str(target).startswith("/lib/systemd") or str(target).startswith("/var/spool/cron"):
        assert_host_mutation_allowed("schedule")
    return target


def create_lab_marker(path: str | Path = LAB_MARKER) -> None:
    if production_webroot_present():
        raise ProductionGuard("生产网站目录存在，拒绝创建实验室标记")
    destination = Path(path)
    destination.write_text("xedc-recover-lab\n", encoding="utf-8")
    os.chmod(destination, 0o644)
