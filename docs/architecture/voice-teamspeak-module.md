# Gemeinsames Voice-/TeamSpeak-Modul

Die Controller und Anwendungsklassen aus `Module/Teamspeak` sowie die TS3-/TS6-Anwendungsdienste und der Viewer-Normalisierer aus `Module/Core/Application` liegen unter `Module/Voice`. Die bestehenden TS3-/TS6-Dienste bleiben für ihre jeweiligen Protokolle und Agent-Payloads zuständig. Die allgemeine Voice-Oberfläche verwendet diese Dienste über `TeamspeakServerOperationsInterface`.

## Bestehende Panel-Daten und Funktionen

| Bereich | Maßgebliche vorhandene Daten / Ausführung |
| --- | --- |
| TS3-Node | `Ts3Node`: Agent, Query-Ziel/Port, Admin-Zugang, Installationspfad, Dienst und Dateitransfer-Port |
| TS6-Node | `Ts6Node`: Agent, Query-Ziel, `queryHttpsPort`, Admin-Zugang, Installationspfad, Dienst und Voice-Port |
| Virtueller Server | Bestehender `Ts3VirtualServer` bzw. `Ts6VirtualServer`, einschließlich Kunde, Node, SID, Ports und Archivierungsstatus |
| Allgemeiner Voice-Eintrag | `VoiceInstance.externalId` bezeichnet die Datenbank-ID des virtuellen Servers, nicht dessen SID; der Provider wird über `VoiceNode` gewählt |
| Start/Stop/Restart | Bestehende `ts3.virtual.action`-/`ts6.virtual.action`-Agent-Jobs mit deren bisherigen Payloads |
| Statusabfrage | Bestehende `ts3.virtual.list`-/`ts6.virtual.list`-Jobs; dadurch werden auch gestoppte Server erfasst |
| Weitere Funktionen | Bestehende Bereitstellung, Tokens, Viewer, Gruppen, Clients, Channels, Bans, Logs, Snapshots, Wiederherstellung, Updates und Docker-Darstellung |

Die Doctrine-Entitäten und Datenbanktabellen behalten ihre Namen. Die Zusammenführung benötigt keine Datenmigration und schreibt keine vorhandenen Zugangsdaten oder Portwerte um. Die bisherigen TS3-/TS6-Modulschlüssel und Aktivierungseinstellungen bleiben erhalten.

Der Name `queryHttpsPort` ist kein Beleg für das tatsächlich verwendete Protokoll: Der vorhandene TS6-Agent-Client versucht SSH und anschließend den vorhandenen TCP-Fallback. Die Zusammenführung verwendet diesen Client unverändert. Die TS3-Ausführung verwendet weiterhin den vorhandenen ServerQuery-Client.

## Gemeinsame Ausführung

`TeamspeakServerOperations::resolve()` löst einen Voice-Eintrag über den vorhandenen Provider und die externe Datenbank-ID auf. Es prüft Kundenzugehörigkeit, Archivierung und die Aktivierung des allgemeinen Voice-Nodes. Ein fremder oder ungültiger Verweis führt zu keiner Serveraktion und liefert keine Serverdetails.

`action()` delegiert an die bestehenden versionsspezifischen Dienste. Gleiche aktive Aktionen werden wiederverwendet; konkurrierende unterschiedliche Aktionen liefern einen Konflikt. Der gemeinsame Pfad verwendet die konfigurierte Symfony-LockFactory, die aktuell `flock` nutzt. Für mehrere Panel-Hosts ist entsprechend der allgemeinen Lock-Konfiguration ein gemeinsamer Lock-Store erforderlich. Die bereits vorhandenen administrativen TS3-/TS6-Dienste behalten ihre bisherigen Abläufe.

Die Voice-Adapter geben die tatsächlich angelegte Agent-Job-ID zurück. Der Kunde erhält keine bloße Erfolgsmeldung ohne ausgeführte beziehungsweise eingereihte Operation. Ein gemessener Voice-Status wird nach dem Einreihen einer Aktion ungültig gemacht. Bestehende allgemeine `voice.action.*`-Jobs im Go-Agent delegieren ebenfalls an die echten TeamSpeak-Handler; alte unvollständige Payloads scheitern ausdrücklich.

`probe()` teilt pro Provider/TeamSpeak-Node eine Abfrage über einen kurzlebigen Job-Verweis im Cache. Ausstehende Jobs liefern HTTP 202 und einen Pending-Status. Erst ein abgeschlossener, dem richtigen Agent und Node zugeordneter Job liefert Messwerte und seinen Abschlusszeitpunkt. Die Auswahl des Servers erfolgt über seine SID. Fehlender Status, unbekannte Spielerzahlen und gescheiterte Jobs werden nicht zu einem Online-Status oder gemessenen Nullwerten umgedeutet.

Die allgemeine und die bisherige Voice-Kundenoberfläche verwenden dieselben Statusabfragen und gemeinsamen Start-/Stop-/Restart-Operationen. Der bisherige Legacy-Probe-Endpunkt kann dadurch jetzt echte Abfragen auslösen. Die Oberfläche wartet begrenzt auf das Ergebnis einer Abfrage.

## Kompatibilität und Rollout

- Sämtliche bisherigen Routennamen, URLs, Methoden, Hosts und Schemes bleiben bestehen. Der Vergleich erfasste 1.246 Routen.
- Die internen PHP-Namespaces der verschobenen Klassen ändern sich zu `App\Module\Voice`. Alle Verweise im Repository wurden angepasst. Eigene Erweiterungen mit direkten Imports alter interner Klassen müssen diese Imports entsprechend aktualisieren.
- Backend-Code und passender Agent sollten gemeinsam aktualisiert werden; der Agent ergänzt die tatsächlich gemessene Spielerzahl in Serverlisten. Bei älteren Ergebnissen bleibt diese Zahl unbekannt.
- Nach dem Deployment die üblichen Composer-Autoload-/Cache-Schritte ausführen und lang laufende PHP-/Messenger-Prozesse mit dem neuen Code starten.
- Vorhandene Datenbanken, Zugangsdaten und Dienste werden durch die Tests nicht verändert. Die Prüfung verwendet isolierte Datenbanken und lokale Protokoll-/Audio-Fixtures. Eine konkrete laufende Kundeninstallation ist damit noch nicht praktisch abgenommen.

## Bereinigte Bestandteile

Der ungenutzte parallele PHP-Aufbau aus `VoiceDriver`, `VoiceProvisioningJobs`, eigenen Server-/Benutzer-/Rechtemodellen, InMemory-Aktionsprotokollen und direkten SSH-/TCP-Query-Transporten wurde entfernt. Eine vollständige Referenzprüfung fand keine produktiven Aufrufer außerhalb dieses Aufbaus. Seine acht ausschließlich darauf bezogenen Testdateien wurden zusammen mit der Komponente entfernt; die Tests der aktiven Panel-/Agent-Abläufe bleiben bestehen.

Ebenfalls entfernt wurden vier nie verwendete Agent-Exception-Klassen, der nicht eingebundene PHP-Docker-Compose-Renderer und der ungenutzte TS3-Update-Wrapper mit seinem `source_not_configured`-Platzhalterprovider. Die vorhandene native TS3-Installation/Aktualisierung über die konfigurierte Download-URL und die aktiven TS6-Update-Prüfungen bleiben bestehen. Ein automatisierter TeamSpeak-Docker-Installationsablauf war durch den einzelnen Renderer nicht implementiert; dieser bleibt ein eigenständiger Roadmap-Punkt.

Die gemeinsamen TS3-/TS6-DTO-Verträge werden in den tatsächlichen Formular-Lookups, DTO-Factories und Erstellungsjobs verwendet. Öffentliche Formularfelder und versionsspezifische Ports beziehungsweise Slots bleiben erhalten. Damit sind auch die sechs zuvor gemeldeten Getter dieser gemeinsamen Core-Verträge eingebunden.

Spielerzahlen stammen ausschließlich aus `VoiceQueryResult` statt aus einer zweiten ungenutzten `getPlayers()`-Projektion. Die vorhandenen generischen Backup-Provider, TeamSpeak-Snapshot-/Restore-Jobs, der persistente AuditLogger, VoiceProbeGuard und ServerQueryLimiter bleiben maßgeblich.

## Node-Status im Panel

Die TS3-/TS6-Node-Seiten bieten eine explizite Aktion „Status aktualisieren“. Sie erfordert einen Administrator, POST und einen gültigen instanzbezogenen CSRF-Token. Sie reiht den bestehenden `ts3.status`- beziehungsweise `ts6.status`-Agent-Job ein; erst dessen Rückmeldung aktualisiert den gemessenen Dienststatus. Die bisherigen Routen bleiben bestehen, zwei POST-Routen für die Statusaktion kommen hinzu.

Die Bereinigung verändert weder die Psalm-Konfiguration noch Baselines oder Diagnose-Unterdrückungen. Der abschließende globale Scan muss für `src/Module/Voice/` null Fundstellen ergeben; die übrigen Module werden davon getrennt weiter bearbeitet.
