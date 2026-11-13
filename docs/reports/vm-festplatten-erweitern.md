# Vorhandene VM-Festplatten vergrößern

Stand: 13. November 2026. Ergänzung der Proxmox-/KVM-Verwaltung im
Adminbereich. Bestehende Kundenberechtigungen und Daten bleiben erhalten.

## Bedienung

Server öffnen → **Einstellungen** → **Festplatte vergrößern**. Proxmox zeigt
geeignete Geräte mit aktueller Kapazität einzeln; verwaltete KVM-VMs verwenden
ihre vorhandene Hauptfestplatte. Eine neue **Gesamtgröße in GiB** eintragen
und die dauerhafte Vergrößerung bestätigen. Beispiel: 32 GiB → 40 GiB bedeutet
8 GiB zusätzliche virtuelle Kapazität. Relative Angaben wie `+8G` sind gesperrt.

Die VM muss ausgeschaltet sein. Vorhandene Snapshots blockieren die Funktion;
sie werden nicht automatisch gelöscht. Das Formular erklärt diese Zustände,
beachtet die Obergrenzen und zeigt bei alten KVM-Agenten das nötige Agent-Update
an. Deutsch und Englisch, Dark-/Lightmode und mobile Breiten werden unterstützt.

QEMU/KVM: Partitionen und Dateisysteme im Gast müssen danach im Betriebssystem
erweitert werden, soweit das Gastbetriebssystem dies nicht selbst erledigt.
Bei LXC übernimmt Proxmox die Volume-Vergrößerung; eine Dateisystem-Erweiterung
hängt vom Storage ab. Die verfügbare Kapazität im Container anschließend prüfen.

Kunden erhalten keine freie Kapazitätsänderung ohne Produkt-/Kontingentverwaltung.
Sie sehen die vom Provider bzw. Agent danach gemeldete Kapazität über ihre
bestehenden berechtigten Ansichten.

## Proxmox

- QEMU-Festplatten an IDE/SATA/SCSI/VirtIO und geeignete LXC-Volumes an
  `rootfs`/`mp0`–`mp255`, sofern ein Speicher-Volume und eine gültige Größe
  in der aktuellen Konfiguration stehen. Ganze GiB, bis 65536 GiB.
- CD-ROMs, Cloud-Init-Medien, EFI-/TPM-Medien, `unused`-Disks, direkte Host-
  oder Bind-Mount-Pfade, schreibgeschützte und als gemeinsam markierte Volumes
  werden ausgeschlossen. Das ist keine vollständige zusätzliche Disk-Verwaltung.
- Der aktuelle Node wird aus Proxmox ermittelt. Größe und Konfigurations-Digest
  des Formulars müssen der frisch gelesenen Konfiguration entsprechen.
- Der bestehende Aufgaben-Lock, Anfrage-Schlüssel, CSRF und Admin-Prüfung gelten.
  Upstream erhält ausschließlich Gerät, absolute Zielgröße und aktuellen Digest.
  Proxmox prüft außerdem Storage, Sperren und Berechtigungen.
- Ein synchroner Erfolg oder eine zurückgegebene UPID wird im bestehenden
  Aufgabenverlauf geführt. Transport-/Providerfehler werden als fehlgeschlagene
  Aufgabe angezeigt. Vor erneutem Versuch die aktuelle Größe neu laden; es gibt
  keinen automatischen Resize-Retry.

## KVM

- Nur bestehende, durch EasyWI verwaltete Hauptfestplatten, maximal 2048 GiB.
  Zusätzliche Dateipfade werden weder vom Panel noch vom Agent akzeptiert.
- Neue Agent-Fähigkeit `disk_grow_version=1`; alter Agent erhält keinen Auftrag.
  Operationen verwenden weiter UUID-/Payload-Journal und Host-Lock.
- Der Agent prüft ausgeschalteten Zustand, UUID, Domain-XML, Manifest,
  tatsächliche QCOW2-Größe und fehlende Libvirt-/interne QCOW2-Snapshots erneut.
  Veraltete Größen, gleiche/kleinere Zielgrößen und falsche Geräte werden abgelehnt.
- Die vorhandene QCOW2-Datei wird ohne vollständige Kopie vergrößert.
  `qemu-img` verwendet seinen exklusiven Image-Lock; es wird kein `--force-share`
  für die Änderung verwendet. Zielgröße wird danach erneut aus dem Image gelesen.
- Eine Bestätigungsstörung nach Aufruf von `qemu-img`, einschließlich fehlgeschlagener
  Manifest-Speicherung, führt zu `KVM_OPERATION_UNCONFIRMED`. Der Agent behält die
  Operation als ausstehend und blockiert weitere Änderungen. Die größere Disk
  wird nicht verkleinert. Vor Freigabe am Host Image-Größe, Manifest und Operation
  prüfen und den tatsächlichen Zustand administrativ abgleichen.
- Ein früher erstelltes kleineres Backup wird beim bestehenden Restore vor
  Einsetzen auf die aktuelle Manifest-Kapazität erweitert. Die neue virtuelle
  Kapazität bleibt damit erhalten; der Dateiinhalt stammt aus dem Backup.

Virtuelle Kapazität ist keine Zusage über dauerhaft freien physischen Storage.
Sparse-Dateien und Thin Provisioning belegen ihren Platz mit späteren Schreibzugriffen.

## Aktualisierung

Dieser Patch ergänzt den Stand nach `messwerte-csv-proxmox-kvm.patch` und den
vorhergehenden Verwaltungs-/Cloud-Init-/Gaststatus-/Netzwerk-/Durchsatz-Patches.
Er ersetzt diese Änderungen nicht. Keine Datenbankmigration erforderlich.

Panel nach dem üblichen Verfahren aktualisieren und Symfony-Cache erneuern.
Für KVM außerdem einen Agent aus demselben Quellstand bauen und ausrollen,
Heartbeat abwarten und das Inventar erneuern. Ein Agent-Neustart für das Update
ist erforderlich; laufende Verwaltungsaufträge vorher beenden lassen.
Der bestehende Dienstname und die Konfiguration bleiben erhalten. Proxmox
benötigt keinen EasyWI-Agent-Update; sein API-Token braucht zusätzlich das
für Resize erforderliche `VM.Config.Disk`-Recht auf den betroffenen Gästen.

## Prüfung und Grenzen

Die Funktionsprüfungen verwenden synthetische Konten, Proxmox-Antworten und
eine simulierte lokale libvirt-Verbindung. Zusätzlich wurden echte QCOW2-
Dateien mit `qemu-img` und `qemu-io` ohne Netzwerkzugriff getestet: Datenmuster
vor/nach Vergrößerung, Image-Konsistenz, interne Snapshots, gestörte Bestätigung
und Restore eines älteren Backups unter Erhalt der neuen Größe.

Ein echter Proxmox-/KVM-Live-Test, VM-Boot und Dateisystem-Erweiterung im Gast
stehen aus. Für den HomeServer zuerst eine separate Test-VM mit Sicherung
verwenden; danach virtuelle Kapazität, Boot und verfügbare Gast-Kapazität prüfen.

Zusätzlich wurde ein vorhandener Sprachwechsel-Fehler korrigiert:
Die Portal-Sprache wurde erst nach Symfonys initialer Locale-Weitergabe
ermittelt. Jetzt werden nach der Auflösung aus Query/Benutzer/Sitzung/Cookie auch
Übersetzer und Formatierer über den Symfony-`LocaleSwitcher` aktualisiert.
Die Priorität nach der Sitzungsprüfung bleibt erhalten. Damit wechseln
`trans`-basierte Karten zusammen mit der gewählten Portal-Sprache.

Weitere Prüfungen:

- KVM-Tests mit Race Detector, `go vet` und Agent-Builds für Linux/amd64 und
  Windows/amd64 bestehen. KVM selbst benötigt weiterhin Linux/amd64.
- Der vollständige Go-Lauf meldet einen Fehler im unveränderten Test
  `TestTeamspeakBackendMissingLibraryAndOpus`: erwartet `opus_missing`, erhält
  `ready`. Der Test entfernt nur seine lokale Opus-Datei; der unveränderte
  Resolver findet hier die installierte Systembibliothek. Isoliert reproduziert.
  Der übrige vollständige Go-Lauf mit genau diesem Test ausgeschlossen besteht.
- 36 JavaScript-Regressionstests bestehen. PHPStan, Twig-/YAML-Lint,
  Container-Lint und Formatprüfung bestehen.
- 30 reguläre Browseransichten: Admin-QEMU/LXC/KVM und Kunden-Proxmox/KVM,
  jeweils Dark/Lightmode bei 1440/960/390 Pixel; dazu zwölf Kartenansichten auf
  Deutsch/Englisch bei Desktop-Dark-/Mobile-Lightmode. Keine JavaScript-Fehler
  und keine horizontalen Seitenüberläufe.
- Acht gesperrte UI-Zustände, drei reguläre Formularübermittlungen und der
  daraus gespeicherte KVM-Auftrag wurden geprüft. Serverseitige Tests decken
  CSRF, Kunden-Ausschluss, Snapshot-/Zustandssperren, veraltete Größe/Digest,
  absolute Zielgröße, Duplikate und die Payload-Freigabeliste ab.

Abschließender vollständiger PHP-Lauf: **1553 Tests, 6559 Assertions**, keine
Fehler/Fehlschläge. Die unveränderten 3 Warnungen, 301 PHPUnit-Hinweise und
12 übersprungenen Tests bleiben bestehen. Der eigene Entwicklungsserver für
die Browserprüfung wurde anschließend beendet.

Der inkrementelle Patch wurde vorwärts gegen den gespeicherten Ausgangsstand
und rückwärts gegen diesen Arbeitsstand geprüft. Nach Anwendung auf den
Ausgangsstand stimmen die geänderten Dateien bytegenau überein.
