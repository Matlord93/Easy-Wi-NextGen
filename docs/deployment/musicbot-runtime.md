# Musicbot Runtime Deployment

The Easy-Wi agent installs Musicbot instances by copying a local, administrator-provided runtime binary. The default runtime path is:

```text
/usr/local/bin/easywi-musicbot
```

The agent does not download this binary automatically. This avoids uncontrolled network downloads and keeps release provenance under administrator control.

## Build artifact

Agent CI builds `agent/cmd/easywi-musicbot` together with `easywi-agent` and publishes the runtime in the agent artifacts and release assets. For Linux amd64, use one of:

- `easywi-agent-linux-amd64.tar.gz` or `easywi-agent-linux-amd64.zip` — agent runtime bundle containing `easywi-agent` and `easywi-musicbot`.
- `easywi-musicbot-linux-amd64.tar.gz` or `easywi-musicbot-linux-amd64.zip` — standalone Musicbot runtime artifact.

Verify the downloaded artifact against the matching `checksums-agent-linux.txt` or release `checksums.sha256` before installation.

## Install on a Linux node

Download and unpack the CI artifact or release asset on the target node. Then install the runtime binary to the default path expected by the agent:

```bash
sudo install -o root -g root -m 0755 easywi-musicbot /usr/local/bin/easywi-musicbot
```

If the artifact contains a target-qualified release binary name, rename it during installation:

```bash
sudo install -o root -g root -m 0755 easywi-musicbot-linux-amd64 /usr/local/bin/easywi-musicbot
```

## Verify the installation

Check that the binary exists and is executable:

```bash
stat /usr/local/bin/easywi-musicbot
/usr/local/bin/easywi-musicbot --help
```

The path must remain `/usr/local/bin/easywi-musicbot` unless the panel job payload is explicitly configured by an administrator with an absolute `runtime_binary` or `runtime_binary_path` override.

## Apply to Musicbot installations

After installing or replacing the runtime binary:

1. Restart the Easy-Wi agent if your operating procedure requires the agent to refresh its environment.
2. Re-run the Musicbot install or repair action from the panel.
3. Confirm that the instance directory contains `bin/easywi-musicbot` and that the generated service unit points to that instance-local binary.

If the runtime binary is missing, install and update jobs fail with an actionable error that points to `/usr/local/bin/easywi-musicbot` or the configured absolute runtime path.
