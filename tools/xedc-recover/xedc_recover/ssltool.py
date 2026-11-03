"""Self-signed certificates, Nginx config rendering, syntax test, and rollback."""

from __future__ import annotations

import os
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path

from .safety import ProductionGuard, assert_write_allowed, production_webroot_present


class SslError(RuntimeError):
    exit_code = 1


NGINX_TEMPLATE = """worker_processes 1;
error_log {prefix}/error.log;
pid {prefix}/nginx.pid;
events {{ worker_connections 128; }}
http {{
    access_log {prefix}/access.log;
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    server {{
        listen {port};
        server_name {server_name};
        root {webroot};
        index index.php index.html;
        client_max_body_size 220m;
        location ~ \\.php$ {{
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_pass unix:{fpm};
        }}
        location / {{ try_files $uri /index.php?$query_string; }}
        {ssl_lines}
    }}
}}
"""


def self_sign(domain: str, out_dir: str, days: int = 30) -> dict:
    directory = assert_write_allowed(out_dir, purpose="ssl")
    directory.mkdir(parents=True, exist_ok=True)
    key = directory / "privkey.pem"
    cert = directory / "fullchain.pem"
    command = [
        "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
        "-keyout", str(key), "-out", str(cert), "-days", str(days),
        "-subj", f"/CN={domain}",
    ]
    completed = subprocess.run(command, text=True, capture_output=True)
    if completed.returncode != 0:
        raise SslError(completed.stderr.strip())
    os.chmod(key, 0o600)
    return {"cert": str(cert), "key": str(key), "domain": domain}


def render_nginx(*, server_name: str, webroot: str, prefix: str, port: int = 8080, fpm: str = "/run/php/php8.5-fpm.sock", cert: str = "", key: str = "") -> str:
    ssl_lines = ""
    if cert and key:
        ssl_lines = f"ssl_certificate {cert};\n        ssl_certificate_key {key};"
    return NGINX_TEMPLATE.format(
        prefix=prefix,
        port=port,
        server_name=server_name,
        webroot=webroot,
        fpm=fpm,
        ssl_lines=ssl_lines,
    )


def write_config(path: str, text: str) -> Path:
    destination = assert_write_allowed(path, purpose="nginx-test")
    if production_webroot_present() and str(destination).startswith("/etc/nginx"):
        raise ProductionGuard("拒绝修改生产 Nginx 配置")
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists():
        shutil.copy2(destination, destination.with_suffix(destination.suffix + ".bak"))
    destination.write_text(text, encoding="utf-8")
    return destination


def nginx_test(config_path: str, prefix: str) -> tuple[bool, str]:
    prefix_path = assert_write_allowed(prefix, purpose="nginx-test")
    prefix_path.mkdir(parents=True, exist_ok=True)
    (prefix_path / "logs").mkdir(exist_ok=True)
    completed = subprocess.run(
        ["nginx", "-t", "-p", str(prefix_path), "-c", config_path],
        text=True,
        capture_output=True,
    )
    output = (completed.stderr or completed.stdout).strip()
    return completed.returncode == 0, output


def rollback(path: str) -> Path:
    destination = Path(path)
    backup = destination.with_suffix(destination.suffix + ".bak")
    if not backup.is_file():
        raise SslError("没有可回滚的配置备份")
    shutil.copy2(backup, destination)
    return destination


def expiry(cert: str) -> str:
    completed = subprocess.run(["openssl", "x509", "-enddate", "-noout", "-in", cert], text=True, capture_output=True)
    if completed.returncode != 0:
        raise SslError(completed.stderr.strip())
    return completed.stdout.strip().replace("notAfter=", "")


def certbot_plan(domain: str, webroot: str, email: str) -> dict:
    if production_webroot_present():
        execute = False
        reason = "生产网站仍在本机，只生成计划，不申请正式证书"
    else:
        execute = False
        reason = "默认只生成 staging 计划。正式证书需要单独授权，避免消耗 Let's Encrypt 配额。"
    command = [
        "certbot", "certonly", "--webroot", "-w", webroot,
        "-d", domain, "--email", email, "--agree-tos", "--non-interactive",
        "--staging", "--keep-until-expiring",
    ]
    return {"execute": execute, "reason": reason, "command": command, "generated": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")}
