# Voice-/TeamSpeak-Upgrade: alte Dateien im Produktionscontainer

Der Fehler

```text
Cannot autowire service "App\Module\Teamspeak\Application\Update\TeamspeakUpdateLockManager": argument "$lockDir" ... is type-hinted "string"
```

betrifft das frühere Modul `App\Module\Teamspeak`. TS3 und TS6 verwenden nach der
Zusammenführung `App\Module\Voice`. Der dortige Lock-Manager hat bereits das
explizite Argument `%kernel.project_dir%/var/teamspeak-update-locks`.

Beim Kopieren eines neuen Releases über einen älteren Dateibestand können alte
TeamSpeak-Controller und deren Dienste zurückbleiben. Die allgemeine
Service-Suche unter `src/` findet diese Klassen weiter und versucht, den alten
Lock-Manager zu erzeugen. Deshalb schließt `config/services.yaml` das frühere
Verzeichnis jetzt zusätzlich aus:

```yaml
services:
  App\:
    resource: '../src/'
    exclude:
      - '../src/DependencyInjection/'
      - '../src/Kernel.php'
      - '../src/Module/Teamspeak/'
```

Die Voice-Dienste, TS3-/TS6-Routen, Update-Locks und gespeicherten Serverdaten
bleiben erhalten. Der Panel-Updater entfernt überholte Dateien bereits bei
vollständigen Updates beziehungsweise anhand der Löschliste bei Delta-Updates.
Eine manuelle Archivkopie sollte ebenfalls einen vollständigen aktuellen
Programmstand herstellen.

Nach Einspielen der Korrektur:

```sh
cd /var/www/easywi/core
sudo -u www-data php bin/console cache:clear --env=prod --no-debug
sudo -u www-data php bin/console lint:container --env=prod --no-debug
```

`bin/console` berücksichtigt jetzt auch `--no-debug`, wenn in der Umgebung
`APP_DEBUG=1` gesetzt ist. Die Cache-Ausgabe muss dabei `debug=false` anzeigen.

## Alte Quelldateien aus einer manuellen Überlagerung sichern

Wenn das aktuelle Voice-Modul bereits vollständig installiert ist, kann der
veraltete Quellordner außerhalb von `src/` gesichert werden. Das betrifft PHP-Code,
keine TeamSpeak-Serververzeichnisse, Datenbanken oder Instanzdateien:

```sh
cd /var/www/easywi/core
if [ -f src/Module/Voice/Application/Update/TeamspeakUpdateLockManager.php ] && [ -d src/Module/Teamspeak ]; then
    legacy_backup="$(mktemp -d var/legacy-teamspeak.XXXXXX)" &&
        mv -- src/Module/Teamspeak "$legacy_backup/"
fi
```

## Regressionstests

`TeamspeakServiceDiscoveryTest` lädt die tatsächlichen Service-Suchregeln und
die Voice-Lock-Konfiguration mit Symfony. Eine saubere Installation und eine
Installation mit einem alten TeamSpeak-Lock-Manager werden getrennt geprüft.
Vor der Korrektur erzeugt der zweite Fall exakt den gemeldeten Autowiring-Fehler;
danach lässt sich der Container kompilieren, und der aktive Voice-Lock kann unter
`var/teamspeak-update-locks` erworben und freigegeben werden.

`ConsoleNoDebugTest` führt den wirklichen Produktions-Cache-Aufruf in einem
separaten PHP-Prozess mit `APP_DEBUG=1` und eigenen Cache-/Logverzeichnissen aus.
Die Ausgabe muss bestätigen, dass der Debug-Modus deaktiviert ist.

Lokal geprüft unter PHP 8.4.27 und PHP 8.5.12: jeweils 64 TeamSpeak-/Konsolentests
mit 236 Assertions, ohne Warnungen oder übersprungene Tests. Die Produktions-
Containerprüfung besteht unter beiden Versionen. Der vollständige Psalm-Lauf
einschließlich Taint-Analyse bleibt bei 0 Meldungen; alle 1.248 Routendefinitionen
sind unverändert.
