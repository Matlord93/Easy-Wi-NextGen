# Musicbot Release Candidate Checklist

Stand: 2026-06-21  
Branch: `claude/amazing-davinci-p0iupn` (alle PRs gemerged in main)

Diese Checkliste beschreibt den finalen RC-Check vor einem Musicbot-Release. Sie ergänzt `musicbot-production-readiness.md` durch konkrete Testergebnisse und eine deployment-bereite Schritt-für-Schritt-Anleitung.

---

## Featurestatus-Übersicht

| Bereich | Status | Anmerkung |
|---------|--------|-----------|
| Datenmodell / Entities | **beta** | Entities, Mappings, Migrationen vorhanden |
| Admin-UI | **beta** | Create/Edit/Delete/Status; Browser-E2E noch offen |
| Customer-UI | **beta** | Queue, Upload, Playlist, Auto-DJ, Stream-UI; Browser-E2E noch offen |
| REST-API (v1) | **beta** | Alle Endpunkte vorhanden; AuthZ-Matrix-Tests noch offen |
| Agent Jobs | **beta** | install/uninstall/update/repair/status/queue.sync/playback.action registriert |
| Runtime Lifecycle | **beta** | Binary, Control-Socket, Playback-State, Queue-Sync produktionsbereit |
| Queue-Sync | **production-ready** | Stabil; Cross-Instance-Injection abgeblockt |
| Playback-State Feedback | **beta** | Status-Payload vollständig; SSE/Frontend-Refresh noch nicht E2E-getestet |
| AudioPipeline | **beta** | FFmpeg/DummyEncoder; lokale Dateien; Opus-Realdateien unter Last noch offen |
| Secrets-Handling | **production-ready** | Token/Passwort nie in Log, Status, API, Audit |
| Webradio HTTP-Stream | **beta** | Eigener HTTP-WAV-Stream (PCM s16le 48 kHz); public/private/token; kein Icecast/Shoutcast; VLC/mpv/ffplay kompatibel |
| Discord Voice | **experimental** | RealDiscordVoiceClient vorhanden; kein Live-E2E-Test bisher |
| TeamSpeak Voice | **experimental** | External Bridge Protokoll vorbereitet; PlaceholderAdapter Default; Bridge Binary gebaut |
| TeamSpeak Bridge Binary | **beta** | `easywi-teamspeak-bridge`; Protokoll-/Secret-Tests grün; echter TS-Client fehlt noch |
| Auto-DJ | **experimental** | API/UI vorhanden; Race-Conditions und Fairness noch nicht E2E-getestet |
| Scheduler | **beta** | CRUD/Toggle/Dispatch vorhanden; Worker-Ausführung noch nicht E2E-getestet |
| Workflows | **beta** | Trigger/Dispatch-Pfade vorhanden; Isolation und Rechte noch nicht E2E-getestet |
| Plugins | **beta** | Manifest/Lifecycle/First-Party-TS-Integration vorhanden |
| Quotas/Limits | **beta** | Plan-Limits für alle Ressourcen vorhanden |
| Logs/Audit | **beta** | Audit-Events und Runtime-Event-Service vorhanden |

### Was ist bewusst nicht enthalten

- Kein YouTube-Audio, kein Spotify-Audio
- Kein SinusBot, kein TS3AudioBot, kein Lavalink
- Kein TeamSpeak Reverse Engineering / ServerQuery-Audio
- Kein Icecast/Shoutcast als erzwungenes Backend
- Kein fremdes Binary als verdeckte Abhängigkeit
- Keine TeamSpeak native SDK-Anbindung (offizielles SDK nicht öffentlich verfügbar)

---

## CI-Gate-Ergebnisse (RC-Lauf 2026-06-21)

### Go Agent

| Prüfung | Ergebnis | Anmerkung |
|---------|----------|-----------|
| `go mod download` | ✅ OK | Alle Module geladen |
| `go mod verify` | ✅ OK | Hash-Verifikation grün |
| `go test ./internal/musicbot/...` | ✅ OK | Alle 59 Tests bestanden |
| `go build ./...` | ✅ OK | Keine Build-Fehler |

### PHP Core

| Prüfung | Ergebnis | Anmerkung |
|---------|----------|-----------|
| `composer install` | ✅ OK | Keine Konflikte |
| `composer test` (870 Tests) | ✅ OK | 0 Failures, 0 Errors; 4 Skipped (DB-abhängig) |
| `lint:twig` (Musicbot-Templates) | ✅ OK | 6 Twig-Dateien, alle valide |
| `doctrine:schema:validate --skip-sync` | ⚠️ WARN | Kein DB in dieser Umgebung; in Staging ausführen |
| `doctrine:migrations:status` | ⚠️ WARN | Kein DB in dieser Umgebung; in Staging ausführen |

### Smoke-Test

| Prüfung | Ergebnis | Anmerkung |
|---------|----------|-----------|
| `scripts/musicbot-smoke-test.sh` | ✅ OK | 25 PASS, 0 FAIL |
| Route-Checks (alle 14 Musicbot-Routen) | ✅ OK | |
| Agent-Job-Handler-Registrierung | ✅ OK | install/status/queue.sync vorhanden |
| TeamSpeak Placeholder ≠ ready | ✅ OK | Korrekt: `client_backend_required` |
| Discord Placeholder ≠ ready | ✅ OK | Korrekt: `voice_backend_required` / `placeholder` |
| Plugin-Manifest First-Party | ✅ OK | |
| DB-Checks (Migrationen, mutating API) | ⚠️ WARN | Kein DB in dieser Umgebung |

### Bekannte Fixes in diesem RC

Folgende Defekte wurden im RC-Lauf identifiziert und behoben:

1. **`MusicbotInstanceRepositoryInterface::find()` Signatur-Konflikt** — PHP-Fatal wegen inkompatibler Rückgabe (`?MusicbotInstance` vs. `?object` aus `ServiceEntityRepository`). Fix: `find` aus Interface entfernt, `findById(int $id): ?MusicbotInstance` eingeführt; Concrete-Repo und Call-Site aktualisiert.

2. **`MusicbotScheduleDispatcher`-Klassenname stimmt nicht mit Dateiname überein** — `MusicbotScheduleJobDispatcher` in `MusicbotScheduleDispatcher.php` ist PSR-4-illegal. Fix: Klasse in `MusicbotScheduleDispatcher` umbenannt; DI-Alias aktualisiert.

3. **DI-Alias `MusicbotScheduleDispatcherInterface` fehlte** in `services.yaml`. Fix: Alias hinzugefügt.

4. **DI-Alias `MusicbotInstanceRepositoryInterface` fehlte** in `services.yaml`. Fix: Alias hinzugefügt.

5. **`MusicbotPlaybackStateFeedbackTest` implementiert veraltete Interface-Methode** — Anonyme Klasse im Test nutzte `find()` statt `findById()`. Fix: Test aktualisiert.

6. **`MusicbotRuntimeEventServiceTest` mockt `final class`** — `createMock(MusicbotRuntimeEventRepository::class)` schlägt fehl. Fix: `newInstanceWithoutConstructor()` via Reflection.

7. **`api_musicbot_status` fehlte in `ApiVersioningGuardTest`-Allowlist** — `/api/musicbots/status` ist eine Legacy-Route (kein `/api/v1/`-Prefix) und muss im Guard-Test explizit gelistet sein. Fix: Pattern hinzugefügt.

---

## Deployment-Checkliste

### Voraussetzungen

- [ ] PHP 8.2+, Composer, Symfony Console verfügbar
- [ ] Go 1.22+ auf dem Build-Server verfügbar
- [ ] Ziel-DB erreichbar (MySQL/MariaDB oder PostgreSQL)
- [ ] Runtime-Verzeichnis mit 0750-Rechten vorhanden
- [ ] Config-Datei mit 0600-Rechten für den Runtime-Binary

### Schritt 1: Core deployen

```bash
# Dependencies installieren
composer install --no-dev --optimize-autoloader --working-dir=core

# Cache warm
php core/bin/console cache:warm --env=prod

# Doctrine-Migrationen ausführen
php core/bin/console doctrine:migrations:migrate --no-interaction --env=prod

# Schema validieren (nach Migrationen)
php core/bin/console doctrine:schema:validate --skip-sync --env=prod
```

### Schritt 2: Agent-Binaries bauen

```bash
cd agent

# Abhängigkeiten sicherstellen
go mod download
go mod verify

# Agent-Binary
go build -o /usr/local/bin/easywi-agent ./cmd/agent

# Musicbot-Runtime-Binary
go build -o /usr/local/bin/easywi-musicbot ./cmd/easywi-musicbot

# TeamSpeak-Bridge-Binary (nur wenn TeamSpeak External Bridge aktiviert)
go build -o /usr/local/bin/easywi-teamspeak-bridge ./cmd/easywi-teamspeak-bridge
```

### Schritt 3: Runtime-Config anlegen

```bash
# Config-Datei mit 0600 (enthält StreamToken und ggf. Bot-Token)
install -m 0600 /dev/null /etc/easywi/musicbot/<instance>.json

# Minimales Beispiel (Stream optional):
cat > /etc/easywi/musicbot/<instance>.json <<'EOF'
{
  "instance_id": "<id>",
  "customer_id": "<cid>",
  "service_name": "<name>",
  "install_path": "/srv/musicbot/<instance>",
  "stream": {
    "enabled": false
  },
  "control": {
    "unix_socket": "/run/easywi/musicbot/<instance>.sock"
  }
}
EOF
chmod 0600 /etc/easywi/musicbot/<instance>.json
```

### Schritt 4: Service installieren

```bash
# Systemd Unit für Musicbot-Runtime
cat > /etc/systemd/system/easywi-musicbot@.service <<'EOF'
[Unit]
Description=easyWI Musicbot Runtime (%i)
After=network.target

[Service]
User=easywi
ExecStart=/usr/local/bin/easywi-musicbot --config /etc/easywi/musicbot/%i.json
Restart=on-failure
RestartSec=5s
StandardInput=null
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable easywi-musicbot@<instance>
systemctl start easywi-musicbot@<instance>
```

### Schritt 5: Smoke-Test ausführen

```bash
# Ohne externe Services (immer ausführen)
MUSICBOT_RUNTIME_BIN=/usr/local/bin/easywi-musicbot \
  scripts/musicbot-smoke-test.sh

# Mit API (wenn Panel läuft)
MUSICBOT_SMOKE_BASE_URL=http://127.0.0.1:8000 \
MUSICBOT_SMOKE_ADMIN_AUTH_HEADER="Authorization: Bearer <admin-token>" \
MUSICBOT_SMOKE_CUSTOMER_AUTH_HEADER="Authorization: Bearer <customer-token>" \
MUSICBOT_SMOKE_INSTANCE_ID=1 \
  scripts/musicbot-smoke-test.sh
```

Erwartetes Ergebnis: `0 FAIL`, exit code `0` (oder `2` bei optionalen WARNs ohne externe Services).

### Schritt 6: Secret-Leak-Check

```bash
# Runtime-Status auf Secret-Leak prüfen
echo '{"command":"status"}' | /usr/local/bin/easywi-musicbot --config /etc/easywi/musicbot/<instance>.json \
  | grep -i "token\|password\|secret\|bearer" && echo "LEAK DETECTED" || echo "OK"

# Control-Socket prüfen
echo '{"command":"status"}' | nc -U /run/easywi/musicbot/<instance>.sock \
  | grep -i "token\|password\|secret\|bearer" && echo "LEAK DETECTED" || echo "OK"
```

---

## Release-Gate-Status

| Gate | Status | Bedingung |
|------|--------|-----------|
| `go test ./...` (musicbot) grün | ✅ | 59/59 |
| `composer test` grün | ✅ | 870/870 |
| Twig Lint grün | ✅ | 6/6 |
| Smoke-Test PASS | ✅ | 25/25 (0 FAIL) |
| Kein Secret-Leak in Status/API | ✅ | Smoke-Test + Code-Review |
| Kein Placeholder als `ready` | ✅ | TS `client_backend_required`, Discord `voice_backend_required` |
| Doctrine-Migrationen laufen | ⚠️ | Nur in Staging mit DB prüfbar |
| Doctrine-Schema valide | ⚠️ | Nur in Staging mit DB prüfbar |
| Discord Live-E2E grün | ❌ | Benötigt Test-Guild + Bot-Token |
| TeamSpeak Live-E2E grün | ❌ | Benötigt echten TS-Server + Bridge-Client |
| Browser-E2E (Admin/Customer) | ❌ | Noch nicht durchgeführt |

### Release-Empfehlung

**Eingeschränkter Beta-Release ist vertretbar** für:
- Verwaltungsfunktionen (Admin/Customer-UI, API, Jobs)
- Queue-Sync, Playback-State-Feedback, Runtime-Control
- Quotas, Scheduler, Playlists, Upload-Validierung
- Plugin-System (First-Party TeamSpeak-Integration)
- **Webradio HTTP-Stream** als optionales Beta-Feature (kein Icecast, WAV-Stream)

**Noch nicht für Production freigeben:**
- Discord Voice (experimental — Live-E2E fehlt)
- TeamSpeak Voice (experimental — echter Bridge-Client-Layer fehlt)
- Auto-DJ (experimental — Race-Conditions und Fairness ungeklärt)

---

## Beziehung zu anderen Dokumenten

| Dokument | Zweck |
|----------|-------|
| `docs/release/musicbot-production-readiness.md` | Ausführliche Komponentenbewertung mit Status-Legende |
| `docs/testing/musicbot-smoke-test.md` | Smoke-Test-Anleitung |
| `docs/testing/musicbot-live-e2e.md` | Live-E2E-Anleitung (Discord/TeamSpeak) |
| `docs/architecture/musicbot-teamspeak-external-bridge-protocol.md` | Bridge-IPC-Protokollspezifikation |
| `docs/release/musicbot-release-candidate-checklist.md` | Dieses Dokument |
