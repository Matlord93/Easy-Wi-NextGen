# Live-Konsole und begrenzter Speicherverbrauch

Umsetzung vom 12.11.2026 im bestehenden Repository. Keine Dienste auf `ameserver` wurden aus dieser Arbeitsumgebung geändert.

## Ergebnis

Die Kundenkonsole verwendet einen echten WebSocket im vorhandenen Go-Agent. Prozessausgabe wird unmittelbar in Blöcken übertragen, ohne Polling-Intervall und ohne auf einen Zeilenumbruch zu warten. Der separate PHP-Relay und dessen Redis-Konsolentransport werden für die neue Oberfläche nicht mehr benötigt. PHP bearbeitet nur kurze Authentifizierungs- und Befehlsanfragen.

Der bestehende Nginx übernimmt den Tunnel und fragt vor dem Aufbau einen privaten, versionierten Panel-Endpunkt nach der Berechtigung. Sitzung, Kundenzuordnung, Origin und Rate-Limit werden geprüft. Agent-Ziel und signierte Berechtigung gehen ausschließlich an den Proxy. Der Browser erhält keinen Agent-Schlüssel. Der Agent prüft außerdem Signatur, Zweck, Instanz, Node, Origin, Ablauf und Einmalverwendung. Ein reiner Ausgabekanal kann keine Befehle entgegennehmen; Befehle behalten ihren bestehenden POST-Weg mit CSRF, Audit und Rate-Limit.

Die lokale Prozessanbindung liegt im gemeinsamen Paket `agent/internal/liveconsole`, das sowohl `easywi-agent --wrapper` als auch das Kompatibilitätsprogramm `easywi-wrapper` verwenden. Linux verwendet PTY beziehungsweise Pipes, Windows ConPTY beziehungsweise Pipes mit geschützten Named Pipes. Die lokalen Ausgabe- und Befehlskanäle sind ebenfalls an die Instanz gebunden; eine volle Befehlswarteschlange wird nicht als erfolgreiche Annahme bestätigt. Ein Browser-Disconnect beendet ausschließlich Viewer-Ressourcen, niemals den Gameserver.

## Speicherpflege

- 128 KiB Verlauf und höchstens 128 Frames pro Wrapper; acht Frames Warteschlange pro Betrachter, höchstens 16 Betrachter pro Instanz und 128 Live-Verbindungen pro Agent.
- Langsame Verbindungen werden getrennt und können mit Sitzungskennung und Sequenz weiterverbinden. Ist der begrenzte Verlauf überschritten, meldet die Oberfläche eine Lücke.
- Die Browserdarstellung ist auf 128 Ki UTF-16-Codeeinheiten begrenzt und zeichnet gesammelt mit Browser-Frames. Verdeckte Tabs schließen ihre Verbindung. UTF-8 und ANSI-Sequenzen werden auch über Blockgrenzen verarbeitet.
- Der lokale Kompatibilitätslog-Snapshot bleibt auf 256 KiB begrenzt; er ist kein vollständiges dauerhaftes Logarchiv. So kann er auch unter `/run` nicht unbegrenzt wachsen.
- Alte HTTP-Logleser sind auf 128 Sitzungen, 256 KiB Text je Sitzung und begrenzte Zeilen beschränkt. Ausgabe allein verlängert ihre Betrachterfrist nicht mehr. Abbruch löst auch blockierte Pipe-Schreibvorgänge. Die Zahl der gespeicherten Rate-Limit-Schlüssel ist gedeckelt.
- Der Monolog-Puffer ist auf 100 Meldungen begrenzt. Der optionale Legacy-Relay bearbeitet endliche Abschnitte, prüft verbleibende Betrachter und gibt Logger-, HTTP-Client- und ORM-Zustand zwischen Abschnitten frei.

Der alte Worker kann nach einer Stunde oder ab 96 MiB PHP-Heap kontrolliert aussteigen; systemd startet ihn neu. Für die Übergangsphase liegen eine gehärtete Beispiel-Unit und ein Drop-in mit `MemoryHigh=128M`, `MemoryMax=192M`, `RuntimeMaxSec=3660` und fünf Sekunden Neustartabstand vor. Diese Absicherung betrifft ausschließlich den alten PHP-Relay. Weder Gameserver noch der gesamte Agent werden regelmäßig zur Speicherpflege neu gestartet. Der Betriebssystem-Cache wird nicht mit `drop_caches` geleert.

Berechtigte Live-Verbindungen werden nach spätestens fünf Minuten mit erneuter Rechteprüfung und Cursor weiterverbunden; früher ablaufende Sitzungen begrenzen die Laufzeit entsprechend. Das begrenzt auch das Fortbestehen einer Verbindung nach Rechteentzug. Grenzen für Nutzdaten sind keine Zusage für den gesamten RSS: Runtime, Bibliotheken und weitere Agent-Aufgaben benötigen ebenfalls Speicher.

## Verifikation

Die Prüfungen liefen mit eigenen synthetischen Konten, isolierter SQLite-Datenbank und einem eigens gestarteten Test-Gameserver.

- Ein echter Browser lief über die erzeugte Nginx-Konfiguration, den privaten `auth_request`, den Panel-Controller, den Go-WebSocket-Handler und einen real gestarteten Wrapper. Für den lokalen Test verband ein kleiner FastCGI-Adapter Nginx mit dem Symfony-Testkernel; ein produktiver PHP-FPM wurde dabei nicht betrieben. Die sonst im Testkernel verwendete Null-Konsolenimplementierung wurde für diesen Test durch den echten HMAC-HTTP-Client ersetzt.
- Ausgabe ohne Newline, Befehle über diesen HMAC-Weg, fragmentiertes UTF-8, ANSI-Sequenzen, ein großer Ausgabeschub, private Proxy-Routen und Browser-Reconnect wurden geprüft. Kein JavaScript-Fehler; der Konsolentab stellte keine Log-/SSE-Polling-Anfragen. Fünf abschließende lokale Messungen von erzeugter Ausgabe bis zur Darstellung: **24, 27, 22, 31 und 32 ms**. Das sind lokale Stichproben, keine Zusage für Netzwerk- oder Produktionslatenzen.
- Der Speicher-Stresstest erzeugte ohne Betrachter insgesamt 40 MB Ausgabe. Der isolierte Konsolen-Host blieb bei 8.716 KiB RSS; der Wrapper lag vor dem Test bei 15.496 KiB, nach 20 MB bei 18.212 KiB und nach 40 MB bei 18.312 KiB. Der Kompatibilitätslog blieb stets unter 256 KiB. Das zeigt nach dem Aufwärmen einen weitgehend stabilen Verlauf in diesem kurzen Test, ersetzt aber keinen Langzeittest des vollständigen Agents auf dem HomeServer.
- Go-Race-Tests des neuen Pakets einschließlich Wrapper-/IPC-Integration erfolgreich. Ein weiterer Test verarbeitete 100.000 Ausgabeblöcke und behielt nur 131.072 Bytes Verlauf sowie acht Frames im getrennten langsamen Abonnenten. Die betroffenen bestehenden Konsolentests und die neuen Tests für TTL, Byte-Grenzen und Abbruch bestehen.
- Linux amd64, Linux arm64 und Windows amd64 wurden gebaut; auch die Windows-Testkompilierung des neuen Pakets besteht. Ein Laufzeittest auf einem echten Windows-Host steht aus.
- Vollständige PHP-Suite: **1.469 Tests, 6.065 Assertions, keine Fehler und keine fehlgeschlagenen Assertions**. Drei vorhandene Warnungen, 301 PHPUnit-Hinweise und zwölf übersprungene Tests bleiben; dazu gehören Tests, die die in dieser Umgebung fehlende PHP-Redis-Erweiterung benötigen.
- Der vollständige Go-Lauf hat ausschließlich die bereits zuvor bekannte Abweichung `TestTeamspeakBackendMissingLibraryAndOpus` (`ready` statt `opus_missing`). Die neue Konsole ist davon unabhängig; der Gesamtlauf wird deshalb nicht als vollständig erfolgreich ausgewiesen.
- PHPStan auf Repository-Level, Container-, Twig- und YAML-Prüfungen, JavaScript-Verhaltenstests und der Python-Test für private, idempotente Konfiguration erfolgreich. Das Bash-Installerskript ist syntaktisch gültig.

Die Testprotokolle, Messdaten und der Screenshot liegen unter `/workspace/live-console-change`. Die Umstellung selbst braucht noch einen Test auf `ameserver`; aus dieser Arbeitsumgebung besteht keine aktive SSH-Verbindung dorthin.

## Installation und Release

Der Konfigurationshelfer und die Nginx-Vorlage liegen unter `core/tools/console` und sind dadurch auch im normalen Core-Release enthalten. Der Installer richtet den neuen Weg ein und aktiviert den alten Relay nicht mehr. Die Release-Prüfungen verlangen die neuen Dateien ausdrücklich. Andere Aufgaben, die Redis verwenden, bleiben erhalten.

Für die Übernahme siehe [Einrichtungs- und Umstellungsanleitung](../operations/gameserver-live-console-setup.md). Bereits laufende Instanzen brauchen einen geplanten einmaligen Neustart mit dem aktualisierten Wrapper; die lokale Schnittstelle ist nun instanzgebunden und bestätigt die Annahme. Es erfolgt kein automatischer Neustart bestehender Kundenspiele. Erst nach erfolgreichem Test den alten Relay deaktivieren. Keine Datenbankmigration erforderlich.

Zusätzlich wurden die vom Benutzer gemeldeten Proxmox-Transportfehler besser diagnostizierbar gemacht: statische Kategorien für TLS, DNS, Timeout, Verbindung und ungültiges JSON statt bloßer Exception-Klasse. Rohfehlertexte und Token werden nicht protokolliert. Fehlgeschlagene Inventar-Aktualisierungen verwenden wachsende Abstände von 20 Sekunden bis fünf Minuten. Die tatsächliche Ursache der Verbindung auf `ameserver` ist damit noch nicht verifiziert; die später geposteten `root@pam`-Fehlanmeldungen waren nach Benutzerauskunft eigene Anmeldeversuche und davon getrennt.
