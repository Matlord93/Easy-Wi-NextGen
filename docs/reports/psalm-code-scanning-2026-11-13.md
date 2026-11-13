# Prüfung der gemeldeten Psalm-Sicherheitsbefunde

Stand: 13. November 2026. Die Prüfung betrifft die 25 im Chat genannten
Code-Scanning-Meldungen. Bereits vorhandene Monitoring-Änderungen wurden erhalten.

## Änderungen je Befundgruppe

| GitHub-IDs | Maßnahme |
| --- | --- |
| #5029, #5030 | Rechnungsvorlagen werden vor der Twig-Kompilierung als Syntaxbaum geprüft. Nur die erlaubten Tags, Filter und Tests sind zugelassen. Funktionen, Methodenaufrufe, Includes, Vererbung und große Bereichsausdrücke werden abgewiesen. Die frische Twig-Umgebung behält ihre ständig aktive Sandbox und HTML-Escapes für Rechnungsdaten. |
| #30 | Gemeinsamer Prozessstarter für YouTube-Suche und Auflösung: Argumentvektor ohne Shell, feste Laufzeit und begrenzte Ausgabe. `--` trennt Ziele von Optionen; `--ignore-config` verhindert fremde yt-dlp-Konfigurationsoptionen. Temporäre Cookie-Dateien werden auch bei Fehlern entfernt. |
| #43 | Agent-Einstellungen benötigen Admin-Rechte und einen passenden CSRF-Token. Die Verbindungsdiagnose akzeptiert ausschließlich HTTP(S)-Adressen mit geprüftem Host und Port, ohne Zugangsdaten, Query oder Fragment. Der Socket-Transport wird aus festen Werten gebildet. |
| #39–#42 | Upload- und Löschpfade werden kanonisch auf die zugehörige Instanz beziehungsweise den Kundenbereich begrenzt. Traversal, fremde Kundenpfade und Symlinks aus dem Speicherbereich werden abgewiesen; bestehende Elternverzeichnisse werden auch vor Neuanlagen geprüft. Reguläre absolute Upload-Pfade können wieder korrekt gelöscht werden. |
| #37, #38 | Radio-Playlists und Metadaten verwenden `NoPrivateNetworkHttpClient`. Dieser prüft und bindet DNS-Adressen vor Verbindungen und bei Weiterleitungen. PHP-/Datei-URLs und private/reservierte Ziele werden blockiert. Playlists sind auf 1 MiB begrenzt; Metadatenabrufe puffern keinen vollständigen Audiostream. XSPF-Dateien mit DTD werden abgewiesen. |
| #33–#36 | Lokale Konfigurationsdateien werden nur innerhalb des Instanzverzeichnisses gelesen. Wrapper, Netzwerkpfade und Symlink-Ausbrüche werden abgewiesen; Lesezugriffe sind auf 256 KiB begrenzt. Windows-Instanzpfade werden auf einem Linux-Panel nicht als relative lokale Dateien geöffnet. |
| #31, #32 | Der konfigurierte SFTP-Schlüsselpfad muss eine absolute lokale Datei sein. Keine HTTP-/PHP-Streams; maximal 64 KiB Schlüsselinhalt. Die bestehende Prüfung des SSH-Hostschlüssels bleibt vorgeschaltet vor der Anmeldung. |
| #53, #54 | Fehlermeldungen zu Subdomains und Portbereichen werden vor HTML-Ausgabe kontextgerecht maskiert. |
| #47–#50 | Musicbot-Backups werden als JSON-Attachments mit `nosniff` und privatem Cacheverbot ausgeliefert. Die JSON-Kodierung maskiert HTML-Sonderzeichen, ohne die dekodierten Backup-Daten zu ändern. |
| #46, #51, #52 | SQL-Downloads und Console-SSE verwenden einen ausdrücklich typisierten Stream. Der Stream verweigert HTML-Ausgabe und fehlendes `nosniff`. SQL-Anführungszeichen bleiben unverändert; SSE kodiert Nutzdaten als JSON, begrenzt Ereignisnamen und behandelt ungültiges UTF-8. |

Die ursprünglichen Einstufungen beweisen für sich keine erfolgreiche Ausnutzung.
Insbesondere waren Twig-Sandbox, CLI-Argumentvektoren und mehrere korrekte
Antworttypen bereits vorhanden. Der Ausbau ergänzt überprüfbare Grenzen und
behandelt die tatsächlichen Ausgabekontexte ausdrücklich.

## Eng begrenzte Analysegrenzen

- Ausschließlich die durch Parser und Positivliste geprüfte Rechnungssprache
  verliert ihre `eval`-Taint-Markierung. HTML-Daten werden dadurch nicht freigegeben.
- `ConfinedLocalPath` entfernt Datei-/URL-Markierungen erst nach Prüfung eines
  nativen Pfads unter einer vom Aufrufer verwalteten Wurzel. Die Speicherwurzel
  stammt aus Konfiguration beziehungsweise der bereits autorisierten Ressource,
  nicht aus einem frei wählbaren Request-Parameter.
- Der Agent-Socket ist eine mit Admin-Recht und CSRF geschützte Betriebsfunktion.
  Private IP-Adressen sind dort ausdrücklich vorgesehen, etwa für HomeServer.
  Deshalb wird diese eng begrenzte Diagnose als autorisierter Netzwerkzugriff
  modelliert; die öffentliche Radio-Funktion besitzt diese Ausnahme nicht.
- Genau am privaten Schreibaufruf von `TypedStreamResponse` werden
  `TaintedHtml` und `TaintedTextWithQuotes` als kontextbedingte Fehlalarme
  eingegrenzt. Laufzeitprüfungen erlauben nur SQL beziehungsweise Event-Streams
  und erzwingen `nosniff`. HTML-Escapes an diesem Punkt würden gültige SQL- und
  JSON-Daten beschädigen. Es gibt keine globale Unterdrückung von Taint-Befunden.

## Verifikation

- PHPStan: keine Fehler über 1618 analysierte Dateien.
- Vollständige PHP-Regression: 1719 Tests, 7091 Assertions, keine Fehler oder
  Fehlschläge; drei Warnungen, 301 PHPUnit-Hinweise und zwölf Skips. Die externen
  E-Rechnungsvalidatoren waren eingeschaltet. Der danach ergänzte SSE-Controller-
  Test und zusätzliche Multicast-Weiterleitungsfälle wurden separat geprüft.
- Gezielte Regression: 139 Tests, 280 Assertions, keine Fehler oder Fehlschläge;
  16 PHPUnit-Hinweise.
- Zusätzlich ist der Console-Controller mit echtem SSE-Response-Callback,
  Replay, Nutzdatenkodierung und Freigabe des Subscribers geprüft.
- Die abschließenden zusätzlichen Randfälle bestehen separat: 51 Tests und
  91 Assertions, einschließlich Multicast-Weiterleitungen und Header-Injektion.
- Lokale Psalm-7.4-Taint-Analyse über `core/src`: keine `Tainted*`-Befunde.
  Ein zusätzlicher Lauf führt Request-Daten explizit in die Rechnungs-, YouTube-
  und Radio-Einstiegspunkte. Er meldet ausschließlich die zwei erwarteten Befunde
  an einer absichtlich unsicheren Kontrollausgabe außerhalb des Repositorys.
- Die lokale Psalm-Konfiguration ist eine eigenständige Analysekonfiguration,
  kein Nachweis eines vollständig grünen Psalm-/GitHub-Workflows. Neben den
  Taint-Prüfungen meldet sie weiterhin 7487 Typ- und Unused-Code-Diagnosen unter
  dieser eigenen Konfiguration; der gesamte Psalm-Lauf ist dadurch nicht
  fehlerfrei. Diese Standarddiagnosen wurden außerhalb der genannten
  Sicherheitspfade nicht pauschal überarbeitet.
  Die vorhandenen GitHub-Meldungen werden erst durch erneute Analyse des
  aktualisierten Commits neu bewertet; sie wurden nicht manuell geschlossen.

## Betrieb und Prüfgrenzen

SFTP-Schlüsseldateien benötigen jetzt einen absoluten lokalen Pfad. UNC-Pfade
und Stream-Wrapper sind absichtlich ausgeschlossen. Verwaltete Speicherwurzeln
müssen weiterhin durch passende Betriebssystemrechte geschützt sein; die Tests
sind kein Nachweis gegen beliebige gleichzeitige Änderungen durch einen lokalen
privilegierten Benutzer.

Die PHP-Änderungen am Radio-Resolver schützen dessen Panel-Abrufe. Die eigentliche
Audiowiedergabe auf dem Node und echte Linux-/Windows-Hosts wurden hier nicht live
geprüft. Die Tests verändern keine produktiven Zahlungen, VMs oder Serverdaten.
