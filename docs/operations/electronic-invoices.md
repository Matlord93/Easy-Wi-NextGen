# XRechnung und ZUGFeRD

## Bereitstellung

Das Panel erzeugt XRechnung 3.0.2 als CII-XML und ZUGFeRD im Profil EN16931
als PDF/A-3b mit eingebettetem `factur-x.xml`. Es werden keine Rechnungsdaten
an externe Prüfdienste geschickt. Die Erstellung ist eine explizite Adminaktion;
Kunden können ausschließlich ihre bereits archivierten Dateien herunterladen.

1. Composer-Abhängigkeiten aus dem neuen Lock installieren. Benötigt werden die
   PHP-Erweiterungen der Composer-Pakete, darunter DOM, XML, SimpleXML, ZIP und
   mbstring. Für PNG-Logos ist zusätzlich GD nötig.
2. Datenbank sichern und die neuen Doctrine-Migrationen ausführen.
   `Version20261113020000` ergänzt das Archiv. Vorhandene Rechnungen und PDFs
   werden weder geändert noch neu berechnet.
3. Java 21, Python 3 und curl installieren. Dann im Repository ausführen:

   ```bash
   python3 scripts/setup-einvoice-validation.py
   ```

   Das Skript lädt ausschließlich festgelegte Versionen von den offiziellen
   KoSIT-/veraPDF-Quellen und prüft die im Skript hinterlegten SHA-256-Werte.
   Die Veröffentlichung des Werkzeugverzeichnisses erfolgt erst nach dem
   erfolgreichen Abschluss. Es überschreibt kein vorhandenes fremdes Verzeichnis.
   Ein Offline-Aufbau ist mit `--asset-cache /pfad/zu/geprüften/archiven` möglich;
   die drei Dateinamen stehen im Skript, ihre Hashes werden erneut geprüft.
4. Das Ziel `core/var/einvoice-validator` muss für PHP lesbar sein, das
   veraPDF-Startskript ausführbar. Die Dateien gehören zum Release/Deployment;
   der PHP-Benutzer braucht dort keine Schreibberechtigung. Bei einem Release mit
   neuem `var`-Verzeichnis muss das Verzeichnis erneut bereitgestellt werden.
   Keine Java-Dateien in ein öffentlich erreichbares Verzeichnis legen.
5. PHP muss lokale Prozesse ausführen können. Die bestehenden Nginx-/PHP-
   Zeitlimits müssen die einmalige Dokumentprüfung zulassen. Je Teilprüfung
   gilt ein Prozesslimit von 45 Sekunden. Java erhält maximal 192 MiB Heap;
   der Gesamtprozess benötigt zusätzlich Arbeitsspeicher für JVM und Bibliotheken.
   Pro Panelhost läuft höchstens eine Prüfung gleichzeitig. Anschließend endet
   der Prozess; es entsteht kein zusätzlicher dauerhaft laufender Dienst.

Die installierten Versionen sind KoSIT 1.6.3, XRechnung-Konfiguration 3.0.2 vom
31.08.2026, EN16931 1.3.16 und veraPDF 1.30.4. Bei einer Aktualisierung müssen
Downloadadressen, Hashes und Versionskennung gemeinsam geändert und die
Akzeptanztests erneut ausgeführt werden.

## Rechnung ausstellen

Unter `/admin/billing/settings` stehen die strukturierten Absenderdaten:
Straße, PLZ, Ort, ISO-Ländercode, Ansprechpartner, Telefon, Steuernummer,
IBAN und optional BIC. Die bisherige freie Anschrift bleibt für bestehende
Vorlagen erhalten; sie wird nicht automatisch als strukturierte Adresse zerlegt.
Umsatzsteuer-ID, E-Mail und Name stammen aus den bisherigen Feldern.

Kundendaten müssen vor der Ausstellung vollständig sein: Name, Straße, PLZ,
Ort, ISO-Ländercode und E-Mail, bei Reverse Charge die Umsatzsteuer-ID. Bei einer
neuen Rechnung zusätzlich Leistungsdatum und für XRechnung Käuferreferenz
(gegebenenfalls Leitweg-ID) eintragen. ZUGFeRD verlangt keine Käuferreferenz.
Die Anwendung prüft erforderliche Angaben je Format und weist fehlende Angaben
im Exportbereich konkret aus.

Ausstellungsdatum, ursprüngliches Zahlungsziel, Nummer, Währung, Positionen,
Steueraufteilung und beide Parteien werden unveränderlich gespeichert. Eine
spätere Zahlung, Zahlungsfristverlängerung, Mahngebühr oder Profiländerung
schreibt die ausgestellte Rechnung nicht um. Alte Rechnungen mit unvollständigem
Snapshot werden nicht nachträglich aus aktuellen Stammdaten rekonstruiert.

Bruttopreise und Mengen bleiben erhalten. Die enthaltene Steuer wird bei neuen
Rechnungen je Steuersatz gerundet. Eine nötige Centverteilung auf Nettopositionen
ist deterministisch und verändert weder Brutto-Positionspreise noch Gesamtsumme.
Mengen besitzen drei Nachkommastellen. Das elektronische Dokument verwendet die
jeweilige abgerechnete Menge als Preisbasismenge, damit auch `0,001` und gemischte
Steuersätze ohne erfundene Nachkommabeträge abgebildet werden.

## Prüfen, archivieren und herunterladen

Im Rechnungsdetail den Bereich „Elektronische Rechnung“ öffnen. „Prüfen und
unveränderlich archivieren“ verlangt Administratorrechte und CSRF-Schutz.
XRechnung durchläuft die lokale XSD- und KoSIT-Prüfung. ZUGFeRD durchläuft XSD,
EN16931-Schematron und veraPDF mit Profil PDF/A-3b. Fehler oder fehlende Werkzeuge
verhindern die Archivierung. Temporäre XML-, PDF- und Prüfberichte werden auch
bei Fehlern gelöscht; sie liegen während der Prüfung in einem privaten
Zufallsverzeichnis.

ZUGFeRD übernimmt das bestehende archivierte PDF als sichtbare Rechnung. Die
Administration muss prüfen, dass dieses Original-PDF zu den Rechnungsdaten gehört,
besonders bei manuell hochgeladenen PDFs. Die Formatprüfung kann diese inhaltliche
Übereinstimmung nicht beweisen. Nicht eingebettete Schriften oder andere
PDF/A-Verstöße eines alten PDFs können den ZUGFeRD-Export verhindern. Das Panel
ersetzt oder überschreibt deshalb kein Original-PDF.

Je Rechnung und Format existiert genau ein Archiv mit Prüfsumme und verwendeter
Prüfversion. Wiederholte Erstellung liefert das bestehende Archiv. Der Download
prüft dessen SHA-256 und gibt die ursprünglichen Bytes aus. Er ist privat und
nicht zwischenspeicherbar. Kundenzuordnung wird bei jedem Aufruf geprüft.
Die normale PDF-Datei bleibt separat erhalten.

Diese Exporte sind Rechnungsdokumente. Eine Stornierung, Gutschrift oder
Zahlungserstattung verändert das ursprüngliche Dokument nicht. Elektronische
Korrekturbelege sind als eigener Ausbau zu behandeln; nicht denselben Beleg mit
negativer Summe oder geändertem Status erneut ausgeben.

## Prüfung der Implementierung

Die normalen PHP-Tests prüfen XSD, Mengen-/Steuerberechnung, geschützte Downloads,
CSRF, fehlende Pflichtangaben und Mandantentrennung. Echte lokale Format- und
Archivtests nach Installation der Werkzeuge:

```bash
cd core
EASYWI_TEST_EINVOICE_VALIDATORS="$PWD/var/einvoice-validator" \
  vendor/bin/phpunit tests/Module/Core/Application/Billing/ElectronicInvoiceStandardsTest.php \
  tests/Module/Core/Application/Billing/ElectronicInvoiceWorkflowTest.php
```

Die Fälle umfassen gemischte Steuersätze, 100 Kleinpositionen, Mengen von 0,001,
Nullpreispositionen, Steuerbefreiung, Kleinunternehmer und Reverse Charge,
Fehlerablehnung sowie das Auslesen des identischen XML-Anhangs aus dem PDF.
Eine Live-Abnahme mit tatsächlichen Firmendaten und dem empfangenden
Rechnungsportal steht weiterhin aus.
