# Gameserver-Live-Konsole ohne separaten Relay

Die Konsole nutzt einen WebSocket im vorhandenen Go-Agent. Ausgabe wird direkt aus dem Gameserverprozess übertragen, auch ohne Zeilenumbruch. Der Browser verwendet weder einen Polling-Zyklus noch den alten PHP-SSE-Stream. Befehle laufen über den bestehenden POST-Endpunkt mit Kundenprüfung, CSRF-Schutz und Rate-Limit.

## Neue Installation

Der Linux-Installer richtet das WebSocket-Routing im vorhandenen Panel-Nginx ein und aktiviert `easywi-console-relay` nicht mehr. Das Release muss `core/tools/console/configure-live-console.py` und die danebenliegende `live-console.conf.template` enthalten. Nginx benötigt das übliche `http_auth_request`-Modul. `DEFAULT_URI` muss die öffentliche Paneladresse enthalten; ein vorgeschalteter TLS-Proxy muss WebSocket-Upgrades weiterreichen.

Der Agent benötigt keine neue Listener-Adresse, keinen öffentlichen Verwaltungsport und keinen separaten Konsolendienst. Nur der Panelpfad `/console/live/{id}` ist für Browser bestimmt. Panel und Nginx benötigen privaten Netzwerkzugriff auf die bestehende Agent-Serviceadresse. Bei HTTPS-Agent-Adressen werden Hostname und Zertifikatskette geprüft; private CAs müssen auf dem Panelhost vertraut sein.

## Bestehende Installation umstellen

1. Paneldateien und Agent aktualisieren. Die Änderungen benötigen keine Datenbankmigration. Die zusätzlichen Windows-Bibliotheken werden beim Go-Build automatisch eingebunden.
2. Routing im bestehenden Nginx-vHost erzeugen, zum Beispiel aus dem Repository-Stamm:

   ```bash
   sudo python3 /var/www/easywi/core/tools/console/configure-live-console.py \
     --core /var/www/easywi/core \
     --vhost /etc/nginx/sites-available/easywi.conf \
     --php-fpm /run/php/php8.5-fpm.sock \
     --origin https://panel.example.test
   ```

   Die tatsächliche öffentliche Adresse und den vorhandenen PHP-FPM-Socket einsetzen. Das Skript erhält andere Umgebungswerte, erzeugt den privaten Proxy-Schlüssel, speichert die Include-Datei mit restriktiven Rechten und legt eine vHost-Sicherung an. Es reloadet keine Dienste und stoppt keine Gameserver. Wiederholtes Ausführen erzeugt keinen zweiten Include und keinen neuen Schlüssel.
3. Falls `.env.local.php` durch `composer dump-env` verwendet wird, die gedumpte Umgebung nach der Konfiguration neu erzeugen (`composer dump-env prod`). Danach den Symfony-Produktionscache mit der installierten PHP-Version und dem Webbenutzer leeren. `nginx -t` ausführen und bei erfolgreicher Prüfung Nginx reloaden. Bei einer fehlgeschlagenen Nginx-Prüfung den vHost aus `.before-live-console` wiederherstellen; nicht mit ungeprüfter Konfiguration reloaden.
4. Zunächst **eine eigene Testinstanz geplant neu starten**. Bereits laufende Gameserver verwenden ihren alten Wrapper weiter; ein Agent-Update ersetzt diesen laufenden Prozess nicht. Der neue Wrapper und Agent müssen gemeinsam aktualisiert sein, da die lokale Befehlsschnittstelle jetzt eine Instanzbindung und Bestätigung verwendet. Es erfolgt kein automatischer Neustart fremder Gameserver.
5. Als zugewiesener Kunde die Konsole öffnen. Ausgabe, einen Befehl und einen Browser-Refresh testen. Die Konsole muss „Live verbunden“ melden. Im Browser darf kein Request an `/console/stream` oder `/console/logs` für diesen Tab laufen. Nach dem Schließen der Konsole muss der Gameserver weiterlaufen.
6. Nach erfolgreicher Umstellung der benötigten Instanzen den alten Dienst deaktivieren:

   ```bash
   sudo systemctl disable --now easywi-console-relay.service
   ```

Redis, Messenger und das VM-Konsolen-Gateway bleiben eigenständige Komponenten. Redis nicht pauschal entfernen.

## Übergang mit altem Relay

Für vorhandene externe Legacy-SSE-Clients kann ausdrücklich `CONSOLE_LEGACY_RELAY=1` gesetzt werden. Die neue Kundenoberfläche bleibt ein echter WebSocket-Client. Der optionale Worker kann mit `--time-limit=3600 --memory-limit=96 --no-debug` kontrolliert recycelt werden; systemd startet ihn mit `Restart=always` neu. Der neue Worker bearbeitet Instanzen in begrenzten Abschnitten und prüft während der Ausgabe erneut, ob noch Betrachter existieren.

Für eine vorhandene Unit gibt es einen Drop-in unter `deploy/systemd/easywi-console-relay.service.d/memory.conf`. Er setzt ausschließlich für diesen alten PHP-Prozess eine Laufzeitgrenze von 3.660 Sekunden, `MemoryHigh=128M`, `MemoryMax=192M`, einen Neustartabstand von fünf Sekunden und deaktiviert Debugging. Beim Installieren des Drop-ins müssen `systemctl daemon-reload` und ein geplanter Relay-Neustart folgen. Die Werte sind Übergangsgrenzen und müssen zum Grundverbrauch der Installation passen; sie sind kein RAM-Limit für Gameserver oder den gesamten Agent.

## Linux und Windows

Linux verwendet PTY, mit Prozess-Pipes als Alternative bei nicht verfügbarer PTY. Windows verwendet ConPTY, wenn verfügbar, und sonst STDIN/STDOUT-Pipes. Lokale Verbindungen laufen unter Linux über geschützte Unix-Sockets, unter Windows über benannte Pipes mit einer ACL für Dienstkonto, Administratoren und SYSTEM. Auf Windows liegt das Runtime-Verzeichnis standardmäßig unter `%ProgramData%\EasyWI\run\instances`.

Auch ältere Units mit einem separaten `easywi-wrapper` können den neuen Datenweg verwenden, wenn dieses Kompatibilitätsprogramm ebenfalls aktualisiert wird. Spiele mit nicht standardmäßiger Konsoleneingabe müssen ihr jeweiliges Laufzeitprotokoll unterstützen. Windows-Builds wurden geprüft; ein Laufzeittest auf einem echten Windows-Host bleibt erforderlich.

## Fehlersuche

- **Handshake scheitert:** öffentliche Origin, Nginx-Include, WebSocket-Weiterleitung eines vorgeschalteten Proxys und private Agent-Serviceadresse prüfen. Die privaten Auth-Endpunkte sind absichtlich nicht direkt im Browser erreichbar.
- **Ältere Instanz ohne Ausgabe/Befehl:** aktualisierten Wrapper durch einen geplanten Instanzneustart starten. Ein Browser-Reload startet keinen Gameserver neu.
- **HTTPS-Upstream scheitert:** die CA-Vertrauensstellung auf dem Panelhost und den Hostnamen der Agent-URL korrigieren; TLS-Prüfung nicht pauschal abschalten.
- **Verlaufslücke:** der Live-Puffer ist bewusst begrenzt. Bei einem langsamen Client oder länger getrenntem Tab zeigt die Oberfläche eine Lücke an; dauerhafte Logs gehören in eine rotierende Logablage.

`drop_caches`, ein Neustart des gesamten Agents oder regelmäßige Gameserver-Neustarts sind für die Speicherpflege dieser Konsole nicht vorgesehen.
