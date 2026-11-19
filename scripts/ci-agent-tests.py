#!/usr/bin/env python3
"""Run every Linux agent package, with isolated root/audio prerequisites and no skipped tests."""
import argparse
import json
import os
import platform
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
AGENT = ROOT / "agent"


def checked(command, **kwargs):
    return subprocess.run(command, cwd=AGENT, check=True, **kwargs)


def output(command):
    return checked(command, stdout=subprocess.PIPE, text=True).stdout.strip()


def execute(reports):
    if platform.system() != "Linux" or os.geteuid() == 0:
        raise RuntimeError("Run on Linux as an unprivileged user; only the FileAPI container runs as root.")
    for tool in ["go", "docker"]:
        if not shutil.which(tool):
            raise RuntimeError(f"Required tool is missing: {tool}")
    checked(["docker", "info"], stdout=subprocess.DEVNULL)
    module = output(["go", "list", "-m"])
    packages = output(["go", "list", "./..."]).splitlines()
    fileapi, bridge = module + "/internal/fileapi", module + "/cmd/easywi-teamspeak-bridge"
    if not {fileapi, bridge}.issubset(packages):
        raise RuntimeError("The required FileAPI and Voice-Bridge packages were not discovered.")
    ordinary = [package for package in packages if package not in {fileapi, bridge}]
    report_names = ["ordinary.jsonl", "fileapi.jsonl", "voice-bridge.jsonl"]
    manifest = {"packages": packages, "required_test_packages": [fileapi, bridge], "reports": report_names,
                "revision": output(["git", "rev-parse", "HEAD"]), "go_version": output(["go", "version"])}
    (reports / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    # Truncate stale reports before doing any work so failed setup cannot reuse an old pass.
    for filename in report_names:
        (reports / filename).write_text("", encoding="utf-8")
    with tempfile.TemporaryDirectory(prefix="easywi-agent-validation-") as scratch_name:
        scratch = Path(scratch_name)
        image_context = scratch / "image"
        image_context.mkdir()
        shutil.copyfile(ROOT / "scripts/ci-agent-test-image.Dockerfile", image_context / "Dockerfile")
        ca_bundle = Path(os.environ.get("EASYWI_TEST_CA_BUNDLE", "/etc/ssl/certs/ca-certificates.crt"))
        if not ca_bundle.is_file():
            raise RuntimeError("Set EASYWI_TEST_CA_BUNDLE to the runner's trusted PEM CA bundle.")
        shutil.copyfile(ca_bundle, image_context / "trusted-ca.crt")
        docker_env = os.environ.copy()
        docker_env.setdefault("BUILDX_CONFIG", str(scratch / "buildx"))
        checked(["docker", "build", "--iidfile", str(scratch / "image-id"), str(image_context)], env=docker_env)
        image = (scratch / "image-id").read_text(encoding="utf-8").strip()
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", image):
            raise RuntimeError("Docker did not return a valid test-image ID.")
        build_env = os.environ.copy()
        build_env["CGO_ENABLED"] = "0"
        checked(["go", "test", "-c", "./internal/fileapi", "-o", str(scratch / "fileapi.test")], env=build_env)
        checked(["go", "test", "-c", "./cmd/easywi-teamspeak-bridge", "-o", str(scratch / "bridge.test")], env=build_env)
        checked(["go", "build", "-o", str(scratch / "easywi-teamspeak-bridge"), "./cmd/easywi-teamspeak-bridge"], env=build_env)
        statuses = []
        containers = []
        try:
            # Ordinary modules keep their normal unprivileged environment.
            with (reports / report_names[0]).open("w", encoding="utf-8") as report:
                statuses.append(subprocess.run(["go", "test", "-count=1", "-json", *ordinary], cwd=AGENT, stdout=report).returncode)
            for package, binary, filename, root in [
                (fileapi, "fileapi.test", report_names[1], True),
                (bridge, "bridge.test", report_names[2], False),
            ]:
                cidfile = scratch / (binary + ".cid")
                containers.append(cidfile)
                command = ["docker", "run", "--rm", "--cidfile", str(cidfile), "--network", "none", "--read-only",
                           "--cap-drop", "ALL", "--security-opt", "no-new-privileges", "--pids-limit", "256",
                           "--memory", "512m", "--cpus", "2", "--tmpfs", "/tmp:rw,exec,nosuid,nodev,size=256m,mode=1777"]
                if root:
                    command += ["--user", "0:0"]
                    for capability in ["CHOWN", "DAC_OVERRIDE", "FOWNER", "SETUID", "SETGID"]:
                        command += ["--cap-add", capability]
                command += ["--mount", f"type=bind,src={scratch / binary},dst=/validation/bin/{binary},readonly"]
                if not root:
                    command += ["--mount", f"type=bind,src={scratch / 'easywi-teamspeak-bridge'},dst=/validation/bin/easywi-teamspeak-bridge,readonly"]
                command += ["--entrypoint", "/validation/bin/" + binary, image, "-test.v=test2json", "-test.timeout=5m"]
                with (reports / filename).open("w", encoding="utf-8") as report:
                    statuses.append(subprocess.run(["go", "tool", "test2json", "-t", "-p", package, *command], cwd=AGENT, stdout=report).returncode)
        finally:
            for cidfile in containers:
                if cidfile.is_file():
                    cid = cidfile.read_text(encoding="utf-8").strip()
                    if re.fullmatch(r"[0-9a-f]{64}", cid):
                        subprocess.run(["docker", "rm", "-f", cid], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        status = subprocess.run([sys.executable, str(ROOT / "scripts/ci-agent-test-report.py"), str(reports / "manifest.json")], check=False).returncode
        if any(statuses):
            return 1
        return status


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report-dir", type=Path, help="Keep JSON test reports here (default: a new temporary directory).")
    args = parser.parse_args()
    reports = args.report_dir.resolve() if args.report_dir else Path(tempfile.mkdtemp(prefix="easywi-agent-test-results-"))
    reports.mkdir(parents=True, exist_ok=True)
    for filename in ["ordinary.jsonl", "fileapi.jsonl", "voice-bridge.jsonl", "manifest.json", "summary.json"]:
        (reports / filename).unlink(missing_ok=True)
    print(f"Agent test reports: {reports}", flush=True)
    try:
        return execute(reports)
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        (reports / "summary.json").write_text(json.dumps({"errors": [str(error)], "state": "setup_failed"}, indent=2) + "\n", encoding="utf-8")
        print(f"Agent validation failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
