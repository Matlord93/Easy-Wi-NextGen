# Kundenkonsolen-Vertrag

Alle Kundenrouten benötigen den aktiven Kundenlogin. Die Zuweisung wird vor Ticketvergabe und beim Verbindungsaufbau überprüft.

| Route | Vertrag |
| --- | --- |
| `POST /customer/vm-console/{provider}/{id}/ticket` | `provider=proxmox|kvm`, zugewiesene ID, Formularfeld `_token` mit CSRF-ID `vm-console-<provider>-<id>` |
| `GET /customer/kvm` und `GET /customer/kvm/{id}` | Eigene KVM-Zuweisungen bzw. eigener Gast mit Konsole |
| `POST /admin/kvm/nodes/{node}/guests/{uuid}/assign` | Admin, CSRF `kvm-assign-<node>-<uuid>`, `customer_id`; `0` entfernt die Zuweisung |
| `GET /vm-console/ws` | WebSocket-Gateway, exakter Panel-Origin, Kunden-Cookie und Subprotokolle `binary`, `easywi-console.<ticket>` |

Die erfolgreiche Ticketantwort ist `{"ticket":"<64 hex>","websocket_path":"/vm-console/ws","expires_in":45}` mit `Cache-Control: private, no-store`. Sie enthält weder Infrastrukturadresse noch Passwörter/API-Tokens. Fremde Zuweisungen liefern 404, ungültiger CSRF 403, zu viele Tickets 429 und nicht erreichbare oder nicht konfigurierte Infrastruktur 503. Eine erfolgreiche Ticketausgabe bedeutet noch keine bestehende VNC-Verbindung.

Die folgenden JSON-POSTs sind ausschließlich für das Gateway bestimmt und benötigen zusätzlich `X-EasyWI-Console-Gateway`, den ursprünglichen Kunden-Cookie und den aktiven Kundenlogin:

- `/customer/vm-console/connect`: `{ticket}`. Liefert nach atomarer Einlösung `{session_id, expires_at, url, authorization, password}` sowie bei Proxmox `skip_tls_verify` aus der gespeicherten Admin-Verbindungseinstellung. Ohne dieses Feld prüft das Gateway das Zertifikat. Die Ausnahme gilt ausschließlich für dieses Upstream-Ziel; Panel-Verbindungen und andere Konsolen übernehmen sie nicht. `expires_at` ist die absolute Unix-Zeit in Sekunden. Diese Antwort enthält sensible Infrastruktur-Zugangsdaten und darf nie an Browser durchgereicht, gecacht oder protokolliert werden.
- `/customer/vm-console/validate`: `{session_id}`. Liefert HTTP 200 mit `{valid:true}` nur solange Login, Ablaufzeit, Anbieter, Zuweisung und VM-Identität noch gültig sind. Jede andere Antwort oder Transportstörung schließt die Verbindung.

Die Gateway-Berechtigung ist keine Alternative zum Kundenlogin. Das Gateway folgt keinem Login-/Fehlerredirect. Ein eingelöstes oder abgelaufenes Ticket kann auch von derselben Kunden-Sitzung nicht erneut verwendet werden. Ein anderer Login desselben Kunden kann das Ticket nicht übernehmen.

KVM verwendet intern `GET /v1/kvm/console/{vm-uuid}` am Agent mit Bearer-Authentisierung. Der Agent erwartet keinen Browser-Origin und liest keinen Socketpfad aus URL oder Payload. Die neue Agentaktion `kvm.managed` / `vm.console.configure` bereitet eine bestehende, ausgeschaltete verwaltete VM vor. Die Agentfähigkeit dafür ist `kvm_managed.console_version=1`.

Deployment und Rollen: [VM-Konsolen](../../wiki/VM-Konsolen.md).

## KVM-Verwaltung im Kundenbereich

Zusätzlich zur Konsole stehen zugewiesenen Kunden folgende Panel-Routen bereit:

| Route | Vertrag |
| --- | --- |
| `POST /customer/kvm/{id}/action` | CSRF-ID `kvm-customer-<id>`, `_token`, `operation_id` (UUID v4), `action` |
| `GET /customer/kvm/{id}/jobs/{jobId}` | Eigene aktuelle Zuweisung, passender Node, VM-UUID und verwalteter KVM-Auftrag; andernfalls 404 |

Erlaubte Aktionen: `vm.start`, `vm.shutdown`, `vm.stop`, `vm.reboot`,
`snapshot.create`, `snapshot.revert`, `snapshot.delete`, `backup.create`,
`backup.restore`, `backup.delete`. Snapshot-Aktionen benötigen `snapshot`,
Backup-Restore/-Löschen `backup_id`. VM und Node werden ausschließlich aus der
Zuweisung abgeleitet. Frei übermittelte VM-IDs, Nodes, URLs und Ressourcenlimits
werden nicht an den Agent weitergereicht.

Stop/Neustart, Snapshot-Restore/-Löschen und Backup-Restore/-Löschen benötigen
`confirm=1`. Snapshot-/Backup-Verwaltung setzt eine ausgeschaltete VM voraus.
Restore/Delete akzeptieren nur im aktuellen Bestand dieser VM geführte
Snapshots/Backups. Backup-Restore ist bei vorhandenen VM-Snapshots gesperrt.
Der Agent prüft Zustand, UUID, Manifest, Disk und Sicherung erneut.

Nach POST erfolgt ein 303-Redirect zum passenden Reiter. Fehler sind lokalisierte
Panel-Meldungen. Job-JSON enthält nur `id`, `action`, `status`, `created_at`;
Host-Logs, Fehlertexte und vollständige Agent-Inventare werden nicht ausgegeben.
Antworten verwenden `private, no-store`. Der Kundenwechsel ist während
ausstehender VM-Aufträge gesperrt und mit der Kundenauftragsanlage serialisiert.

## KVM-Provisionierung und Boot-Konfiguration

Die bestehenden KVM-Action-Routen unterstützen zusätzlich `vm.cloud_init`
(`hostname`, `ssh_keys`) und `vm.boot_order` (`boot_order=disk|network`) für Admins
und zugewiesene Kunden. `vm.guest_agent` ist ausschließlich eine Admin-Aktion
und bereitet den VirtIO-Channel vor. Alle benötigen einen aktuellen Agent mit
`kvm_managed.config_version=1` und eine ausgeschaltete VM. Nach Agent-Aktualisierung
Heartbeat und Inventar erneuern. Die Cloud-Init-Ansicht enthält nur Hostname,
Benutzer und öffentliche Schlüssel; der ursprüngliche Benutzer bleibt erhalten.

Cloud-Init ändert Initialisierungsvorgaben, keine bestätigten Einstellungen eines
bereits eingerichteten Gasts. Instance-ID, Netzwerkdatei und Disk bleiben erhalten.
Netzwerkboot benötigt externe PXE-Dienste und hat einen Festplatten-Fallback.
Die Guest-Agent-Vorbereitung installiert bei bestehenden VMs keine Gastsoftware.
Sie bestätigt weder einen gestarteten Dienst noch dessen Erreichbarkeit.

## Lesende KVM-Gastinformationen

Die bestehenden Admin-/Kunden-Action-Routen akzeptieren `action=vm.agent_status`,
CSRF-Token und `operation_id`. Admins übermitteln die verwaltete `vm_id`, bei
Kunden wird sie aus der Zuweisung bestimmt. Erfordert
`kvm_managed.guest_agent_status_version=1` im Heartbeat. AJAX-Anfragen erhalten
`202` mit `job_id`; Formularübermittlungen behalten ihren Redirect.

Die bestehenden Job-Statusrouten ergänzen nach erfolgreichem Prüfauftrag das
gefilterte Objekt `guest_agent`: `state`, `observed_at`, `version`, `hostname`,
`os`. Andere Resultate, Host-Logs und komplette Inventare werden nicht ausgegeben.
Es ist eine Beobachtung des Gastdiensts, keine Konfigurationsänderung und keine
automatische Bestätigung von Cloud-Init-Vorgaben. Siehe
[Gaststatus-Bericht](../reports/vm-gaststatus-erweiterung.md).

Neuere KVM-Agenten ergänzen im bestehenden `vm.agent_status`-Resultat
`guest_agent.network` mit `state`, `interfaces`, `limited` und melden
`guest_network_info_version=1` im Heartbeat. Adapter enthalten `name`, `mac`,
`addresses` mit `family`, `address`, `prefix`. Die Statusroute prüft diese Felder
erneut; andere Resultatfelder bleiben ausgeblendet. Fehlt das Netzwerkobjekt,
zeigt das Panel `not_reported`. Details:
[Netzwerkinformationen](../reports/vm-netzwerkinformationen.md).

## KVM-Durchsatzmessung

Neuere Agent-Heartbeats ergänzen `kvm_usage.guests.<uuid>` um
`net_rx_bytes_per_second`, `net_tx_bytes_per_second`,
`disk_read_bytes_per_second`, `disk_write_bytes_per_second` sowie
`io_observed_at`, `io_interval_seconds`, `io_source=counter_delta`.
Unbekannte Raten fehlen; eine gemessene Null ist gültig. Die Panel-Auslastungsrouten
prüfen Aktualität, Quelle und Intervall vor der Anzeige. Diagramme halten nur
begrenzte Daten im aktuellen Browser-Tab. Details:
[Durchsatz und Kurzverlauf](../reports/vm-durchsatz-und-kurzverlauf.md).

Das Panel ergänzt normalisierte Agent-Auslastungsdaten um
`load_source=agent_heartbeat`. Der CSV-Export verwendet nur die schon geladene
Serienkarte, übernimmt die gemeldete I/O-Quelle und verlangt keine neue
Agent-Aktion. Fehlende Werte bleiben leer. Details:
[CSV-Messwertexport](../reports/vm-messwerte-csv-export.md).

## Verwaltete KVM-Festplatte vergrößern (Admin)

`POST /admin/kvm/nodes/{id}/actions` akzeptiert `action=vm.disk_grow`, `vm_id`,
`disk_gib` (neue absolute Gesamtgröße, ganze GiB, 1–2048), `expected_disk_gib`
(angezeigte bisherige Kapazität), `confirm=1`, `operation_id` und `_token`.
Benötigt `kvm_managed.disk_grow_version=1`. Kein Kundenauftrag und keine frei
wählbaren Geräte- oder Dateipfade. Die üblichen 303-/202-Antworten und
Auftragsstatusrouten gelten weiter.

Unter dem bestehenden Host-Lock prüft der Agent VM-Identität, Gerätekonfiguration,
ausgeschalteten Zustand, Manifest und tatsächliche QCOW2-Größe. Libvirt-Snapshots
und interne QCOW2-Snapshots blockieren die Änderung. `qemu-img resize` verwendet
eine absolute größere Kapazität und seine exklusive Bildsperre. Nach Erfolg
werden Größe und Manifest bestätigt. Fehler nach Aufruf des Resize markieren
die Operation als unbestätigt und sperren weitere Änderungen bis zur Prüfung
am Host. Es gibt keine automatische Verkleinerung zur Rücknahme.
Details: [Festplatten-Erweiterung](../reports/vm-festplatten-erweitern.md).
