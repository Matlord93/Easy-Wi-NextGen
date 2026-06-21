# Musicbot Production Readiness Check

Stand: 2026-06-21

Diese Bewertung ist bewusst konservativ. Sie beschreibt, was vor einem echten Release produktiv nutzbar ist, was als Beta/Experimental gilt und was weiterhin ein Placeholder oder blockiert ist. Kein vorbereiteter Connector, kein Stub und kein UI-Schalter darf als fertige Audio-Funktion verkauft werden.

## Status-Legende

| Status | Bedeutung |
| --- | --- |
| `production-ready` | Mit normalen Release-Gates produktiv nutzbar. |
| `beta` | Funktional vorbereitet und intern testbar, aber vor breiter Freigabe sind weitere Integrations-/E2E-Tests nötig. |
| `experimental` | Technisch vorhanden, aber nur für kontrollierte Tests oder explizit aktivierte Instanzen geeignet. |
| `placeholder` | Bewusst vorbereitete Oberfläche/Struktur ohne echten produktiven Backend-Effekt. Darf nicht als fertig beworben werden. |
| `blocked` | Nicht releasefähig, weil externe Entscheidung, Infrastruktur oder Implementierung fehlt. |

## Executive Summary

Das Musicbot-Modul ist als Verwaltungs-, Queue-, Plugin- und Runtime-Grundlage weit fortgeschritten, aber noch nicht als vollwertiger produktiver Voice-/Streaming-Musicbot freizugeben. Produktionsnah nutzbar sind vor allem Datenmodell, Kern-APIs, Quotas, Admin-/Customer-Verwaltung, Agent-Lifecycle-Jobs, Runtime-Control-Basics, Queue-Sync und Playback-State-Feedback. Audio-Ausgabe in echte Voice-Netze bleibt eingeschränkt: Discord Real Voice ist optional/experimental bis zu echten Discord-E2E-Tests; TeamSpeak Voice ist nur dann ready, wenn ein erlaubter echter Client-Layer (`external_client_bridge` oder später offizielles `native_sdk`) tatsächlich verbunden ist. Webradio ist weiterhin Placeholder.

## Komponentenmatrix

| Bereich | Status | Release-Aussage | Offene Punkte vor Production |
| --- | --- | --- | --- |
| Datenmodell | `beta` | Tabellen/Entities für Instanzen, Connections, Tracks, Queue, Playlists, Plugins, Limits, Scheduler, Workflows, Auto-DJ/Webradio-Einstellungen sind vorhanden. | Doctrine-Mapping und Migrationen gegen Ziel-DBs validieren; Upgrade-/Rollback-Pfade testen. |
| Migrationen | `beta` | Migrationen sind vorhanden und werden vom Smoke-Test geprüft. | In CI und Staging gegen leere DB und bestehende DB ausführen. |
| Admin UI | `beta` | Admin kann Musicbots verwalten, Connections konfigurieren, Limits/Schedules/Workflows einsehen und Jobs auslösen. | Browser-E2E für Create/Edit/Delete, Secrets und Connector-Status ergänzen. |
| Customer UI | `beta` | Customer-Flows für eigene Musicbots, Queue, Uploads, Playlists, Plugins, Stream-/Auto-DJ-Oberflächen sind vorbereitet. | Browser-E2E und Rechte-/Tenant-Isolation prüfen. |
| API | `beta` | REST-Endpunkte für Customer/Admin, Queue, Tracks, Playlists, Plugins, Secrets, Limits, Scheduler, Workflows, Logs und Status sind vorhanden. | API-Contract-Tests, AuthZ-Matrix und negative Tests vervollständigen. |
| Agent Jobs | `beta` | `musicbot.install`, `uninstall`, `update`, `repair`, `service.action`, `status`, `playback.action`, `queue.sync` und `connection.test` sind registriert. | Systemd-/Windows-Service-Verhalten auf Zielplattformen testen. |
| Runtime Lifecycle | `beta` | Runtime-Binary, Install-/Repair-/Update-Pfade und Runtime-Konfiguration sind vorbereitet. | Packaging, Rollback und Service-Supervision in Staging validieren. |
| Runtime Control | `beta` | Lokaler Control-Kanal und Commands wie `status`, Playback-Actions und `queue.sync` sind vorhanden. | Auth/Socket-Rechte, Timeouts und Failure-Recovery in echter Installation prüfen. |
| Queue-Sync | `production-ready` | Lokale Upload-Queue wird in die Runtime synchronisiert; fremde/externe Quellen werden verworfen. | Last-/Concurrency-Test mit vielen Queue-Updates. |
| Playback-State Feedback | `beta` | Status enthält Playback, Queue, AudioPipeline, Connector-Status und Output-Fehlerfelder. | Frontend-Refresh/SSE/Job-Rückmeldung über längere Laufzeit testen. |
| AudioPipeline | `beta` | Lokale Dateien können per Decoder/Encoder-Pipeline verarbeitet werden; Frames, Output-Status, `frames_sent`, `last_output_error` und Timing sind modelliert. | FFmpeg/Opus-Realdateien unter Last und Stop/Pause/Skip E2E testen. |
| Discord Voice Backend | `experimental` | RealDiscordVoiceClient, Gateway/Voice/UDP, Heartbeats, Reconnects und DiscordAudioOutput sind vorbereitet. | Nur optional aktivieren; echte Discord-E2E-Tests, Rate-Limit-Tests und Langläufer erforderlich. |
| TeamSpeak Voice Backend | `experimental` | Backend-Modell existiert; Placeholder bleibt Default; `external_client_bridge` ist der erlaubte konkrete Pfad; `native_sdk` bleibt Stub ohne offizielle SDK-Anbindung. | Externen Bridge-Client final spezifizieren/liefern; echte TS3/TS6-E2E-Tests; niemals ready ohne echten Client-Layer. |
| TeamSpeak Integration Plugin | `beta` | First-Party Runtime-Plugin mit Command-Router, Event-Bridge, Permission-Mapper, Rate-Limits und Tests ist vorbereitet. | An echte TeamSpeak-Event-/Chat-Bridge anbinden und E2E-Rechte testen. |
| Secrets | `production-ready` | SecretConfig-/API-Flows zeigen keine Plaintext-Werte; Runtime-/Connector-Fehler werden maskiert. | Release-Gate: zusätzliche Leak-Scans in Logs, Status, API und Audit-Daten. |
| Quotas | `beta` | Limits für Musicbots, Uploads, Queue, Playlists, Plugins, Scheduler/Workflows/Webradio/Connections sind vorhanden. | Plan-Migration, Admin-Defaults und Überschreitungsfälle in API/UI testen. |
| Logs | `beta` | Audit-Logs und Runtime-Events sind vorhanden; Customer/Admin-Log-APIs existieren. | Retention, PII/Secret-Scan und hohe Eventmengen prüfen. |
| Scheduler | `beta` | Schedule-CRUD/Test/Toggle APIs und Admin-Übersicht sind vorhanden. | Worker-Ausführung, Cron-Semantik und Retry-/Misfire-Verhalten E2E testen. |
| Workflows | `beta` | Workflow-Entities/APIs und TeamSpeak-Event-Dispatch-Pfade sind vorbereitet. | Trigger-Ausführung, Rechte und Isolation mit realen Events testen. |
| Plugins | `beta` | Plugin-Manifest, Zuweisung, Aktivierung und Konfiguration sind vorhanden; TeamSpeak-Integration ist First-Party. | Keine fremde Codeausführung; Plugin-Lifecycle und Migration der Plugin-Konfiguration testen. |
| Auto-DJ | `experimental` | UI/API/Service sind vorhanden und können Queue-Aktionen vorbereiten. | Echte Auswahlregeln, Fairness, Race Conditions und Runtime-E2E testen. |
| Webradio | `placeholder` | UI/Settings/Token-Flows existieren; es gibt bewusst keinen echten Broadcast-Output. | Eigenes Streaming-Backend implementieren und klar als noch nicht produktiv markieren. |

## Release Gates

Ein Release darf erst als produktionsfähig markiert werden, wenn alle Gates grün sind:

1. **Keine Secret-Leaks**
   - Keine Tokens/Passwörter in API-Antworten, Runtime-Status, Connector-Status, Logs, Audit-Events oder Fehlermeldungen.
   - Smoke-Test mit synthetischen Secret-Markern muss grün sein.
2. **Migrationen laufen**
   - `php bin/console doctrine:migrations:migrate --dry-run --no-interaction` gegen leere Ziel-DB grün.
   - Upgrade-Test gegen letzte produktive Schema-Version grün.
3. **Go Tests grün**
   - `go test ./...` im Agent-Verzeichnis grün.
   - Netzwerkabhängige Tests müssen gemockt bleiben; keine echten Discord-/TeamSpeak-Verbindungen in Unit Tests.
   - Abhängigkeiten müssen vor dem Testlauf lokal vorliegen: `go mod download && go mod verify` muss erfolgreich sein.
   - Ein 403-Fehler von `proxy.golang.org` ist ein Netzwerk-/Cache-Problem, kein Code-Fehler. `GOPROXY=https://proxy.golang.org,direct go mod download` behebt den Download; danach laufen Tests ohne Netzwerkzugang.
   - CI-Workflows cachen `~/go/pkg/mod` nach `agent/go.sum`-Hash; nach dem ersten Lauf ist kein erneuter Download nötig.
4. **PHPUnit grün**
   - `php bin/phpunit` im Core-Verzeichnis grün.
5. **Twig Lint grün**
   - `php bin/console lint:twig templates src/Module/Musicbot --no-interaction` grün.
6. **Doctrine Mapping grün**
   - `php bin/console doctrine:schema:validate --skip-sync --no-interaction` grün.
7. **Smoke Test grün**
   - `scripts/musicbot-smoke-test.sh` in Staging ohne `FAIL`.
   - Optionaler Runtime-Binary-Teil und API-Teil in Staging aktivieren.
8. **Keine falschen Ready-Status bei Placeholdern**
   - TeamSpeak Placeholder darf nie `capability_status=ready` melden.
   - Discord Placeholder darf nur `placeholder` oder `voice_backend_required` melden.
   - `output_backend=null` ist akzeptiert, wenn kein echter Voice-Backend-Layer ready ist.
9. **Discord Real Backend nur optional/experimental**
   - Aktivierung nur pro Instanz/Umgebung mit Bot-Token und explizitem Opt-in.
   - Vor Production: echter Join/Send/Leave/Reconnect-Langzeittest gegen dedizierten Test-Guild.
10. **TeamSpeak Voice nur ready mit echtem Client-Layer**
    - `connected=true`, `voice_client_available=true` und `output_backend=teamspeak_voice` nur bei wirklich verbundenem `external_client_bridge` oder später offizieller SDK-Implementierung.
    - Placeholder, fehlender Bridge-Pfad oder fehlendes SDK bleiben `client_backend_required`/`error`, nicht ready.

## Bekannte Nicht-Ziele

Diese Punkte sind bewusst nicht Teil des Release-Scopes:

- Kein YouTube-Audio.
- Kein Spotify-Audio.
- Kein SinusBot.
- Kein TS3AudioBot.
- Kein Lavalink.
- Kein TeamSpeak Reverse Engineering.
- Kein ServerQuery-Audio für TeamSpeak.
- Keine fremden Musicbot-Systeme oder fremde Binaries als verdeckte Abhängigkeit.

## Nächste echte Implementierungsschritte

1. **DiscordAudioOutput finalisieren**
   - Echte Discord-E2E-Testumgebung bereitstellen.
   - Join/Send/Pause/Stop/Skip/Leave/Reconnect mit realen Opus-Frames validieren.
   - Langläufer gegen Rate-Limits, Heartbeat-Timeouts und UDP-Fehler testen.
2. **TeamSpeak Client Backend klären**
   - Entscheidung bestätigen: offizielles `native_sdk` mit bereitgestellten Libraries oder eigener erlaubter `external_client_bridge`.
   - Bridge-Protokoll versionieren, lokale Auth/IPC härten, Prozess-Lifecycle testen.
   - TS3 als Hauptprofil, TS6 über denselben `ts3_client_compatible` Pfad prüfen.
3. **TeamSpeak Integration Plugin aktivieren**
   - Echte Chat-/Event-Bridge anbinden.
   - Servergruppen-/Channelgruppen-Mapping mit realen TeamSpeak-Events testen.
   - Rate-Limits, Spam-Schutz und Tenant-Isolation E2E absichern.
4. **Webradio Backend implementieren**
   - Streaming-Ausgabe konzipieren, z. B. Icecast-kompatibler Output oder eigenes internes Backend.
   - Token-/URL-/Limit-Modell und Secret-Handling finalisieren.
5. **Live E2E Testumgebung aufbauen**
   - Dedizierter Discord-Test-Guild, dedizierter TeamSpeak-Testserver, isolierte Staging-DB.
   - Reproduzierbare Fixtures für Admin/Customer/API/Agent/Runtime.
   - Smoke-Test mit API- und Runtime-Binary-Modus in CI/Staging ausführen.

## Release-Entscheidung

Aktueller Vorschlag: **kein allgemeines Production-Release für echte Voice-/Webradio-Ausgabe**. Ein eingeschränkter Beta-Release für Verwaltungsfunktionen, Queue/Playlist/Upload, Agent-Lifecycle, Runtime-Control, Queue-Sync, Quotas, Scheduler/Workflow-Grundlagen und Plugin-Konfiguration ist vertretbar, wenn alle Release-Gates für diese Teilmenge grün sind. Discord Voice und TeamSpeak Voice müssen als optional/experimental gekennzeichnet bleiben; Webradio bleibt Placeholder.
