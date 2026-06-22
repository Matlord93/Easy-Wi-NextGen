#!/usr/bin/env python3
import sys
import yaml


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: validate_openapi.py <spec>")
        return 2

    with open(sys.argv[1], "r", encoding="utf-8") as f:
        spec = yaml.safe_load(f)

    for key in ("openapi", "info", "paths", "components"):
        if key not in spec:
            raise SystemExit(f"missing top-level key: {key}")

    if not isinstance(spec["paths"], dict) or not spec["paths"]:
        raise SystemExit("spec paths must be a non-empty object")

    print(f"validated {sys.argv[1]} ({len(spec['paths'])} paths)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
