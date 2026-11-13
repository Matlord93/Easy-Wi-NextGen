# Netzwerkinformationen für Proxmox und KVM

Stand: 13. November 2026. Ergänzt die bestehende Gaststatus-Prüfung um lesende
Netzwerkinformationen im Admin- und Kundenbereich.

## Sichtbares Verhalten

Unter **Server → Einstellungen → Gastinformationen → Gaststatus jetzt prüfen**
zeigt das Panel die vom Gast gemeldeten Adapter mit Namen, optionaler
MAC-Adresse sowie IPv4- und IPv6-Adressen einschließlich Präfixlänge an.
Die Darstellung passt sich an Desktop und Mobilgeräte an und verwendet den
vorhandenen Dark-/Lightmode.

Die Angaben stammen ausschließlich aus dem QEMU Guest Agent der ausgewählten
VM. Sie sind keine IP-Reservierung, keine verwaltete IP-Zuweisung und kein
Nachweis externer Erreichbarkeit. Lokale, interne und Loopback-Adressen bleiben
sichtbar, wenn der Gast sie meldet. Ein Gastadministrator kann diese Meldungen
beeinflussen. Es werden keine Netzwerkparameter geändert.

Die Oberfläche unterscheidet:

| Netzwerkzustand | Bedeutung |
| --- | --- |
| `available` | Netzwerkantwort gelesen; kann eine leere Adapterliste enthalten |
| `unsupported` | Netzwerkbefehl fehlt oder ist im Gastdienst deaktiviert |
| `not_reported` | Kein Netzwerkobjekt geliefert, z. B. durch einen älteren EasyWI-Agent |
| `unavailable` | Netzwerkabfrage konnte nicht abgeschlossen werden |
| `invalid_response` | Die gelieferte Netzwerkstruktur hat ein ungültiges Format |

Scheitert ausschließlich die Netzwerkabfrage, bleiben erfolgreich gelesene
Agent-Version, Hostname und Betriebssystem erhalten. Alte IP-Adressen werden bei
einer neuen oder fehlgeschlagenen Prüfung entfernt. Eine leere Antwort erzeugt
keine erfundenen Adressen. Die Anzeige wird beim Seitenaufruf nicht automatisch
abgefragt und verwendet keinen zusätzlichen Hintergrunddienst.

## Umsetzung und Grenzen

- Proxmox nutzt ausschließlich `GET .../qemu/{vmId}/agent/network-get-interfaces`.
  Der Aufruf erfolgt nur, wenn `guest-info` den Befehl als aktiviert meldet.
  Die bestehenden Prüfungen für Kundenzuweisung, Identitätstag, aktuellen Node,
  Laufzustand und aktivierte Agent-Anbindung bleiben vorgeschaltet.
- KVM erweitert den vorhandenen lesenden Auftrag `vm.agent_status` um
  `guest-network-get-interfaces`. Der neue Agent meldet zusätzlich
  `guest_network_info_version: 1`. Ein älterer Agent kann weiterhin die bisherigen
  Basisinformationen liefern; fehlende Netzwerkdaten werden ausdrücklich angezeigt.
- Keine frei übermittelten Guest-Agent-Befehle und keine Shell. Der KVM-Agent
  prüft weiterhin UUID, Manifest, libvirt-Definition und Laufzustand. Es wird
  weder ein vollständiges Node-Inventar erstellt noch ein VM-Datenträger geändert.
- In beiden Implementierungen bleiben maximal 16 Adapter mit je 16 Adressen in
  der Antwort. Namen sind auf 128 Byte begrenzt. IP-Adressen müssen zur gemeldeten
  Familie passen; Präfixe sind Ganzzahlen von 0–32 bzw. 0–128. MAC-Adressen werden
  normalisiert; ungültige MAC-Werte bleiben leer. IPv6-Zonenangaben, ungültige
  Adressen, doppelte Adapter und doppelte Adresse/Präfix-Paare werden ausgefiltert.
  Eine Begrenzung oder Filterung wird mit `limited: true` sichtbar gemacht.
- Der Speicher- und Zeitrahmen bleibt begrenzt: höchstens 128 KiB pro QGA-Antwort;
  der KVM-Prüfauftrag insgesamt zwölf Sekunden. Netzwerkdaten werden vor den
  optionalen Hostname-/OS-Abfragen gelesen. Ein Timeout einer optionalen Abfrage
  macht eine bestätigte Basisantwort nicht nachträglich ungültig.
- Proxmox verwendet weiterhin den kurzen Zehn-Sekunden-Cache. Ein eigener
  Cache-Namensraum verhindert das Wiederverwenden älterer Antworten ohne dieses
  Netzwerkmodul. Beobachtungszeit und Eigentümerprüfung bleiben erhalten.
- PHP prüft auch die bereits normalisierten KVM-Netzwerkdaten erneut, bevor sie
  den Kunden erreichen. Zusätzliche Gast-/Hostfelder werden nicht weitergereicht.
  Die Browseransicht erstellt Textelemente statt Gastdaten als HTML einzusetzen.

Dies ist eine Netzwerkanzeige für Proxmox-QEMU und verwaltete KVM-VMs. IP-Pools,
IPAM, VLAN-/Bridge-Zuweisung, Gastnetzwerk-Konfiguration und Firewallverwaltung
sind weitere Ausbaupunkte. Der KVM-Host benötigt weiterhin Linux/amd64.

## Prüfung

- Gemeinsame JSON-Vertragsfälle für PHP und Go: Dual Stack, Windows-Adaptername,
  Loopback, fehlende Adressen, ungültige IP-/Präfix-/MAC-Werte, Duplikate,
  falsche Antwortstruktur, EUI-64/InfiniBand-MAC und explizite Null-Adresslisten.
- Größenbegrenzungen, erneute Filterung der KVM-Antwort, unveränderte
  Erreichbarkeitsanzeige bei Netzwerkfehlern und gezielte lesende Provider-Aufrufe.
- Bestehende HTTP-Prüfungen für Eigentümerschaft und CSRF laufen mit; die
  bereinigten Netzwerkdaten werden zusätzlich in den Kundenantworten geprüft.
- Chromium: 24 Kombinationen aus Admin/Kunde, Proxmox/KVM, Dark/Lightmode und
  1440/960/390 Pixeln, vier erfolgreiche Abfragen. Keine JavaScript-Fehler oder
  horizontalen Seitenüberläufe, einschließlich langer IPv6-Adressen.
- Zusätzliche Browserfälle für Gast-HTML als Text, Begrenzungshinweis, alte
  Adressen nach Fehlschlag, fehlendes Netzwerkobjekt älterer Agenten, leere
  Antworten und HTTP-Fehler bestehen.
- PHPStan, Twig-/YAML-/Container-Lint, Go-Race-Prüfung, Go-Vet sowie Linux- und
  Windows-Agent-Build bestehen.

Vollständige PHP-Regression: **1.519 Tests, 6.422 Assertions**, keine Fehler oder
Fehlschläge. Die bisherigen 3 Warnungen, 301 PHPUnit-Hinweise und 12 übersprungenen
Tests sind unverändert.
Lokale PHP-Laufzeit: 8.4.27. Die Tests verwenden simulierte Proxmox-/libvirt-
Antworten und synthetische Agent-Aufträge. Es wurde kein echter HomeServer,
Proxmox-Host oder Linux-/Windows-Gast kontaktiert.

## Aktualisierung

Panel und KVM-Agent aktualisieren, einen Heartbeat abwarten und dann die Prüfung
bei einer separaten laufenden Test-VM mit installiertem Guest Agent auslösen.
Proxmox muss den Netzwerkbefehl über den vorhandenen API-Token lesen dürfen;
Rechte werden nicht automatisch erweitert. Es ist keine Datenbankmigration nötig.

## Referenzen

- [QEMU-Protokoll: Netzwerkschnittstellen und IP-Präfixe](https://www.qemu.org/docs/master/interop/qemu-ga-ref.html#command-guest-network-get-interfaces)
- [Proxmox: lesende Agent-Endpunkte und Berechtigungen](https://github.com/proxmox/qemu-server/blob/master/src/PVE/API2/Qemu/Agent.pm)

## Patch

`/workspace/vm-network-info-change/netzwerkinformationen-proxmox-kvm.patch`
enthält ausschließlich dieses Modul auf dem Arbeitsstand nach VM-Verwaltung,
Cloud-Init und Gaststatus (Basis-Commit `1ab993b7` zuzüglich der drei vorherigen
Patches). Er ersetzt diese vorherigen Patches nicht. Vorwärts-/Rückwärtsprüfung
und ein bytegleicher Wiederaufbau im separaten Verzeichnis prüfen die Anwendung.
