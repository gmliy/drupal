#!/usr/bin/env python3
"""Scan staged text for secrets. Exit 1 on a hit. Does not print secret values."""

import math
import re
import subprocess
import sys

PATTERNS = [
    ("private_key", re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----")),
    ("age_secret", re.compile(r"AGE-SECRET-KEY-1[A-Z0-9]+")),
    ("aws_key", re.compile(r"AKIA[0-9A-Z]{16}")),
    ("github_token", re.compile(r"gh[pousr]_[A-Za-z0-9]{20,}")),
    ("slack", re.compile(r"xox[baprs]-[A-Za-z0-9-]{10,}")),
]
ASSIGN = re.compile(r"""(?i)(?:password|secret|hash_salt|api[_-]?key)\s*[:=]\s*(['"])([^'"]{8,})\1""")
PLACEHOLDERS = {"not-stored", "xedc_redacted", "changeme", "change-me", "password", "secret", "example"}
SKIP_EXT = (".png", ".jpg", ".jpeg", ".webp", ".gif", ".woff", ".woff2", ".sql", ".zst", ".gz")


def entropy(value: str) -> float:
    if not value:
        return 0.0
    counts = {}
    for char in value:
        counts[char] = counts.get(char, 0) + 1
    total = len(value)
    return -sum((count / total) * math.log2(count / total) for count in counts.values())


def main() -> int:
    diff = subprocess.check_output(["git", "diff", "--cached", "--no-color", "-U0"], text=True, errors="replace")
    hits = []
    current = ""
    for line in diff.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
            continue
        if not line.startswith("+") or line.startswith("+++"):
            continue
        if current.endswith(SKIP_EXT) or current.startswith("xedc-recovery/lib/acme-dns-providers.json"):
            continue
        added = line[1:]
        for name, pattern in PATTERNS:
            if pattern.search(added):
                hits.append(f"{current}: {name}")
        match = ASSIGN.search(added)
        if match:
            value = match.group(2).strip()
            if (
                value.lower() not in PLACEHOLDERS
                and "XEDC_REDACTED" not in added
                and "$" not in value
                and not value.startswith(" ")
            ):
                hits.append(f"{current}: assignment")
        if re.search(r"(?i)sha256|checksum", added):
            continue
        for token in re.findall(r"[A-Za-z0-9+/=_-]{40,}", added):
            if "/" in token or token.startswith("XEDC_") or current.endswith("composer.lock"):
                continue
            if entropy(token) > 4.2:
                hits.append(f"{current}: high-entropy")
                break
    if hits:
        print("敏感信息扫描未通过:")
        for hit in hits:
            print(hit)
        return 1
    print("敏感信息扫描通过")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
