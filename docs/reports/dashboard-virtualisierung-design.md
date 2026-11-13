# Dashboard und Virtualisierung: Design und Auslastung

## Umsetzung

Die Admin- und Kundenansichten für Dashboard, Proxmox und KVM verwenden ein
gemeinsames Design mit Hell-/Dunkelmodus. Proxmox und KVM trennen Node-Übersicht,
Serverliste und einzelne Server. Bestehende Aktionen sind über Reiter für
Übersicht, Konfiguration, Snapshots, Backups, Aufträge und die Kundenkonsole
erreichbar. KVM besitzt dafür eine eigene Admin-Serverroute.

Die Node-Seiten zeigen Host-Messwerte; beim Öffnen eines Servers werden dessen
Messwerte neben denen seines Hosts dargestellt. Kunden erhalten ausschließlich
die Messwerte ihrer eigenen zugewiesenen Server und numerische Auslastungswerte
des zugehörigen Hosts. Hostnamen, interne Adressen, fremde VM-Daten und
Infrastruktur-Zugangsdaten werden über die Messwert-Endpunkte nicht ausgegeben.

Messwerte werden bei sichtbarer Seite alle 20 Sekunden aktualisiert. Formulare
werden dabei nicht ersetzt. Serverlisten besitzen Suche und Statusfilter;
deren Auswahl bleibt erhalten. Während einer Eingabe wird die betreffende
Liste nicht ersetzt. Fehler behalten die letzten bestätigten Werte bei und
verlängern den Wiederholungsabstand bis maximal fünf Minuten. Fehlende oder
veraltete Messwerte werden als solche gekennzeichnet.

## Herkunft und Bedeutung der Werte

| Ansicht | Messquelle |
| --- | --- |
| Proxmox-Node | `/cluster/resources`, CPU-Anteil sowie RAM-/Datenträgerwerte des Nodes |
| Proxmox-Server | Status des aktuellen Nodes und der VM bzw. des Containers; der Node wird beim Lesen neu ermittelt |
| KVM-Node | Vorhandene CPU-, RAM- und Systemdatenträgerwerte aus Agent-Heartbeats |
| KVM-Server | Ein begrenzter `virsh domstats`-Aufruf je Heartbeat, nicht je Browser oder VM |

KVM-CPU wird aus zwei aufeinanderfolgenden CPU-Zählern und der Zahl aktiver
vCPUs berechnet. Ein erster Messpunkt, ein Zählerreset oder ein zu langer
Messabstand erzeugt keinen erfundenen Prozentwert. RAM stammt aus verfügbaren
Balloon-Gastdaten; ersatzweise wird der gemessene Host-RSS mit entsprechender
Beschriftung angezeigt. Konfigurierte Ressourcenlimits bleiben davon getrennt.
KVM-Dateisystembelegung ist ohne passende Gastdaten unbekannt und wird nicht
aus der virtuellen Plattengröße abgeleitet. Die zugewiesene Kapazität wird
gesondert angezeigt. Die Node-Datenträgeranzeige des Agents betrifft sein
Systemdateisystem, nicht automatisch jeden zusätzlichen Storage-Pool.

Die zusätzliche Agent-Abfrage ist auf zwei Sekunden Laufzeit, 1 MiB Ausgabe,
512 verwaltete Domains und einen entsprechend begrenzten CPU-Zählercache
beschränkt. Sie liest nur die lokale libvirt-Verbindung. Auf anderen Systemen
oder ohne `virsh` wird sie ausgelassen. Es entsteht kein zusätzlicher Dienst.

## Prüfung

- Gesamte PHP-Suite: **1.480 Tests, 6.142 Assertions, keine Fehler/Failures**.
  Drei bestehende Warnungen, 301 PHPUnit-Notices und zwölf übersprungene Tests.
- 94 gezielte PHP-Tests mit 452 Assertions bestanden. Darunter Eigentümerschaft,
  VM-Identität, Kunden-/Admin-Zugriff, bestehende Aktionen und alte Bestandsdaten.
- Acht JavaScript-Tests sowie der vorhandene Proxmox-Backoff-Test bestanden.
- KVM-Agent-Tests mit Race-Prüfung bestanden; CPU-Berechnung, Zählerreset,
  fehlende Werte, RSS-Kennzeichnung und Ausgabebegrenzung geprüft.
- Agent für Linux amd64/arm64 und Windows amd64 gebaut; `go vet` bestanden.
- Container-, Twig- und YAML-Prüfungen erfolgreich.
- Browserprüfung mit echter Symfony-Anwendung und synthetischen Daten:
  zwölf Seiten × zwei Farbschemata × drei Breiten (1440/960/390 px), insgesamt
  72 Kombinationen ohne JavaScript-Fehler oder Seitenüberlauf. Reiter einschließlich
  Tastatursteuerung, Filter, Theme-Schalter und Erhalt bearbeiteter Formulare
  beim Aktualisieren der Messwerte wurden geprüft.

Diese Prüfung verwendet eine separate Vorschau-Datenbank und simulierte
Infrastruktur. Ein Live-Test auf dem HomeServer wurde hier nicht durchgeführt.

## Einspielen

Die aktualisierten Panel-Dateien einspielen und den Symfony-Produktionscache
mit dem Dienstbenutzer leeren. Für die KVM-Messwerte die aktualisierte
`easywi-agent`-Binärdatei auf dem KVM-Host installieren und den Agent-Dienst
neu starten. Nach zwei erfolgreichen Heartbeats können CPU-Prozentwerte
erscheinen. Messwerte müssen vom jeweiligen Host tatsächlich verfügbar sein.
Für diesen Design-/Messwert-Umbau ist keine neue Datenbankmigration erforderlich.

Die zuvor ergänzte TLS-Einstellung behält ihre eigene
[Update-Anleitung](../operations/proxmox-tls.md). Die zusätzlichen Module aus
dem gelieferten Funktionskatalog sind im
[Ausbauplan](../plans/virtualisierung-ausbau.md) erfasst und folgen nach dem
hier priorisierten Design mit vorhandenen Funktionen.
