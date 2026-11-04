#!/usr/bin/env python3
import importlib.util
from pathlib import Path

path = Path(__file__).resolve().parents[2] / "lib" / "redact_config.py"
spec = importlib.util.spec_from_file_location("redact_config", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

sample = "\n".join(
    [
        "  token: 0",
        "  password_reset_timeout: 86400",
        "    password: Your password",
        "    tokenize: false",
        "smtp_password: 'secret with space'",
        "api_key: 'abc'",
    ]
)
text, count = module.redact_text(sample + "\n")
assert "token: 0" in text
assert "password_reset_timeout: 86400" in text
assert "password: Your password" in text
assert "tokenize: false" in text
assert "smtp_password: 'XEDC_REDACTED'" in text
assert "api_key: 'XEDC_REDACTED'" in text
assert "secret with space" not in text
assert count == 2, count
print("PASS redact_config")
