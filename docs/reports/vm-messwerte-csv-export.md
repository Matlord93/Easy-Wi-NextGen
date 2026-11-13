# CSV-Export von Proxmox- und KVM-Messwerten

Stand: 13. November 2026. Ergänzt die bestehenden Auslastungs- und
Kurzverlaufsansichten im Admin- und Kundenbereich.

## Bedienung

In der jeweiligen **Server-Auslastung** bzw. **Node-Auslastung** den Bereich
**Kurzer Verlauf dieser Ansicht** aufklappen, einen Exportumfang auswählen und
**CSV herunterladen** drücken:

- **Letzter verfügbarer Messwert:** letzte bereits geladene Werte dieser Karte
  mit ihren ursprünglichen Messzeitpunkten. Veraltete Werte werden gekennzeichnet.
- **Verlauf: letzte 5 Minuten:** gespeicherte Beobachtungen dieses Browser-Tabs
  innerhalb dieses Zeitraums.
- **Gesamter Kurzverlauf:** bis zu 20 Minuten, höchstens 60 CPU/RAM-/Kapazitäts-
  und 60 I/O-Beobachtungen. Die Node-Karte exportiert ausschließlich ihre
  CPU/RAM-/Kapazitätsbeobachtungen.

Auswahl, Aufklappzustand und Tastaturfokus bleiben bei der normalen
Messwertaktualisierung erhalten. Ohne exportierbare Werte ist die Schaltfläche
deaktiviert. Fehler beim Erstellen des Downloads werden angezeigt; ein erneuter
Versuch bleibt möglich.

## Umfang und Zuordnung

Der Export verwendet ausschließlich Daten der ausgewählten Karte. Server und
Node werden getrennt exportiert. Der Serienbezeichner der gespeicherten Werte
muss mit dem aktuellen Datensatz dieser Karte übereinstimmen. Ein Wechsel der
VM bzw. des Nodes übernimmt keinen Verlauf einer anderen Serie.

Die CSV enthält den sichtbaren Ansichtsnamen, `scope=server|node` und den bereits
im Messdatensatz enthaltenen technischen Serienbezeichner. Die Kombination mit `view_path`
unterscheidet auch gleich nummerierte VMs in verschiedenen Verbindungen.
`view_path` enthält nur den Pfad der aktuellen Ansicht; Abfrageparameter und
Fragmente werden nicht übernommen. Der Dateiname verwendet
nur den festen Bereich, einen geprüften Ausschnitt dieses Bezeichners und den
Exportzeitpunkt. Frei eingegebene VM-Namen werden nicht als Dateipfad verwendet.

Es werden nur bekannte numerische Messfelder und freigegebene Metadaten
übernommen. Agent-Inventare, Rohlogs, API-Tokens, Zugangsdaten und andere
Resultatfelder gehören nicht zum Export.

Der Download findet vollständig lokal im Browser statt. Er löst keine neue
HTTP-Abfrage aus und benötigt keine zusätzliche Backend-Route, Datenbankhistorie
oder neuen Hintergrunddienst. Die Daten wurden zuvor über die vorhandenen,
berechtigungsgeprüften Auslastungsrouten geladen. Ein lokaler Download prüft die
Serverberechtigung nicht erneut; auch bei einem Ausfall können bereits geladene
Werte mit ihrem bisherigen Zeitpunkt exportiert werden.

## Dateiformat und Messsemantik

UTF-8 mit BOM, Komma als Trennzeichen, CRLF-Zeilenenden und Dezimalpunkt.
Zeitstempel sind ISO-8601 in UTC. Bei Tabellenprogrammen mit abweichenden
Ländereinstellungen die Text-/CSV-Importfunktion mit Trennzeichen **Komma** verwenden.

| Spalte bzw. Gruppe | Bedeutung |
| --- | --- |
| `exported_at_utc`, `view_label`, `view_path`, `scope`, `series_key` | Exportzeitpunkt und Zuordnung zur sichtbaren Karte |
| `sample_type` | `load` für Auslastung/Kapazität, `io` für Durchsatz |
| `sample_missing` | `1`, wenn eine historische Beobachtung keine gültigen Zahlen enthält; erhält Messlücken zwischen vorhandenen Werten |
| `observed_at_utc` | Ursprünglicher Beobachtungszeitpunkt, getrennt je Messgruppe |
| `source` | `agent_heartbeat`, `proxmox_status`, `proxmox_cluster`, `counter_delta`, `proxmox_rrd`; sonst ausdrücklich `not_reported` |
| `interval_seconds` | Gemeldetes I/O-Messintervall; bei Auslastungs-/Kapazitätszeilen leer |
| `memory_source` | Falls gemeldet: `guest_balloon` oder `host_rss` |
| `gap_before` | Beginn eines neuen Abschnitts nach Unterbrechung, vollständig fehlender Beobachtung oder größerer Zeitlücke |
| `stale_at_export` | Wert war als veraltet markiert oder ist beim Export älter als zwei Minuten; gilt auch für reguläre ältere Verlaufspunkte |
| `stream_interrupted_at_export` | Aktualisierung der Karte war beim Export als unterbrochen markiert |
| `cpu_percent`, `memory_percent`, `disk_percent` | Prozentwerte, soweit vorhanden |
| `memory_used_bytes`, `memory_total_bytes`, `disk_used_bytes`, `disk_total_bytes` | Normalisierte Kapazitätswerte in Bytes, soweit in der Karte vorhanden |
| `net_rx_bytes_per_second`, `net_tx_bytes_per_second`, `disk_read_bytes_per_second`, `disk_write_bytes_per_second` | Durchsatz in Bytes pro Sekunde |

Auslastung und I/O werden nicht zu einem vermeintlich gleichzeitigen Messpunkt
zusammengefügt. Jede Beobachtung behält die damals gemeldete Quelle,
RAM-Messbasis und das Intervall. Bei Proxmox-Auslastung bezeichnet die Zeit die
Abfrage der Status-/Clusterwerte; RRD und Agent-Messungen behalten ihre eigenen
Beobachtungszeitpunkte.

Gemessene Nullen bleiben `0`, fehlende oder ungültige Werte bleiben leer.
Historische Leerbeobachtungen bleiben in einem Verlauf mit gültigen Messpunkten erhalten.
Besteht der gewählte Zeitraum ausschließlich aus unbekannten Werten, wird keine
Messdatei erzeugt. Für Node-Karten werden keine VM-I/O-Felder hinzugemischt.

Alle Zellen werden gequotet; enthaltene Anführungszeichen werden verdoppelt.
Potenzielle Formelpräfixe in Textzellen werden mit einem führenden Apostroph
neutralisiert, einschließlich führender Leer- oder Formatzeichen. Die CSV ist
auf höchstens 120 Messzeilen pro Karte begrenzt. Temporäre Download-Links werden
entfernt und ihre Blob-URLs kurz verzögert freigegeben.

## Prüfung

- 26 JavaScript-Tests einschließlich CSV-Roundtrip mit dem unabhängigen
  Python-CSV-Parser: Quoting, Formeltexte, getrennte Zeitstempel, Quellen/
  Intervalle, Messlücken, Leerwerte, gemessene Null, Bereichsfilter,
  Serienwechsel, Größenbegrenzung und Dateinamen.
- 24 Chromium-Ansichten: Admin/Kunde × Proxmox/KVM × Dark/Lightmode ×
  1440/960/390 Pixel; keine JavaScript-Fehler oder horizontalen Seitenüberläufe.
- 24 reguläre Downloads: jeweils Server-/Node-Karte und alle drei Exportumfänge.
  Tatsächliche Dateien mit Python eingelesen; UTC, Kartenzuordnung, Quellen und
  Nullwerte geprüft. Kein zusätzlicher HTTP-Aufruf beim Download. Auswahl und
  Fokus bleiben nach Aktualisierung erhalten; temporäre URLs werden freigegeben.
- Zusätzliche Browserfälle: abweichende Serien-ID blockiert den Download;
  ein künstlicher Downloadfehler hinterlässt weder Link noch Blob-URL;
  erneuter Versuch und eine CSV mit formelähnlichem VM-Namen funktionieren.
- PHP-Tests prüfen ausdrücklich die neue Herkunftskennzeichnung und das
  Ausfiltern unbekannter Quelltexte. PHPStan, Twig-/YAML-Lint und Formatprüfung
  bestehen.

Vollständige PHP-Regression: **1.528 Tests, 6.460 Assertions**, keine Fehler oder
Fehlschläge. Bestehende 3 Warnungen, 301 PHPUnit-Hinweise und 12 übersprungene
Tests sind unverändert.
Tests verwenden synthetische Messdaten und simulierte Proxmox-/Agent-Antworten.
Kein realer HomeServer wurde kontaktiert. Lokale PHP-Version: 8.4.27.

## Aktualisierung und weitere Module

Für diesen Schritt genügt ein Panel-Update; es gibt keine Agent-Änderung und
keine neue Datenbankmigration. Die vorhandene Durchsatz-/Kurzverlaufsfunktion
wird vorausgesetzt. Der Verlauf beginnt nach einem Neuladen erneut und enthält
keine Daten aus geschlossenen Tabs oder früheren Sitzungen. Persistente
Langzeitverläufe und deren Export bleiben weitere Ausbaupunkte.

## Patch

`/workspace/vm-metrics-export-change/messwerte-csv-proxmox-kvm.patch` enthält
nur diesen Ausbau auf dem bisherigen Arbeitsstand (Basis-Commit `1ab993b7`
zuzüglich VM-Verwaltung, Cloud-Init, Gaststatus, Netzwerkinformationen und
Durchsatz/Kurzverlauf). Die fünf vorherigen Erweiterungspatches werden
vorausgesetzt und nicht ersetzt. Vorwärts-/Rückwärtsprüfung und ein bytegleicher
Wiederaufbau im separaten Verzeichnis prüfen seine Anwendung.
