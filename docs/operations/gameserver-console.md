# Gameserver Live-Konsole (SSE Relay)

## Architektur (Ist-Zustand)

- **Transport zum Browser:** Server-Sent Events (`/instances/{id}/console/stream`).
- **Backend-Pipeline:** Agent Stream -> `app:console:relay` -> Redis EventBus (bounded list + pub/sub) -> SSE Controller.
- **Resume/Reconnect:** Browser sendet `Last-Event-ID` oder `last_offset` (Fallback: `cursor`), Controller replayed Events ab Sequenz.
- **Auth/Zugriff:** Stream nur für Instanz-Besitzer oder Admin.

## Hotfix-Änderungen (Stabilität)

1. **Resume mit Offset über alle Schichten**
   - Relay merkt sich `last_offset` pro Instanz und reicht ihn beim Reconnect an den Agent-Stream weiter.
   - Browser sendet `last_offset` aktiv beim Neuaufbau der EventSource.
   - SSE-Response enthält `X-Console-Resume-Offset` zur Diagnose.
2. **Near real-time + bounded buffering**
   - Agent-Console-Session bleibt begrenzt (1000 Zeilen).
   - Bei Overflow werden alte Zeilen verworfen (`dropped_lines` wird hochgezählt).
   - Frontend-Scrollback bleibt begrenzt (`SCROLLBACK_LIMIT`) und verworfene Zeilen werden ebenfalls gezählt.
3. **Heartbeat/Reconnect robuster**
   - Server sendet Ping-Events.
   - Client erzwingt Reconnect bei ausbleibendem Heartbeat (30s Timeout) mit Backoff.
   - `Cache-Control: no-cache, no-transform` + `X-Accel-Buffering: no` minimieren Proxy-Buffering.
4. **Observability ergänzt**
   - Stream-Metriken: `bytes_per_sec`, `reconnects`, `dropped_lines`, `last_offset`.
   - `correlation_id` bleibt auf Agent/Relay/SSE-Events verfügbar.

## Fehlerbilder (beobachtet/abgesichert)

1. **Fehlende Ausgabe nach Reconnect**
   - Ursache: uneinheitliche oder fehlende Sequenznummern.
   - Gegenmaßnahme: zentrale Seq-Normalisierung im Redis EventBus + `last_offset` Resume.
2. **Delay/Disconnect bei längerem Idle**
   - Ursache: Keepalive-Lücken und inaktive Verbindungen.
   - Gegenmaßnahme: Ping/Heartbeat Events, Client-Heartbeat-Timeout und TTL-Refresh.
3. **Memory-/Queue-Wachstum**
   - Ursache: unbegrenzter Log-Backlog.
   - Gegenmaßnahme: bounded Redis Buffer (`bufferSize`), bounded Agent-Session-Buffer, Drop-Strategie mit Metriken.
4. **Schlechte Nachvollziehbarkeit bei Streamfehlern**
   - Ursache: fehlende Korrelation über Schichten.
   - Gegenmaßnahme: `correlation_id` in Relay/SSE Payloads und `X-Correlation-ID` Header.

## Betriebs-Hinweise

- Relay muss laufen: `php bin/console app:console:relay`.
- Redis muss erreichbar sein (inkl. Pub/Sub).
- Für Debugging: Correlation-ID aus Browser-Response in Relay-Logs suchen.

## Reproduzierbarer Hotfix-Test (10k + Reconnect)

1. Gameserver-Job starten (Instanz muss laufend Output schreiben können).
2. Stream öffnen: `/instances/{id}/console/stream`.
3. Output erzeugen (z. B. 10k Zeilen via RCON/Console-Befehl).
4. Während laufendem Stream Browser-Tab refreshen oder Netz kurz unterbrechen (Reconnect erzwingen).
5. Erwartung:
   - Stream verbindet wieder ohne Hänger/Abbruch.
   - Ausgabe läuft weiter (near real-time).
   - Keine unbounded Memory-Entwicklung.
   - Metriken enthalten `bytes_per_sec`, `reconnects`, `dropped_lines`, `last_offset`.
