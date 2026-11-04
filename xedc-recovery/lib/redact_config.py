#!/usr/bin/env python3
"""Copy sensitive config files into a secret directory and write redacted copies."""

import re
import sys
from pathlib import Path

KEY_RE = re.compile(
    r"(?i)^(\s*)([A-Za-z0-9_.-]*(?:password|secret|token|api_key|access_key)[A-Za-z0-9_.-]*)(\s*:\s*)(.+?)\s*$"
)


def _should_redact(value: str) -> bool:
    if value in {"''", '""', "~", "null", "[]", "{}", "true", "false"}:
        return False
    quoted = len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}
    # 模块权重、超时秒数和邮件标题不是凭据。带引号的值仍然脱敏，即使其中有空格。
    if not quoted and re.fullmatch(r"-?\d+(?:\.\d+)?", value):
        return False
    if not quoted and " " in value:
        return False
    return True


def redact_text(text: str) -> tuple[str, int]:
    count = 0
    lines = []
    for line in text.splitlines(keepends=True):
        match = KEY_RE.match(line.rstrip("\n"))
        if match and _should_redact(match.group(4)):
            newline = "\n" if line.endswith("\n") else ""
            lines.append(f"{match.group(1)}{match.group(2)}{match.group(3)}'XEDC_REDACTED'{newline}")
            count += 1
        else:
            lines.append(line)
    return "".join(lines), count


def main() -> int:
    if len(sys.argv) != 4:
        print("usage: redact_config.py CONFIG_DIR SECRET_DIR OVERLAY_DIR", file=sys.stderr)
        return 1
    config_dir = Path(sys.argv[1])
    secret_dir = Path(sys.argv[2])
    overlay_dir = Path(sys.argv[3])
    secret_dir.mkdir(parents=True, exist_ok=True)
    total = 0
    if not config_dir.is_dir():
        print("0")
        return 0
    for path in config_dir.rglob("*"):
        if not path.is_file():
            continue
        if path.suffix not in {".yml", ".yaml", ".env", ".php", ".json"}:
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        redacted, count = redact_text(text)
        if count == 0:
            continue
        relative = path.relative_to(config_dir)
        secret_target = secret_dir / relative
        secret_target.parent.mkdir(parents=True, exist_ok=True)
        overlay_target = None
        if str(overlay_dir) != "INPLACE":
            overlay_target = overlay_dir / relative
            overlay_target.parent.mkdir(parents=True, exist_ok=True)
        secret_target.write_text(text, encoding="utf-8")
        secret_target.chmod(0o600)
        if str(overlay_dir) == "INPLACE":
            path.write_text(redacted, encoding="utf-8")
        elif overlay_target is not None:
            overlay_target.write_text(redacted, encoding="utf-8")
        total += count
    print(total)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
