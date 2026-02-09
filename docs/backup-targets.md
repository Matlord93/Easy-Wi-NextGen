# Backup-Ziele (Targets)

Dieses Dokument beschreibt die neuen Backup-Ziele (Targets) für lokale Pfade, NFS/SMB sowie WebDAV/Nextcloud.
Die Endpunkte sind sowohl für Admins als auch Kunden verfügbar. Admins können optional `customer_id` übergeben,
um Ziele für Kunden anzulegen.

## API-Übersicht

### Ziele auflisten
`GET /api/v1/customer/backup-targets`

### Ziel anlegen
`POST /api/v1/customer/backup-targets`

Beispiel (WebDAV):
```json
{
  "label": "Nextcloud Archiv",
  "type": "webdav",
  "config": {
    "url": "https://cloud.example.com/remote.php/dav/files/backups",
    "root_path": "/easywi",
    "verify_tls": true
  },
  "credentials": {
    "username": "backup-user",
    "password": "app-password"
  }
}
```

### Ziel aktualisieren
`PATCH /api/v1/customer/backup-targets/{id}`

Optional können Credentials aktualisiert oder entfernt werden:
```json
{
  "credentials": {
    "token": "new-app-token"
  },
  "clear_credentials": ["password", "username"]
}
```

### Ziel löschen
`DELETE /api/v1/customer/backup-targets/{id}`

## Validierung & Sicherheit

- Credentials werden serverseitig verschlüsselt abgelegt (keine Rückgabe im Klartext).
- Für SMB ist `username` + `password` erforderlich.
- Für WebDAV/Nextcloud ist entweder `token` oder `username` + `password` erforderlich.

## Backup-Definitionen verknüpfen

Beim Anlegen einer Backup-Definition kann optional `backup_target_id` gesetzt werden, um das Ziel zu referenzieren.
```json
{
  "target_type": "game",
  "target_id": "123",
  "label": "Nightly",
  "backup_target_id": 42,
  "cron_expression": "0 3 * * *",
  "retention_days": 7,
  "retention_count": 5,
  "enabled": true
}
```
