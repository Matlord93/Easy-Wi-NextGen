# VM-Durchsatz und kurzer Messverlauf

Stand: 13. November 2026. Erweitert die vorhandenen Proxmox-/KVM-Auslastungsansichten
im Admin- und Kundenbereich.

## Sichtbares Verhalten

Die Serverkarte zeigt Netzwerkempfang/-versand sowie Datenträger-Lese- und
Schreibraten in B/s, KiB/s, MiB/s oder GiB/s. Quelle, Intervall und Zeitpunkt
stehen beim Messwert. Fehlende, unvollständige oder veraltete Angaben bleiben
unbekannt; eine gemessene Null bleibt als Null sichtbar.

Unter **Kurzer Verlauf dieser Ansicht** erscheinen CPU/RAM-Verläufe für Server
und Node. Die Serverkarte ergänzt Netzwerk- und Datenträgerverläufe. Die Raten
beziehen sich auf die VM; für Nodes werden hier keine neuen Host-I/O-Raten
behauptet. Das Aufklappen und der Tastaturfokus bleiben beim Aktualisieren erhalten.

Der Verlauf besteht ausschließlich im Arbeitsspeicher dieser geöffneten
Browseransicht: höchstens 60 Punkte je CPU/RAM- und I/O-Messreihe aus den letzten
20 Minuten. Beim Neuladen beginnt er erneut. Es gibt keine neue Datenbankhistorie,
keinen zusätzlichen Hintergrunddienst und keinen neuen Abfragetimer; verwendet
wird die vorhandene Aktualisierung etwa alle 20 Sekunden mit ihrer Fehlerpause.
Die Diagramme passen den dargestellten Zeitbereich an die vorhandenen Punkte an.
Zeitangaben der Diagramme folgen wie die bestehenden Auslastungskarten
Europe/Berlin.

Doppelte Provider-Zeitstempel erzeugen keine neuen Punkte. Fehlende Werte werden
nicht als Null gezeichnet. Nach Abfragefehlern oder größeren Messlücken wird die
nächste Linie getrennt fortgesetzt. Bei Berechtigungsverlust endet die Abfrage.
Letzte bestätigte Messwerte bleiben mit ihrem bisherigen Zeitpunkt sichtbar.

## Proxmox

- Liest `rrddata` für die ausgewählte QEMU-VM bzw. den LXC-Container mit
  `timeframe=hour` und `cf=AVERAGE`. Die bereits von Proxmox berechneten
  `netin`/`netout`/`diskread`/`diskwrite`-Raten werden unverändert als Raten
  übernommen, nicht erneut differenziert.
- Verwendet einen aktuellen gültigen RRD-Punkt und den Abstand zum vorherigen
  Punkt als Intervall. Nullwerte ohne Messung, negative/nicht endliche Werte,
  alte und unplausibel zukünftige Daten werden nicht angezeigt.
- Ein vollständiges Intervall muss innerhalb der aktuellen VM-Laufzeit liegen.
  Das verhindert die Anzeige von Daten aus einem früheren VM-Lauf bzw. einer
  früheren Nutzung derselben VM-ID. Nach einem Neustart kann dadurch zunächst
  eine Aufwärmphase ohne Rate entstehen. Ohne gemeldete Laufzeit bleibt sie leer.
- Proxmox-Werte sind zeitlich gemittelt. Sie sind keine verzögerungsfreie
  Momentanmessung. Das Panel kennzeichnet diese Quelle ausdrücklich.
- Kurzer 15-Sekunden-Cache je Verbindung, Node, Gasttyp und VM; maximal 256 RRD-Zeilen
  und 256 KiB je API-Antwort, vier Sekunden Gesamtzeit für diese optionale Abfrage.
  Ein fehlendes API-Recht für RRD verhindert nicht die vorhandene CPU-/RAM-Anzeige.
- Kunden-Auslastungsabfragen prüfen zusätzlich den Identitätstag aus der frischen
  VM-Konfiguration, bevor sie Status und RRD dieser VM lesen. Vorhandene
  Eigentümerprüfung, aktuelle Node-Auflösung und Cache-Zugriff bleiben erhalten.

## KVM

Die vorhandene einzelne `virsh domstats`-Erfassung je Heartbeat wird weiterverwendet.
Der Agent berechnet Raten aus vollständigen Byte-Zählersummen und dem tatsächlichen
Abstand zweier Beobachtungen. Die erste Messung liefert noch keine Rate.

Vor dem Vergleich müssen CPU-Zähler und Messzeit fortgeschritten bzw. plausibel
sein. Alle zu einem Aggregat gehörenden Geräte-Zähler müssen vorhanden sein.
Gerätenamen werden intern als Fingerabdrücke einbezogen; ein Gerätewechsel oder
eine geänderte Reihenfolge erlaubt keinen Vergleich fremder Zählerstände.
Bei Rücksetzung, Überlauf, fehlendem Gerät/Zähler, Zeitrücksprung oder einer
Lücke über zwei Minuten bleibt die jeweilige Rate unbekannt. Die bisherigen
Byte-Zähler werden weiterhin geliefert, aber keine unvollständigen Teilsummen.

Der Agent hält weiterhin nur den vorigen Zustand von maximal 512 beobachteten
verwalteten Domains. Keine vollständigen XML-Inventare oder unverwalteten Gäste
werden für diese Erweiterung erfasst. Die bisherige Ausgabegrenze von 1 MiB und
das Zwei-Sekunden-Limit der `domstats`-Abfrage bleiben erhalten. Die zusätzlichen
Fingerabdrücke werden nicht an Kunden ausgegeben.

## Prüfung

- Vollständige PHP-Regression: **1.527 Tests, 6.456 Assertions**, keine Fehler oder
  Fehlschläge. Bestehende 3 Warnungen, 301 PHPUnit-Hinweise und 12 übersprungene
  Tests sind unverändert.
- Fokussierte PHP-Prüfungen: RRD-Raten/Cache, Neustartgrenze, fehlende Rechte,
  aktuelle Identität, unbekannte/ungültige Werte, frische Zeitstempel und die
  bestehenden Proxmox-/KVM-Verwaltungsrouten.
- Go einschließlich Race-Prüfung: richtige Zählerdifferenzen, gemessene Null,
  CPU-/Byte-Zählerrücksetzungen, Gerätewechsel, fehlende Namen/Zähler,
  Zeitrücksprung, größere Lücken und Überlauf. Bestehende KVM-Verwaltungs- und
  Gaststatus-Prüfungen laufen mit.
- 12 JavaScript-Tests für begrenzte Messreihen, Quellen-/VM-Wechsel, doppelte
  Zeitstempel, unbekannte Werte, Lücken, Skalierung und bestehendes Fehlerverhalten.
- Chromium: 24 Ansichten (Admin/Kunde, Proxmox/KVM, Dark/Lightmode,
  1440/960/390 Pixel). Tatsächliche Auslastungsaktualisierung gegen die isolierte
  Testumgebung, wachsende SVG-Verläufe, erhaltene Aufklappzustände und Fokus.
  Keine JavaScript-Fehler oder horizontalen Seitenüberläufe.
- Zusätzlicher Browser-Ausfalltest: bestätigte Werte bleiben erhalten,
  Wiederholungen pausieren, die Anzeige erholt sich, Messlücken bleiben getrennt,
  ein 403 stoppt weitere Abfragen.
- PHPStan, Twig-/YAML-/Container-Lint, Go-Vet und Linux-/Windows-Build bestehen.

Die Provider- und Agent-Daten in den HTTP-/Browserprüfungen waren synthetisch.
Der reale HomeServer und Proxmox-Host wurden nicht kontaktiert. Lokale PHP-Version:
8.4.27. Ein dauerhafter RAM-Verbrauch unter Produktionslast wurde nicht gemessen.

## Aktualisierung und weitere Module

Panel und KVM-Agent aktualisieren; für KVM zwei gültige Heartbeats abwarten.
Für Proxmox muss der API-Token RRD-Daten der jeweiligen VM lesen dürfen und ein
vollständiges Intervall des aktuellen VM-Laufs vorliegen. Es ist keine neue
Datenbankmigration erforderlich. Persistente Langzeitverläufe, Export,
Schwellwerte und Benachrichtigungen bleiben weitere Ausbaupunkte.

## Referenzen

- [libvirt: domstats-Zähler und Gerätezuteilung](https://www.libvirt.org/manpages/virsh.html#domstats)
- [Proxmox: RRD-Datenquellen und berechnete Raten](https://lore.proxmox.com/pve-devel/20250523160029.404400-4-a.lauterer%40proxmox.com/T/)

## Patch

`/workspace/vm-throughput-change/durchsatz-kurzverlauf-proxmox-kvm.patch`
enthält dieses Modul auf dem Arbeitsstand nach VM-Verwaltung, Cloud-Init,
Gaststatus und Netzwerkinformationen (Basis-Commit `1ab993b7` zuzüglich der vier
vorherigen Patches). Diese werden vorausgesetzt und nicht ersetzt.
Vorwärts-/Rückwärtsprüfung und ein bytegleicher Wiederaufbau im separaten
Verzeichnis prüfen seine Anwendung.
