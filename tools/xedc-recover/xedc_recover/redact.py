"""Log and text redaction. Never print secrets."""

from __future__ import annotations

import re

_PATTERNS = (
    re.compile(r"(?i)(password|passwd|secret|token|hash_salt|access_key|secret_key|authorization|key_b64)(\s*[:=]\s*|\s+)(\S+)"),
    re.compile(r"(?i)(-----BEGIN [A-Z ]*PRIVATE KEY-----)[\s\S]+?(-----END [A-Z ]*PRIVATE KEY-----)"),
    re.compile(r"(?i)(ghp_[A-Za-z0-9]{20,})"),
    re.compile(r"(?i)(AKIA[0-9A-Z]{16})"),
)


def redact(text: str) -> str:
    if not text:
        return text
    cleaned = text
    cleaned = _PATTERNS[0].sub(lambda m: f"{m.group(1)}{m.group(2)}***", cleaned)
    cleaned = _PATTERNS[1].sub(lambda m: f"{m.group(1)} *** {m.group(2)}", cleaned)
    cleaned = _PATTERNS[2].sub("ghp_***", cleaned)
    cleaned = _PATTERNS[3].sub("AKIA***", cleaned)
    return cleaned
