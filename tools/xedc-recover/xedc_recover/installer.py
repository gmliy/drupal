"""Install the runtime onto a machine that is not the current production host."""

from __future__ import annotations

import os
import platform
import shutil
import subprocess
from pathlib import Path

from .safety import ProductionGuard, assert_host_mutation_allowed, create_lab_marker, production_webroot_present

PACKAGES = [
    "ca-certificates", "curl", "gnupg", "python3", "python3-cryptography",
    "nginx", "mariadb-server", "mariadb-client", "redis-server",
    "ffmpeg", "p7zip-full", "unzip", "certbot", "openssl",
    "php8.5-cli", "php8.5-fpm", "php8.5-mysql", "php8.5-gd", "php8.5-mbstring",
    "php8.5-xml", "php8.5-curl", "php8.5-zip", "php8.5-intl", "php8.5-bcmath",
    "php8.5-apcu", "php8.5-redis", "php8.5-igbinary",
]


class InstallError(RuntimeError):
    exit_code = 5


def assert_install_allowed() -> None:
    if production_webroot_present():
        raise ProductionGuard("生产网站目录存在，拒绝安装或升级软件包")
    assert_host_mutation_allowed("install")


def _run(command: list[str]) -> None:
    completed = subprocess.run(command, text=True, capture_output=True)
    if completed.returncode != 0:
        raise InstallError((completed.stderr or completed.stdout or "apt failed")[-800:])


def install_pinned(*, yes: bool) -> dict:
    if not yes:
        raise InstallError("安装软件需要 --yes")
    assert_install_allowed()
    if platform.system() != "Linux":
        raise InstallError("目前只自动安装 Linux 运行环境")
    os.environ["DEBIAN_FRONTEND"] = "noninteractive"
    policy = Path("/usr/sbin/policy-rc.d")
    if not policy.exists():
        policy.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
        os.chmod(policy, 0o755)
    _run(["apt-get", "update"])
    _run(["apt-get", "install", "-y", "ca-certificates", "curl", "gnupg", "software-properties-common", "python3", "python3-cryptography"])
    if not Path("/etc/apt/sources.list.d/ondrej-php.sources").exists() and shutil.which("add-apt-repository"):
        _run(["add-apt-repository", "-y", "ppa:ondrej/php"])
    if not Path("/etc/apt/sources.list.d/nginx-xedc.list").exists():
        _run(["bash", "-lc", "curl -fsSL https://nginx.org/keys/nginx_signing.key | gpg --dearmor -o /usr/share/keyrings/nginx-archive-keyring.gpg"])
        os.chmod("/usr/share/keyrings/nginx-archive-keyring.gpg", 0o644)
        Path("/etc/apt/sources.list.d/nginx-xedc.list").write_text(
            "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] http://nginx.org/packages/mainline/ubuntu noble nginx\n",
            encoding="utf-8",
        )
    if not Path("/etc/apt/sources.list.d/mariadb-xedc.sources").exists():
        Path("/etc/apt/keyrings").mkdir(parents=True, exist_ok=True)
        _run(["bash", "-lc", "curl -fsSL https://supplychain.mariadb.com/mariadb-keyring-2019.gpg -o /etc/apt/keyrings/mariadb-keyring.gpg"])
        os.chmod("/etc/apt/keyrings/mariadb-keyring.gpg", 0o644)
        Path("/etc/apt/sources.list.d/mariadb-xedc.sources").write_text(
            "\n".join([
                "X-Repolib-Name: MariaDB Server",
                "Types: deb",
                "URIs: https://dlm.mariadb.com/repo/mariadb-server/13.rolling/repo/ubuntu",
                "Suites: noble",
                "Components: main",
                "Architectures: amd64 arm64",
                "Signed-By: /etc/apt/keyrings/mariadb-keyring.gpg",
                "",
            ]),
            encoding="utf-8",
        )
    _run(["apt-get", "update"])
    _run(["apt-get", "install", "-y", *PACKAGES])
    if shutil.which("php") is None:
        raise InstallError("PHP 安装后仍不可用")
    version = subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True).strip()
    if not version.startswith("8.5."):
        raise InstallError(f"BLOCKED: 需要 PHP 8.5，实际得到 {version}")
    return {"php": version, "packages": PACKAGES}


def ensure_marker() -> None:
    if production_webroot_present():
        raise ProductionGuard("生产网站目录存在，拒绝创建实验室标记")
    create_lab_marker()
