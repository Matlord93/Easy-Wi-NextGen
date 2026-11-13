# Validierung des Rechnungsausbaus · 12.11.2026

Basis: `eedaf0969d80363c8ae3af62d74164ae250eb4a1`. Der HomeServer wurde nicht verändert.

| Prüfung | Ergebnis |
|---|---|
| Neue Betrags-, Workflow- und HTTP-Tests | 35 Tests, 116 Assertions, erfolgreich |
| PHPStan, Projektkonfiguration/Stufe 1 | Keine Fehler |
| PHP-Syntax der geänderten/neuen PHP-Dateien | Keine Fehler |
| Twig-, YAML-, Übersetzungs- und Containerprüfung | Erfolgreich |
| Composer-Validierung | Erfolgreich |
| Gesamte PHPUnit-Suite | 1.357 Tests, 4.934 Assertions; keine Failures, zwei bekannte Redis-Umgebungsfehler, drei Warnungen, 297 bestehende Notices, zehn Skips |
| Upgrade auf isolierter MariaDB 10.11 | 39 bestehende Migrationen und die neue Rechnungsmigration erfolgreich |
| Doctrine-Schema nach Upgrade | Synchron; kein ausstehendes SQL-Diff |
| Historischer „bezahlt“-Datensatz | Status durch neue manuelle Ledgerbuchung erhalten |
| MariaDB-Buchungsablauf | Teilzahlung, doppelte Referenz, Restzahlung, Gutschrift, Storno und persistente Historie erfolgreich |
| Migrationsabbruch bei Duplikaten | Reproduziert; beide bestehenden Datensätze bleiben erhalten |
| PDF | Echte PDF erzeugt, Text einschließlich Umlauten ausgelesen und gerenderte Seite visuell geprüft |
| Abhängigkeiten-Audit | 19 bereits bekannte Meldungen in zwölf Symfony-Paketen; keine neu hinzugekommenen Advisory-IDs gegenüber dem Repository-Audit |

Die zwei Fehler der Gesamtsuite entstehen in `ConsoleStreamDiagnosticsTest`, weil `ext-redis` in dieser Cloud-PHP-Umgebung fehlt. Es wurden keine neuen Fehler dieses Typs eingeführt. Die gezielten Rechnungstests wurden nach den letzten Anpassungen erneut erfolgreich ausgeführt.

Die Tests verwenden isolierte Datenbanken, einen simulierten Erinnerungsversand und signierte Stripe-Testnachrichten bzw. einen HTTP-Testclient. Es wurden keine echten Zahlungen oder Kunden-E-Mails ausgelöst. Echtes Stripe, E-Mail-Zustellung, PHP 8.5 auf dem HomeServer und die Übernahme in die laufende Installation stehen als Live-Prüfung aus.

Die vorhandenen Symfony-Sicherheitsmeldungen sind durch den Rechnungsausbau nicht behoben. Sie bleiben ein eigener Arbeitspunkt des Systemplans.

Bedienung, Migrationshinweise und Live-Testablauf: [Rechnungssystem](../operations/billing.md).
