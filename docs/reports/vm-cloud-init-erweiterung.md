# Cloud-Init und Boot-Konfiguration für Proxmox und KVM

Stand: 13. November 2026. Dieses Modul ergänzt den vorhandenen VM-Ausbau;
der gesamte Funktionskatalog ist damit noch nicht abgeschlossen.

## Umgesetztes Verhalten

| Bereich | Proxmox QEMU | Verwaltete KVM-VMs |
| --- | --- | --- |
| Cloud-Init im Admin- und Kundenbereich | Hostname/VM-Name, Linux-Benutzer und öffentliche SSH-Schlüssel | Hostname und öffentliche SSH-Schlüssel des ursprünglichen Benutzers; Benutzer bleibt unverändert |
| Boot-Konfiguration | Admin: Reihenfolge aus vorhandenen Geräten, Cloud-Init-Medium ausgeschlossen | Admin und Kunde: Festplatte oder Netzwerk/PXE mit Festplatten-Fallback |
| QEMU Guest Agent | Admin aktiviert die Host-Konfiguration unter Erhalt weiterer Agent-Optionen | Neue VMs erhalten VirtIO-Channel und Cloud-Init-Paketvorgabe; Admin kann den Channel vorhandener VMs vorbereiten |
| Voraussetzungen | VM ausgeschaltet, vorhandenes Cloud-Init-Laufwerk, keine `cicustom`-Dateien | VM ausgeschaltet, aktueller Agent mit `config_version: 1`, ursprüngliche verwaltete Initialisierungsdateien |

Die Ansichten sind unter **Einstellungen** erreichbar. LXC bekommt keinen
Cloud-Init-Editor. Netzwerkstart stellt keine PXE-Infrastruktur bereit.
Die neuen Funktionen installieren kein Betriebssystem neu und verändern keine
Gastpasswörter. Für Windows-Cloudbase-Init ist hier keine neue Integration enthalten.

**Cloud-Init-Vorgaben sind keine bestätigten Änderungen im bereits eingerichteten
Gast.** Insbesondere bleiben KVM-UUID und NoCloud-`instance-id` unverändert.
Cloud-Init führt die betroffenen Module daher bei einem bereits initialisierten
Gast meist nicht erneut aus. Die Oberfläche weist darauf hin. Es gibt kein
automatisches `cloud-init clean` und keine automatische Neuinitialisierung.
Eine spätere Gastkonfiguration benötigt einen eigenen, kontrollierten Ablauf.

**Eine vorbereitete Guest-Agent-Anbindung bestätigt keinen laufenden Guest
Agent.** Der Dienst muss im Gast installiert und gestartet sein; bei vorhandenen
KVM-VMs installiert der Host-Button kein Paket nach. Neue KVM-VMs erhalten die
Paketvorgabe `qemu-guest-agent` für Cloud-Init. Deren erfolgreiche Installation
hängt vom Gastimage und dessen Paketquellen ab.

## Datenerhalt und Zugriff

- Proxmox schreibt nur die freigegebenen Felder und den frisch gelesenen
  Konfigurations-Digest. SSH-Schlüssel werden zusätzlich zur Formularcodierung
  entsprechend dem Proxmox-API-Vertrag URL-codiert. Ein vorhandenes
  Cloud-Init-Laufwerk wird weder ersetzt noch neu angelegt. Andere Laufwerke,
  Netzwerkkonfiguration und weitere Einstellungen werden nicht mitgeschrieben.
- Kundenaktionen sind an die bestehende Zuweisung gebunden. Vor einer
  Cloud-Init-Änderung wird der Identitätstag zusätzlich aus der frischen
  VM-Konfiguration geprüft. Vollständige Proxmox-Konfigurationen gelangen nicht
  in die Kundenansicht. Boot-Geräte und Guest-Agent-Konfiguration bleiben dort
  Admin-Aktionen.
- KVM erzeugt das neue Cloud-Init-ISO zuerst in einem separaten Verzeichnis.
  Netzwerkdatei und Root-Datenträger bleiben erhalten; bestehende zusätzliche
  User-Data-Felder bleiben erhalten. Ein Update ersetzt ausschließlich die
  SSH-Schlüsselvorgaben des ursprünglichen Benutzers. Initialisierungsdateien
  werden begrenzt eingelesen (je maximal 1 MiB).
- Bei einem Fehler nach dem Medientausch werden altes ISO, User-Data,
  Meta-Data und Manifest wiederhergestellt. Scheitert die Wiederherstellung,
  bleibt die Operation unbestätigt und sperrt weitere Änderungen bis zur
  Hostprüfung. Auch ein Prozessabbruch erfordert eine Prüfung des vorhandenen
  Operationsjournals; der Ablauf verspricht keine Transaktion über einen
  Stromausfall hinweg.
- KVM-Boot- und Channel-Vorgaben bleiben bei Snapshot-Wiederherstellung erhalten.
  Snapshots vor der Channel-Vorbereitung bleiben verwendbar; fremde
  Guest-Agent-Socketpfade werden abgewiesen.
- Öffentliche Schlüssel werden syntaktisch und kryptographisch geprüft:
  Ed25519, RSA und ECDSA, ein bis acht Schlüssel, zusammen maximal 32 KiB,
  keine Schlüsseloptionen. Private Schlüssel werden nicht angenommen.

## Prüfung

- 80 fokussierte PHP-Tests, 466 Assertions: Validierung, begrenzte API-Schreibfelder,
  SSH-Codierung, frischer Digest/Identitätstag, Kundenzuordnung, CSRF,
  unbekannte Boot-Geräte und ausgeschaltete VMs.
- Go-Tests für verwaltete KVM-VMs einschließlich Race-Prüfung: erfolgreicher
  Cloud-Init-Austausch, unveränderte Disk-/Netzwerkdaten und Instance-ID,
  Zusatzfelder, ungültige Schlüssel, Schlüsseloptionen, laufende Gäste,
  fremde Metadaten, übergroße Dateien und fehlgeschlagener ISO-Bau. Ein
  nach dem ISO-Bau künstlich verhinderter Dateizugriff bestätigt die Erhaltung
  der Wiederherstellungsdateien und die Sperre nach fehlgeschlagenem Rollback.
- Native Werkzeugtests im vorhandenen isolierten Testcontainer:
  `cloud-localds`, `qemu-img`, ISO-Auslesen und libvirt-XML-Schemavalidierung.
  Auch Snapshot-Wiederherstellung nach späterer Guest-Agent-Vorbereitung besteht.
  libvirt-Aufrufe und Gastzustände bleiben dabei simuliert.
- Chromium: 24 Kombinationen aus Admin/Kunde, Proxmox/KVM, Dark/Lightmode und
  1440/960/390 Pixeln; keine JavaScript-Fehler oder horizontalen Seitenüberläufe.
  Alle vier Cloud-Init-Formulare wurden gegen die isolierte Testumgebung
  übermittelt. Proxmox wurde simuliert; KVM-Aufträge wurden nur eingereiht.
- Twig-, YAML- und Container-Lint sowie PHPStan für die geänderten PHP-Dateien,
  Go-Vet und Linux-/Windows-Build bestehen. KVM-Ausführung selbst bleibt
  Linux/amd64 vorbehalten.

Vollständige PHP-Regression: **1.508 Tests, 6.338 Assertions**, keine Fehler oder
Fehlschläge. Die bisherigen 3 Warnungen, 301 PHPUnit-Hinweise und 12 übersprungenen
Tests sind unverändert. Lokale PHP-Laufzeit: 8.4.27; die Produktionslaufzeit
PHP 8.5 wurde hier nicht ausgeführt.

Es gab keinen Zugriff auf den HomeServer oder einen realen Proxmox-Host.
Ein realer Gaststart, Cloud-Init im Betriebssystem, SSH-Anmeldung und ein laufender
Guest Agent sind durch diese Prüfungen nicht bestätigt.

## Aktualisierung und Live-Abnahme

Panel und KVM-Agent aus demselben Stand aktualisieren. Danach einen Heartbeat
abwarten und das KVM-Inventar neu einlesen. Der Versionsindikator verhindert,
dass die neuen KVM-Aktionen an einen älteren Agent gesendet werden. Es ist keine
Datenbankmigration für dieses Modul nötig.

Für den Live-Test eine separate ausgeschaltete Test-VM verwenden. Zuerst bei
Proxmox Laufwerk/Cloud-Init-Vorlage und API-Rechte prüfen; bei KVM die ursprünglichen
Cloud-Init-Dateien. Mit einer noch nicht initialisierten VM die Vorgaben ändern,
starten und Hostname/SSH im Gast prüfen. Bei bereits initialisierten VMs zuerst
nur die gespeicherten Vorgaben prüfen. Boot-Reihenfolge und Guest-Agent-Kanal
separat abnehmen; vor einem PXE-Test externe Netzwerkboot-Dienste prüfen.
Bestehende Produktionsgäste nicht zu diesem Zweck neu initialisieren.

## Technische Referenzen

- [Cloud-Init: NoCloud und Instance-ID](https://cloudinit.readthedocs.io/en/latest/reference/datasources/nocloud.html)
- [Cloud-Init erneut ausführen und Auswirkungen](https://docs.cloud-init.io/en/latest/howto/rerun_cloud_init.html)
- [Proxmox Cloud-Init-Optionen](https://pve.proxmox.com/pve-docs/qm.1.html)
- [Proxmoxer: zusätzliche SSH-Schlüsselcodierung](https://proxmoxer.github.io/docs/dev/examples/cloud-init/)

## Patch

`/workspace/vm-cloud-init-change/cloud-init-proxmox-kvm.patch` enthält nur die
Änderungen dieses Moduls auf dem Arbeitsstand zu Beginn dieser Aufgabe
(Basis-Commit `1ab993b7` zuzüglich des vorherigen VM-Verwaltungs-Patches).
Er ersetzt diesen vorherigen Patch nicht. Vorwärts- und Rückwärtsprüfung sowie
bytegleicher Wiederaufbau in einem separaten Verzeichnis prüfen seine Anwendung.
