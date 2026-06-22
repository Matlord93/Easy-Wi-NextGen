# Gameserver Live-Konsole: Sofortbetrieb + Echtzeit-Setup

## 1) Was ohne Zusatz-Setup direkt funktioniert

Nach Panel + Agent Installation funktioniert die Konsole im **Polling-Modus** ohne zusätzliche Einrichtung:

- Befehle werden an den Agent gesendet.
- Log-Ausgabe wird regelmäßig nachgeladen.
- Kein Redis/Relay notwendig.

## 2) Was für echte Live-Echtzeit (SSE) zusätzlich benötigt wird

Für kontinuierlichen Live-Stream (SSE, ohne Polling) werden benötigt:

1. Redis (inkl. Pub/Sub)
2. laufender Relay-Worker: `php bin/console app:console:relay`
3. erreichbarer Agent-Console-Endpunkt

## 3) Schritt-für-Schritt Anleitung (Produktiv)

### Schritt A: Redis installieren und starten

Ubuntu/Debian Beispiel:

```bash
sudo apt update
sudo apt install -y redis-server
sudo systemctl enable --now redis-server
redis-cli ping
```

Erwartung: `PONG`

### Schritt B: Panel .env prüfen

Sicherstellen, dass Redis-DSN gesetzt ist (Beispiel):

```dotenv
REDIS_URL=redis://127.0.0.1:6379
```

Danach Cache leeren:

```bash
php bin/console cache:clear
```

### Schritt C: Relay lokal testen

```bash
php bin/console app:console:relay
```

Wenn keine Fehler erscheinen, läuft der Relay korrekt.

### Schritt D: Relay als systemd Service einrichten

Datei `/etc/systemd/system/easywi-console-relay.service` anlegen:

```ini
[Unit]
Description=EasyWI Console Relay
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/easywi/core
ExecStart=/usr/bin/php /var/www/easywi/core/bin/console app:console:relay
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

Aktivieren:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now easywi-console-relay.service
sudo systemctl status easywi-console-relay.service
```

### Schritt E: Funktionsprüfung

1. Gameserver starten.
2. Kunden-Console öffnen.
3. Befehl senden (z. B. `status`).
4. Erwartung:
   - Befehl wird angenommen.
   - Antwort erscheint direkt im Console-Output.
   - Health zeigt keinen Relay/Redis-Fehler.

## 4) Troubleshooting (wenn es nicht „100%“ läuft)

### Problem: Befehle kommen nicht an

Prüfen:

- Instanzstatus: läuft der Server wirklich?
- Agent erreichbar?
- CSRF/Session gültig?
- Reverse Proxy blockiert POST nicht?

### Problem: Nur Polling, kein Live-Stream

Prüfen:

```bash
php bin/console app:console:relay
redis-cli ping
```

Wenn Relay oder Redis fehlen/fehlschlagen, fällt die UI absichtlich auf Polling zurück.

### Problem: Verzögerte oder keine Ausgabe

- Agent-Logs prüfen
- Relay-Logs prüfen (`journalctl -u easywi-console-relay -f`)
- Redis-Verbindung prüfen

## 5) Empfehlung für „direkt geht's“ Installationen

Wenn wirklich ohne Nacharbeit **immer** Live-Echtzeit gewünscht ist:

- Redis in den Standard-Installer aufnehmen
- systemd-Unit für `app:console:relay` im Installer automatisch erzeugen und starten
- Installations-Healthcheck um Relay-Heartbeat erweitern

Damit funktioniert die Live-Konsole nach Installation reproduzierbar und dauerhaft in Echtzeit.
