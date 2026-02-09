# CI & Security Gates

## Überblick
Die CI führt bei jedem Pull Request vollständige Prüfungen für **core/**, **agent/** und **installer/** aus und blockiert PRs, wenn Tests, Static Analysis, Lint oder Security-Scans fehlschlagen.

## Kurz-Audit (Repo)
- **Architekturüberblick:** Symfony 8 Webinterface/API in `core/`, Go-Agent in `agent/`, Installer-Skripte in `installer/` (Linux/Windows). 
- **Konfiguration & Secrets:** Symfony-Konfiguration in `core/config/`; Umgebungsvariablen/Secrets in `core/.env*`.
- **Provisioning / Jobs / Queues:** Provisioning-Workflows sind als Modul dokumentiert; Automatisierung läuft über Agent/Runner-Komponenten.
- **Security & Health/Monitoring:** Setup/Secrets, SFTP-Handling und Admin/Monitoring sind als Module ausgewiesen.
- **Module/Komponenten:** TemplateCatalog, Provisioning, Setup, Ports, Files, Sftp, Nodes, PanelCustomer, PanelAdmin.
- **Bekannte Lücken:** Keine expliziten Hinweise auf buggy/unvollständige Module in der aktuellen Doku.

## Core (Symfony/PHP)
Checks im Workflow `core-ci` (Job-Name: `core-ci`):
- `composer validate --strict`
- `composer install` (mit Cache)
- `php -l` für alle PHP-Dateien (ohne `vendor/`) + Symfony Lints (`lint:yaml`, `lint:twig`, `lint:container`)
- PHPUnit: `php bin/phpunit`
- PHPStan: `php -d memory_limit=-1 vendor/bin/phpstan analyse --configuration=phpstan.neon`
- PHP CS Fixer (Dry-Run): `PHP_CS_FIXER_IGNORE_ENV=1 php vendor/bin/php-cs-fixer fix --dry-run --diff`

### Lokal ausführen (Core)
```bash
cd core
composer validate --strict
composer install
APP_ENV=test APP_DEBUG=0 composer run lint
composer run test
composer run phpstan
composer run cs:check
```

## Agent (Go)
Checks im Workflow `agent-ci` (Job-Name: `agent-ci`):
- `go test ./...`
- `go vet ./...`
- `golangci-lint` (via Action + `.golangci.yml`)
- `govulncheck ./...`

### Lokal ausführen (Agent)
```bash
cd agent
go test ./...
go vet ./...
golangci-lint run

go install golang.org/x/vuln/cmd/govulncheck@latest
govulncheck ./...
```

## Installer (Shell)
Checks im Workflow `installer-ci` (Job-Name: `installer-ci`):
- `bash -n` für alle `*.sh`
- `shellcheck` für `installer/*.sh`

### Lokal ausführen (Installer)
```bash
bash -n installer/*.sh
shellcheck installer/*.sh
```

## Security Scans
- **CodeQL** (`security-codeql`): SAST für PHP + Go mit Standard-Queries plus `security-extended`. CI schlägt bei Findings auf **Warning**-Level oder höher fehl (strikter als High/Critical). 
- **Dependency Review** (`security-dependency-review`): Blockiert PRs mit Dependencies ab **High**-Severity (`fail-on-severity: high`).
- **Secret Scanning** (`security-secrets`): Gitleaks scannt PRs und failt bei Secrets (keine Allowlist ohne Dokumentation).

## GitHub Einstellungen (Branch Protection)
Empfohlen für geschützte Branches:
- Require status checks to pass
- Required checks:
  - `core-ci`
  - `agent-ci`
  - `installer-ci`
  - `security-codeql`
  - `security-dependency-review`
  - `security-secrets`
- Require PR reviews: mindestens 1
- Disallow force push
- Require linear history (optional)
