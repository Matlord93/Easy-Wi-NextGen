# Monitoring-Optimierung und PHPStan-Migrationsklassen

Prüfung vom 13. November 2026, aufbauend auf Commit `085b6109`.

## PHPStan

Die Migrationstests instanziieren Klassen aus dem Namespace `DoctrineMigrations`.
Diese liegen außerhalb des Composer-Entwicklungsautoloaders. PHPStan durchsucht
jetzt `migrations` über `scanDirectories`, während `src` und `tests` weiterhin
analysiert werden. Die Klassen werden erkannt, ohne Befunde zu unterdrücken oder
den Produktionsautoloader zu ändern. `composer run phpstan` besteht.

## Messwertverläufe

Für neue Messungen entstehen Rohdaten und Stundenstatistiken atomar in derselben
Transaktion. Duplikate zählen einmal; fehlgeschlagene Archivschreibvorgänge rollen
auch die Rohdatenschreibvorgänge zurück. Archive enthalten gewichtbare Anzahlen,
Mittelwerte, Minimum, Maximum und Quellen. Fehlende Messgrößen bleiben unbekannt.

Abfragen über mehr als 24 Stunden verwenden vollständige Stundenarchive. An den
Zeitraum- und Besitzgrenzen werden genaue Rohdaten ausgewertet. Fehlende Archive
verwenden ebenfalls Rohdaten und werden durch begrenzte Hintergrundstapel
nachgetragen. Migration `Version20261113030000` ist vor Einsatz erforderlich.

Eine isolierte MariaDB 10.11 enthielt eine Million synthetische Rohmessungen
zweier Datenströme über ungefähr 347 Tage. Die Anfrage umfasste ein Jahr.
Die PHP-Anwendung benötigte vor jeder Anfrage etwa 20 MiB.

| Verarbeitung | Laufzeit | PHP-Spitzenverbrauch | Berücksichtigte Messungen |
| --- | ---: | ---: | ---: |
| Vollständig gepufferte Rohabfrage | 13,523 s | 239,26 MiB | 1.000.000 |
| Ungepufferte Rohabfrage | 12,555 s | 22 MiB | 1.000.000 |
| Stundenarchive mit genauen Rohdatenrändern | 0,119 s | 22 MiB | 1.000.000 |

Die Langzeitauflösung umfasst jetzt ganze Stunden. Daher ändern sich Zahl und
Grenzen der Diagrammpunkte; die ursprünglichen Messungen werden vollständig
gewichtet. Die Zahlen sind lokale Einzelmessungen und keine Leistungszusage
für einen produktiven Server mit anderen Datenbeständen.

## Prüfungen und Grenzen

- Die vollständige PHP-Suite lief ohne fehlgeschlagene Tests: 1632 Tests und
  6952 Assertions, mit drei Warnungen, 301 PHPUnit-Hinweisen und zwölf Skips.
  Die externen E-Rechnungsvalidatoren waren für diesen Lauf eingeschaltet.
- Zusätzliche Archivtests prüfen genaue Zeitränder, Kundenbesitzgrenzen,
  Datenquellen, gewichtete Mittelwerte, echte Nullwerte, Duplikate, Rollback,
  Aufbewahrung und wiederaufnehmbare CLI-Stapel.
- Die neue MariaDB-Tabelle stimmt mit dem ORM-Modell überein. PDO-Puffermodus
  wird nach der Anfrage wiederhergestellt; weitere Abfragen funktionieren.
- Acht parallele MariaDB-Schreiber erzeugten aus 160 Schreibversuchen genau
  zwei unterschiedliche Rohmessungen und zwei gezählte Archivmessungen.
- Chromium prüfte 48 Admin-/Kundenansichten in Deutsch/Englisch, Hell/Dunkel
  und Desktop/Mobil sowie 24 Zeitraumabfragen ohne JavaScript-Fehler.
  Eine zusätzliche Prüfung mit simulierter Browserzeit bestätigt die langsamere
  Jahresaktualisierung, manuelle eigene Zeiträume und pausierte versteckte Seiten.
- Die geänderten PHP-Dateien bestehen eine explizite PSR-12-Prüfung. Das bisherige
  `composer run cs:check` besitzt keine Repository-Konfiguration und startet mit
  der installierten Fixer-Version einen Konfigurationsassistenten. Das ist kein
  belastbarer globaler Stilcheck; automatisch erzeugte Dateien wurden entfernt.

Die Tests verwenden isolierte Daten und ersetzen keine Live-Abnahme von
Proxmox, KVM, VNC, echten Scheduler-Diensten oder produktiven Rechnungen.
