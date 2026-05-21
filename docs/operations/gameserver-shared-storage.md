# Gameserver Shared Storage (shared_paths)

`instance.create` und `instance.reinstall` unterstützen jetzt optional `shared_paths` + `template_id` im Job-Payload.

## Neues Feld

```yaml
shared_paths:
  - source: "maps"
    target: "maps"
    mode: "symlink"
    readonly: true
```

- `source`: Pfad innerhalb des zentralen Template-Shared-Ordners.
- `target`: Pfad innerhalb der Serverinstanz.
- `mode`: aktuell `symlink`.
- `readonly`: muss aktuell `true` sein. Das Feld ist weiterhin ein Policy-Flag und erzwingt **keine** OS-Dateirechte.

## Verzeichnisstruktur

- Shared root: `$EASYWI_INSTANCE_BASE_DIR/shared/<template-id>/`
- Instance root: `$EASYWI_INSTANCE_BASE_DIR/<instance-user>/`

## Sicherheitsregeln

Der Agent blockiert:
- Pfade mit `..` oder absoluten Escape-Pfaden.
- sensible Bereiche (`config`, `logs`, `saves/worlds`, `db/sqlite`, `cache/tmp`, `banlist`, `whitelist`, `permissions`, `token`, `secret`, `rcon`, …).

Zusätzlich:
- existierende nicht-leere Zielpfade werden **nicht überschrieben**, sondern atomar per Rename in `*.instance-backup.<timestamp>` gesichert.
- bestehende korrekte Symlinks bleiben unverändert.

## Migration bestehender Instanzen

1. Shared-Ordner pro Template vorbereiten.
2. Große, immutable Assets einmalig in den Shared-Ordner verschieben/kopieren.
3. `instance.reinstall` mit `template_id` und `shared_paths` ausführen.
4. Validieren, dass Instanzpfade als Symlink auf Shared zeigen.

## Payload-Beispiele

`instance.create`:

```json
{
  "type": "instance.create",
  "payload": {
    "template_id": "minecraft_vanilla_all",
    "shared_paths": [
      { "source": "libraries", "target": "libraries", "mode": "symlink", "readonly": true }
    ]
  }
}
```

Hinweis: `shared_paths` werden serverseitig aus dem Template geladen, **nicht** aus frei manipulierbarem Client-Input. Die Aktivierung erfolgt über `use_shared_storage=true` im Create-/Reinstall-Flow.

`instance.reinstall`:

```json
{
  "type": "instance.reinstall",
  "payload": {
    "template_id": "minecraft_vanilla_all",
    "shared_paths": [
      { "source": "libraries", "target": "libraries", "mode": "symlink", "readonly": true }
    ],
    "backup_old": "true"
  }
}
```

## Hinweise zur manuellen Migration

- Vorher Dienst stoppen und Backup der Instanz erstellen.
- Große immutable Asset-Ordner in `$EASYWI_INSTANCE_BASE_DIR/shared/<template-id>/` zentral ablegen.
- Reinstall/Create mit `shared_paths` ausführen.
- Für bestehende Instanzen: Reinstall mit aktivierter Option „Shared Storage verwenden“ auslösen, um auf Shared Storage umzustellen.
- Danach prüfen: `readlink -f <instance-path>/<target>` muss auf den Shared-Pfad zeigen.

## UI/API-Verhalten

- Die Option ist nur verfügbar, wenn das gewählte Template `shared_paths` definiert.
- Ohne explizite Auswahl bleibt das Verhalten unverändert (kein Shared Storage).
- Eine automatische Deaktivierung/Rückmigration bestehender Shared-Symlinks ist aktuell nicht implementiert.
- Die UI-Meldung „Diese Instanz nutzt Shared Storage bereits.“ basiert auf dem Instanz-Flag `shared_storage_enabled` (aktivierter Flow), nicht auf einer Byte-genauen Live-Diskanalyse.

## Erwartetes Verhalten bei Speichergröße

- Shared Storage teilt **nur** die in `shared_paths` definierten Zielordner (typisch immutable Assets wie `libraries`, `maps`, `mods` je nach Template).
- Konfiguration, Saves/World-Daten, Logs, Caches und Backups bleiben weiterhin instanzlokal und können die gemeldete Größe deutlich hoch halten.
- Bei Migration per Reinstall werden bestehende lokale Zielordner vor dem Verlinken als `*.instance-backup.<timestamp>` umbenannt; diese Backups zählen ebenfalls zur Instanzgröße, bis sie bewusst gelöscht werden.
- Deshalb ist es normal, dass die Instanz nach Aktivierung nicht sofort „klein“ wirkt, obwohl Shared Storage technisch genutzt wird.
- Verifizieren sollte man über Symlinks statt über die Gesamtgröße: `readlink -f <instance-path>/<target>` muss auf `$EASYWI_INSTANCE_BASE_DIR/shared/<template-id>/<source>` zeigen.

## Troubleshooting

- `template_id is required when shared_paths are configured`: Payload ohne Template-ID.
- `shared path ... is blocked because it appears sensitive`: Pfad verletzt Sicherheits-Policy.
- Symlink-Fehler unter Windows: Agent benötigt OS-Rechte für Symlink/Junction-Erstellung.
