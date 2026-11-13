# Rechnungspositionen und Vorlageneditor

Stand: 13. November 2026. Der bisherige Rechnungsdialog erfasste nur einen
Gesamtbetrag und eine Beschreibung. Die Vorlagenbearbeitung war als Codefeld
innerhalb einer vorhandenen Rechnung versteckt.

## Bedienung

Unter **Rechnungen → Rechnungsvorlage gestalten** steht jetzt ein eigener
Editor bereit, auch vor der ersten Rechnung. Derselbe Einstieg ist in den
Rechnungseinstellungen und in der Rechnungsdetailansicht verlinkt.

Der visuelle Editor bietet Farben, drei eingebettete Schriftfamilien, Textgröße,
Seitenränder, kompakte/großzügige Abstände, Kopf-/Logoausrichtung, einen
klassischen/modernen Kopf, eigenen Rechnungstitel, Einleitung und zusätzliche
Fußzeile. Die Positionstabelle bleibt auf A4 lesbar und verwendet höchstens
11 pt. Firmenname, Anschrift, Steuerangaben und Zahlungshinweise werden weiter
in den Rechnungseinstellungen gepflegt.

PNG-/JPEG-Logos werden direkt eingebettet: höchstens 48 KiB und 1600 × 1600 Pixel.
Das gespeicherte Logo lässt sich entfernen oder ersetzen. Für PNG-Ausgabe in
Dompdf wird PHP-GD benötigt. Der Linux-Installer sieht GD bereits vor; bei
fehlender Erweiterung erscheint vor Aktivierung/Neuausstellung eine verständliche
Meldung. PNG/JPEG werden nach Typ, Größe und Abmessungen geprüft, bei vorhandenem
GD zusätzlich vollständig dekodiert. SVG und externe Logo-URLs sind ausgeschlossen.

Die Vorschau verwendet Musterkundendaten, aktualisiert sich ohne Speicherung
und zeigt Fehler, ohne die letzte erfolgreiche Vorschau zu ersetzen. Erst
**Speichern** aktiviert die ausgewählte Variante. HTML-Browseransicht und
Dompdf unterstützen nicht alle CSS-Funktionen identisch; das endgültige PDF
nach eigener umfangreicher Code-Anpassung zusätzlich prüfen.

Für eigene HTML-/CSS-/Twig-Vorlagen gibt es einen Code-Modus. Bestehender eigener
Code bleibt erhalten. Der visuelle Modus erzeugt eine neue Vorlage aus seinen
Feldern und übernimmt freie Code-Änderungen nicht automatisch. Ungültiger Code
bleibt zur Korrektur im Formular stehen und ersetzt die gespeicherte Vorlage nicht.

## Mehrere Positionen

**Neue Rechnung** enthält bis zu 100 Positionszeilen. Zeilen lassen sich
hinzufügen, entfernen und nach oben/unten verschieben. Jede enthält Beschreibung,
Menge, Brutto-Einzelpreis und Steuersatz. Browser und Server verwenden dieselbe
Cent-/Mengenpräzision; der Server berechnet die verbindlichen Beträge selbst.
Übermittelte Gesamtbeträge werden bei vorhandenen Positionen ignoriert.

- Mengen: positiv, höchstens drei Nachkommastellen; Punkt oder Komma als
  Dezimaltrennzeichen. Geldbeträge: höchstens zwei Nachkommastellen.
- Positionsbrutto = auf Cent gerundetes Produkt aus Menge und Brutto-Einzelpreis.
  Danach wird Netto nach der bestehenden `splitGross`-Regel auf Cent gerundet;
  die Differenz zum Brutto ist die enthaltene Steuer. Keine Fließkomma-Rechnung.
- Der Rechnungsbetrag ist die Summe der gerundeten Positionen. Kostenlos enthaltene
  Positionen sind möglich, die gesamte Rechnung muss positiv bleiben.
- Im Umsatzsteuer-Modus sind unterschiedliche Steuersätze möglich. Summen werden
  je Steuersatz ausgewiesen. Steuerbefreit/Reverse Charge/Kleinunternehmer verwenden
  weiterhin den entsprechenden globalen Modus und effektiv null Prozent.
- Höchstens 2000 Zeichen je Beschreibung und insgesamt 24000 Beschreibungsbytes;
  Gesamtsumme höchstens 2147483647 Cent. Negative Rabattpositionen gehören nicht
  zu dieser Ergänzung.

Die gespeicherten Positionen erscheinen in Admin-, Kunden- und PDF-Ansicht.
Kundenzuordnung, Zahlungen, Storno/Gutschriften und unveränderliche Rechnungsdaten
verwenden weiter die bestehende Verarbeitung. Eine Gutschrift bleibt eine
Gutschrift auf die Gesamtforderung und wird hier nicht zu einer Positionsgutschrift.

## Bestehende Daten und eigene Vorlagen

Vorhandene Rechnungen ohne Positionsdaten werden lesend als eine Position mit
ihren ursprünglichen Beträgen dargestellt. Es erfolgt keine Neuberechnung und
keine Migration bestehender Beträge. Alte Ein-Positionen-POSTs mit `amount` und
`description` bleiben kompatibel. Ein explizit übermitteltes ungültiges `items`
wird abgelehnt und fällt nicht auf einen frei übermittelten Gesamtbetrag zurück.

Die unveränderte alte Standardvorlage wird durch die neue positionsfähige
Standardvorlage dargestellt. Eigene gespeicherte Vorlagen werden nicht überschrieben;
sie müssen die Positionsschleife selbst verwenden oder bewusst durch den visuellen
Editor ersetzt werden. Beispiel:

```twig
{% for item in invoice.items %}
  <p>{{ item.description }} · {{ item.quantity_milli / 1000 }}
    · {{ (item.gross_cents / 100)|number_format(2, decimal_separator, thousands_separator) }}
    {{ invoice.currency }}</p>
{% endfor %}
```

`item.unit_price_cents`, `net_cents`, `tax_cents`, `gross_cents` sind Centbeträge;
`quantity_milli` sind Tausendstel, `tax_rate_bps` Hundertstel-Prozentpunkte.
`invoice.tax_breakdown` enthält Summen je Steuersatz. Bei gemischten Sätzen ist
`invoice.mixed_tax_rates=true`; der einzelne alte `tax_rate_bps` ist dann kein
repräsentativer Rechnungssatz. Die alte Gesamtbeschreibung bleibt als begrenzte
Zusammenfassung für kompatible Vorlagen verfügbar.

Archivierte PDFs bleiben bytegleich. Vorlagenänderungen beeinflussen die aktuelle
HTML-Vorschau und künftig erzeugte PDFs; ein Download eines vorhandenen PDF-Archivs
liefert weiterhin das Original. Die Vorschau kennzeichnet diesen Unterschied.
Alte, noch nicht archivierte Rechnungen werden wie bisher beim Download mit der
aktuellen Vorlage erzeugt.

Der Rechnungs-CSV-Export enthält für gemischte Steuersätze `mixed` statt eines
vermeintlichen einzelnen Steuersatzes. Die angehängte Spalte `Steueraufteilung`
enthält die Gruppen als JSON mit Centbeträgen und `tax_rate_bps`. Die bisherigen
Spalten behalten ihre Reihenfolge.

## Sicherheit und Aktualisierung

Editor, Speichern und Vorschau benötigen eine Admin-Sitzung; POSTs benötigen den
bestehenden Billing-CSRF-Token. Vorschauen speichern weder Rechnung noch Vorlage.
HTML-Vorschauen laufen in einem Sandbox-Iframe mit zusätzlicher Content Security
Policy: keine Skripte, fremden Ressourcen oder Formularaktionen. Das PDF-Rendering
behält deaktivierte Remote-, PHP- und JavaScript-Ausführung. Normale Textfelder
werden als Text eingebettet und können keine Twig-Ausdrücke einschleusen.

Der Patch ergänzt den aktuellen Arbeitsstand. Frühere Proxmox-/KVM-Änderungen
bleiben erhalten. Nur das Panel aktualisieren und den üblichen Symfony-Cache
erneuern; kein Agent-Update und keine Datenbankmigration erforderlich. PNG-Logos
erfordern GD in der tatsächlich verwendeten PHP-CLI-/FPM-Version, beispielsweise
`php8.5-gd` bei einem passenden PHP-8.5-Paketangebot.

## Validierung

- 48 Chromium-Ansichten: Editor, Erstellung, Admin-Rechnung und Kunden-Rechnung,
  Deutsch/Englisch, Dark/Lightmode und 1440/960/390 Pixel. Keine JavaScript-Fehler
  oder horizontalen Seitenüberläufe.
- Echte Formularübermittlung mit drei Positionen, unterschiedlicher Umsatzsteuer,
  dezimaler Menge, einer kostenlosen Position und zuvor umsortierten Zeilen.
- PDF mit eingebettetem PNG und drei Positionen als A4-Einzelseite; PDF mit
  100 Positionen über mehrere Seiten. Dateien mit `pdftotext` eingelesen:
  Positionsnamen einschließlich letzter Position und Summen enthalten.
- Nach Vorlagenänderung ist das vorherige PDF-Archiv bytegleich. Kunde sieht seine
  Positionen und erhält für fremde Rechnungen weiterhin 404.
- Separate Tests für Cent-Rundung, Mengen, Obergrenzen, ungültige Daten, CSRF,
  Vorschau ohne Speicherung, kundenseitigen Ausschluss, Twig-Einschleusung,
  Logoformate und Verhalten mit/ohne GD.

Der vollständige PHP-Lauf besteht mit **1587 Tests und 6653 Assertions**;
keine Fehler oder Fehlschläge. Bestehende 3 Warnungen, 301 PHPUnit-Hinweise
und 12 übersprungene Tests bleiben bestehen. Nach der letzten Beschriftungs-
Anpassung bestehen zusätzlich die 57 Billing-Tests mit 129 Assertions.
Vier Design-Tests wurden auch mit tatsächlich geladenem GD geprüft.
Alle 41 JavaScript-Tests, PHPStan, Twig-/YAML-/Container-Lint und die
Formatprüfung bestehen.

Zusätzliche Browserprüfung der Vorschau: eingebettete Skripte und externe
Bilder sind blockiert, ungültiges Twig erhält die vorige Vorschau, und Vorschauen
speichern die geänderte Vorlage nicht. Ein final erzeugtes Drei-Positionen-PDF
enthält alle Texte, den Steuer-Spaltentitel und die Summe auf genau einer A4-Seite.

Der inkrementelle Patch ist gegen den gesicherten Ausgangsstand vorwärts und
gegen den aktuellen Stand rückwärts anwendbar. Die nach Anwendung rekonstruierten
Dateien stimmen bytegenau überein.
Die Tests liefen lokal mit synthetischen Kundendaten; auf dem HomeServer wurde
für diese Änderung noch nichts ausgeführt.
