# Adminoberfläche und Designwechsel

Stand: 12.11.2026. Änderungen am bestehenden EasyWI-Panel, keine Neuentwicklung.

## Ergebnis

Alle Seiten, die das gemeinsame Adminlayout nutzen, erhalten außerhalb des Dashboards ein einheitliches Arbeitslayout: ruhigere Karten, klar erkennbare Aktionen, lesbare Formulare, deutliche Fokusmarkierungen und eine durchsuchbare Navigation. Aktive Navigationsgruppen öffnen sich beim Seitenaufruf. Breite Tabellen scrollen innerhalb ihres Bereichs; das Aktionsmenü der Benutzerverwaltung bleibt darüber erreichbar. Auf schmalen Bildschirmen werden Formularspalten untereinander angeordnet.

Unter `/admin/getting-started` gibt es eine Einrichtungsübersicht mit Sprunglinks und 22 Themenbereichen. Die einzelnen Seiten zeigen eine passende, aufklappbare Hilfe mit drei Schritten und Links auf bestehende Einstellungsseiten. Deaktivierte Module werden in der Übersicht gekennzeichnet. Die Übersicht verändert keine Konfiguration und führt keine Infrastrukturaktionen aus. Texte sind auf Deutsch und Englisch vorhanden; die Hinweise für TeamSpeak 6 und SinusBot führen in den jeweils passenden Bereich.

Der Designwechsel wird vor der Darstellung aus dem Browserspeicher geladen. Die Auswahl bleibt nach Neuladen und Seitenwechsel erhalten und wird zwischen Tabs synchronisiert. Bei gesperrtem Browserspeicher funktionieren Schalter und Navigation weiterhin im aktuellen Tab. Native Formularfelder erhalten ebenfalls den passenden Farbmodus. Feste dunkle Farbwerte älterer Vorlagen sowie Überschreibungen durch das Nexus-Theme wurden für den Adminbereich berücksichtigt.

Layout und Inhalt des Dashboards bleiben unverändert. Dort werden weder das neue Arbeitslayout noch die Einrichtungshilfe oder Menüsuchfunktion geladen. Die gemeinsame Korrektur des Designwechsels gilt auch dort. Kundenbereich und öffentliche Seiten erhalten die neuen Admin-Styles und den neuen Designmanager nicht.

## Zusätzlich behobener Bedienfehler

Im CMS-Seitenformular wurde beim Speichern per HTMX der Container `#cms-page-form` durch eine Antwort ohne denselben Container ersetzt. Die Tabelle reagierte außerdem nicht auf `cms-pages-changed`. Die Formularantwort enthält jetzt dauerhaft ihren Zielcontainer; die Tabelle aktualisiert sich nach Änderungen. Zurückgegebene Validierungsfehler werden für dieses Formular angezeigt. Wiederholtes Anlegen von Entwürfen funktioniert ohne vollständiges Neuladen. Bestehende Schreibrechte, Routen und Datenvalidierung bleiben erhalten.

## Prüfung

Die Browserprüfungen liefen in Chromium mit einer isolierten SQLite-Vorschau und synthetischen Konten. Proxmox-Antworten wurden simuliert. Es wurden keine Daten auf `ameserver` verändert.

- 47 Hauptseiten aus der tatsächlichen Adminnavigation in Hell und Dunkel: 94 Aufrufe mit HTTP 200, ohne JavaScript-Fehler und ohne horizontalen Seitenüberlauf am Desktop. Das Dashboard wurde dabei auf Ausschluss des neuen Arbeitslayouts geprüft.
- 46 überarbeitete Hauptseiten bei 390 Pixel Breite: kein Seitenüberlauf und keine seitlich abgeschnittenen sichtbaren Formularfelder außerhalb der scrollbaren Tabellen.
- Zusätzlich 26 Formularseiten und Einstellungsreiter mit aktivem Nexus-Theme in beiden Modi am Desktop sowie 35 Seiten auf schmalem Bildschirm geprüft. Keine Lade- oder JavaScript-Fehler und kein Seitenüberlauf.
- Nexus: zehn Hauptseiten in beiden Modi geprüft. Gefundene Kontrastprobleme bei Überschriften und Modulstatus korrigiert und erneut geprüft. Die automatischen Kontraststichproben erfassen Überschriften, Beschriftungen, Tabellenköpfe, Schaltflächen und Statusbadges; sie sind keine vollständige Barrierefreiheitszertifizierung.
- Neun Interaktionsabläufe mit beiden Panel-Themes geprüft: Designspeicherung, Synchronisierung zwischen Tabs, Menüsuche, Einstellungsformular speichern, CMS-Entwurf speichern, Validierung und wiederholtes Speichern, Tabellenaktionen mit Escape, mobile Navigation/Aktionsmenüs sowie blockierter Browserspeicher. Zusätzlich Wechsel von eingeklappter Desktopnavigation zur mobilen Ansicht und aktive Menüfarben geprüft.
- Sieben neue PHP-Tests mit 630 Assertions erfolgreich. Zusammen mit den bestehenden Phase-11-Regressionstests: zehn Tests, 634 Assertions erfolgreich.
- 221 Twig-Dateien und beide Übersetzungsdateien syntaktisch gültig; Dependency-Injection-Container und PHPStan für die neuen PHP-Dateien ohne Fehler.

Gesamte PHP-Testsuite unter PHP 8.4.27: 1.463 Tests, 6.034 Assertions, keine fehlgeschlagenen Assertions. Zwei bereits vor dieser Änderung bekannte Testfehler bleiben bestehen, weil die PHP-Redis-Erweiterung in der Prüfumgebung fehlt (`ConsoleStreamDiagnosticsTest`). Außerdem meldet der bestehende Gesamtlauf drei Warnungen, 301 PHPUnit-Hinweise und zehn übersprungene Tests. Der zusätzliche Regressionstest für das Rendern ohne aktuellen Request besteht ebenfalls.

## Übernahme

Keine neue Abhängigkeit, kein Frontend-Build und keine Datenbankmigration erforderlich. Neue öffentliche CSS-/JavaScript-Dateien müssen zusammen mit den PHP- und Twig-Änderungen ausgeliefert werden. Anschließend den Symfony-Produktionscache im installierten Release mit dem dort verwendeten PHP und Benutzer leeren.

Der separate Patch `/workspace/admin-ui-change/admin-oberflaeche.patch` enthält ausschließlich die Änderungen dieses Arbeitsschritts. Er setzt den zu Beginn vorhandenen Quellstand einschließlich der vorherigen KVM-/Konsolenänderungen voraus. Frühere Änderungen werden nicht zurückgesetzt. Vor der Übernahme aus dem Repository-Stamm `git apply --check /pfad/admin-oberflaeche.patch` ausführen.

Die Browser-Screenshots und Prüfprotokolle liegen außerhalb des Repositorys unter `/workspace/admin-ui-change`. Ein Live-Test auf dem HomeServer nach der Auslieferung steht noch aus.
