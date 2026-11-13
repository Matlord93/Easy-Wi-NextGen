# Erweiterte VM-Verwaltung für Proxmox und KVM

Stand: 13. November 2026.

## KVM-Kundenbereich

Eigene verwaltete VMs besitzen jetzt Starten, Herunterfahren, sofortiges Stoppen
und Neustarten sowie Reiter für Snapshots, Backups und Aufträge. Kunden können
Snapshots und Backups erstellen, vorhandene Sicherungen ihrer VM wiederherstellen
und löschen. Die Konsole und Auslastungsanzeige bleiben integriert.

Alle Änderungen laufen über bestehende Agent-Aufträge. Der Server wird aus der
aktuellen Kundenzuordnung bestimmt. Unverwaltete, nicht verfügbare oder mit
Bestandsfehlern gemeldete VMs werden für diese Aktionen nicht freigegeben.
Ressourcenänderungen, VM-Anlage, Vorlagenimport und Host-/Netzwerkverwaltung
bleiben administrative Aktionen.

Rücksicherungen und Löschaktionen benötigen Bestätigung; ebenso sofortiges
Stoppen und Neustarten. Snapshots und Backups erfordern eine ausgeschaltete VM.
Vor Backup-Restore müssen vorhandene Snapshots entfernt werden. Der Agent
prüft den tatsächlichen Hostzustand erneut. Die bereits vorhandene
Restore-Implementierung prüft die Sicherung und behält den alten Datenträger
für eine manuelle Rücknahme.

Wiederholte Übermittlung desselben Vorgangs erzeugt keine erneute Mutation.
Ausstehende eigene VM-Aufträge werden angezeigt und die Aktionsbuttons sind
vorübergehend deaktiviert. Ein Eigentümerwechsel während ausstehender
VM-Aufträge wird blockiert. Kundenauftragsanlage und Zuordnungsänderung werden
über eine kurze Datenbanktransaktion am Node serialisiert; der Eigentümer wird
innerhalb der Transaktion erneut aus der Datenbank geprüft.

Die Auftragsübersicht lädt nur benötigte Felder statt vollständiger
Agent-Inventare und Logs. Kunden sehen Status und Aktionsnamen, keine internen
Host-Fehlermeldungen oder Zugangsdaten.

## Proxmox-Adminbereich

Unter Server → Einstellungen können CPU-Kerne und RAM bestehender QEMU-VMs und
LXC-Container geändert werden. Der Server muss dafür ausgeschaltet sein.
QEMU-Sockel bleiben erhalten; das Formular bezeichnet die Kerne ausdrücklich
als Kerne je Sockel. Andere Geräte-, Netzwerk- und VM-Einstellungen werden
nicht überschrieben. Die API erhält den aktuell gelesenen Konfigurations-Digest,
damit gleichzeitige externe Konfigurationsänderungen nicht still überschrieben
werden. Synchrone Konfigurationsänderungen werden korrekt als abgeschlossen
im Aufgabenverlauf geführt. KVM bietet CPU-/RAM-Limits bereits im Adminbereich.

## Einspielen und Grenzen

Prüfergebnisse:

- Gesamte PHP-Suite: **1.499 Tests, 6.287 Assertions, keine Fehler/Failures**;
  drei bestehende Warnungen, 301 PHPUnit-Notices und zwölf übersprungene Tests.
- 106 gezielte PHP-Tests mit 544 Assertions bestanden, einschließlich
  Rechte-/Zustandsprüfung, Bestätigungen, fremder Backup-/Snapshot-Auswahl,
  Wiederholungen, frischer Eigentümerprüfung und blockiertem Kundenwechsel.
- 36 Browserkombinationen (Reiter, Hell/Dunkel, 1440/960/390 px) ohne
  JavaScript-Fehler oder Seitenüberlauf; Proxmox-Speichern und KVM-Auftragsanlage
  über die Formulare in der isolierten Vorschau geprüft.
- Bestehende KVM-Agent-Tests mit Race-Prüfung, acht JavaScript-Tests und der
  Proxmox-Backoff-Test bestanden. Container-, Twig- und YAML-Prüfungen erfolgreich.

Aktualisierte Panel-Dateien einspielen und den Symfony-Produktionscache als
Dienstbenutzer leeren. Für diesen Schritt ist keine neue Datenbankmigration
und keine zusätzliche Agentfunktion erforderlich. Der KVM-Agent benötigt die
bereits vorhandene Unterstützung für `kvm.managed` und einen aktuellen
Bestandsabgleich. Externe libvirt-VMs werden nicht automatisch übernommen.

Die Prüfung erfolgt lokal mit SQLite, isolierter Vorschau-Datenbank,
simulierter Proxmox-API und Agent-Testdateien. Echte VM-Aktionen und das
MySQL-/MariaDB-Lockverhalten auf dem HomeServer wurden nicht live getestet.
Die übrigen Module des [Ausbauplans](../plans/virtualisierung-ausbau.md) sind
damit nicht als fertig umgesetzt zu verstehen.
