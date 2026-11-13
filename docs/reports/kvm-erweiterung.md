# Prüfbericht: KVM-Erweiterung

Stand: 12.11.2026 (Europe/Berlin). Ausgangscommit: `dd40e15689d4ee0f4391eb4ad7e35f9a2e5f07ff`. Der Arbeitsbaum war vor Beginn sauber. Vorhandene Rechnungs-, Minecraft-, Sicherheits- und Proxmox-Änderungen bleiben erhalten.

> Nachtrag: Die ergänzten Kundenkonsolen benötigen jetzt Migration `Version20261112040000` und den Konsolen-Gatewaydienst. Der ursprüngliche KVM-Basisbericht unten beschreibt den vorherigen Stand; aktueller Konsolen-Prüfbericht: [VM-Konsolen](vm-konsolen.md).

## Ergebnis und Übernahme

Die KVM-Erweiterung ist im Adminbereich und im Go-Agent umgesetzt: Bestandsanzeige und Aufträge, unveränderliche Templateversionen, Provisionierung mit Cloud-Init und SSH-Schlüsseln, VM-Lifecycle, CPU/RAM, interne Snapshots, lokale Backups mit Restore und freigegebene Netzwerke/Bridges. Andere libvirt-VMs werden nicht automatisch übernommen. Bestehende Domain-UUIDs und Namen werden vor dem Definieren einer neuen VM auf Kollisionen geprüft.

Es ist keine zusätzliche Datenbankmigration erforderlich. Die Erweiterung nutzt vorhandene Agentaufträge und einen verwalteten Katalog auf dem KVM-Host. Panel und Agent müssen gemeinsam aktualisiert werden. Hostvoraussetzungen, Grenzen, Storage-Rechte und die Reihenfolge für den HomeServer-Test stehen in [KVM-Integration](../../wiki/KVM-Integration.md); der Vertrag steht in [API-Dokumentation](../api/kvm.md).

## Ausgeführte Prüfungen

| Prüfung | Ergebnis / Reichweite |
| --- | --- |
| KVM-HTTP-Tests | 16 Tests, 113 Assertions erfolgreich: Adminansichten, CSRF, Kundensperre, Nodebindung, Jobstatus, veralteter Agent, wiederholte Formulare, konkurrierende Aktionen, Validierung und vorheriger Bestand nach Fehler |
| KVM-Agenttests mit Race Detector | 22 Tests einschließlich bestehender KVM-Adaptertests erfolgreich; unter anderem acht gleichzeitige identische Aufträge mit nur einer tatsächlichen Mutation |
| Native Werkzeuge | Dieselben 22 KVM-Tests erfolgreich mit echten `qemu-img`-, `cloud-localds`-, `isoinfo`- und `virt-xml-validate`-Aufrufen in einem unprivilegierten Testcontainer ohne externes Netzwerk |
| Native Datenträgerabläufe | qcow2-Kopie und Resize, Cloud-Init-ISO mit tatsächlicher user-data, XML gegen libvirt-Schemata, interne qcow2-Snapshots, Backup/Restore, Prüfsummenabweichung und Disk-Rollback bei fehlgeschlagener Definition |
| Browser | Template-Import, VM-Erstellung, Limits, Snapshot, Backup/Restore und Netzwerk-Erstellung; Restore bei vorhandenen Snapshots deaktiviert; Zustandsabfrage nach vorübergehendem HTTP-503 erholt sich und erhält den vorherigen Bestand |
| Mobilansicht | 390-Pixel-Ansicht ohne horizontalen Seitenüberlauf; Desktop- und Mobilansicht ohne JavaScript-Fehler |
| Statische PHP-Prüfung | PHPStan für neue PHP-Dateien ohne Fehler; Symfony-Servicecontainer, vier Twig-Dateien und beide Übersetzungsdateien erfolgreich geprüft |
| Plattformverträglichkeit | Windows-amd64-Agent baut weiterhin; die neue KVM-Ausführung selbst ist auf Linux/x86_64 beschränkt |
| Vollständige PHP-Suite | 1.442 Tests, 5.328 Assertions, **2 bestehende Fehler** wegen fehlender PHP-Klasse `Redis`; keine Test-Failures. Außerdem 3 Warnings, 301 PHPUnit-Notices und 10 Skips |
| Vollständige Go-Suite | Ein Fehler in `TestTeamspeakBackendMissingLibraryAndOpus`: vorhandenes Opus führt zu `ready` statt erwartetem `opus_missing`. Derselbe Test schlägt in einem separaten Checkout des unveränderten Ausgangscommits ebenfalls fehl. Die übrigen Pakete bestehen. Der Agentpaketlauf ohne diesen ausdrücklich dokumentierten Test besteht ebenfalls. |

Wesentliche Sicherheitsfälle: fremde Diskquellen und Hostgeräte in Snapshot-XML werden abgewiesen; externe/unverwaltete Domains bleiben schreibgeschützt; ein ersetztes namensgleiches Netzwerk wird anhand seiner UUID abgewiesen; überlappende Subnetze und nicht freigegebene Host-Bridges werden blockiert. Eine abgebrochene unbestätigte Mutation sperrt weitere Mutationen, bis der Hostbetreiber sie geprüft hat.

## Aussagegrenzen

Die HTTP- und Browsertests verwenden simulierte Agentresultate. Im nativen Werkzeugtest werden die Datenträger- und ISO-Programme tatsächlich ausgeführt, libvirt-Aufrufe und VM-Laufzustände jedoch simuliert. Der Container läuft ohne `/dev/kvm` und verändert keine realen Host-Netzwerke. Daher wurden weder ein beschleunigter Gaststart noch tatsächliche Cloud-Init-Ausführung, Gastnetzwerk/SSH, libvirt-DAC/AppArmor oder ein reales Bridge-Setup bestätigt.

`ameserver` wurde aus dieser Cloud-Aufgabe nicht verändert. Die Live-Abnahme mit einer separaten VM steht aus. Große Disks, produktive Host-Bridges und distroabhängige libvirt-Berechtigungen müssen im Zielsystem geprüft werden; die dokumentierten Laufzeit-/Größengrenzen gelten auch dort. Die KVM-Implementierung ist damit vorbereitet, ihre Live-Abnahme ist ausdrücklich noch offen.
