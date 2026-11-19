#!/usr/bin/env python3
"""Validate complete Go test2json reports; skipped test cases are failures."""
import argparse
import json
import sys
from pathlib import Path


def validate(manifest_path):
    manifest_path = Path(manifest_path)
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    expected = set(manifest["packages"])
    completed = set()
    started_tests = set()
    ended_tests = set()
    passed = set()
    skipped = set()
    failed = set()
    errors = []
    for filename in manifest["reports"]:
        path = manifest_path.parent / filename
        if not path.is_file() or not path.stat().st_size:
            errors.append(f"Missing or empty report: {filename}")
            continue
        for line_number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            if not line.strip():
                continue
            try:
                item = json.loads(line)
            except json.JSONDecodeError:
                errors.append(f"Invalid JSON at {filename}:{line_number}")
                continue
            if not isinstance(item, dict):
                errors.append(f"Invalid event at {filename}:{line_number}")
                continue
            package, name, action = item.get("Package"), item.get("Test"), item.get("Action")
            if package not in expected:
                errors.append(f"Unexpected package in {filename}: {package!r}")
                continue
            if action == "fail":
                failed.add(f"{package}:{name or '(package)'}")
            if name:
                case = (package, name)
                if action == "run":
                    started_tests.add(case)
                if action in {"pass", "fail", "skip"}:
                    ended_tests.add(case)
                if action == "pass":
                    passed.add(case)
                if action == "skip":
                    skipped.add(case)
            elif action in {"pass", "fail", "skip"}:
                # Go emits a package-level skip for directories with no test files.
                completed.add(package)
    for package in sorted(expected - completed):
        errors.append(f"Package did not complete: {package}")
    for package, name in sorted(started_tests - ended_tests):
        errors.append(f"Test did not complete: {package}:{name}")
    for package, name in sorted(ended_tests - started_tests):
        errors.append(f"Test completed without a start event: {package}:{name}")
    for package in manifest["required_test_packages"]:
        if not any(case[0] == package for case in passed):
            errors.append(f"No passing test cases in required package: {package}")
    if not passed:
        errors.append("No test cases passed")
    errors.extend(f"Skipped test: {p}:{t}" for p, t in sorted(skipped))
    errors.extend(f"Failed test: {case}" for case in sorted(failed))
    summary = {"passed": len(passed), "skipped": len(skipped), "failed": len(failed),
               "completed_packages": len(completed), "expected_packages": len(expected), "errors": errors}
    (manifest_path.parent / "summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2))
    return 1 if errors else 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", type=Path)
    args = parser.parse_args()
    try:
        return validate(args.manifest)
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f"Cannot validate agent test reports: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
