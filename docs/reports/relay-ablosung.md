# Gameserver-Konsole ohne separaten Relay-Dienst

Untersuchung vom 12.11.2026. Gemeint ist `easywi-console-relay`, nicht das VM-Konsolen-Gateway. Ergebnis: Der separate PHP-Relay lässt sich durch einen bedarfsgesteuerten Konsolenweg im vorhandenen System ersetzen. Die folgenden Änderungen sind ein konkreter Umsetzungsvorschlag; die Laufzeitkonfiguration und Dienste wurden bei dieser Untersuchung nicht umgestellt.

Die inzwischen umgesetzten Änderungen und ihr Prüfstand stehen im [Umsetzungsbericht](live-konsole-umsetzung.md). Dieser Text hält die Untersuchung des vorherigen Zustands fest.

## Verbindliche Anforderung: echte Live-Ausgabe

Die Konsole soll neue Ausgabe unmittelbar übertragen. Polling mit einem festen Nachladeintervall erfüllt diese Anforderung nicht und ist deshalb nicht der empfohlene Standard. Es soll keine künstliche Wartezeit durch einen Abfragezyklus oder das Sammeln vollständiger Logzeilen entstehen. Normale Netzwerk-, Prozess- und Darstellungszeiten bleiben technisch unvermeidbar.

## Empfehlung: WebSocket im bestehenden Go-Agent

Der bestehende Agent erhält einen echten WebSocket-Endpunkt. Er schiebt neue Ausgabe direkt nach dem Lesen von STDOUT/STDERR beziehungsweise PTY/ConPTY an die verbundenen Browser. Der vorhandene HTTPS-Reverse-Proxy vermittelt die Verbindung. PHP prüft beim Verbindungsaufbau die Kundenberechtigung und stellt eine kurzlebige, auf Node, Instanz und erlaubte Aktionen begrenzte Berechtigung aus; PHP hält den Ausgabestream nicht offen. Befehle können weiterhin über den bestehenden authentifizierten POST-Endpunkt mit CSRF-Schutz und Protokollierung unmittelbar zum Agent gesendet werden.

Damit entfällt der zusätzliche PHP-Dienst `easywi-console-relay`. Für den Konsolentransport werden weder dessen Redis-Pub/Sub-Zwischenschritt noch ein dauerhaft belegter PHP-FPM-Prozess pro Browser benötigt. Redis kann für andere Aufgaben bestehen bleiben. Ein direkt in PHP weitergereichter SSE-Stream wäre für das RAM-Ziel ungünstig, weil er den PHP-Prozess für die Dauer der Verbindung belegt.

Die Umsetzung muss folgende Eigenschaften haben:

- Ausgabe als verfügbare Byte-Blöcke senden, ohne auf Zeilenende oder ein Polling-Intervall zu warten. Auch Fortschrittsausgaben ohne abschließendes Newline müssen live erscheinen.
- Ein gemeinsamer Prozessleser pro Instanz und Verteilung an mehrere Zuschauer; kein eigener Leser beziehungsweise gestarteter Gameserver pro Browser.
- Nach Bytes begrenzter Verlauf und begrenzte Warteschlangen pro Verbindung; zusätzlich globale Grenzen und Zeitlimits für langsame Clients. Bei Überlauf einen sichtbaren Hinweis beziehungsweise kontrollierten Reconnect statt unbegrenzter Speicherbelegung vorsehen.
- Ablauf des Zugriffstickets, Origin-Prüfung, Kunden-/Instanzbindung, TLS-Routing, Verbindungsgrenzen und Widerruf bei Rechteänderungen absichern. Der Browser erhält keinen Agent-Hauptschlüssel.
- Beim Schließen der Konsole nur deren Verbindung und Abonnement freigeben. Der Gameserverprozess läuft weiter. Die Betrachterfrist wird durch Betrachteraktivität erneuert, nicht durch neue Gameserver-Ausgabe.
- Sequenz und Sitzungskennung für Wiederverbindung verwenden; der begrenzte Verlauf dient dem kurzen Wiederanschluss und ist kein vollständiges dauerhaftes Logarchiv.
- Persistente Logs auf einem Datenträger rotieren. Unter Linux gehört ein wachsendes `console.log` nicht dauerhaft nach `/run`, das häufig auf einem RAM-Dateisystem liegt.
- Die Einrichtung des WebSocket-Routings in den Installer integrieren und die automatische Aktivierung des alten Relay-Diensts entfernen. Ein separates neues Gateway wäre für diese Gameserver-Lösung nicht erforderlich.

### Wiederverwendbare Bausteine und noch fehlende Integration

`agent/internal/ptyconsole/manager.go` enthält bereits einen direkten Leser mit 4-KiB-Blöcken, einen auf 128 KiB begrenzten Ausgabeverlauf und begrenzte Abonnentenkanäle. `readLoop()` verteilt einen Block sofort nach einem erfolgreichen Read. Diese Bausteine sind derzeit jedoch nicht an den produktiven HTTP-Gameserverpfad angeschlossen; der Paketname oder seine Existenz belegt keine bereits funktionierende WebSocket-Konsole.

Der aktuelle HTTP-Pfad verwendet stattdessen `consoleSession` und den Logdateileser. `startLogFileStream()` fragt die Datei bei EOF mit 100 ms Wartezeit erneut ab und liefert nur vollständige Zeilen. Diesen Weg lediglich mit einem WebSocket zu ummanteln würde die interne Verzögerung beibehalten. Für die geforderte Live-Konsole muss die Ausgabe daher aus dem Prozess-/Wrapper-Lesepfad ereignisgesteuert an den Agent gelangen. Bereits unter einem separaten Wrapper laufende Spiele müssen dafür über eine passende lokale Verbindung angebunden werden; ein Browser-Reconnect darf sie nicht neu starten.

Dies ist die aktualisierte Architekturentscheidung nach der ausdrücklich genannten Live-Anforderung. Zum Zeitpunkt dieser Untersuchung waren WebSocket-Pfad und Windows-Backend noch nicht implementiert; der aktuelle Stand ist im verlinkten Umsetzungsbericht beschrieben.

## Im vorhandenen Code belegte Probleme

### Unbegrenzter Logger-Puffer

`core/config/packages/monolog.yaml` konfiguriert `fingers_crossed` mit Aktivierung ab `error`, ohne `buffer_size`. Der installierte Monolog-Handler verwendet dafür standardmäßig einen unbegrenzten Puffer. Der Relay läuft dauerhaft und protokolliert Verbindungsfehler auf Warnstufe; einen regelmäßigen Logger-Reset gibt es nicht.

Isolierter Test mit der installierten Bibliothek, 50.000 synthetische Warnungen ohne auslösendes Error-Ereignis:

| Konfiguration | Aufbewahrte Meldungen | Zusätzlicher PHP-Heap |
| --- | ---: | ---: |
| Puffer unbegrenzt | 50.000 | 81.368.288 Bytes, etwa 77,6 MiB |
| Puffer auf 100 Meldungen begrenzt | 100 | 203.832 Bytes, etwa 0,19 MiB |

Das belegt einen möglichen Wachstumspfad im eingesetzten Logger. Es ist keine Messung des tatsächlichen RSS oder der alleinigen Ursache auf `ameserver`. Wiederholte Warnungen können diesen Pfad auslösen, solange der Handler puffert. Symfony beschreibt das Problem bei lang laufenden Prozessen ebenfalls: [Logging – Handling Logs in Long-Running Processes](https://symfony.com/doc/current/logging.html#handling-logs-in-long-running-processes).

Bis zur Ablösung empfiehlt sich ein begrenzter Logger-Puffer, regelmäßiges Freigeben des Zustands im Worker und Start ohne Debugmodus. Ein Speicherlimit mit Neustart kann zusätzlich als Rückfallgrenze dienen; allein behebt es den Wachstumspfad nicht. Das sollte als eigener kleiner Fix geprüft werden, weil die Logging-Konfiguration auch andere Prozesse betrifft.

### Relay verarbeitet einen endlosen Stream seriell

`ConsoleRelayCommand::execute()` ruft `relayInstance()` nacheinander auf. Darin läuft `attachStream()` des HTTP-Clients endlos. Die Abonnentenzahl wird nur vor diesem Stream geprüft, nicht während der Ereignisse. Bei einer gesunden Verbindung kann deshalb die erste Instanz den Worker dauerhaft belegen; das Schließen des Browserfensters beendet diese Verarbeitung nicht zuverlässig.

Im begrenzten Nachweis lieferte ein synthetischer Stream drei Ereignisse. Der letzte Abonnent verschwand nach Ereignis eins. Trotzdem wurden noch beide folgenden Ereignisse veröffentlicht. Im produktiven Client ist die Ereignisquelle im Gegensatz zu diesem Test nicht auf drei Ereignisse begrenzt.

### Agent-Frist und Speichergrenzen

`consoleSession.appendLine()` aktualisiert `lastAccess` bei jeder neuen Logzeile. `cleanupLoop()` verwendet denselben Wert für die Inaktivitätsfrist. Ein Gameserver, der regelmäßig schreibt, kann dadurch den Leser auch ohne Betrachter dauerhaft erhalten.

Zwei zusätzliche Go-Prüfungen mit dem unveränderten Agent-Code bestätigen:

- Neue Ausgabe erneuert die vermeintliche Betrachterfrist ohne Betrachter.
- 1.000 aufbewahrte Zeilen mit jeweils 16 KiB halten 16.384.000 Bytes reinen Text, etwa 15,6 MiB, pro Sitzung. Verwaltungsdaten und Antwortkopien kommen hinzu.

Außerdem sammelt `startLogFileStream()` unvollständige Zeilen in `pending`, bis ein Zeilenende kommt. Dafür fehlt eine Byte-Grenze. Im Wrapper wird das Konsolenlog aktuell unter dem Runtime-Pfad ohne Rotation geschrieben. Diese Agent-/Dateisystemeffekte sind vom PHP-Relay-RAM zu unterscheiden und müssen bei einer sparsamen Lösung ebenfalls berücksichtigt werden.

## Linux und Windows

Der WebSocket-Transport lässt sich für beide Plattformen einheitlich verwenden. Die lokale Anbindung an den Gameserver ist jedoch noch nicht vollständig plattformübergreifend:

- **Linux:** vorhandenen Wrapper beziehungsweise STDIN/STDOUT und bei Bedarf PTY verwenden; Datei-Ausgabe als Alternative zu `journalctl`. Ein verpflichtendes systemd/journalctl wäre keine Lösung für alle Linux-Installationen.
- **Windows:** prozessgebundene STDIN/STDOUT-Pipes für kompatible Gameserver; für echte Terminalprogramme ein ConPTY-Backend. Für lokale Befehle ist eine geeignete Windows-IPC-Anbindung nötig. Ein bereits extern gestarteter Windows-Dienst lässt sich nicht allein durch den Wechsel des Netzwerktransports mit einer neuen STDIN-Pipe versehen; der Prozess muss durch den passenden Wrapper gestartet oder über ein unterstütztes Spielprotokoll angesprochen werden.

Der bestehende `ptyconsole.Manager` meldet unter Windows ausdrücklich `non_interactive_logs_only` und verweigert PTY-Starts. Auch der aktuelle HTTP-Konsolenpfad verwendet Linux-Runtime-Pfade und Unix-Sockets. Daher wäre die Aussage „funktioniert bereits vollständig auf allen Windows-Systemen“ falsch.

Microsoft nennt für `CreatePseudoConsole` mindestens Windows 10 Version 1809 beziehungsweise Windows Server 2019: [CreatePseudoConsole](https://learn.microsoft.com/en-us/windows/console/createpseudoconsole). Für ältere Systeme und Anwendungen ohne Terminalbedarf ist ein Pipe-/protokollbasierter Weg erforderlich. Welche Spiele das unterstützen, muss separat getestet werden. Für den WebSocket-Transport dokumentiert Nginx die nötige Weitergabe der Upgrade-Header und die Behandlung von Leerlaufverbindungen: [WebSocket proxying](https://nginx.org/en/docs/http/websocket.html).

## Sichere Umstellung und Abnahme

1. Logger-Puffer als kurzfristige Entlastung begrenzen und Ausgangswerte auf dem Server erfassen.
2. Den echten WebSocket-Pfad mit Ticketprüfung und Proxy-Routing implementieren und an die direkte Prozessausgabe anschließen. Browser, Health-Anzeige und Diagnose auf diesen Pfad umstellen; keine regelmäßigen Polling- oder Relay-Verbindungsversuche im gewollten Live-Modus.
3. Agent-Puffer nach Bytes begrenzen, Konsolenabonnements nach tatsächlicher Betrachteraktivität beenden und Logs rotieren. Das Schließen eines Abonnements darf den Gameserver nicht beenden. Windows-Anbindung ergänzen und auf echten Windows-Hosts testen.
4. Installer auf den integrierten Konsolenweg umstellen. Er ruft heute `write_panel_console_relay_service()` auf und würde den alten Dienst sonst erneut aktivieren.
5. Erst mit diesem Stand auf einer eigenen Testinstanz Ausgabe, Befehle, Installation, Reconnect, Neustart und mehrere gleichzeitige Konsolen testen. Danach ausschließlich `easywi-console-relay` deaktivieren. Redis, Messenger und VM-Konsolen-Gateway sind davon unabhängige Komponenten und werden nicht pauschal entfernt.
6. Live-Latenz vom erzeugten Prozessblock bis zum Browser messen, ausdrücklich auch ohne Zeilenende. Messung mit null, einer und vielen Konsolen sowie getrennten Clients; zusätzlich hohe Ausgabe, sehr lange Zeilen, Agent-Ausfall und langsame Clients. RAM muss nach Schließen der Konsolen wieder auf ein stabiles Niveau zurückgehen. Rohpuffergrenzen sind keine Zusage für den gesamten RSS des Agents, der weitere Aufgaben ausführt.

Lesende Ausgangsmessung auf dem Linux-Panelhost:

```bash
systemctl show easywi-console-relay.service -p MainPID -p MemoryCurrent -p MemoryPeak -p ExecStart
systemctl cat easywi-console-relay.service
ps -C php -C php8.5 -o pid,etimes,rss,vsz,args --sort=-rss
findmnt /run
```

Konfigurationsausgaben können Zugangsdaten enthalten; vor dem Weitergeben prüfen. Messungen auf `ameserver`, Windows-Laufzeittests und eine Produktionsumstellung wurden hier nicht durchgeführt.

Die reproduzierbaren lokalen Nachweise liegen unter `/workspace/relay-analysis`: `log-buffer.php`, `log-unbounded.json`, `log-bounded.json`, `relay-subscriber.php`, `relay-subscriber.json`, `console_retention_test.go`, `go-overlay.json` und `agent-retention.log`. Der Go-Overlay-Test ergänzt nur die Testkompilierung; er verändert keine Agent-Quelldatei im Repository.
