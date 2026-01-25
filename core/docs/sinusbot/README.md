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
  "web_bind_ip": "127.0.0.1",
  "web_port_base": 8087,
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

## Hinweise

- Agent API Token und Admin Passwörter werden verschlüsselt gespeichert.
- Der Admin kann Credentials nur manuell im Detailbereich anzeigen.
- Für `.tar.bz2`-Downloads müssen `tar` und `bzip2` (bzw. `lbzip2`) auf dem Agent-Host installiert sein, sonst wird das Archiv heruntergeladen, aber nicht entpackt.
