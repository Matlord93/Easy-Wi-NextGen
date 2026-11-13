# Abschlussausbau Proxmox, KVM, Monitoring und Rechnungen

Auftrag vom 13. November 2026: die drei Bereiche vollständig ausbauen.
Ausgangspunkt ist Commit `5f813862`; bestehende Daten und Funktionen bleiben erhalten.
E-Rechnungen sollen ausdrücklich XRechnung **und** ZUGFeRD unterstützen.

## Abnahmekriterien

| Bereich | Zu bearbeiten / abzunehmen |
| --- | --- |
| Monitoring | Automatische Erfassung ohne geöffneten Browser; dauerhafte Node-/VM-Verläufe; Zeiträume und Export; Aufbewahrung; Schwellwerte, Ausfälle und Benachrichtigungen; Mandantentrennung auch bei Neuzuweisung |
| VM-Konfiguration | Zusätzliche Disks, Netzwerk-/Bridge-/VLAN-Konfiguration, IP-Verwaltung, kontrollierte Neuinstallation, Ressourcen-/Kundenlimits und sinnvolle Vorlagenverwaltung |
| Betrieb | Backup-/Snapshot-/Power-Zeitpläne, Aufbewahrung und Schutz; Migration; Proxmox-Cluster/HA/Wartungsmodus; Aufgabenstatus und Fehlerbehandlung |
| Rechnungen | XRechnung und ZUGFeRD mit Pflichtfeldern und externer Formatvalidierung; Buchhaltungsexport; kontrollierte Erstattungen; wiederkehrende Abrechnung und Verknüpfung mit Diensten; unveränderte Archive und Mandantentrennung |
| Live-Abnahme | Echte Proxmox-/KVM-VMs, Kunden-VNC, Backup/Restore, Messwerterfassung und reale Scheduler-/Proxy-/Datenbankkonfiguration auf dem HomeServer |

Technische Grundlagen und bereits umgesetzte Funktionen stehen weiterhin in
[Virtualisierung](virtualisierung-ausbau.md) und
[Rechnungspositionen/Vorlagen](../reports/rechnungspositionen-und-vorlagen.md).

## Fortschritt

- Bestandsaufnahme: abgeschlossen; Arbeitsbaum am Beginn sauber.
- Monitoring: automatische Erfassung, Speicherung, Zeiträume, CSV, Aufbewahrung und In-App-Alarme implementiert; 48 lokale Browseransichten sowie Erfassungs-, Besitzwechsel-, Clusterverlust- und Alarmtests bestanden. Die Jahresabfrage über eine Million MariaDB-Messwerte benötigt mit Stundenarchiven im lokalen Test 22 MiB statt 239 MiB und 0,119 statt 13,523 Sekunden. Rohdaten, gewichtete Statistiken und genaue Besitzgrenzen bleiben erhalten. [Monitoring](../operations/infrastructure-monitoring.md), [Prüfbericht](../reports/monitoring-performance-und-phpstan.md).
- VM-Betrieb: Schutz vor doppelter Ausführung nach verlorenen Proxmox-Antworten ergänzt; unbestätigte Aufträge halten ihre Sperre bis zur protokollierten Adminprüfung. [Auftragsprüfung](../operations/proxmox-task-recovery.md). Zusätzliche Disk-/Netzwerkverwaltung und die weiteren Betriebsmodule bleiben in Arbeit.
- VM-Konfiguration: zusätzliche Proxmox-QEMU-/LXC-Volumes und der Wechsel des vorhandenen KVM-Netzwerkadapters sind implementiert und lokal geprüft. Weitergehende Funktionen bleiben in Arbeit. [Speicher/Netzwerk](../operations/vm-storage-and-network.md).
- E-Rechnungen: XRechnung/ZUGFeRD mit strukturierten Pflichtangaben, lokalen Formatprüfungen, unveränderlichen Archiven und geschützten Downloads implementiert. KoSIT, EN16931, veraPDF und Archivablauf lokal geprüft. Elektronische Korrekturbelege, weitergehende Rechnungsautomatisierung, Erstattungen und DATEV bleiben offen. [Voraussetzungen](../operations/electronic-invoices.md).
- Lokale Regression: vollständige PHP-Suite ohne fehlgeschlagene Tests (1632 Tests, 6952 Assertions; drei Warnungen, 301 PHPUnit-Hinweise, zwölf übersprungene Tests). Zusätzliche neue Archivtests separat bestanden. PHPStan findet die Migrationsklassen über `scanDirectories`; keine Fehlerunterdrückung. Live-Abnahme und die oben noch offenen Betriebsmodule bleiben offen.

Ein vorhandener Codepfad oder bestandener Mock-Test ist keine Live-Abnahme.
Für dieses Cloud-Arbeitsverzeichnis besteht aktuell keine aktive HomeServer-
Verbindung. Zahlungsaktionen und VM-Änderungen werden bei der Entwicklung mit
isolierten Testdaten geprüft, ohne produktive VMs oder Zahlungen zu verändern.
