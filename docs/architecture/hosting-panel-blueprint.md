# Hosting-Panel Basisarchitektur

## Implementierungs-Roadmap (max 20)
1. Gemeinsame Domänengrenzen definieren: Webinterface, Agent, Node, Module.
2. Versionierte Agent API (`/api/v1/agent`) festlegen.
3. Idempotency-Key Pflicht für schreibende Kommandos einführen.
4. Datenmodell für Node/Agent/Module/Jobs/Metrics/Secrets/Audit bereitstellen.
5. Job-Statusmaschine definieren (`queued/running/success/failed/retry/cancelled`).
6. Messenger Queue `async` als einzige Ausführungsschiene für Aktionen nutzen.
7. Dispatch-Message + Handler für Job-Erzeugung implementieren.
8. Desired-State-Felder in Module und Job-Payload standardisieren.
9. Agent-Token Authentifizierung (Bearer + Hash) implementieren.
10. Heartbeat-Endpunkt mit Version/OS/Capabilities liefern.
11. Secret-Vault mit verschlüsselter Persistenz aufbauen.
12. Doctrine-basierte Audit-Logs für Node/Agent/Module Änderungen aktivieren.
13. Agent-Robustness (Backoff/Jitter/Offline-Cache/Replay-safe) implementieren.
14. Strukturierte Agent-Logs und Fehler-Reporting hinzufügen.
15. OpenAPI/JSON-Schema für Agent-Kommunikation publizieren.
16. Dev/Prod Setup-Dokumentation erstellen.
17. Unit-Tests für Auth, Idempotency, Job-Transitions schreiben.
18. E2E-Happy-Path als Integrationsszenario ergänzen.

## Ordner- und Package-Struktur

### Symfony 8 (`core/`)
- `src/Module/HostingPanel/Domain/Entity`: isolierte Entities für Panel-Orchestrierung.
- `src/Module/HostingPanel/Domain/Enum`: Status-Enums.
- `src/Module/HostingPanel/Application/Job`: Job-Interfaces, Statusmaschine, Dispatch Handler.
- `src/Module/HostingPanel/Application/Security`: Secret Vault + Agent Auth.
- `src/Module/HostingPanel/Application/Audit`: AuditWriter + Doctrine Listener.
- `src/Module/HostingPanel/Infrastructure/Controller`: versionierte Agent API Controller.
- `config/packages/messenger.yaml`: Routing für HostingPanel Jobs.

### Go-Agent (`agent/`)
- `cmd/panel-agent/main.go`: Agent Runtime, Heartbeat Loop.
- `internal/panelagent/api`: HTTP Client + JSON Contracts.
- `internal/panelagent/runtime`: Backoff/Jitter/Replay/Cache.
- `internal/panelagent/logging`: Structured logging.

## Kommunikationsprinzip
- Webinterface schreibt ausschließlich `Job` Datensätze + Messenger Messages.
- Agent holt/erhält Commands über versionierte API und meldet Run-Status zurück.
- Module werden als Desired/Actual-State verwaltet und über Jobs konvergiert.
- Jede Mutation an Node/Agent/Module wird auditiert.
