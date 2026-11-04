"""Key creation and explicit emergency admin recovery. Normal restore does not reset passwords."""

from __future__ import annotations

import hashlib
import json
import os
import secrets
from datetime import datetime, timedelta, timezone
from pathlib import Path

from .crypto import write_key
from .safety import ProductionGuard, assert_write_allowed, production_webroot_present


def init_key(path: str) -> dict:
    return write_key(path)


def reset_admin_plan(*, explicit: bool, user: str = "admin") -> dict:
    if not explicit:
        raise ProductionGuard("正常恢复不会重置管理员密码。只有显式 --reset-admin 才会生成应急计划。")
    if production_webroot_present():
        raise ProductionGuard("生产网站仍在本机，拒绝重置管理员密码")
    password = secrets.token_urlsafe(18)
    return {
        "user": user,
        "password": password,
        "applied": False,
        "note": "此密码只在隔离环境中使用。调用方必须自行写入 0600 文件，不能打印到普通日志。",
    }


def login_link(*, target: str, user: str, explicit: bool, ttl_minutes: int = 30) -> dict:
    if not explicit:
        raise ProductionGuard("需要显式 --login-link")
    if production_webroot_present():
        raise ProductionGuard("生产网站仍在本机，拒绝生成管理员登录链接")
    directory = assert_write_allowed(target, purpose="login-link")
    directory.mkdir(parents=True, exist_ok=True)
    token = secrets.token_urlsafe(32)
    record = {
        "user": user,
        "token_sha256": hashlib.sha256(token.encode()).hexdigest(),
        "expires": (datetime.now(timezone.utc) + timedelta(minutes=ttl_minutes)).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "single_use": True,
    }
    path = directory / "admin-login-token.json"
    path.write_text(json.dumps(record, indent=2) + "\n", encoding="utf-8")
    os.chmod(path, 0o600)
    return {"file": str(path), "token": token, "expires": record["expires"], "user": user}
