# Secure Config Runbook (DB/SFTP)

Dieses Dokument beschreibt die sichere Konfiguration für Datenbank- und SFTP-Zugangsdaten in Easy-Wi NextGen.

## 1) Schlüsseldatei

**Pfad:** `/etc/easywi/secret.key`

**Rechte:**
```bash
sudo chown root:root /etc/easywi/secret.key
sudo chmod 600 /etc/easywi/secret.key
```

**Format (JSON, empfohlen):**
```json
{
  "active_key_id": "v1",
  "keys": {
    "v1": "<base64-encoded-32-bytes>"
  }
}
```

**Alternativ (einzeilig):**
```
v1:<base64-encoded-32-bytes>
```

**Key generieren:**
```bash
openssl rand -base64 32 | tr -d '\n' > /etc/easywi/secret.key
sudo chmod 600 /etc/easywi/secret.key
```

> Hinweis: PHP-FPM/Webserver-User benötigt **lesenden** Zugriff.

## 2) Setup-Flow (DB Credentials)

1. **Installer UI** nimmt DB-Zugangsdaten einmalig entgegen.
2. Daten werden **verschlüsselt in der Datenbank** abgelegt.
3. Zusätzlich wird eine **Bootstrap-Datei** geschrieben:
   - `/etc/easywi/bootstrap-db.json` (verschlüsselt)
   - wird verwendet, um die DB-Konfiguration beim Start zu laden.
4. Ab diesem Zeitpunkt nutzt die App ausschließlich die gespeicherten Werte.

**Wichtig:** Keine DB-Credentials in `.env`, `.env.local` oder `.env.prod`.

## 3) SFTP-Konfiguration

* SFTP Host/Port und globale Credentials werden über die Admin-UI gesetzt.
* Werte werden **verschlüsselt in der DB** gespeichert.
* Webspace-Credentials werden pro Webspace in `webspace_sftp_credentials` abgelegt (verschlüsselt).

## 4) Health & Diagnose

* **HTTP:** `GET /system/health`
  * Zeigt Setup-Status, Key-Status, DB-Konfig-Status, SFTP-Status (ohne Secrets).

* **CLI:** `bin/console app:diagnose:config`
  * Prüft Key-Datei, DB-Konfig-Entschlüsselung und DB-Verbindung.

## 5) Key-Rotation

1. Neuen Key generieren (z. B. `v2`).
2. `secret.key` aktualisieren:
   ```json
   {
     "active_key_id": "v2",
     "keys": {
       "v1": "<alt>",
       "v2": "<neu>"
     }
   }
   ```
3. Werte neu speichern (z. B. SFTP-Settings im Admin-UI erneut speichern, Installer/DB-Config neu persistieren).
4. Sobald keine Daten mehr mit `v1` verschlüsselt sind, kann `v1` entfernt werden.

## 6) Troubleshooting

| Symptom | Ursache | Lösung |
|---|---|---|
| `Key file not readable` | Datei fehlt oder falsche Rechte | `/etc/easywi/secret.key` prüfen |
| `Database config could not be decrypted` | Key falsch oder beschädigt | Key prüfen, ggf. Rotation rückgängig |
| `Database connection failed` | DB erreichbar? Credentials korrekt? | DB-Server/Netz prüfen, Installer erneut |
| `SFTP host is not configured` | SFTP Settings fehlen | Admin-UI prüfen |

## 7) Deployment-Checkliste

1. `/etc/easywi/secret.key` vorhanden + korrekt berechtigt.
2. Installer einmalig ausführen (DB-Zugangsdaten setzen).
3. SFTP-Settings im Admin-Panel setzen (optional).
4. `bin/console app:diagnose:config` ausführen.
5. `/system/health` prüfen.
