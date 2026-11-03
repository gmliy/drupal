"""Domain migration through the PHP rewriter. Production socket writes are refused."""

from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path

from .safety import (
    ProductionGuard,
    assert_db_write_target,
    assert_remote_hostname_is_not_local,
    production_webroot_present,
)

PHP = Path(__file__).resolve().parents[1] / "php" / "domain_migrate.php"


class DomainError(RuntimeError):
    exit_code = 1


def self_test() -> str:
    completed = subprocess.run(["php", str(PHP), "self-test"], text=True, capture_output=True)
    if completed.returncode != 0:
        raise DomainError(completed.stderr.strip() or completed.stdout.strip())
    return completed.stdout.strip()


def _guard_database(host: str, port: int, unix_socket: str, hostname: str) -> None:
    assert_db_write_target(host, port, unix_socket)
    if production_webroot_present():
        assert_remote_hostname_is_not_local(hostname)


def _client_file(user: str, password: str, host: str, port: int) -> Path:
    import tempfile
    handle = tempfile.NamedTemporaryFile("w", delete=False, prefix="xedc-db-", suffix=".cnf")
    handle.write("[client]\n")
    handle.write(f"user={user}\n")
    handle.write(f"password={password}\n")
    handle.write(f"host={host}\n")
    handle.write(f"port={port}\n")
    handle.write("protocol=tcp\n")
    handle.close()
    os.chmod(handle.name, 0o600)
    return Path(handle.name)


def migrate(*, host: str, port: int, user: str, password: str, database: str, source: str, target: str, dry_run: bool, unix_socket: str = "") -> dict:
    if unix_socket and not dry_run:
        assert_db_write_target(host, port, unix_socket)
    if not dry_run and production_webroot_present() and int(port) == 3306:
        raise ProductionGuard("拒绝在生产数据库端口执行域名迁移")
    defaults = _client_file(user, password, host, port)
    try:
        probe = subprocess.run(
            ["mariadb", f"--defaults-extra-file={defaults}", "-N", "-e", "SELECT @@hostname", database],
            text=True,
            capture_output=True,
        )
    finally:
        defaults.unlink(missing_ok=True)
    if probe.returncode != 0:
        raise DomainError(f"无法连接目标数据库: {(probe.stderr or '').replace(password, '***')[:240]}")
    hostname = probe.stdout.strip()
    if not dry_run:
        _guard_database(host, port, unix_socket, hostname)
    elif production_webroot_present() and hostname.strip().lower() == os.uname().nodename.strip().lower() and int(port) == 3306:
        raise ProductionGuard("拒绝把生产数据库当作域名迁移目标")
    payload = {
        "host": host,
        "port": port,
        "user": user,
        "password": password,
        "database": database,
        "from": source,
        "to": target,
        "dry_run": dry_run,
    }
    completed = subprocess.run(["php", str(PHP), "migrate"], input=json.dumps(payload), text=True, capture_output=True)
    if completed.returncode != 0:
        message = completed.stderr.replace(password, "***")
        raise DomainError(message.strip() or "域名迁移失败")
    line = completed.stdout.strip().splitlines()[-1]
    return json.loads(line)


def dump_for_rollback(*, host: str, port: int, user: str, password: str, database: str, destination: str) -> str:
    if production_webroot_present() and int(port) == 3306:
        raise ProductionGuard("拒绝从生产端口导出回滚副本到可变恢复流程")
    defaults = _client_file(user, password, host, port)
    target = Path(destination)
    try:
        with open(target, "w", encoding="utf-8") as handle:
            completed = subprocess.run(
                ["mysqldump", f"--defaults-extra-file={defaults}", "--single-transaction", "--no-tablespaces", database],
                stdout=handle,
                stderr=subprocess.PIPE,
                text=True,
            )
        if completed.returncode != 0:
            raise DomainError("回滚备份导出失败")
    finally:
        defaults.unlink(missing_ok=True)
    os.chmod(target, 0o600)
    return str(target)


def restore_rollback(*, host: str, port: int, user: str, password: str, database: str, source: str) -> None:
    if production_webroot_present() and int(port) == 3306:
        raise ProductionGuard("拒绝把回滚导入生产数据库")
    defaults = _client_file(user, password, host, port)
    try:
        with open(source, "rb") as handle:
            completed = subprocess.run(
                ["mariadb", f"--defaults-extra-file={defaults}", database],
                stdin=handle,
                stderr=subprocess.PIPE,
            )
        if completed.returncode != 0:
            raise DomainError("回滚导入失败")
    finally:
        defaults.unlink(missing_ok=True)


def count_remaining(*, host: str, port: int, user: str, password: str, database: str, needle: str) -> int:
    defaults = _client_file(user, password, host, port)
    statement = "SELECT COUNT(*) FROM sample WHERE body LIKE '%" + needle.replace("'", "") + "%' OR meta LIKE '%" + needle.replace("'", "") + "%' OR note LIKE '%" + needle.replace("'", "") + "%'"
    try:
        completed = subprocess.run(
            ["mariadb", f"--defaults-extra-file={defaults}", "-N", "-e", statement, database],
            text=True,
            capture_output=True,
        )
    finally:
        defaults.unlink(missing_ok=True)
    if completed.returncode != 0:
        raise DomainError("无法复查数据库")
    return int((completed.stdout or "0").strip() or "0")
