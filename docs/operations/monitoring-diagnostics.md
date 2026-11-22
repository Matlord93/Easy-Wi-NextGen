# Monitoring: Aggregationsanalyse, Kunden-IDs und Gastagent-Timeouts

## Langsame Psalm-Analyse von Infrastrukturarchiven

`InfrastructureAggregation::fromJson()` verknüpfte die Prüfung von elf möglichen
Metriknamen direkt mit dem Schreiben eines verschachtelten Arrays. Psalm baute
dabei viele Kombinationen aus konkreten Array-Schlüsseln auf. Dadurch konnte die
Datei die Warnschwelle von zehn Sekunden überschreiten, obwohl keine Typfehler
vorlagen.

Die Prüfung liegt jetzt in einer eigenen Methode mit einem `string`-Rückgabewert.
Die zulässigen Namen stammen weiterhin aus `InfrastructureHistoryStore::LOAD`
und `IO`; eine einmal aufgebaute Lookup-Tabelle vermeidet wiederholte Listen
und lineare Suchen. Die drei Archivzähler werden einzeln validiert.
Gewichtung, Behandlung fehlender Werte und gemessener Nullwerte, Quellenreihenfolge, Zeitgrenzen und
Ablehnung unbekannter Metriken bleiben erhalten.

Lokale Vergleichsmessung unter PHP 8.5.12 / Psalm 7.4.0, jeweils mit leerem Cache
und einem Analyseprozess: `fromJson()` sank von 19,41 auf 0,07 ms je analysiertem
AST-Knoten. Der neue Validator benötigt 0,09 ms je Knoten. Diese Zahlen messen
die statische Analyse und sind kein Benchmark des gesamten Panels.

Der vollständige CI-Aufruf mit vier Analyseprozessen und Taint-Analyse meldet
weiterhin 0 Fehler und keine Warnung wegen langsamer Aggregationsanalyse.
Die separat angezeigten 58 `MissingParamType`-Verbesserungsvorschläge sind
optionale Vorschläge; auch die Fehlerausgabe mit `--show-info=true` war leer.

## Metrikübersicht: „Metric query returned an invalid string.“

Die Admin-Abfrage liefert `customer.id` als numerische Doctrine-ID. Eine strikte
Zeichenkettenprüfung war für diesen Wert ungeeignet und brach die gesamte
Metrikübersicht ab. Die ID wird jetzt als Zahl validiert und anschließend in die
bisherige Zeichenkettenform für die Übersicht umgewandelt. E-Mail-Adressen und
Node-IDs behalten ihre strikten Zeichenkettenprüfungen.

Ein Regressionstest führt die echte Doctrine-Abfrage gegen SQLite aus. Er
prüft numerische Kunden-IDs, einen fehlenden Node-Namen, RAM-Werte ab 4 GiB,
Zeitstempel sowie Kunden-/Node-/Instanzfilter.

## Proxmox: wiederholte `get-fsinfo`-Timeouts

Die gemeldeten Timeouts betrafen die Dateisystemabfrage der VM 202. Laut
Rückmeldung war deren QEMU-Gastagent unter Ubuntu 26.04 nicht aktiv. Diese
Gastabfrage benötigt den Agenten innerhalb der VM.

Innerhalb der Ubuntu-VM prüfen beziehungsweise starten:

```sh
systemctl is-active qemu-guest-agent
sudo systemctl start qemu-guest-agent
```

Auf dem Proxmox-Host lässt sich die Kommunikation prüfen:

```sh
qm agent 202 ping
qm agent 202 get-fsinfo
```

Für fehlgeschlagene Dateisystemabfragen setzt das Panel die Cache-Laufzeit jetzt
auf 300 Sekunden. Das begrenzt erneute Wartezeiten bei deaktivierten oder
blockierten Agenten. Ein fehlgeschlagener Abruf bleibt `unavailable`, mit
unbekannten Größen statt erfundenen Nullwerten. Erfolgreiche Abrufe behalten
ihre bisherige Laufzeit von 30 Sekunden. Nach Ablauf der Fehlerlaufzeit wird
automatisch erneut abgefragt.

Die bestehende Trennung nach Verbindung, Credentials, TLS-Einstellung, Node,
VM-ID und Konfigurations-Digest bleibt erhalten. Änderungen an Konfiguration
oder Zugangsdaten erzeugen einen eigenen Cache-Eintrag; die bisherigen
Besitz-/Identitätsprüfungen erfolgen weiterhin vor dem Cache-Zugriff.

## Validierung

- PHP 8.4.27 und PHP 8.5.12: jeweils 162 betroffene Tests mit 715 Assertions,
  ohne Warnungen oder übersprungene Tests.
- Archivtests prüfen alle elf unterstützten Metriken, unbekannte/numerische
  Schlüssel, Quellen und IO-Intervalle einschließlich bekannter Nullwerte.
- Der Gastagent-Test verwendet einen simulierten Cache-Zeitgeber: Fehler bleiben
  bis 299 Sekunden gecacht, bei 300 Sekunden wird erneut abgefragt, anschließend
  gelten wieder 30 Sekunden. Änderungen an VM, Digest und Credentials werden
  separat geprüft.
- Vollständiger kalter Psalm-Lauf mit `CI=true`, GitHub-Ausgabe, vier Prozessen
  und Taint-Analyse: Exit-Code 0, keine Langlaufwarnung für die Aggregation.
