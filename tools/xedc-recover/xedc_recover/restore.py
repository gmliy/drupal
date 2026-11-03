"""Restore a backup chain onto an isolated target. Never writes the production tree."""

from __future__ import annotations

import gzip
import json
import os
import shutil
import tarfile
import tempfile
from pathlib import Path

from .backup import BackupError, canonical, show_backup
from .crypto import decrypt_file, load_key
from .safety import ProductionGuard, assert_restore_target, lab_marker_present, production_webroot_present

STRIP_LAB = ("xedc-storage-keys",)


def chain_ids(root: Path, backup_id: str) -> list[str]:
    ordered = []
    seen = set()
    current = backup_id
    while current:
        if current in seen:
            raise BackupError("备份链出现循环")
        seen.add(current)
        manifest = show_backup(root, current)
        ordered.append(current)
        current = manifest.get("parent_id") or ""
    ordered.reverse()
    return ordered


def _contained(root: Path, relative: str) -> bool:
    if not relative or relative.startswith("/") or ".." in Path(relative).parts or "\x00" in relative:
        return False
    try:
        (root / relative).resolve().relative_to(root.resolve())
    except ValueError:
        return False
    return True


def _symlink_escapes(root: Path, name: str, linkname: str) -> bool:
    if not linkname or linkname.startswith("/") or "\x00" in linkname:
        return True
    resolved = ((root / name).parent / linkname).resolve()
    try:
        resolved.relative_to(root.resolve())
    except ValueError:
        return True
    return False


def _reject_member_name(member: tarfile.TarInfo, root: Path) -> None:
    name = member.name
    if not _contained(root, name) or member.isdev():
        raise BackupError(f"备份包包含不安全路径: {name}")


def _safe_members(archive: tarfile.TarFile, root: Path):
    regular = []
    hardlinks = []
    for member in archive.getmembers():
        _reject_member_name(member, root)
        if member.issym():
            if _symlink_escapes(root, member.name, member.linkname or ""):
                raise BackupError(f"备份包包含不安全路径: {member.name}")
            continue
        if member.islnk():
            if not _contained(root, member.linkname or ""):
                raise BackupError(f"备份包包含不安全路径: {member.name}")
            hardlinks.append(member)
            continue
        regular.append(member)
    return regular, hardlinks


def _materialize_hardlinks(destination: Path, hardlinks: list[tarfile.TarInfo]) -> int:
    pending = list(hardlinks)
    copied = 0
    while pending:
        next_pending = []
        for member in pending:
            source = destination / member.linkname
            target = destination / member.name
            if not source.is_file():
                next_pending.append(member)
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            if not target.exists():
                shutil.copy2(source, target)
                copied += 1
        if len(next_pending) == len(pending):
            break
        pending = next_pending
    unresolved = [member.name for member in pending if "node_modules" not in Path(member.name).parts]
    if unresolved:
        raise BackupError(f"备份包中的硬链接无法还原: {unresolved[0]}")
    return copied


def extract_archive(archive_path: Path, destination: Path) -> int:
    destination.mkdir(parents=True, exist_ok=True)
    with tarfile.open(archive_path, "r:gz") as archive:
        regular, hardlinks = _safe_members(archive, destination)
        archive.extractall(destination, members=regular, filter="data")
    return len(regular) + _materialize_hardlinks(destination, hardlinks)


def _decrypt_artifact(directory: Path, manifest: dict, name: str, key: bytes, key_id: str, plain_dir: Path) -> Path | None:
    artifact = manifest["artifacts"][name]
    if not artifact.get("plain_sha256"):
        return None
    source = directory / artifact["file"]
    suffix = ".sql.gz" if name == "database" else ".tar.gz" if name != "secrets" else ".json"
    target = plain_dir / f"{name}{suffix}"
    digest = decrypt_file(source, target, key, key_id)
    if digest != artifact["plain_sha256"]:
        raise BackupError(f"{name} 解密后校验失败")
    return target


def isolate_storage(target: Path) -> None:
    for path in target.rglob("xedc-storage-keys"):
        if path.is_dir():
            shutil.rmtree(path)
    for active in target.rglob("active.json"):
        if active.parent.name != "xedc-storage-runtime":
            continue
        try:
            data = json.loads(active.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            continue
        data["public_mode"] = "local"
        data["cdn_enabled"] = False
        data["writes_paused"] = True
        data["status"] = "lab-isolated"
        active.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def _replace_last(pattern: str, text: str, build) -> tuple[str, int]:
    import re
    matches = list(re.finditer(pattern, text, re.S))
    if not matches:
        return text, 0
    match = matches[-1]
    text = text[:match.start()] + build(match) + text[match.end():]
    return text, 1


def rewrite_settings(text: str, *, database: str, username: str, password: str, host: str, port: int, trusted_hosts: list[str] | None = None, private_path: str = "") -> str:
    values = {
        "database": database,
        "username": username,
        "password": password,
        "host": host,
        "port": str(port),
    }
    for key, value in values.items():
        if "'" in value or value.endswith("\\"):
            raise BackupError(f"settings.php 的 {key} 含有不允许写入的字符")
        text, count = _replace_last(
            rf"('{key}'\s*=>\s*')[^']*(')",
            text,
            lambda match, replacement=value: match.group(1) + replacement + match.group(2),
        )
        if count != 1:
            raise BackupError(f"无法改写 settings.php 中的 {key}")
    if trusted_hosts is not None:
        for host_pattern in trusted_hosts:
            if "'" in host_pattern or host_pattern.endswith("\\"):
                raise BackupError("trusted host 含有不允许写入的字符")
        body = "\n".join(f"  '{host_pattern}'," for host_pattern in trusted_hosts)
        block = "$settings['trusted_host_patterns'] = [\n" + body + "\n];"
        text, count = _replace_last(
            r"\$settings\['trusted_host_patterns'\]\s*=\s*\[.*?\];",
            text,
            lambda _match, replacement=block: replacement,
        )
        if count != 1:
            text += "\n" + block + "\n"
    if private_path:
        if "'" in private_path or private_path.endswith("\\"):
            raise BackupError("私有文件目录含有不允许写入的字符")
        text, count = _replace_last(
            r"(\$settings\['file_private_path'\]\s*=\s*')[^']*(')",
            text,
            lambda match, replacement=private_path: match.group(1) + replacement + match.group(2),
        )
        if count != 1:
            text += f"\n$settings['file_private_path'] = '{private_path}';\n"
    return text


def _chmod_tree(root: Path, dir_mode: int, file_mode: int) -> None:
    if not root.exists():
        return
    for dirpath, _dirnames, filenames in os.walk(root):
        os.chmod(dirpath, dir_mode)
        for name in filenames:
            path = Path(dirpath) / name
            if path.is_symlink():
                continue
            os.chmod(path, file_mode)


def apply_deletions(target: Path, deleted: list[str]) -> None:
    for relative in deleted:
        path = target / relative
        try:
            path.resolve().relative_to(target.resolve())
        except ValueError as exc:
            raise BackupError(f"拒绝删除目标目录之外的文件: {relative}") from exc
        if path.is_file():
            path.unlink()


def import_database(sql_gz: Path, *, database: str, user: str, password: str) -> dict:
    if production_webroot_present() or not lab_marker_present():
        raise ProductionGuard("只有不存在生产网站、且已写入恢复标记的机器才能导入数据库")
    if not re_ident(database) or not re_ident(user) or "'" in password or "\\" in password:
        raise BackupError("数据库名、用户名或密码包含不允许的字符")
    sql_plain = sql_gz.with_suffix("")
    gunzip_sql(sql_gz, sql_plain)
    init_sql = sql_gz.parent / "db-init.sql"
    init_sql.write_text(
        "CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;\n"
        "CREATE USER IF NOT EXISTS '{user}'@'localhost' IDENTIFIED BY '{password}';\n"
        "CREATE USER IF NOT EXISTS '{user}'@'127.0.0.1' IDENTIFIED BY '{password}';\n"
        "ALTER USER '{user}'@'localhost' IDENTIFIED BY '{password}';\n"
        "ALTER USER '{user}'@'127.0.0.1' IDENTIFIED BY '{password}';\n"
        "GRANT ALL PRIVILEGES ON `{db}`.* TO '{user}'@'localhost';\n"
        "GRANT ALL PRIVILEGES ON `{db}`.* TO '{user}'@'127.0.0.1';\n"
        "FLUSH PRIVILEGES;\n".format(db=database, user=user, password=password),
        encoding="utf-8",
    )
    os.chmod(init_sql, 0o600)
    with init_sql.open("rb") as handle:
        completed = subprocess_run(["mariadb"], stdin=handle)
    init_sql.unlink(missing_ok=True)
    if completed.returncode != 0:
        raise BackupError("无法在隔离数据库中创建恢复用户")
    with sql_plain.open("rb") as handle:
        imported = subprocess_run(["mariadb", database], stdin=handle)
    sql_plain.unlink(missing_ok=True)
    if imported.returncode != 0:
        detail = imported.stderr.decode("utf-8", "replace")[-400:]
        raise BackupError(f"数据库导入失败: {detail}")
    return {"imported": True, "database": database}


def re_ident(value: str) -> bool:
    import re
    return re.fullmatch(r"[A-Za-z0-9_]+", value) is not None


def subprocess_run(command: list[str], stdin=None):
    import subprocess
    return subprocess.run(command, stdin=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def restore_backup(config: dict, backup_id: str, target: str, *, role: str = "lab", yes: bool = False, dry_run: bool = False, skip_database: bool = False, import_database_now: bool = False, database_name: str = "xedc_restore", database_user: str = "xedc_restore", database_password: str = "", database_host: str = "127.0.0.1", database_port: int = 3306, trusted_hosts: list[str] | None = None) -> dict:
    if role not in ("lab", "replacement"):
        raise BackupError("role 只能是 lab 或 replacement")
    if role == "replacement" and production_webroot_present():
        raise ProductionGuard("生产网站仍在本机，拒绝以 replacement 角色恢复")
    destination = assert_restore_target(target)
    root = Path(config["backup_root"])
    ids = chain_ids(root, backup_id)
    if dry_run:
        return {"dry_run": True, "chain": ids, "target": str(destination), "role": role}
    if not yes:
        raise BackupError("恢复会写入目标目录，需要 --yes")
    key_id, key = load_key(config["key_file"])
    destination.mkdir(parents=True, exist_ok=True)
    os.chmod(destination, 0o750)
    report = {"chain": ids, "extracted": [], "database_imported": False, "role": role}
    newest = show_backup(root, backup_id)
    if newest["encryption"]["key_id"] != key_id:
        raise BackupError("密钥编号与最新备份不一致")
    for item_id in ids:
        manifest = show_backup(root, item_id)
        directory = root / item_id
        plain = Path(tempfile.mkdtemp(prefix="xedc-plain-"))
        os.chmod(plain, 0o700)
        try:
            code = _decrypt_artifact(directory, manifest, "code", key, key_id, plain)
            public = _decrypt_artifact(directory, manifest, "public", key, key_id, plain)
            private = _decrypt_artifact(directory, manifest, "private", key, key_id, plain)
            if code:
                report["extracted"].append({"id": item_id, "code": extract_archive(code, destination / "code")})
            if public:
                report["extracted"].append({"id": item_id, "public": extract_archive(public, destination / "public")})
            if private:
                report["extracted"].append({"id": item_id, "private": extract_archive(private, destination / "private")})
            apply_deletions(destination / "code", [path for path in manifest.get("deleted", []) if not path.startswith("public/") and not path.startswith("private/")])
            apply_deletions(destination / "public", [path.removeprefix("public/") for path in manifest.get("deleted", []) if path.startswith("public/")])
            apply_deletions(destination / "private", [path.removeprefix("private/") for path in manifest.get("deleted", []) if path.startswith("private/")])
        finally:
            shutil.rmtree(plain, ignore_errors=True)
    if role == "lab":
        isolate_storage(destination)
    if not skip_database:
        manifest = newest
        directory = root / backup_id
        plain = Path(tempfile.mkdtemp(prefix="xedc-plain-"))
        os.chmod(plain, 0o700)
        try:
            database = _decrypt_artifact(directory, manifest, "database", key, key_id, plain)
            if database:
                sql_target = destination / "database.sql.gz"
                shutil.copyfile(database, sql_target)
                os.chmod(sql_target, 0o600)
                report["database_sql"] = str(sql_target)
                report["database_imported"] = False
        finally:
            shutil.rmtree(plain, ignore_errors=True)
    public_rel = config.get("public_files", "web/sites/default/files")
    public_link = destination / "code" / public_rel
    if (destination / "public").is_dir():
        public_link.parent.mkdir(parents=True, exist_ok=True)
        if public_link.is_symlink():
            public_link.unlink()
        elif public_link.is_dir() and not any(public_link.iterdir()):
            public_link.rmdir()
        if not public_link.exists():
            public_link.symlink_to(destination / "public")
    private_path = str(destination / "private")
    settings = destination / "code" / "web" / "sites" / "default" / "settings.php"
    if settings.is_file() and database_password:
        text = settings.read_text(encoding="utf-8", errors="replace")
        text = rewrite_settings(
            text,
            database=database_name,
            username=database_user,
            password=database_password,
            host=database_host,
            port=database_port,
            trusted_hosts=trusted_hosts,
            private_path=private_path,
        )
        settings.write_text(text, encoding="utf-8")
        os.chmod(settings, 0o440)
    os.chmod(destination, 0o755)
    _chmod_tree(destination / "code", 0o755, 0o644)
    _chmod_tree(destination / "public", 0o755, 0o644)
    _chmod_tree(destination / "private", 0o750, 0o640)
    if settings.is_file():
        os.chmod(settings, 0o440)
    if import_database_now:
        if "database_sql" not in report:
            raise BackupError("备份中没有数据库，不能导入")
        import_database(Path(report["database_sql"]), database=database_name, user=database_user, password=database_password)
        report["database_imported"] = True
    report_path = destination / "RESTORE_REPORT.json"
    report_path.write_text(canonical({"status": "PASS", **report}), encoding="utf-8")
    return report


def gunzip_sql(path: Path, destination: Path) -> None:
    with gzip.open(path, "rb") as source, open(destination, "wb") as output:
        shutil.copyfileobj(source, output)
