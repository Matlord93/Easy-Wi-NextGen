# Gaststatus für Proxmox und KVM

Stand: 13. November 2026. Ergänzt die bestehende Guest-Agent-Anbindung um eine
lesende Prüfung im Admin- und Kundenbereich. Das Modul erweitert den vorhandenen
VM-Ausbau; weitere Punkte des Funktionskatalogs bleiben offen.

## Verhalten

Unter **Server → Einstellungen → Gastinformationen** startet **Gaststatus jetzt
prüfen** eine gezielte Prüfung der ausgewählten VM. Die Oberfläche unterscheidet
zwischen nicht geprüft, ausgeschaltet, deaktivierter Anbindung, fehlender
Antwort, fehlenden Proxmox-Rechten und einem antwortenden Guest Agent.

Eine erfolgreiche `guest-info`-Antwort bestätigt die Erreichbarkeit zum
angezeigten Zeitpunkt. Angezeigt werden die Agent-Version sowie optional der
vom Gast gemeldete Hostname und das Betriebssystem. Diese Angaben stammen aus
dem Gast, nicht aus Cloud-Init-Vorgaben oder dem VM-Anzeigenamen. Sie sind keine
vertrauenswürdige Identität des Betriebssystems: ein Gastadministrator kann die
Meldungen beeinflussen. Fehlende optionale Angaben bleiben leer.

Es gibt keine automatische Prüfung beim Seitenaufruf und keine regelmäßigen
Abfragen aller VMs. Nach einem Neuladen zeigt die Oberfläche zunächst wieder
„Noch nicht geprüft“. Bei einer fehlgeschlagenen Prüfung werden alte Angaben
entfernt. Es gibt keinen neuen Hintergrunddienst.

## Proxmox

- Ausschließlich QEMU-VMs. Vor einer Prüfung werden Kundenzuweisung, aktueller
  Node, Identitätstag aus der frischen VM-Konfiguration, Laufzustand und
  aktivierte Agent-Anbindung geprüft. Auch Cache-Treffer umgehen diese Prüfungen
  nicht. Ein Node-Wechsel wird über die aktuellen Cluster-Ressourcen aufgelöst.
- Nur GET-Aufrufe für `info`, `get-host-name` und `get-osinfo`. Die beiden
  optionalen Abfragen erfolgen nur, wenn der Agent sie als aktiviert meldet.
  Es gibt keine frei eingebbaren Befehle und keine Gastkonfigurationsänderung.
- Kurzer Cache von zehn Sekunden pro Verbindung, Node, VM und Konfigurations-Digest.
  Die Schlüssel trennen auch geänderte API-Zugangsdaten und TLS-Einstellungen.
  Der ursprüngliche Beobachtungszeitpunkt bleibt beim Cache-Treffer erhalten.
- Je Guest-Agent-HTTP-Abfrage höchstens vier Sekunden Gesamtzeit und drei Sekunden
  Inaktivitäts-Timeout. Der Download wird auf 128 KiB begrenzt.
- Bei neueren Proxmox-Versionen benötigen die lesenden Agent-Endpunkte
  `VM.GuestAgent.Audit` auf der VM; ältere Versionen verwenden ein anderes
  Berechtigungsmodell. Rechte werden nicht automatisch erweitert. Ein 401/403
  wird als fehlende Berechtigung angezeigt. Rohtexte der API bleiben intern.

## KVM

- Neuer, lesender Auftrag `kvm.managed` mit `action=vm.agent_status`. Er benötigt
  `kvm_managed.guest_agent_status_version=1` im Agent-Heartbeat. Panel und Agent
  aktualisieren und den Heartbeat abwarten. Für vorhandene VMs muss die
  Guest-Agent-Anbindung zuvor vorbereitet sein und der Dienst im Gast laufen.
- Der Agent prüft die verwaltete UUID, das Manifest, die libvirt-Definition und
  den Laufzustand. Fremde Diskquellen und unverwaltete VMs werden abgewiesen.
  Es wird nur die ausgewählte VM geprüft; der Auftrag erstellt kein vollständiges
  Inventar und kein Mutationsjournal und schreibt keine VM-Dateien um.
- Feste QGA-Befehle: `guest-info`, optional `guest-get-host-name` und
  `guest-get-osinfo`. Keine Shell, keine `guest-exec`-Weiterleitung. Der lokale
  libvirt-Anschluss und der private verwaltete Channel bleiben bestehen.
- Gesamtes Zeitbudget zwölf Sekunden einschließlich Host-Lock; je QGA-Aufruf
  drei Sekunden Prozesslimit mit zwei Sekunden libvirt-Timeout. Die Ausgabe ist
  bereits beim Einlesen auf 128 KiB je Aufruf begrenzt. Wiederholte laufende
  Prüfaufträge derselben VM und fertige Ergebnisse der letzten zehn Sekunden
  werden zusammengefasst.
- Kunden-VM und Node stammen aus der aktuellen Zuweisung. Auftragsanlage und
  Zuweisungsänderungen bleiben serialisiert. Statusabfragen prüfen wiederum
  Zuweisung, Node, VM-UUID und Auftragstyp. Antworten enthalten nur Status,
  Zeitpunkt und die freigegebenen kurzen Gastfelder.

Die KVM-Hostfunktion benötigt weiterhin Linux/amd64. QEMU Guest Agent kann auch
in Windows-Gästen laufen; welche optionalen Angaben geliefert werden, hängt vom
Gastdienst ab. Reale Linux- und Windows-Gäste wurden hier nicht kontaktiert.

## Prüfung

- 84 fokussierte PHP-Tests, 496 Assertions: vorhandene Verwaltungsabläufe sowie
  Eigentümerschaft/CSRF, reine Lesezugriffe, Cache und frische Identitätsprüfung,
  fehlende Rechte, KVM-Auftragszusammenfassung und gefilterte Statusantworten.
- Zusätzlicher Zeitformat-Test: 2 Tests, 9 Assertions einschließlich RFC3339 mit
  `Z` aus dem Go-Agent und ungültiger Datumsangaben.
- Go-Tests einschließlich Race-Prüfung: feste Befehle und VM-Ziele,
  Zeit-/Ausgabegrenzen, unveränderte VM-Dateien und Journale, deaktivierte und
  ausgeschaltete Gäste, fremde Diskquellen, ungültige Antworten und optionale
  Fehler. Bestehende verwaltete KVM-Tests laufen mit.
- Chromium: 24 Kombinationen (Admin/Kunde, Proxmox/KVM, Dark/Lightmode,
  1440/960/390 Pixel), vier erfolgreiche Prüfabläufe mit tatsächlicher
  Formularübermittlung und KVM-Auftragspolling gegen die isolierte Testumgebung.
  Keine JavaScript-Fehler oder horizontalen Seitenüberläufe. Weitere Browserfälle
  prüfen Gast-HTML als reinen Text, entfernte alte Angaben bei HTTP-Fehlern und
  verborgene interne Fehlertexte.
- PHPStan, Twig-/YAML-/Container-Lint, Go-Vet und Linux-/Windows-Agent-Build bestehen.

Vollständige PHP-Regression: **1.515 Tests, 6.379 Assertions**, keine Fehler oder
Fehlschläge. Die bisherigen 3 Warnungen, 301 PHPUnit-Hinweise und 12 übersprungenen
Tests bleiben bestehen. Die anschließend ergänzte UTC-Zeitformatprüfung besteht
mit 2 Tests und 9 Assertions ebenfalls.
Lokale PHP-Version: 8.4.27. API-/libvirt-Antworten und KVM-Auftragsausführung
waren simuliert. Der reale Proxmox-Host, HomeServer, Gastdienst und seine
Berechtigungen müssen anschließend mit einer separaten Test-VM geprüft werden.

## Referenzen

- [Proxmox-Implementierung der Guest-Agent-Endpunkte, HTTP-Methoden und Rechte](https://github.com/proxmox/qemu-server/blob/master/src/PVE/API2/Qemu/Agent.pm)
- [QEMU Guest Agent: `guest-info` und optionale Befehle](https://www.qemu.org/docs/master/interop/qemu-ga-ref.html)
- [libvirt: `qemu-agent-command` und Zeitlimits](https://www.libvirt.org/manpages/virsh.html#qemu-agent-command)

## Patch

`/workspace/vm-guest-agent-change/gaststatus-proxmox-kvm.patch` enthält nur dieses
Modul und setzt den Arbeitsstand nach dem VM-Verwaltungs- und Cloud-Init-Ausbau
voraus (Basis-Commit `1ab993b7` zuzüglich der beiden vorherigen Patches).
Vorwärts- und Rückwärtsprüfung sowie ein bytegleicher Wiederaufbau im separaten
Verzeichnis prüfen seine Anwendung. Es ist keine neue Datenbankmigration nötig.
