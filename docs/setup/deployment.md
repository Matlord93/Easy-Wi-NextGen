# Deployment Setup (TASK-001 Baseline)

Dieses Setup definiert ein reproduzierbares Docker-Compose Deployment für **dev** und **stage**.

## Struktur

- `deploy/compose/dev/docker-compose.yml`
- `deploy/compose/stage/docker-compose.yml`
- `deploy/shared/` (gemeinsame Env- und Nginx-Defaults)
- `deploy/k8s/stubs/` (optionale, zukünftige K8s-Struktur)

## Services

Pflichtservices:
- `core`
- `agent`
- `db` (PostgreSQL)
- `queue` (Redis)

Optionale Services:
- `reverse-proxy` (Compose profile: `edge`)
- `metrics` (Compose profile: `metrics`, nur dev)

## Secrets-Referenzen

Secrets sind **nicht hartcodiert** im Compose-Servicecode, sondern werden über Env-Variablen (`deploy/shared/env/*.env`) referenziert.

Für stage/prod gilt:
1. Platzhalterwerte in `deploy/shared/env/stage.env` nicht produktiv verwenden.
2. Werte über CI/CD Secret Store oder eine nicht versionierte Env-Datei injizieren.
3. Mindestens `DB_PASSWORD` und `QUEUE_PASSWORD` setzen.

## Starten (dev)

```bash
cd /workspace/webinterface
docker compose -f deploy/compose/dev/docker-compose.yml up -d
```

Healthchecks:
- Core: `http://127.0.0.1:8080/healthz`
- Agent: `http://127.0.0.1:8087/healthz`

## Starten (stage)

```bash
docker compose -f deploy/compose/stage/docker-compose.yml up -d
```

Healthchecks:
- Core: `http://127.0.0.1:18080/healthz`
- Agent: `http://127.0.0.1:18087/healthz`

## Optional: Profiles

```bash
# reverse proxy aktivieren
docker compose -f deploy/compose/dev/docker-compose.yml --profile edge up -d

# metrics stub aktivieren (dev)
docker compose -f deploy/compose/dev/docker-compose.yml --profile metrics up -d
```
