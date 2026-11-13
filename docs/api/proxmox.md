# Proxmox-Panel-Endpunkte

Authentifizierung erfolgt über die vorhandene Panel-Sitzung. Alle schreibenden
Formulare benötigen ein gültiges `_token`. Proxmox-Tokens werden ausschließlich
serverseitig entschlüsselt und an die konfigurierte HTTPS-Origin gesendet;
Redirects sind deaktiviert. Clients erhalten keine Token-Secrets.

In den Admin-Verbindungsformularen legt `verify_tls=1|0` fest, ob Zertifikat und
Hostname geprüft werden. Die Auswahl wird pro Verbindung gespeichert und gilt
auch für die Kunden-VNC-Konsole. Neue und bestehende Verbindungen prüfen
standardmäßig. Fehlt das Feld beim Bearbeiten, bleibt die gespeicherte Auswahl
erhalten. HTTPS bleibt auch bei deaktivierter Prüfung verpflichtend.

## Administratoren

| Methode | Pfad | Funktion |
|---|---|---|
| GET / POST | `/admin/proxmox` | Verbindungen anzeigen / verschlüsselt speichern |
| GET | `/admin/proxmox/{id}` | Cluster, Nodes, Gäste und Aufgaben |
| POST | `/admin/proxmox/{id}/settings` | Verbindung bearbeiten/deaktivieren |
| GET | `/admin/proxmox/{id}/resources` | Gerenderte Ressourcen als JSON, `private, no-store` |
| GET | `/admin/proxmox/{id}/guests/{kind}/{vmId}` | Gast, Snapshots, Backups und Zuordnung |
| POST | `/admin/proxmox/{id}/guests/{kind}/{vmId}/action` | Validierte Gastaktion |
| POST | `/admin/proxmox/{id}/guests/{kind}/{vmId}/assign` | Kundenzuordnung und Identitäts-Tag |
| GET / POST | `/admin/proxmox/{id}/nodes/{node}/new` | QEMU-Anlage aus vorhandener ISO |
| GET | `/admin/proxmox/{id}/tasks/{taskId}` | Persistenten Taskstatus abgleichen |

`kind` ist `qemu` oder `lxc`. Aktionsformulare enthalten außerdem eine zufällige
32-stellige hexadezimale `request_key`, die serverseitig auf Cluster, Aktion,
Gast und Akteur gebunden wird. Derselbe Schlüssel wiederholt keine Mutation.

Unterstützte `action`-Werte: `start`, `shutdown`, `stop`, `reboot`,
`snapshot-create`, `snapshot-delete`, `snapshot-rollback`, `backup`,
`backup-delete`, `backup-restore`, `iso-mount`, `clone`.

- Snapshot: `snapshot`, optional `description`.
- Backup: `storage`, optional `mode` (`snapshot`, `suspend`, `stop`).
- Backup-Löschen: `storage`, `volume`.
- Restore: `storage`, `volume`, `target_storage`; nur derselbe gestoppte Gast.
- ISO: `iso` aus dem vorhandenen Storage-Inhalt.
- Klonen: `name`; nur ein in Proxmox als Vorlage markierter Gast.
- `resources-update` (nur Admin): `cores` (1–128), `memory` (128–1048576 MiB).
  Nur ausgeschaltete QEMU-VMs/LXC-Container. Bei QEMU sind `cores` die Kerne je
  bestehendem CPU-Sockel. Der aktuelle Konfigurations-Digest wird serverseitig
  gelesen; andere Einstellungen werden nicht mitgeschrieben.
- `stop`, `reboot`, Snapshot-Rollback/-Löschen, Backup-Restore/-Löschen und
  ISO-Wechsel benötigen zusätzlich `confirm=1`.

QEMU-Anlage: `name`, `iso`, `storage`, `cores`, `memory` (MiB), `disk` (GiB),
`bridge`, `request_key`, `_token`. Der Node aus der Route ist maßgeblich. Die neue
VM-ID wird aus Proxmox bezogen. Es wird keine sofort startende VM angelegt.

## Kunden

| Methode | Pfad | Funktion |
|---|---|---|
| GET | `/customer/proxmox` | Ausschließlich zugeordnete Gäste |
| GET | `/customer/proxmox/{assignmentId}` | Eigener Gast und dessen Daten |
| POST | `/customer/proxmox/{assignmentId}/action` | Eigene erlaubte Gastaktion |
| GET | `/customer/proxmox/{assignmentId}/tasks/{taskId}` | Status eines Tasks desselben Gasts |

Erlaubte Kundenaktionen: `start`, `shutdown`, `stop`, `reboot`,
`snapshot-create`, `snapshot-delete`, `snapshot-rollback`, `backup`.
Cluster, Gasttyp und VM-ID werden aus der Zuordnung genommen, nicht aus frei
übermittelten Parametern. Auch die Anwendungsschicht prüft Eigentümerschaft und
Identitäts-Tag. Backups sind auf den administrativ freigegebenen Storage begrenzt.
Fremde Zuordnungen und Tasks liefern 404, bevor Proxmox abgefragt wird.

## Antworten

Formularaktionen verwenden einen 303-Redirect mit anschließendem Auftragsverlauf
oder einer lokalisierten Fehlermeldung. Ungültige CSRF-Tokens werden mit 403
abgelehnt. Fehlende Sitzungen/abweichende Rollen werden von der vorhandenen
Sitzungsprüfung abgefangen. Die Erstellung einer ungültigen Verbindung liefert
422.

Task-JSON: `state`, `result`, `active`. Zustände sind `submitting`, `running`,
`succeeded`, `failed`. Bekannte Ergebniscodes werden im Panel lokalisiert;
ungefilterte Upstream-Fehlertexte werden nicht an Kunden weitergereicht.
Ein Ausfall beim lesenden Abgleich liefert 503 und erhält den letzten
bestätigten Status. Task-IDs sind interne Panel-IDs; Proxmox-UPIDs werden nicht
als frei wählbare Statusziele akzeptiert.

## Cloud-Init und Boot-Konfiguration (QEMU)

Die bestehenden Action-Routen akzeptieren zusätzlich:

| Aktion | Rolle | Felder |
| --- | --- | --- |
| `cloud-init` | Admin / zugewiesener Kunde | `hostname`, `username`, `ssh_keys` (öffentliche Schlüssel, eine Zeile je Schlüssel) |
| `boot-order` | Admin | `boot_order`, z. B. `scsi0;net0` aus vorhandenen Boot-Geräten |
| `guest-agent` | Admin | keine weiteren Felder; aktiviert Host-Anbindung unter Erhalt bestehender Optionen |

Alle drei Aktionen erfordern eine ausgeschaltete QEMU-VM. Cloud-Init benötigt
ein bestehendes Cloud-Init-Laufwerk ohne `cicustom`. Die Kundenzuweisung wird
zusätzlich anhand des Tags aus der frisch gelesenen Konfiguration geprüft.
Der aktuelle Digest wird serverseitig gelesen. Andere Konfigurationsfelder aus
Formulareingaben werden nicht übernommen. Die Cloud-Init-Vorgaben lösen keine
Neuinitialisierung des Gastbetriebssystems aus. Details:
[Cloud-Init-Ausbau](../reports/vm-cloud-init-erweiterung.md).

## Lesende Gastinformationen

`POST /admin/proxmox/{id}/guests/qemu/{vmId}/guest-agent` verwendet die bestehende
CSRF-ID `proxmox-action-<id>-qemu-<vmId>`. Für Kunden gilt
`POST /customer/proxmox/{assignmentId}/guest-agent` mit
`proxmox-customer-<assignmentId>`. Beide benötigen `_token` und eine passende
Sitzung. Frei übermittelte VM-IDs oder Guest-Agent-Befehle werden nicht verwendet.

JSON: `guest_agent` mit `state`, `observed_at`, `version`, `hostname`, `os`.
Beobachtungszustände sind unter anderem `reachable`, `not_running`, `disabled`,
`unreachable`, `forbidden`, `unavailable`. Optionale Werte sind `null`.
Die Antworten sind `private, no-store`; providerseitige Agent-Antworten können
zehn Sekunden zwischengespeichert werden. Weitere Details im
[Gaststatus-Bericht](../reports/vm-gaststatus-erweiterung.md).

Die bestehende Gaststatus-JSON-Antwort enthält zusätzlich `guest_agent.network`:
`state`, `interfaces`, `limited`. Adapter enthalten `name`, `mac`, `addresses`;
Adressen enthalten `family` (`ipv4`/`ipv6`), `address`, `prefix`. Maximal 16 Adapter
mit je 16 Adressen. Die Angaben sind Gastbeobachtungen und keine IP-Zuweisungen.
Siehe [Netzwerkinformationen](../reports/vm-netzwerkinformationen.md).

## Durchsatz im Auslastungs-HTML

Die bestehenden `/usage`-Routen ergänzen Raten aus einem aktuellen RRD-Intervall
innerhalb der aktuellen VM-Laufzeit. Fehler dieser optionalen RRD-Abfrage lassen
Basiswerte bestehen. Das HTML enthält bereinigte numerische Diagrammdaten;
kein neuer schreibender Endpunkt wird eingeführt. Proxmox-Raten sind gemittelt.
Details: [Durchsatz und Kurzverlauf](../reports/vm-durchsatz-und-kurzverlauf.md).

Die bereinigten Auslastungsdaten enthalten `load_source=proxmox_status` bzw.
`proxmox_cluster`. Der Browser kann die bereits geladene Serie der jeweiligen
Karte als CSV exportieren. Dafür gibt es keinen neuen HTTP-Endpunkt. Quellen,
UTC-Zeitpunkte und I/O-Intervalle bleiben je Beobachtung erhalten. Details:
[CSV-Messwertexport](../reports/vm-messwerte-csv-export.md).

## Festplatte vergrößern (Admin)

Die bestehende Admin-Action-Route akzeptiert `action=disk-grow`, `disk` (ein in
der aktuellen Konfiguration angebotenes Gerät), `size_gib` (neue absolute
Gesamtgröße, ganze GiB, 1–65536), `expected_bytes` (bisherige Größe),
`expected_digest` (Digest der angezeigten Konfiguration), `confirm=1`,
`request_key` und `_token`. Kunden dürfen diese Aktion nicht ausführen.

Zustand und Konfiguration werden neu gelesen. Abweichende Größe oder Digest,
laufender Gast, Proxmox-Sperre und vorhandene Snapshots blockieren die Änderung.
Nur angehängte, beschreibbare Speichervolumes mit bekannter Größe werden angeboten.
Verkleinerung, CD-ROM, Cloud-Init-Medien, EFI/TPM, `unused`-Disks, direkte
Host-Pfade und als gemeinsam markierte Volumes sind ausgeschlossen.

Upstream: `PUT /nodes/{currentNode}/{qemu|lxc}/{vmId}/resize` mit `disk`,
absolutem `size=<GiB>G` und dem frisch gelesenen `digest`. Frei übermittelte
Volume-Pfade und relative Größen werden nicht weitergereicht. Synchrone Erfolge
und zurückgegebene UPIDs werden über den bestehenden Aufgabenverlauf verarbeitet.
Details: [Festplatten-Erweiterung](../reports/vm-festplatten-erweitern.md).
