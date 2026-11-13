# Rechnungssystem

## Bedienung

- Adminübersicht: `/admin/billing`; Einstellungen: `/admin/billing/settings`.
- Rechnung anlegen: Kunde, eindeutige Nummer, Währung, Fälligkeit und bis zu 100 Positionen mit Beschreibung, Menge, Bruttoeinzelpreis und Steuersatz auswählen. Der Bruttobetrag enthält bereits die ausgewählte Steuer.
- Absender, Empfänger, Sprache und Steueraufteilung werden bei Ausstellung gespeichert. Eine echte PDF wird bei neuen Rechnungen sofort archiviert. Spätere Profil-, Steuer- oder Layoutänderungen überschreiben diese Daten und Archive nicht.
- Der Vorlagendesigner liegt unter `/admin/billing/design`, einschließlich visueller Einstellungen, Logo, geschützter Vorschau und optionaler Twig-Vorlage. Der Download einer bereits archivierten Rechnung liefert weiterhin das Original.
- Kunden sehen unter `/customer/billing` ausschließlich eigene Rechnungen, Zahlungen, Gutschriften und Mahnstufen. PDF- und Detailendpunkte prüfen die Kundenzuordnung erneut.
- Alle neuen und bestehenden administrativen Rechnungsänderungen sowie die Zahlungsinitiierung verlangen einen CSRF-Token.

## Beträge und Steuern

Beträge werden als ganze Centbeträge verarbeitet. Eingaben erlauben Punkt oder Komma mit höchstens zwei Nachkommastellen; Exponentialnotation, negative Werte und Werte außerhalb des Datenbankbereichs werden abgewiesen. Der vorhandene Zahlungs-/Währungsansatz setzt zwei Nachkommastellen voraus.

Aus 119,00 EUR brutto bei 19 % entstehen 100,00 EUR netto und 19,00 EUR Umsatzsteuer. Es wird mit ganzen Zahlen gerundet. Die Modi `exempt`, `reverse_charge` und `small_business` weisen keine Umsatzsteuer aus und erhalten einen entsprechenden Text. Die Wahl des Modus bleibt eine bewusste Entscheidung des Rechnungsausstellers. Vorhandene Rechnungen ohne Snapshot bleiben als `legacy` erkennbar; Steuerbeträge werden für sie nicht nachträglich erfunden.

Positionen unterstützen Mengen mit drei Nachkommastellen und unterschiedliche Steuersätze. Neue Rechnungen runden die enthaltene Steuer je Steuersatz und verteilen Centdifferenzen auf Nettopositionen, ohne die Bruttopreise zu ändern. Bestehende Snapshots bleiben unverändert. XRechnung und ZUGFeRD sind als lokal geprüfte, unveränderliche Exporte ergänzt; Voraussetzungen und Bedienung stehen in [E-Rechnungen](electronic-invoices.md). Ein spezialisierter DATEV-Export ist noch nicht ergänzt.

## Zahlungen, Gutschrift und Storno

- `PaymentRecorder` verarbeitet Provider/Referenz eindeutig, prüft Betrag und Währung und sperrt die Rechnung während einer Buchung. Wiederholte erfolgreiche Zahlungsnachrichten erzeugen keine zweite Zahlung. Neue Zahlungen zählen bereits vor einem Flush für den Status.
- Teilzahlungen reduzieren den offenen Betrag. Eine Rechnung wird erst ausgeglichen, wenn erfolgreiche Zahlungen die verbleibende Forderung decken.
- „Als bezahlt markieren“ erzeugt eine echte manuelle Buchung. Das Zurücknehmen manueller Buchungen verändert deren Status; erfolgreiche externe Zahlungen werden dadurch nicht geändert.
- Eine Gutschrift verlangt eine Begründung, passende Währung und einen positiven Betrag innerhalb der verbleibenden Forderung.
- Ein Storno erzeugt für die noch verbleibende Forderung einen Korrektureintrag und setzt die Rechnung endgültig auf `cancelled`. Rechnungsnummer, ursprünglicher Gesamtbetrag, Zahlungen und Archiv bleiben erhalten.
- Eine Gutschrift ist keine automatische Auszahlung. Bereits empfangenes Geld wird separat über den Zahlungsanbieter erstattet. Automatische Teilrückerstattungen und die Verarbeitung sämtlicher Refund-Events sind nicht Teil dieses Pakets.

## Stripe und automatische Aktualisierung

`STRIPE_SECRET_KEY` und `STRIPE_WEBHOOK_SECRET` werden über die vorhandene Laufzeitkonfiguration gesetzt. Eine konfigurierte Stripe-Zahlungsart kann im Kundenprofil ausgewählt werden. `DEFAULT_URI` oder explizite `STRIPE_SUCCESS_URL`/`STRIPE_CANCEL_URL` müssen auf die gewünschte Paneladresse zeigen.

Webhook: `POST /billing/webhook/stripe`. Der Session-Guard lässt diesen exakten Routennamen ohne Benutzersitzung durch; die Signaturprüfung bleibt Pflicht. Signaturen besitzen eine Zeitgrenze von fünf Minuten. Nicht bezahlte Checkout-Sessions markieren keine Rechnung als bezahlt. `checkout.session.completed` mit `payment_status=paid` und `checkout.session.async_payment_succeeded` werden verarbeitet. Der periodische Abgleich liest alle Stripe-Seiten und verwendet nach erfolgreichem Schedulerlauf einen überlappenden Zeitbereich für weitere Abgleiche. Ein fehlgeschlagener Abgleich wird nicht als erfolgreicher Schedulerlauf verbucht.

Es wurden keine echten Stripe-Zahlungen ausgeführt. Der externe Vertrag wurde mit signierten Testereignissen und einem HTTP-Testclient geprüft; der HomeServer-Test verwendet Stripe-Testmodus.

## Automatik und Mahnungen

Die Automatik ist initial deaktiviert und wird in den Rechnungseinstellungen eingeschaltet. Der zentrale Scheduler findet den Handler `billing.automation` und führt aktivierte Abgleiche höchstens einmal je Stunde aus. Die explizite Zuordnung der getaggten Handler muss nach dem allgemeinen `App\`-Serviceimport stehen, damit sie nicht überschrieben wird.

Automatische Erinnerungen besitzen einen eigenen Schalter. Gebühren der drei Mahnstufen sind konfigurierbar und standardmäßig null. Zwischen versandten Stufen gelten konfigurierbar 1–90 Tage. Das ursprüngliche Rechnungsfälligkeitsdatum wird nicht durch einen Mahnlauf verändert. Ein seit Monaten überfälliger Beleg erzeugt nicht innerhalb weniger Minuten sämtliche Stufen.

Wenn die Mailqueue die Nachricht nicht annimmt, bleibt dieselbe Mahnung ausstehend und wird erneut versucht. Es entsteht dafür keine zweite Gebühr und keine weitere Stufe. `sent` bedeutet hier wie im vorhandenen System „Versand eingeplant“, nicht eine bestätigte Zustellung im Empfängerpostfach. Eine funktionierende Mail-/Messenger-Konfiguration ist Voraussetzung. Ausgeglichene oder stornierte Rechnungen erhalten keine neue Mahnung.

Manuelle Diagnose: `php bin/console app:billing:automation --env=prod`. Dieser Befehl führt den Abgleich unmittelbar aus; aktivierte Erinnerungen können dabei Versandaufträge erzeugen. Er ist kein rein lesender Diagnosebefehl.

## Buchhaltungsexport

`/admin/billing/export?type=invoices&year=2026` und `type=payments` liefern CSV. Rechnungen enthalten zusätzlich Netto, Umsatzsteuer, Steuersatz, Steuerbehandlung, aggregierte Gutschriften, offenen Betrag und Stornogrund. Tabellenformeln in Textfeldern werden entschärft. Dies ist ein allgemeiner CSV-Export, kein als DATEV zertifiziertes Format.

## Übernahme und Migration

1. Änderungen in einem eigenen Release vorbereiten; Datenbank und bisheriges Release sichern.
2. `composer install --no-dev --prefer-dist --no-interaction` für den neuen Composer-Lock ausführen. Die PDF-Erzeugung verwendet Dompdf; ausgehende Ressourcen, PHP und JavaScript sind im PDF-Renderer deaktiviert.
3. `doctrine:migrations:migrate --env=prod --no-interaction` ausführen. `Version20261112010000` ergänzt nullable Snapshots und Stornodaten sowie eindeutige Beleg-/Zahlungsreferenzen.
4. Existieren doppelte Rechnungsnummern, Gutschriftnummern oder Provider/Referenz-Paare, bricht die Migration vor ihren Änderungen ab. Diese Datensätze müssen bewusst geprüft werden; die Migration löscht oder nummeriert sie nicht automatisch um.
5. Frühere manuelle „bezahlt“-Markierungen ohne ausreichende Zahlungsbuchungen erhalten eine nachvollziehbare manuelle Buchung mit Referenz `legacy-manual-<invoice-id>`. So öffnet der neue Abgleich alte ausgeglichene Rechnungen nicht wieder.
6. `doctrine:schema:validate --env=prod --no-interaction` ausführen. Danach Worker-/PHP-Caches gemäß bestehendem Releaseprozess erneuern.
7. Absender und Steuerbehandlung konfigurieren. Erst nach dem Test die automatische Zahlungsabstimmung und gegebenenfalls Erinnerungen aktivieren.

Die Migration ist wegen der zu erhaltenden Finanzhistorie nicht durch Löschen der neuen Spalten rückwärts ausführbar. Ein Code-Rollback sollte die ergänzenden Datenbankfelder erhalten. Bestehende HTML-Archive bleiben downloadbar, werden aber nicht nachträglich als PDF ausgegeben oder überschrieben.

## Prüfungen

Automatisierte Tests: `php bin/phpunit tests/Module/Core/Application/Billing tests/Controller/BillingHttpTest.php`.

Abgedeckt sind Cent-/Steuerrundung, Teilzahlungen, wiederholte Referenzen, Pending→Succeeded, abweichende Währung, Gutschriften, Storno, Mahnabstände und Versandwiederholung, geschützte Vorlagen, unveränderte Archivdateien, CSV-Formelentschärfung, signierte Stripe-Ereignisse, Pagination, Schedulerverdrahtung, Kundentrennung und CSRF über echte HTTP-Requests an den Symfony-Kernel.

Auf dem HomeServer anschließend mit einem eigenen Testkunden prüfen: 119,00-EUR-Rechnung bei 19 %, PDF und Kundenhistorie, Teilzahlung und Restzahlung im Testmodus, Teilgutschrift, Storno einer separaten Rechnung und ein kontrollierter Mahnlauf. Diese Live-Übernahme wurde aus der Cloud-Aufgabe nicht ausgeführt.
