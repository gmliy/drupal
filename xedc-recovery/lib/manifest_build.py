#!/usr/bin/env python3
"""Merge backup fact fragments into manifest.json. Fragments must not contain secrets."""

import json
import sys
from datetime import datetime, timezone
from pathlib import Path


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: manifest_build.py FACT_DIR OUTPUT", file=sys.stderr)
        return 1
    fact_dir = Path(sys.argv[1])
    output = Path(sys.argv[2])
    merged = {
        "schema": 2,
        "created": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "warnings": [],
    }
    for path in sorted(fact_dir.glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            continue
        warnings = data.pop("warnings", [])
        if isinstance(warnings, list):
            merged["warnings"].extend(str(item) for item in warnings)
        merged[path.stem] = data
    output.write_text(json.dumps(merged, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    output.chmod(0o600)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
