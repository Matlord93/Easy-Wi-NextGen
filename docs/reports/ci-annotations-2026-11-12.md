# Psalm- und CodeQL-Annotationen vom 12. November 2026

## Änderungen

- `GdprMenuProvider::adminMenuItems()`, `customerMenuItems()` und `RedisExtensionCheckPass::process()` deklarieren ihre Implementierung des Interface-Vertrags mit `#[\Override]`.
- `GdprMenuProvider` ist als von Symfony geladener Service-Einstiegspunkt dokumentiert. Der Konstruktor von `ExtensionMenuRegistry` ist als Einstiegspunkt für die Injection der getaggten Provider gekennzeichnet.
- Die drei Erweiterungsereignisse `JobBeforeDispatchEvent`, `JobAfterResultEvent` und `BillingBeforeAutomationEvent` dokumentieren ihre öffentlichen Payloads für externe Listener mit `@psalm-api`.
- Der ungültige Parameter `fail-on` wurde aus dem CodeQL-Analyze-Schritt entfernt. Die Eingaben der referenzierten Action unterstützen diesen Parameter nicht. Die zwei Warnungen betreffen dieselbe Action in Ausführung und Nachbereitung.
- Die Ubuntu-Mitteilung ist ein Hinweis zum Wechsel des GitHub-Runner-Images. Sie ist kein Fehler im Anwendungscode. Runner können weiterhin über `CI_RUNNER_LABELS` gewählt werden.

Die öffentlichen Ereignisdaten und Menüfunktionen werden weiterhin benötigt. Ihre dynamische Nutzung durch den Symfony-Container, Twig und externe Erweiterungen ist für Psalm ohne Kennzeichnung nicht vollständig sichtbar.

## Prüfung

- PHP-Syntaxprüfung der sechs geänderten PHP-Dateien erfolgreich.
- Vorhandene Extension- und Kernel-Tests: zusammen 11 Tests, 15 Assertions erfolgreich.
- Symfony `lint:container --env=test` erfolgreich.
- Im gebooteten Test-Container sind beide Admin-Menüeinträge und alle drei Kunden-Menüeinträge über den getaggten Provider, die Registry und die Twig-Funktion verfügbar.
- Workflow-YAML erfolgreich gelesen; der ungültige Parameter ist entfernt. Abgleich mit den veröffentlichten Eingaben von `github/codeql-action/analyze@v4.39.3` erfolgt.
- Vollständiger Psalm-Vergleich mit Psalm 7.3.1 und `--taint-analysis --no-cache --threads=2`: vorher 11.856, nachher 11.844 Meldungen. In den sechs bearbeiteten PHP-Dateien sinkt die Zahl von 11 auf 0. Zusätzlich entfällt die Meldung zum Konstruktor von `MenuItem`. Es entstehen keine neuen Meldungen.

## Offene Befunde und Grenzen

Die vom Nutzer geteilte Annotationsansicht zeigt nur einen Ausschnitt: Das verlinkte vollständige Actions-Log enthält bereits **11.658 Psalm-Fehlermeldungen**, darunter **26 Taint-Meldungen**. Der lokale Arbeitsstand enthält zusätzliche vorherige Änderungen und wurde mit PHP 8.4.27 geprüft; im verlinkten CI-Lauf lief PHP 8.5.12. Die Gesamtzahlen sind deshalb nicht direkt gleichzusetzen.

Der lokale vollständige Psalm-Lauf beendet sich weiterhin mit Status 2 und enthält **11.844 weitere Befunde, darunter 27 Taint-Meldungen**. Diese sind mit dieser gezielten Korrektur nicht abgearbeitet. Taint-Meldungen müssen auf tatsächliche Datenflüsse, vorhandene Validierung und Ausnutzbarkeit geprüft werden; die Anzahl allein ist kein Nachweis für entsprechend viele ausnutzbare Schwachstellen.

Im verlinkten CI-Lauf wurde der Job trotz seiner Annotationen als erfolgreich gewertet und `Fail on Psalm findings` übersprungen. Die Ursache dieses abweichenden Statusverhaltens ist noch nicht geklärt. Der bestehende Psalm-Aufruf und seine Statusprüfung wurden in dieser Änderung nicht umgebaut. Ein grüner Job allein belegt deshalb hier keine fehlerfreie Analyse.

Diese Prüfung führt keinen neuen GitHub-Actions-Lauf aus und ersetzt keine Prüfung auf dem HomeServer.
