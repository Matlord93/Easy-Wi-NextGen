# Gameserver-Live-Konsole: Datenweg und Speichergrenzen

Standard ist der integrierte WebSocket-Datenweg:

1. Der Browser öffnet `/console/live/{id}` am vorhandenen Panel-Nginx.
2. Ein privater Nginx-Auth-Unterrequest an `/api/v1/customer/instances/{id}/console/live-authorize` prüft die angemeldete Sitzung, Kundenzuordnung, Origin und Verbindungsrate. Nur der Proxy erhält Agent-Ziel und Berechtigung. Direkte Zugriffe sind auch durch einen separaten privaten Proxy-Schlüssel geschützt.
3. Nginx vermittelt zum bestehenden Agent-Endpunkt `/v1/console/live/{id}`. Die signierte Berechtigung ist an Agent, Instanz, Benutzer und Origin gebunden, nur 30 Sekunden zum Verbindungsaufbau gültig und innerhalb des laufenden Agentprozesses einmal verwendbar. Eine Verbindung läuft höchstens fünf Minuten, bei früherem Sitzungsablauf entsprechend kürzer. Danach verbindet der Browser mit erneuter Rechteprüfung und Cursor weiter. Ein Rechteentzug beendet bereits aufgebaute Verbindungen spätestens innerhalb dieses Zeitfensters.
4. Der Agent bindet die Verbindung an den passenden lokalen Gameserver-Wrapper. Dessen erster Status enthält eine überprüfte Instanzkennung. Er liefert den begrenzten Verlauf und danach neue Prozessblöcke ereignisgesteuert. Ein fremder lokaler Socket wird zurückgewiesen.
5. Der Ausgabekanal ist nur lesbar. Befehle bleiben auf dem bestehenden authentifizierten POST-Endpunkt mit CSRF, Rate-Limit und Audit. Auch die lokale Befehlsverbindung prüft die Instanz und bestätigt die Annahme; eine volle Befehlswarteschlange gilt nicht als Erfolg.

## Harte Anwendungsgrenzen

| Bereich | Grenze |
| --- | --- |
| Prozessblock | höchstens 4 KiB pro Frame |
| Wrapper-Verlauf | höchstens 128 KiB Nutzdaten und 128 Frames pro Instanz |
| Warteschlange je Betrachter | acht Frames; langsame Betrachter werden getrennt |
| Betrachter je Wrapper | 16 |
| Live-Verbindungen je Agent | 128 |
| kurzlebiger Nonce-Speicher je Agent | 512 Einträge; abgelaufene Einträge werden entfernt |
| lokaler Kompatibilitätslog-Snapshot | 256 KiB pro Instanz, danach überschreiben |
| Browser-Verlauf | 128 Ki UTF-16-Codeeinheiten, kein vollständiges Logarchiv |
| Legacy-Logleser | 128 Sitzungen; pro Sitzung 256 KiB Text, höchstens 1.000 Zeilen und etwa 16 KiB pro Zeile |
| Logger vor Fehleraktivierung | 100 gepufferte Meldungen |

Diese Grenzen beschreiben Nutzdaten und Warteschlangen, nicht den gesamten RSS des Agents, des Wrappers oder des Browsers. Betriebssystem-, TLS-, Go- und Bibliotheksdaten kommen hinzu. Der Agent übernimmt weitere Aufgaben mit eigenem Speicherbedarf.

Die Prozessausgabe wird unabhängig von Betrachtern weiter gelesen, damit der Gameserver nicht durch eine volle STDOUT-Pipe blockiert. Nach dem Trennen werden nur die Viewer-Ressourcen freigegeben. Das Spiel bleibt am Leben. Verdeckte Tabs schließen ihre Verbindung und verbinden beim Sichtbarwerden mit Sitzungskennung und Sequenz erneut. Bei überschrittenem Verlauf zeigt der Client eine Lücke an. Fragmentiertes UTF-8 wird zusammengesetzt; ANSI-Steuersequenzen werden für die Textdarstellung zustandsbehaftet entfernt. Zeichnen erfolgt gesammelt mit Browser-Frames, ohne ein Nachladeintervall für die Daten.

Die Legacy-Logleser erneuern ihre Betrachterfrist nur bei tatsächlichem Zugriff. Neue Logausgabe allein hält sie nicht mehr dauerhaft aktiv. Sehr lange, nicht abgeschlossene Zeilen werden begrenzt; ein abgebrochener Leser löst auch einen blockierten Pipe-Schreibvorgang auf. Rate-Limit-Schlüssel sind ebenfalls begrenzt.

## Rollout und Prüfstand

Siehe [Einrichtung und Umstellung](gameserver-live-console-setup.md) und [Prüfbericht](../reports/live-konsole-umsetzung.md). Es wurde kein `ameserver`-Dienst aus dieser Arbeitsumgebung verändert. Cache-Speicher des Betriebssystems wird nicht geleert, und aktive Gameserver werden nicht zur regelmäßigen Speicherpflege neu gestartet.
