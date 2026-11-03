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
        detail = (completed.stderr or completed.stdout or "命令失败")[-800:]
        raise InstallError(f"软件安装失败，恢复已停止，没有继续后面的步骤。\n{detail}")


def read_os_release(path: str | Path = "/etc/os-release") -> dict:
    info = {"id": "", "version_id": "", "codename": "", "pretty": ""}
    file = Path(path)
    if not file.is_file():
        return info
    for line in file.read_text(encoding="utf-8", errors="replace").splitlines():
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip().strip('"')
        if key == "ID":
            info["id"] = value
        elif key == "VERSION_ID":
            info["version_id"] = value
        elif key == "VERSION_CODENAME":
            info["codename"] = value
        elif key == "PRETTY_NAME":
            info["pretty"] = value
    return info


def assert_restore_os(path: str | Path = "/etc/os-release") -> dict:
    """Automatic package install is tested only on Ubuntu 24.04."""
    info = read_os_release(path)
    pretty = info["pretty"] or f"{info['id']} {info['version_id']}".strip()
    if info["id"] == "ubuntu" and info["version_id"] == "24.04" and info["codename"] == "noble":
        return info
    raise InstallError(
        f"这台机器是 {pretty or '未知系统'}。自动安装网站环境只支持已经完整测试过的 Ubuntu 24.04 (noble)。"
        "Ubuntu 26.04 目前是 BLOCKED：不会添加 PHP 软件源，也不会继续恢复。"
        "请换一台 Ubuntu 24.04，或等这个版本完成安装测试后再恢复。"
    )


def install_pinned(*, yes: bool) -> dict:
    if not yes:
        raise InstallError("安装软件需要 --yes")
    assert_install_allowed()
    if platform.system() != "Linux":
        raise InstallError("目前只自动安装 Linux 运行环境")
    release = assert_restore_os()
    codename = release["codename"]
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
            f"deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] http://nginx.org/packages/mainline/ubuntu {codename} nginx\n",
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
                f"Suites: {codename}",
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
        raise InstallError(f"PHP 版本不兼容，恢复已停止。Drupal 11 这套环境需要 PHP 8.5，实际是 {version}。")
    try:
        maria = subprocess.check_output(["mariadb", "--version"], text=True, stderr=subprocess.STDOUT).strip()
    except (OSError, subprocess.CalledProcessError) as exc:
        raise InstallError("MariaDB 安装后无法运行，恢复已停止。") from exc
    digits = ""
    for token in maria.replace(",", " ").split():
        if token[0:1].isdigit() and "." in token:
            digits = token
            break
    major = int(digits.split(".", 1)[0]) if digits else 0
    if major < 10:
        raise InstallError(f"MariaDB 版本不兼容，恢复已停止。需要 10 或更新的版本，实际是：{maria[:160]}")
    return {"php": version, "mariadb": maria[:160], "ubuntu": release["version_id"], "packages": PACKAGES}


def ensure_marker() -> None:
    if production_webroot_present():
        raise ProductionGuard("生产网站目录存在，拒绝创建实验室标记")
    create_lab_marker()
