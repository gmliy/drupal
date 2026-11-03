"""Record the Git commit that matches a backup, and recover that commit later.

GitHub is the source of versioned code. The encrypted package remains the
fallback when the commit cannot be fetched, or when the working tree was dirty
and therefore not identical to that commit.
"""

from __future__ import annotations

import hashlib
import os
import shutil
import subprocess
import urllib.parse
from pathlib import Path


def _run(argv: list[str], timeout: int = 30) -> subprocess.CompletedProcess:
    env = dict(os.environ)
    env["GIT_TERMINAL_PROMPT"] = "0"
    env["GIT_CONFIG_COUNT"] = "1"
    env["GIT_CONFIG_KEY_0"] = "safe.directory"
    env["GIT_CONFIG_VALUE_0"] = "*"
    return subprocess.run(argv, text=True, capture_output=True, timeout=timeout, env=env)


def sanitize_remote(url: str) -> str:
    """Drop embedded usernames, passwords, and tokens."""
    text = (url or "").strip()
    if not text:
        return ""
    if text.startswith("git@"):
        return text.split(" ", 1)[0]
    parsed = urllib.parse.urlsplit(text)
    host = parsed.hostname or ""
    if not host:
        return text
    netloc = host
    if parsed.port:
        netloc = f"{host}:{parsed.port}"
    return urllib.parse.urlunsplit((parsed.scheme, netloc, parsed.path, "", ""))


def remote_allowed(url: str) -> bool:
    """Only the project repository, or a local fixture when tests opt in."""
    if os.environ.get("XEDC_RECOVER_ALLOW_LOCAL_GIT") == "1":
        candidate = Path(url)
        if candidate.is_dir() and ((candidate / ".git").exists() or (candidate / "HEAD").is_file()):
            return True
    cleaned = sanitize_remote(url)
    if cleaned.startswith("git@github.com:xxedc/drupal"):
        return True
    parsed = urllib.parse.urlsplit(cleaned)
    if parsed.hostname != "github.com":
        return False
    path = parsed.path.strip("/")
    return path in {"xxedc/drupal", "xxedc/drupal.git"}


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _drupal_version(source: Path) -> str:
    core = source / "web/core/lib/Drupal.php"
    if not core.is_file():
        return ""
    for line in core.read_text(encoding="utf-8", errors="replace").splitlines():
        if "const VERSION" in line and "'" in line:
            return line.split("'", 2)[1][:40]
    return ""


def _php_version() -> str:
    try:
        completed = _run(["php", "-r", "echo PHP_VERSION;"], timeout=15)
    except (OSError, subprocess.TimeoutExpired):
        return ""
    if completed.returncode != 0:
        return ""
    return (completed.stdout or "").strip()[:40]


def collect_identity(source: Path) -> dict:
    """Versions and the commit. Dirty paths are counted, not stored."""
    source = Path(source)
    lock = source / "composer.lock"
    identity = {
        "commit": "",
        "branch": "",
        "remote": "",
        "dirty": False,
        "dirty_entries": 0,
        "composer_lock_sha256": _sha256(lock) if lock.is_file() else "",
        "drupal_version": _drupal_version(source),
        "php_version": _php_version(),
    }
    try:
        top = _run(["git", "-C", str(source), "rev-parse", "--show-toplevel"], timeout=20)
    except (OSError, subprocess.TimeoutExpired):
        return identity
    if top.returncode != 0:
        return identity
    try:
        if Path(top.stdout.strip()).resolve() != source.resolve():
            return identity
    except OSError:
        return identity
    head = _run(["git", "-C", str(source), "rev-parse", "HEAD"], timeout=20)
    if head.returncode != 0:
        return identity
    identity["commit"] = (head.stdout or "").strip()
    branch = _run(["git", "-C", str(source), "rev-parse", "--abbrev-ref", "HEAD"], timeout=20)
    identity["branch"] = (branch.stdout or "").strip() if branch.returncode == 0 else ""
    remote = _run(["git", "-C", str(source), "remote", "get-url", "origin"], timeout=20)
    if remote.returncode == 0:
        identity["remote"] = sanitize_remote(remote.stdout or "")
    try:
        status = _run(["git", "-C", str(source), "status", "--porcelain", "-z"], timeout=120)
    except subprocess.TimeoutExpired:
        identity["dirty"] = True
        identity["dirty_entries"] = -1
        return identity
    if status.returncode == 0:
        entries = [item for item in (status.stdout or "").split("\0") if item]
        identity["dirty_entries"] = len(entries)
        identity["dirty"] = bool(entries)
    return identity


def align_code(code_dir: Path, git_info: dict | None, work: Path | None = None) -> dict:
    """Use the recorded commit when it matches. Otherwise keep the package tree."""
    info = git_info or {}
    commit = str(info.get("commit") or "")
    if not commit:
        return {"code_source": "package-snapshot", "reason": "no-commit-recorded"}
    if info.get("dirty"):
        return {
            "code_source": "package-snapshot",
            "reason": "working-tree-dirty",
            "commit": commit,
        }
    remote = sanitize_remote(str(info.get("remote") or ""))
    if not remote_allowed(remote):
        return {"code_source": "package-fallback", "reason": "commit-unavailable", "commit": commit}
    if not Path(code_dir).is_dir():
        return {"code_source": "package-fallback", "reason": "code-dir-missing", "commit": commit}
    from .workspace import make_work_dir
    stage = work if work is not None else make_work_dir(None, "git-align")
    stage.mkdir(parents=True, exist_ok=True)
    try:
        cloned = stage / "src"
        try:
            clone = _run(["git", "clone", "--quiet", remote, str(cloned)], timeout=90)
            if clone.returncode != 0:
                return {"code_source": "package-fallback", "reason": "commit-unavailable", "commit": commit}
            checkout = _run(["git", "-C", str(cloned), "checkout", "--quiet", commit], timeout=60)
            if checkout.returncode != 0:
                return {"code_source": "package-fallback", "reason": "commit-unavailable", "commit": commit}
        except (OSError, subprocess.TimeoutExpired):
            return {"code_source": "package-fallback", "reason": "commit-unavailable", "commit": commit}
        recorded = str(info.get("composer_lock_sha256") or "")
        fetched_lock = cloned / "composer.lock"
        if recorded and fetched_lock.is_file() and _sha256(fetched_lock) != recorded:
            return {"code_source": "package-fallback", "reason": "composer-lock-mismatch", "commit": commit}
        for path in cloned.rglob("*"):
            if not path.is_file() or path.is_symlink():
                continue
            if ".git" in path.parts:
                continue
            relative = path.relative_to(cloned)
            if ".." in relative.parts:
                continue
            target = Path(code_dir) / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
        return {"code_source": "github-commit", "commit": commit, "remote": remote}
    finally:
        shutil.rmtree(stage, ignore_errors=True)
