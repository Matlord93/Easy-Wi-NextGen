# Easy-Wi NextGen Monorepo Structure

This repository is a single monorepo that hosts the Symfony core, the Go agent, and installer assets.

## Layout

- `/core` — Symfony web interface and API (PHP). Contains configuration, migrations, templates, and tests.
- `/agent` — Go-based agent that runs on managed hosts.
- `/installer` — Bash/PowerShell installers for Linux and Windows.
- `/docs` — Product and operational documentation.
- `/.github/workflows` — CI pipelines for each component.

## CI Workflows

Workflows are organized per component in `/.github/workflows`:

- `ci-core.yml` — PHP tests and tagged core release bundle.
- `ci-agent.yml` — Go build/test and tagged agent release assets.
- `release-agent.yml` — Manual agent release builder.
- `ci-installer.yml` — Installer linting and tagged installer assets.
- `ci-release.yml` — Optional combined release helper.

## Release Flow

1. Create a version tag (`vX.Y.Z`).
2. CI builds component bundles/binaries.
3. CI generates SHA256 checksums, signs them, and uploads artifacts to GitHub Releases.
4. Installers/agents verify signatures + checksums before installing or upgrading.

## Contribution Notes

- Core changes live in `/core` (controllers, services, templates, migrations).
- Agent changes live in `/agent` (Go modules under `cmd/` and `internal/`).
- Installer changes live in `/installer` (Linux/Windows scripts).
- Docs live in `/docs`.

This monorepo is the single source of truth; there are no separate repositories for the components listed above.
