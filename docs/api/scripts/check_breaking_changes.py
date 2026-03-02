#!/usr/bin/env python3
import sys
import yaml


def load(path):
    with open(path, "r", encoding="utf-8") as f:
        return yaml.safe_load(f)


def schema_required(schema, components):
    if not isinstance(schema, dict):
        return set()
    if "$ref" in schema:
        prefix = "#/components/schemas/"
        if schema["$ref"].startswith(prefix):
            name = schema["$ref"][len(prefix):]
            return set(components.get(name, {}).get("required", []) or [])
        return set()
    return set(schema.get("required", []) or [])


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: check_breaking_changes.py <base-spec> <head-spec>")
        return 2

    base = load(sys.argv[1])
    head = load(sys.argv[2])
    base_paths = base.get("paths", {})
    head_paths = head.get("paths", {})
    base_components = base.get("components", {}).get("schemas", {})
    head_components = head.get("components", {}).get("schemas", {})

    errors = []

    for path, base_ops in base_paths.items():
        if path not in head_paths:
            errors.append(f"removed path: {path}")
            continue
        for method, base_op in base_ops.items():
            if method not in head_paths[path]:
                errors.append(f"removed operation: {method.upper()} {path}")
                continue
            base_responses = base_op.get("responses", {})
            head_responses = head_paths[path][method].get("responses", {})
            for code in base_responses.keys():
                if code not in head_responses:
                    errors.append(f"removed response code {code} for {method.upper()} {path}")
                    continue

                b_schema = (((base_responses[code] or {}).get("content", {})
                            .get("application/json", {})
                            .get("schema")) or {})
                h_schema = (((head_responses[code] or {}).get("content", {})
                            .get("application/json", {})
                            .get("schema")) or {})

                b_req = schema_required(b_schema, base_components)
                h_req = schema_required(h_schema, head_components)
                removed_required = sorted(b_req - h_req)
                if removed_required:
                    errors.append(
                        f"response schema for {method.upper()} {path} {code} removed required fields: {', '.join(removed_required)}"
                    )

    if errors:
        print("breaking changes detected:")
        for err in errors:
            print(f"- {err}")
        return 1

    print("no breaking changes detected")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
