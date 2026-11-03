"""Local backup packages. The file on disk and the object on R2 are the same tar.zst."""

from __future__ import annotations

import calendar
import json
import os
import time
from pathlib import Path

from .backup import BackupError, sha256_file
from .safety import assert_backup_destination

_PACKAGE = "xedc-full-*.tar.zst"


def package_directory(config: dict) -> Path:
    raw = str(config.get("package_dir") or config["backup_root"])
    path = assert_backup_destination(raw)
    path.mkdir(parents=True, exist_ok=True)
    os.chmod(path, 0o700)
    return path


def _meta_path(archive: Path) -> Path:
    return Path(str(archive) + ".meta.json")


def write_meta(archive: Path, meta: dict) -> Path:
    path = _meta_path(archive)
    path.write_text(json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.chmod(archive, 0o600)
    os.chmod(path, 0o600)
    checksum = Path(str(archive) + ".sha256")
    if checksum.is_file():
        os.chmod(checksum, 0o600)
    return path


def read_meta(archive: Path) -> dict:
    path = _meta_path(archive)
    data = {}
    if path.is_file():
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            data = {}
    data["archive"] = archive.name
    data["path"] = str(archive)
    data["bytes"] = archive.stat().st_size
    data.setdefault("created", time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(archive.stat().st_mtime)))
    checksum = Path(str(archive) + ".sha256")
    if checksum.is_file():
        data.setdefault("sha256", checksum.read_text(encoding="utf-8").split()[0])
    data.setdefault("r2", "unknown")
    data.setdefault("backup_id", "")
    return data


def _iter_archives(config: dict) -> list[Path]:
    root = package_directory(config)
    found = [path for path in root.glob(_PACKAGE) if path.is_file() and not path.name.endswith(".sha256")]
    temporary = root / ".r2-only-tmp"
    if temporary.is_dir():
        found.extend(path for path in temporary.glob(_PACKAGE) if path.is_file())
    return sorted(found)


def list_local_packages(config: dict) -> list[dict]:
    items = [read_meta(archive) for archive in _iter_archives(config)]
    items.sort(key=lambda item: item.get("created") or "")
    return items


def find_local_package(config: dict, spec: str) -> dict:
    items = list_local_packages(config)
    if not items:
        raise BackupError("本地没有完整备份")
    if spec in ("", "latest"):
        return items[-1]
    matched = []
    for item in items:
        name = item["archive"]
        stem = name[: -len(".tar.zst")] if name.endswith(".tar.zst") else name
        if spec in {item.get("backup_id"), name, stem} or name.startswith(spec):
            matched.append(item)
    if len(matched) == 1:
        return matched[0]
    if not matched:
        raise BackupError(f"本地没有备份 {spec}")
    raise BackupError(f"备份 {spec} 匹配到多份，请写出完整编号")


def prune_log(config: dict, message: str) -> None:
    path = Path(config["backup_root"]) / "prune.log"
    path.parent.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    with open(path, "a", encoding="utf-8") as handle:
        handle.write(f"{stamp} {message}\n")
    os.chmod(path, 0o600)


def _sidecar_paths(archive: Path) -> list[Path]:
    stem = archive.name[: -len(".tar.zst")]
    return [
        archive,
        Path(str(archive) + ".sha256"),
        Path(str(archive) + ".meta.json"),
        archive.with_name(stem + ".credentials.enc"),
    ]


def delete_local_package(config: dict, spec: str, *, automatic: bool) -> dict:
    item = find_local_package(config, spec)
    items = list_local_packages(config)
    if len(items) <= 1:
        prune_log(config, f"refuse delete only backup {item.get('backup_id') or item['archive']}")
        raise BackupError("拒绝删除唯一一份本地备份")
    pending = [row for row in items if row.get("r2") != "uploaded"]
    if automatic and item.get("r2") != "uploaded" and len(pending) <= 1:
        prune_log(config, f"refuse delete sole backup not on R2 {item.get('backup_id') or item['archive']}")
        raise BackupError("拒绝自动删除尚未成功上传到 R2 的唯一完整备份")
    removed = []
    for path in _sidecar_paths(Path(item["path"])):
        if path.is_file():
            path.unlink()
            removed.append(path.name)
    label = item.get("backup_id") or item["archive"]
    prune_log(config, f"delete {'auto' if automatic else 'manual'} {label} files={','.join(removed)}")
    return {"backup_id": label, "removed": removed}


def _age_days(created: str) -> float:
    try:
        parsed = time.strptime(created, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError:
        return 0
    return (time.time() - calendar.timegm(parsed)) / 86400


def prune_local_packages(config: dict) -> dict:
    items = list_local_packages(config)
    retention = config.get("retention") or {}
    keep = int(retention.get("keep") or retention.get("daily") or 7)
    days = int(retention.get("days") or 0)
    if keep < 1:
        raise BackupError("至少保留一份本地备份")
    if len(items) <= 1:
        prune_log(config, f"keep only local backup count={len(items)}")
        return {"removed": [], "kept": [item.get("backup_id") or item["archive"] for item in items]}
    newest_first = sorted(items, key=lambda item: item.get("created") or "", reverse=True)
    must_keep = set()
    for item in newest_first[:keep]:
        if days and _age_days(item.get("created") or "") > days and must_keep:
            continue
        must_keep.add(item["path"])
    pending = [item for item in items if item.get("r2") != "uploaded"]
    if len(pending) == 1:
        must_keep.add(pending[0]["path"])
    if not must_keep:
        must_keep.add(newest_first[0]["path"])
    removed = []
    for item in items:
        if item["path"] in must_keep:
            continue
        if len(items) - len(removed) <= 1:
            prune_log(config, f"stop before deleting last backup {item.get('backup_id') or item['archive']}")
            break
        try:
            result = delete_local_package(config, item.get("backup_id") or item["archive"], automatic=True)
        except BackupError as exc:
            prune_log(config, f"skip {item.get('backup_id') or item['archive']}: {exc}")
            continue
        removed.append(result["backup_id"])
    kept = [item.get("backup_id") or item["archive"] for item in list_local_packages(config)]
    return {"removed": removed, "kept": kept}


def read_package_document(archive: Path) -> dict:
    import subprocess
    completed = subprocess.run(
        ["tar", "--zstd", "-xOf", str(archive), "./PACKAGE.json"],
        text=True,
        capture_output=True,
    )
    if completed.returncode != 0:
        listing = subprocess.run(["tar", "--zstd", "-tf", str(archive)], text=True, capture_output=True)
        member = next((line for line in (listing.stdout or "").splitlines() if line.endswith("PACKAGE.json")), "")
        if not member:
            raise BackupError("恢复包里没有 PACKAGE.json")
        completed = subprocess.run(["tar", "--zstd", "-xOf", str(archive), member], text=True, capture_output=True)
    if completed.returncode != 0 or not (completed.stdout or "").strip():
        raise BackupError("无法读取恢复包清单")
    return json.loads(completed.stdout)


def package_needs_legacy_key(archive: Path) -> bool:
    document = read_package_document(archive)
    encryption = document.get("encryption") or {}
    if encryption.get("alg") == "none":
        return False
    return True


def assert_archive_manifest(archive: Path) -> dict:
    from .bundle import _extract_package, verify_package

    digest = verify_package(archive)
    stage_parent = Path(os.environ.get("TMPDIR") or "/tmp")
    import tempfile
    stage = Path(tempfile.mkdtemp(prefix="xedc-manifest-", dir=str(stage_parent)))
    try:
        _extract_package(archive, stage)
        manifest_path = stage / "manifest.json"
        checksum_path = stage / "manifest.sha256"
        if not manifest_path.is_file() or not checksum_path.is_file():
            raise BackupError("恢复包缺少 manifest")
        recorded = checksum_path.read_text(encoding="utf-8").strip().split()[0]
        actual = sha256_file(manifest_path)
        if recorded != actual:
            raise BackupError("manifest 校验失败")
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        document = json.loads((stage / "PACKAGE.json").read_text(encoding="utf-8")) if (stage / "PACKAGE.json").is_file() else {}
        if document.get("backup_id") and document.get("backup_id") != manifest.get("id"):
            raise BackupError("备份编号与 manifest 不一致")
        return {
            "sha256": digest,
            "backup_id": manifest.get("id") or "",
            "manifest_sha256": actual,
            "encryption": (manifest.get("encryption") or {}).get("alg") or "",
            "git_commit": ((document.get("git") or {}).get("commit") or ""),
            "drupal_version": document.get("drupal_version") or "",
            "artifacts": manifest.get("artifacts") or {},
            "database": manifest.get("database") or {},
        }
    finally:
        import shutil
        shutil.rmtree(stage, ignore_errors=True)
