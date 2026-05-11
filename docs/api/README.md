# API Contracts (`docs/api/`)

## English

### Single source of truth
For Core ↔ Agent communication, the canonical contract is:

- `core-agent.v1.openapi.yaml`

For mail control-plane communication, the canonical contract is:

- `openapi-mail-control-plane-v1.yaml`

Supporting contracts:

- `error-envelope.schema.yaml` defines the shared error envelope.
- `webspace-lifecycle-contract.md` defines webspace lifecycle and file API behavior.
- `console-v1.proto` defines live console streaming.
- `agent-v1.openapi.yaml` is a deprecated transitional alias and is **not** authoritative.

### Versioning policy
- We use URI major versioning (`/api/v1/...`).
- **Additive** changes in `v1` are allowed (new optional fields, additional non-breaking response fields, new endpoints).
- **Breaking** changes require a new major (`v2`) and a documented migration/sunset window.

### Deprecation policy
Endpoint deprecations must:

1. be documented in this folder and in the wiki,
2. keep backward compatibility during the deprecation window,
3. include replacement endpoint guidance,
4. be reflected in the changelog.

### CI checks
CI is expected to enforce:

1. OpenAPI structural validation of canonical specs,
2. breaking-change diffs against `origin/main` for canonical specs,
3. contract tests in both `core` and `agent` pipelines.

### Shared error envelope
Core and Agent APIs must return errors as:

```json
{
  "error": {
    "code": "STRING_ENUM",
    "message": "string",
    "request_id": "string",
    "details": {}
  }
}
```

`request_id` must be propagated from `X-Request-ID` or generated when missing.

### Retry and idempotency
- Clients may automatically retry `GET` requests for transient failures (`429`, `5xx`, timeout).
- Mutating requests (`POST`/`PUT`/`PATCH`/`DELETE`) must include `Idempotency-Key` to be retry-safe.
- Core should persist idempotency keys per endpoint and actor for a bounded TTL and return the original outcome for duplicates.
- When rate limiting (`429`), APIs should send `Retry-After`; clients must honor it before the next attempt.

### Publishing workflow
1. Update the canonical API contract in this directory.
2. Update German and English wiki pages in `wiki/`, especially `API-Dokumentation.md` and `API-Dokumentation.en.md`.
3. Run `python3 docs/api/scripts/validate_openapi.py <spec>` for changed OpenAPI specs.
4. Update changelog/release notes when behavior changes.
5. Publish/synchronize the `wiki/` pages to the hosted wiki.

## Deutsch

### Verbindliche Quelle
Für die Core-↔-Agent-Kommunikation ist diese Datei kanonisch:

- `core-agent.v1.openapi.yaml`

Für die Mail-Control-Plane ist diese Datei kanonisch:

- `openapi-mail-control-plane-v1.yaml`

Ergänzende Verträge:

- `error-envelope.schema.yaml` definiert den gemeinsamen Fehlerumschlag.
- `webspace-lifecycle-contract.md` definiert Webspace-Lifecycle und File-API-Verhalten.
- `console-v1.proto` definiert Live-Console-Streaming.
- `agent-v1.openapi.yaml` ist ein deprecated Übergangs-Alias und **nicht** verbindlich.

### Versionierungsregeln
- Wir verwenden URI-Major-Versionierung (`/api/v1/...`).
- **Additive** Änderungen in `v1` sind erlaubt (neue optionale Felder, zusätzliche nicht-brechende Response-Felder, neue Endpunkte).
- **Breaking Changes** benötigen einen neuen Major (`v2`) und ein dokumentiertes Migrations-/Sunset-Fenster.

### Deprecation-Regeln
Endpoint-Deprecations müssen:

1. in diesem Ordner und im Wiki dokumentiert werden,
2. während des Deprecation-Fensters rückwärtskompatibel bleiben,
3. einen Ersatz-Endpunkt nennen,
4. im Changelog sichtbar sein.

### CI-Prüfungen
CI soll sicherstellen:

1. strukturelle OpenAPI-Validierung der kanonischen Spezifikationen,
2. Breaking-Change-Diffs gegen `origin/main` für kanonische Spezifikationen,
3. Contract-Tests in `core`- und `agent`-Pipelines.

### Gemeinsamer Fehlerumschlag
Core- und Agent-APIs müssen Fehler so zurückgeben:

```json
{
  "error": {
    "code": "STRING_ENUM",
    "message": "string",
    "request_id": "string",
    "details": {}
  }
}
```

`request_id` muss aus `X-Request-ID` übernommen oder bei Fehlen generiert werden.

### Retry und Idempotenz
- Clients dürfen `GET`-Requests bei transienten Fehlern (`429`, `5xx`, Timeout) automatisch wiederholen.
- Mutierende Requests (`POST`/`PUT`/`PATCH`/`DELETE`) müssen für sichere Wiederholungen `Idempotency-Key` senden.
- Core soll Idempotency-Keys pro Endpoint und Actor für eine begrenzte TTL speichern und bei Duplikaten das ursprüngliche Ergebnis zurückgeben.
- Bei Rate-Limiting (`429`) sollen APIs `Retry-After` senden; Clients müssen diesen Wert beachten.

### Veröffentlichungsworkflow
1. Kanonischen API-Vertrag in diesem Ordner aktualisieren.
2. Deutsche und englische Wiki-Seiten in `wiki/` aktualisieren, insbesondere `API-Dokumentation.md` und `API-Dokumentation.en.md`.
3. Für geänderte OpenAPI-Spezifikationen `python3 docs/api/scripts/validate_openapi.py <spec>` ausführen.
4. Changelog/Release Notes aktualisieren, wenn sich Verhalten ändert.
5. `wiki/`-Seiten in das gehostete Wiki veröffentlichen/synchronisieren.

## Voice probe rate-limit behavior (v1) / Voice-Probe-Rate-Limit-Verhalten (v1)

For `/api/v1/customer/voice/{id}/probe` / Für `/api/v1/customer/voice/{id}/probe` gilt:

- `429` with/mit `error.code=voice_rate_limited` when Core/Agent token bucket or backoff is active.
- `error.code=voice_circuit_open` when the Core circuit is open after repeated probe failures.
- Agent-side probe failures are mirrored in job outputs with these codes:
  - `voice_query_rate_limited`
  - `voice_query_timeout`
  - `voice_query_banned`
  - `voice_query_auth_failed`
  - `voice_query_failed`
- If present, `Retry-After` or `retry_after` must be respected by clients.
- `X-Correlation-ID` is propagated to the agent query and returned in logs/outputs.
