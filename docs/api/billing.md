# Rechnungsabläufe und HTTP-Verträge

Die folgenden Routen ergänzen die vorhandenen servergerenderten Panelabläufe. Mutationen nehmen Formulardaten entgegen. Sie liefern bei Erfolg in der Regel HTTP 303 auf das Rechnungsdetail, bei Validierungsfehlern 422 und bei fehlender Berechtigung bzw. ungültigem CSRF-Token 403. PDF-/CSV-Downloads sind privat und nicht für gemeinsame Caches bestimmt.

## Administration

Alle Aktionen verlangen einen angemeldeten Admin gemäß bestehender `User::isAdmin()`-Prüfung. Schreibaktionen verlangen zusätzlich `_token` für CSRF-ID `billing`.

| Methode | Pfad | Bedeutung |
|---|---|---|
| GET | `/admin/billing` | Rechnungs-/Gutschriftenübersicht |
| GET | `/admin/billing/invoices/new` | Erfassungsformular |
| POST | `/admin/billing/invoices` | Rechnung mit Snapshot und archivierter PDF ausstellen |
| GET | `/admin/billing/invoices/{id}` | Detail, Zahlungen, Gutschriften, Erinnerungen, Vorschau |
| GET | `/admin/billing/invoices/{id}/pdf` | PDF; vorhandene PDF-Archive unverändert verwenden |
| POST | `/admin/billing/invoices/{id}/mark-paid` | Offenen Restbetrag manuell buchen |
| POST | `/admin/billing/invoices/{id}/mark-unpaid` | Erfolgreiche manuelle Buchungen zurücknehmen; keine externen Zahlungen ändern |
| POST | `/admin/billing/invoices/{id}/credit` | Teilgutschrift mit `amount` und `reason` |
| POST | `/admin/billing/invoices/{id}/cancel` | Storno mit `reason` und Korrektureintrag |
| POST | `/admin/billing/invoices/{id}/archive/generate` | PDF für einen bisher nicht archivierten Beleg erzeugen |
| POST | `/admin/billing/invoices/{id}/archive` | Validierte PDF bis 10 MB unter `invoice_pdf` hochladen |
| GET | `/admin/billing/invoices/{id}/archive/download` | Originalarchiv herunterladen, einschließlich historischer HTML-Archive |
| POST | `/admin/billing/layout` | Validierte Vorlage als `invoice_layout` speichern; optional `invoice_id` für Rückleitung |
| GET / POST | `/admin/billing/settings` | Einstellungen anzeigen bzw. unter `options[...]` speichern |
| POST | `/admin/billing/dunning` | Mahnlauf ausdrücklich sofort ausführen |
| GET | `/admin/billing/export` | CSV; `type=invoices` oder `payments`, optional `year` |

Rechnungserfassung: `customer_id`, `number` (max. 40 Zeichen, eindeutig), `amount` (positiver Bruttobetrag), `currency` (dreistellig), `due_date` (gültiges `YYYY-MM-DD`), `description` (max. 2000 Zeichen), `tax_mode` und `tax_rate` als Prozentangabe. Gültige Steuerarten: `standard`, `exempt`, `reverse_charge`, `small_business`.

Gutschriften akzeptieren keinen freien Währungswechsel. Sie beziehen ihre Währung aus der Rechnung. Die Begrenzung erfolgt serverseitig innerhalb einer Transaktion. Stornierte Rechnungen werden vom automatischen Statusabgleich nicht wieder geöffnet. Ein bereits vorhandenes Archiv kann nicht überschrieben werden.

## Kundenbereich

| Methode | Pfad | Bedeutung |
|---|---|---|
| GET | `/customer/billing` | Historie des angemeldeten Kunden |
| GET | `/customer/billing/invoices/{id}` | Eigene Rechnung einschließlich Zahlungshistorie |
| GET | `/customer/billing/invoices/{id}/pdf` | Eigene PDF herunterladen |
| POST | `/customer/billing/invoices/{id}/pay` | Zahlung über gespeicherte Zahlungsart initiieren; CSRF erforderlich |

Die Kundenzuordnung wird je Detail-, Download- und Zahlungsaktion erneut geprüft. Fremde Rechnungen liefern 404. Es genügt nicht, eine Rechnungs-ID zu kennen.

## Zahlungswebhook

`POST /billing/webhook/stripe` ist ohne Benutzersitzung erreichbar und verlangt eine gültige `Stripe-Signature` mit maximal fünf Minuten Zeitabweichung. Eine ungültige Signatur liefert 401. Erfolgreich verarbeitete bzw. nicht relevante Ereignisse werden mit JSON und HTTP 200 quittiert. Fehler des Providers werden vom bestehenden Webhookcontroller als Fehlerantwort behandelt, damit keine erfolgreiche Verarbeitung vorgetäuscht wird.

Bezahlte Checkout-Sessions werden anhand ihrer Provider-/Sessionreferenz genau einmal gebucht. Unbezahlte Sessions erzeugen keine erfolgreiche Zahlung. Die Referenz darf nicht nachträglich auf eine andere Rechnung, einen anderen Betrag oder eine andere Währung umgedeutet werden.

Automatik, Konfiguration und Grenzen: [Betriebsdokumentation](../operations/billing.md).
# Mehrere Positionen und Vorlageneditor

`POST /admin/billing/invoices` akzeptiert `items[index][description]`,
`items[index][quantity]`, `items[index][unit_price]` (brutto) und
`items[index][tax_rate]`. Dazu wie bisher Kunde, Nummer, Währung, Fälligkeit,
globaler `tax_mode` und `_token`. Maximal 100 Positionen. Mengen haben drei,
Geldbeträge zwei Nachkommastellen. Gesamtsummen und Steuergruppen berechnet der
Server; zusätzliche übermittelte Summen sind nicht maßgeblich. Ungültige Daten
liefern 422 mit dem ausgefüllten Formular.

`GET|POST /admin/billing/design`: eigener Vorlageneditor. POST benötigt Billing-
CSRF, `mode=visual|custom`. Visual: `design[...]`, optional Datei `logo` oder
`remove_logo=1`; Custom: `invoice_layout` (freigegebene HTML-/Twig-Vorlage).
`POST /admin/billing/design/preview` verwendet dieselben Felder, speichert nichts
und liefert JSON `html` oder bei 422 `error`. Die Vorschau enthält Musterkundendaten.
Alle Routen sind Admin-Routen. Antworten verwenden `private, no-store`.

Details zu Beträgen, Vorlagenvariablen und Kompatibilität:
[Rechnungspositionen und Vorlageneditor](../reports/rechnungspositionen-und-vorlagen.md).
