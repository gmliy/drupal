"""Command line for backup, restore, domain migration, and SSL."""

from __future__ import annotations

import argparse
import json
import sys

from . import __version__
from .backup import (
    BackupError,
    create_backup,
    list_backups,
    prune_backups,
    show_backup,
    verify_backup,
)
from .bundle import create_full_package, install_bundle
from .config import load_config
from .credentials import init_key, login_link, reset_admin_plan
from .crypto import CryptoError
from .domain import DomainError, migrate, self_test
from .installer import InstallError, ensure_marker, install_pinned
from .redact import redact
from .remote import download_backup, download_package, upload_backup, upload_package
from .restore import restore_backup
from .s3 import S3Error
from .safety import ProductionGuard
from .schedule import render as render_schedule
from .schedule import uninstall as uninstall_schedule
from .ssltool import SslError, certbot_plan, expiry, nginx_test, render_nginx, rollback, self_sign, write_config


def _print(data, as_json: bool) -> None:
    if as_json:
        print(json.dumps(data, ensure_ascii=False, indent=2, default=str))
    elif isinstance(data, str):
        print(data)
    else:
        print(json.dumps(data, ensure_ascii=False, indent=2, default=str))


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="xedc-recover", description="XEDC Drupal 备份、迁移与灾难恢复")
    parser.add_argument("--version", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--config")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--force", action="store_true", help="不能绕过生产环境保护")
    sub = parser.add_subparsers(dest="command")

    backup = sub.add_parser("backup")
    backup_sub = backup.add_subparsers(dest="action", required=True)
    create = backup_sub.add_parser("create")
    create.add_argument("--type", default="full", choices=["full", "incremental"])
    create.add_argument("--backup-id", default="")
    create.add_argument("--resume", action="store_true")
    create.add_argument("--allow-live-read", action="store_true")
    create.add_argument("--label", default="")
    for name in ("list", "status"):
        backup_sub.add_parser(name)
    show = backup_sub.add_parser("show")
    show.add_argument("--backup-id", required=True)
    verify = backup_sub.add_parser("verify")
    verify.add_argument("--backup-id", required=True)
    upload = backup_sub.add_parser("upload")
    upload.add_argument("--backup-id", required=True)
    download = backup_sub.add_parser("download")
    download.add_argument("--backup-id", required=True)
    download.add_argument("--dest")
    backup_sub.add_parser("prune")

    restore = sub.add_parser("restore")
    restore_sub = restore.add_subparsers(dest="action", required=True)
    run = restore_sub.add_parser("run")
    run.add_argument("--backup-id", required=True)
    run.add_argument("--target", required=True)
    run.add_argument("--role", default="lab", choices=["lab", "replacement"])
    run.add_argument("--yes", action="store_true")
    run.add_argument("--skip-database", action="store_true")
    run.add_argument("--import-database", action="store_true")
    run.add_argument("--db-name", default="xedc_restore")
    run.add_argument("--db-user", default="xedc_restore")
    run.add_argument("--db-password-env", default="XEDC_RESTORE_DB_PASSWORD")
    run.add_argument("--db-host", default="127.0.0.1")
    run.add_argument("--db-port", type=int, default=3306)
    run.add_argument("--trusted-host", action="append")
    plan = restore_sub.add_parser("plan")
    plan.add_argument("--backup-id", required=True)
    plan.add_argument("--target", required=True)

    domain = sub.add_parser("domain")
    domain_sub = domain.add_subparsers(dest="action", required=True)
    domain_sub.add_parser("self-test")
    move = domain_sub.add_parser("migrate")
    move.add_argument("--from", dest="source", required=True)
    move.add_argument("--to", required=True)
    move.add_argument("--host", default="127.0.0.1")
    move.add_argument("--port", type=int, default=3306)
    move.add_argument("--user", required=True)
    move.add_argument("--database", required=True)
    move.add_argument("--password-env", default="XEDC_DB_PASSWORD")

    ssl = sub.add_parser("ssl")
    ssl_sub = ssl.add_subparsers(dest="action", required=True)
    sign = ssl_sub.add_parser("self-sign")
    sign.add_argument("--domain", required=True)
    sign.add_argument("--out-dir", required=True)
    render = ssl_sub.add_parser("render")
    render.add_argument("--server-name", required=True)
    render.add_argument("--webroot", required=True)
    render.add_argument("--prefix", required=True)
    render.add_argument("--port", type=int, default=18081)
    render.add_argument("--cert", default="")
    render.add_argument("--key", default="")
    render.add_argument("--output", required=True)
    test = ssl_sub.add_parser("test")
    test.add_argument("--config", required=True)
    test.add_argument("--prefix", required=True)
    back = ssl_sub.add_parser("rollback")
    back.add_argument("--config", required=True)
    check = ssl_sub.add_parser("expiry")
    check.add_argument("--cert", required=True)
    plan_ssl = ssl_sub.add_parser("plan")
    plan_ssl.add_argument("--domain", required=True)
    plan_ssl.add_argument("--webroot", required=True)
    plan_ssl.add_argument("--email", required=True)

    cred = sub.add_parser("credentials")
    cred_sub = cred.add_subparsers(dest="action", required=True)
    init = cred_sub.add_parser("init")
    init.add_argument("--out", required=True)
    reset = cred_sub.add_parser("reset-admin")
    reset.add_argument("--reset-admin", action="store_true")
    reset.add_argument("--user", default="admin")
    link = cred_sub.add_parser("login-link")
    link.add_argument("--login-link", action="store_true")
    link.add_argument("--target", required=True)
    link.add_argument("--user", default="admin")

    sched = sub.add_parser("schedule")
    sched_sub = sched.add_subparsers(dest="action", required=True)
    install = sched_sub.add_parser("install")
    install.add_argument("--prefix", required=True)
    install.add_argument("--binary", required=True)
    install.add_argument("--hour", type=int, default=19)
    remove = sched_sub.add_parser("uninstall")
    remove.add_argument("--prefix", required=True)
    sched_sub.add_parser("status")

    packages = sub.add_parser("install")
    packages.add_argument("--yes", action="store_true")
    bundle = sub.add_parser("bundle")
    bundle_sub = bundle.add_subparsers(dest="action", required=True)
    bundle_create = bundle_sub.add_parser("create")
    bundle_create.add_argument("--output", required=True)
    bundle_create.add_argument("--from-backup", default="")
    bundle_create.add_argument("--allow-live-read", action="store_true")
    bundle_create.add_argument("--label", default="")
    bundle_install = bundle_sub.add_parser("install")
    bundle_install.add_argument("--backup", required=True)
    bundle_install.add_argument("--target", required=True)
    bundle_install.add_argument("--yes", action="store_true")
    bundle_install.add_argument("--role", default="lab", choices=["lab", "replacement"])
    bundle_install.add_argument("--domain", default="")
    bundle_install.add_argument("--db-name", default="xedc_restore")
    bundle_install.add_argument("--db-user", default="xedc_restore")
    bundle_install.add_argument("--db-password-env", default="XEDC_RESTORE_DB_PASSWORD")
    bundle_install.add_argument("--db-host", default="127.0.0.1")
    bundle_install.add_argument("--db-port", type=int, default=3306)
    bundle_install.add_argument("--search-dir", default="")
    bundle_install.add_argument("--skip-services", action="store_true")
    bundle_install.add_argument("--skip-database", action="store_true")
    bundle_upload = bundle_sub.add_parser("upload")
    bundle_upload.add_argument("--archive", required=True)
    bundle_fetch = bundle_sub.add_parser("fetch")
    bundle_fetch.add_argument("--name", default="latest")
    bundle_fetch.add_argument("--output", required=True)
    sub.add_parser("doctor")
    sub.add_parser("deps")
    sub.add_parser("audit")
    return parser


def _normalize(argv):
    if argv is None:
        return None
    flags = {"--verbose", "--json", "--dry-run", "--force", "--version"}
    head = []
    rest = []
    index = 0
    while index < len(argv):
        item = argv[index]
        if item == "--config" and index + 1 < len(argv):
            head.extend([item, argv[index + 1]])
            index += 2
            continue
        if item in flags or item.startswith("--config="):
            head.append(item)
            index += 1
            continue
        rest.append(item)
        index += 1
    return head + rest


def main(argv=None) -> int:
    parser = build_parser()
    if argv is None:
        argv = sys.argv[1:]
    args = parser.parse_args(_normalize(list(argv)))
    if args.version or args.command is None and not args.version:
        if args.version or args.command is None:
            if args.version or args.command is None:
                print(__version__)
                if args.command is None:
                    parser.print_help()
                    return 0 if args.version else 2
    try:
        config = load_config(args.config)
        as_json = args.json
        if args.command == "doctor":
            from .safety import lab_marker_present, production_webroot_present
            _print({
                "version": __version__,
                "production_webroot": production_webroot_present(),
                "lab_marker": lab_marker_present(),
                "force_ignored": bool(args.force),
            }, as_json)
            return 0
        if args.command == "backup" and args.action == "create":
            result = create_backup(
                config,
                backup_type=args.type,
                dry_run=args.dry_run,
                allow_live_read=args.allow_live_read,
                resume=args.resume,
                backup_id=args.backup_id,
                label=args.label,
            )
            _print(result, as_json)
            return 0
        if args.command == "backup" and args.action in ("list", "status"):
            _print({"backups": list_backups(config["backup_root"])}, as_json)
            return 0
        if args.command == "backup" and args.action == "show":
            _print(show_backup(config["backup_root"], args.backup_id), as_json)
            return 0
        if args.command == "backup" and args.action == "verify":
            _print(verify_backup(config, args.backup_id), as_json)
            return 0
        if args.command == "backup" and args.action == "upload":
            _print(upload_backup(config, args.backup_id), as_json)
            return 0
        if args.command == "backup" and args.action == "download":
            _print(download_backup(config, args.backup_id, args.dest), as_json)
            return 0
        if args.command == "backup" and args.action == "prune":
            _print(prune_backups(config, dry_run=args.dry_run), as_json)
            return 0
        if args.command == "restore" and args.action == "plan":
            _print(restore_backup(config, args.backup_id, args.target, dry_run=True), as_json)
            return 0
        if args.command == "restore" and args.action == "run":
            import os
            _print(restore_backup(
                config,
                args.backup_id,
                args.target,
                role=args.role,
                yes=args.yes,
                dry_run=args.dry_run,
                skip_database=args.skip_database,
                import_database_now=args.import_database,
                database_name=args.db_name,
                database_user=args.db_user,
                database_password=os.environ.get(args.db_password_env, ""),
                database_host=args.db_host,
                database_port=args.db_port,
                trusted_hosts=args.trusted_host,
            ), as_json)
            return 0
        if args.command == "domain" and args.action == "self-test":
            _print({"status": self_test()}, as_json)
            return 0
        if args.command == "domain" and args.action == "migrate":
            import os
            _print(migrate(
                host=args.host,
                port=args.port,
                user=args.user,
                password=os.environ.get(args.password_env, ""),
                database=args.database,
                source=args.source,
                target=args.to,
                dry_run=args.dry_run,
            ), as_json)
            return 0
        if args.command == "ssl" and args.action == "self-sign":
            _print(self_sign(args.domain, args.out_dir), as_json)
            return 0
        if args.command == "ssl" and args.action == "render":
            text = render_nginx(server_name=args.server_name, webroot=args.webroot, prefix=args.prefix, port=args.port, cert=args.cert, key=args.key)
            path = write_config(args.output, text)
            _print({"config": str(path)}, as_json)
            return 0
        if args.command == "ssl" and args.action == "test":
            ok, output = nginx_test(args.config, args.prefix)
            _print({"ok": ok, "output": output}, as_json)
            return 0 if ok else 1
        if args.command == "ssl" and args.action == "rollback":
            _print({"restored": str(rollback(args.config))}, as_json)
            return 0
        if args.command == "ssl" and args.action == "expiry":
            _print({"expires": expiry(args.cert)}, as_json)
            return 0
        if args.command == "ssl" and args.action == "plan":
            _print(certbot_plan(args.domain, args.webroot, args.email), as_json)
            return 0
        if args.command == "credentials" and args.action == "init":
            result = init_key(args.out)
            _print({"key_id": result["key_id"], "path": result["path"]}, as_json)
            return 0
        if args.command == "credentials" and args.action == "reset-admin":
            result = reset_admin_plan(explicit=args.reset_admin, user=args.user)
            if not as_json:
                print("管理员应急密码已生成，但默认不写入日志。请使用 --json 并立刻保存到 0600 文件。")
                return 0
            _print(result, True)
            return 0
        if args.command == "credentials" and args.action == "login-link":
            result = login_link(target=args.target, user=args.user, explicit=args.login_link)
            _print({"file": result["file"], "expires": result["expires"], "user": result["user"]}, as_json)
            return 0
        if args.command == "schedule" and args.action == "install":
            _print(render_schedule(args.prefix, binary=args.binary, config=args.config or ""), as_json)
            return 0
        if args.command == "schedule" and args.action == "uninstall":
            _print({"removed": uninstall_schedule(args.prefix)}, as_json)
            return 0
        if args.command == "schedule" and args.action == "status":
            _print({"note": "生产主机不会安装定时任务。请查看 install 时指定的 --prefix。"}, as_json)
            return 0
        if args.command == "install":
            ensure_marker()
            _print(install_pinned(yes=args.yes), as_json)
            return 0
        if args.command == "bundle" and args.action == "create":
            _print(create_full_package(
                config,
                args.output,
                from_backup=args.from_backup,
                allow_live_read=args.allow_live_read,
                label=args.label,
            ), as_json)
            return 0
        if args.command == "bundle" and args.action == "upload":
            _print(upload_package(config, args.archive), as_json)
            return 0
        if args.command == "bundle" and args.action == "fetch":
            _print(download_package(config, args.name, args.output), as_json)
            return 0
        if args.command == "bundle" and args.action == "install":
            import os
            _print(install_bundle(
                config,
                args.backup,
                args.target,
                yes=args.yes,
                role=args.role,
                domain=args.domain,
                database_name=args.db_name,
                database_user=args.db_user,
                database_password=os.environ.get(args.db_password_env, ""),
                database_host=args.db_host,
                database_port=args.db_port,
                skip_services=args.skip_services,
                skip_database=args.skip_database,
                search_dir=args.search_dir,
            ), as_json)
            return 0
        if args.command in ("audit", "deps"):
            from .audit import collect
            _print(collect(), as_json)
            return 0
        parser.print_help()
        return 2
    except ProductionGuard as exc:
        print(redact(str(exc)), file=sys.stderr)
        return 3
    except CryptoError as exc:
        print(redact(str(exc)), file=sys.stderr)
        return 4
    except (BackupError, DomainError, SslError, S3Error, InstallError) as exc:
        print(redact(str(exc)), file=sys.stderr)
        return getattr(exc, "exit_code", 1)
    except Exception as exc:
        print(redact(f"{exc.__class__.__name__}: {exc}"), file=sys.stderr)
        return 1
