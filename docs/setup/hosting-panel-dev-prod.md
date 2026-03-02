# Setup (Dev/Prod)

## Development
1. `cd core && composer install`
2. `.env` setzen: `MESSENGER_TRANSPORT_DSN`, `APP_HOSTING_PANEL_SECRET_KEY` (base64 key).
3. Migrationen für `hp_*` Tabellen erzeugen und ausführen.
4. Worker starten: `php bin/console messenger:consume async --time-limit=3600`.
5. Agent starten: `cd agent && go run ./cmd/panel-agent`.

## Production
- TLS erzwingen (Reverse Proxy + HSTS).
- Agent-Tokens nur gehasht speichern, Rotation per Secret-Rollover Job.
- Queue-Worker als Systemd Service mit Restart-Policy betreiben.
- Audit-Log regelmäßig exportieren/archivieren (WORM-kompatibel).
- Least-Privilege DB User + restriktive Firewall zwischen Panel/Nodes.

## Sicherheitsregeln
- Keine direkte Klick-Aktion auf Nodes; nur Job Dispatch.
- Jede schreibende API benötigt Idempotency-Key.
- Keine Secret-Ausgabe in API/Logs.
- Änderungen an Node/Agent/Module in `hp_audit_log` persistieren.
