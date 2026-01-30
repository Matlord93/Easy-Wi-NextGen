# SinusBot Modul

Dieses Modul verwaltet SinusBot Nodes und (später) Bot-Instanzen über den Easy-Wi Agent. Alle Aktionen laufen per HTTP+JSON über den Agent und ohne SSH.

## Agent-Endpunkte

Der Agent muss folgende Endpunkte anbieten:

- `POST /v1/sinusbot/install`
- `GET /v1/sinusbot/status`
- `POST /v1/sinusbot/instances`
- `GET /v1/sinusbot/instances/{id}`
- `PATCH /v1/sinusbot/instances/{id}`
- `DELETE /v1/sinusbot/instances/{id}`
- `POST /v1/sinusbot/instances/{id}/start`
- `POST /v1/sinusbot/instances/{id}/stop`
- `POST /v1/sinusbot/instances/{id}/restart`

### Install Payload

```json
{
  "download_url": "https://michael.frie.se/sinusbot-1.1f-amd64.tar.bz2",
  "install_path": "/opt/sinusbot",
  "instance_root": "/opt/sinusbot/instances",
  "web_bind_ip": "0.0.0.0",
  "web_port_base": 8087,
  "admin_password": "wird-automatisch-generiert",
  "return_admin_credentials": true,
  "dependencies": {
    "install_ts3_client": true,
    "ts3_client_download_url": "https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run"
  }
}
```

### Status Response

```json
{
  "installed": true,
  "installed_version": "1.1.0",
  "last_error": null,
  "dependencies": {
    "ts3_client_installed": true,
    "ts3_client_version": "3.6.2",
    "ts3_client_path": "/opt/teamspeak3/ts3client"
  }
}
```

## Admin UI

- Nodes unter `/admin/sinusbot/nodes` verwalten.
- Install/Repair im Detailbereich.
- TS3 Client Dependency Status sichtbar + Install/Repair Button.

## Agent-Installation (kein manuelles Setup)

Die SinusBot-Installation wird ausschließlich durch den Easy-Wi Agent durchgeführt. Es darf kein manueller Installationsablauf auf dem Node nötig sein. Der Agent übernimmt:

- Installation der erforderlichen Systempakete (z. B. `ca-certificates`, `curl`, `tar`, `xz`, `bzip2`, `libatomic1`, `libevent-2.1-7`).
- Download und Entpacken des SinusBot-Archivs.
- Setzen der Rechte im Installationspfad.
- Optionaler Download und Installation des TeamSpeak-Clients inkl. Plugin-Setup.
- Erzeugen der Konfiguration (`config.ini`) inkl. Web-Port/Bind-IP.
- Start über systemd mit `--override-password`, damit das Admin-Passwort gesetzt wird.
- Rückgabe der Admin-Zugangsdaten, wenn `return_admin_credentials=true` gesetzt ist.

### TS3-Client Zusatzpakete (Ubuntu/Debian)

Wenn der TeamSpeak-3-Client für SinusBot installiert wird, müssen zusätzlich folgende Pakete vorhanden sein:

```bash
apt install libqt5gui5 libqt5widgets5 libqt5network5 libqt5dbus5 libqt5core5a libstdc++6 libxcb-keysyms1 libxcb-image0 libxcb-shm0 libxcb-icccm4 libxcb-sync1 libxcb-render-util0 libxcb-xinerama0 libxcb-xkb1 libxkbcommon-x11-0
```

## Fehlerbehebung bei Startproblemen

Wenn SinusBot nach der Agent-Installation nicht startet, prüfe zuerst:

1. Der Agent-Job `sinusbot.install` ist erfolgreich abgeschlossen und `last_error` ist leer.
2. Der TS3-Client ist laut Status installiert (`ts3_client_installed=true`). Ohne TS3-Client startet SinusBot nicht, auch wenn nur Discord genutzt wird.
3. `web_port_base` ist in der Firewall des Nodes freigegeben (Standard: `8087`).
4. Das Admin-Passwort wird nach dem ersten Login im Webinterface dauerhaft gesetzt.

## Hinweise

- Agent API Token und Admin Passwörter werden verschlüsselt gespeichert.
- Der Admin kann Credentials nur manuell im Detailbereich anzeigen.
- Für `.tar.bz2`-Downloads müssen `tar` und `bzip2` (bzw. `lbzip2`) auf dem Agent-Host installiert sein, sonst wird das Archiv heruntergeladen, aber nicht entpackt.
