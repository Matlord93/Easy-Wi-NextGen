# Kundenbereich: Gameserver-Arbeitsbereich

Übersicht, Konsole, Dateimanager, Konfiguration, Backups und Verwaltung verwenden einen gemeinsamen Seitenrahmen. Die bisherige zusätzliche Statuskarte und die eigenständigen alten Dateimanager-Seiten entfallen. `/instances/{id}/files/legacy` führt zur aktuellen Dateiverwaltung; `?tab=configs` öffnet das neue Setup. Installation, Updates, Versionswechsel, Rollback, Konsole, SFTP, Dateioperationen, Backups, Addons, Aufgaben und Zeitpläne bleiben erreichbar.

## Setup und feste Vertragswerte

Unter **Konfiguration → Setup** liest das Panel die tatsächlichen Konfigurationsdateien vom zugewiesenen Agenten. Kategorien, Suche und beschriebene Felder ermöglichen Änderungen ohne Rohtexteditor. Minecraft verwendet die vorhandene `server.properties`; andere Spiele verwenden die am Template bzw. der Spieldefinition hinterlegten Dateien und Schemas. Es werden nur tatsächlich vorhandene skalare Einstellungen angeboten.

- IP-/Bind-Adressen, reservierte Ports und gebuchte Ressourcen sind vom Panel verwaltete Werte. Diese Felder sind im einfachen Editor schreibgeschützt; unerlaubte Schreibanfragen werden auch serverseitig abgewiesen.
- **Gebuchte Slots** zeigen das feste Maximum. **Maximale Spielerzahl** verändert das aktuelle Spielerlimit zwischen 1 und diesem Maximum. Eine zusätzliche vom Betreiber gesetzte Slotsperre bleibt wirksam. Die gebuchte Slotzahl wird dabei niemals geändert.
- Beim Speichern liest das Panel die Datei erneut und schreibt mit dem ursprünglichen ETag. Zwischenzeitliche Änderungen ergeben einen Konflikt, anstatt eine fremde Änderung zu überschreiben. Die Eingaben bleiben im Browser erhalten.
- Der Schreibzugriff erfordert Eigentümerschaft und CSRF-Token. Der Agent muss das Speichern bestätigen. Ein gespeicherter alter Panel-Snapshot dieser Datei wird entfernt, damit spätere Änderungen im Dateimanager nicht beim Start überschrieben werden.
- `.properties`, INI und Convar-Dateien behalten unberührte Zeilen und Kommentare. Fortsetzungszeilen in Properties werden unverändert erhalten und im einfachen Editor ausgelassen. Die Schreibprüfung liest sie trotzdem als vollständige Java-Properties-Einträge, einschließlich fortgesetzter Schlüssel, sodass IP-, Port- und Slotvorgaben auch im Rohtexteditor geschützt bleiben. Backslashes am Ende eines Kommentars verschlucken den nächsten Eintrag nicht. Leere Einträge ohne Gleichheitszeichen werden erkannt; führende Leerzeichen in bearbeiteten Werten bleiben korrekt maskiert erhalten. JSON und YAML behalten die Datenstruktur einschließlich unbekannter Werte, werden jedoch neu formatiert. JSON und YAML erhalten insbesondere leere Objekte, Objekte mit numerischen Schlüsseln und echte Listen als unterschiedliche Typen. YAML-Datumswerte behalten ihren Typ, ihre Schreibweise wird vereinheitlicht. YAML-Kommentare bleiben nicht erhalten; Anker und Aliase werden aufgelöst. Änderungen an einem Alias verändern keine anderen Verwendungen oder seine Vorlage. Dateien über 1 MiB und Binärdateien werden nicht im einfachen Editor geladen.
- Vor dem Speichern prüft der Browser alle geänderten Zahlenfelder, auch wenn Suche oder Kategorien sie gerade ausblenden. Ungültige Eingaben werden wieder eingeblendet und fokussiert. Leere Zahlenfelder sowie Zahlen außerhalb der Grenzen werden auch bei Auswahlfeldern abgewiesen. Unveränderte Altwerte blockieren im Browser keine anderen zulässigen Änderungen; die serverseitige Prüfung bleibt für alle Schreibanfragen verbindlich.
- Zahlenfelder unterstützen ganze Zahlen und Dezimalwerte einschließlich der Mindest- und Höchstwerte aus dem Spielschema. `1.0` bleibt als Dezimalwert erkennbar; Bruchteile werden nicht auf ganze Zahlen gekürzt. Nicht endliche Zahlen werden abgewiesen.
- Bei BungeeCord begrenzt `player_limit` die tatsächlichen Spieler, während `listeners.*.max_players` die Anzeige in der Serverliste steuert. Der Agent setzt beim Installieren und vor dem Start beide Werte auf das aktuelle Spielerlimit. Eigene MOTDs und Backend-Adressen bleiben erhalten; Listener-Adressen und Query-Einstellungen sind im Setup schreibgeschützt. Die Korrektur erfordert den aktualisierten Agenten.
- Installationstokens und andere tatsächlich benötigte Startvariablen bleiben im aufklappbaren Bereich **Installation und Spielzugänge**. Der Dateimanager bietet weiterhin den Rohtexteditor für weitere Dateien.

[Spielprofile, unterstützte Formate und Einrichtung aller 54 Templates](game-specific-setup.md).

Nicht jedes Spiel hat dieselben Einstellungen. Fehlt eine hinterlegte Datei oder ein unterstütztes Format, zeigt der Editor den Grund, statt Minecraft-Felder für ein anderes Spiel zu erfinden. Unbekannte Parameter bekommen ihre Bezeichnung aus dem Spielschema, sofern eines vorhanden ist.

## Service-Informationen und Ressourcen

Die Übersicht zeigt IP/Host, Verbindungsadresse, Live-Abfragestatus, Prozessstatus, Version, Spieler, gebuchte Slots, aktuelles Spielerlimit, RAM-Tarif und Node. Fehlende Messwerte bleiben unbekannt. Live-Abfragen pausieren bei verborgenem Dokument und laufen nicht gleichzeitig mehrfach. Dateimanager und Setup laden keine unnötigen Backup-, Addon- und Aufgabenlisten und lösen keine zusätzlichen Spielstatus-Jobs beim Seitenaufbau aus.

Auf Agenten mit der Fähigkeit `instance_runtime_limits` entfällt der bisherige Java-Heap-Abzug von 25 Prozent. Bei **4 GiB gebuchtem Minecraft-RAM** erhält Java `-Xmx4096M`. Der Prozess benötigt zusätzlich native JVM-Ressourcen: Die Prozessgrenze enthält dafür `max(256 MiB, 25 % des Heaps)`, im Beispiel insgesamt **5120 MiB**. `-Xms` bleibt höchstens 512 MiB; der maximale Heap wird nicht bereits beim Start vollständig belegt. Bedrock und andere Spiele erhalten keinen Java-Zuschlag.

Der Shop reserviert die zusätzliche physische Kapazität, ohne den gebuchten RAM oder Preis zu ändern. Fehlende Reserven bereits provisionierter älterer Java-Verträge werden bei der freien Kapazität berücksichtigt, ohne historische Tarife umzuschreiben. Für manuell verwaltete Instanzen muss der Betreiber diese Reserve im Node-Budget berücksichtigen. Tatsächlicher Verbrauch und gebuchter RAM bleiben getrennte Größen; die Anzeige meldet keine künstlich ausgelasteten 4 GiB.

## Aktualisierung

Keine Datenbankmigration erforderlich. **Agent und Panel aktualisieren**, den Produktionscache erneuern und die nächste Agent-Rückmeldung abwarten. Erst ein Agent, der `instance_runtime_limits` meldet, erhält den vollen Java-Heap. Alte Agenten behalten den vorherigen Heap-Abstand, damit ein reines Panel-Update keine bestehenden Server unter ihrer alten Prozessgrenze zum Absturz bringt. Eine Rückmeldung ohne diese Fähigkeit entfernt eine frühere Freigabe wieder, etwa nach einem Agent-Downgrade.

Vor einem vom Panel ausgeführten Start/Neustart setzt der neue Linux-Agent CPU- und Speichergrenzen mit `systemctl set-property` dauerhaft für den bestehenden Dienst. Erst danach schreibt er das neue Startskript. Schlägt die Grenzanpassung fehl, wird der Startauftrag abgebrochen und der bisherige Heap bleibt im Startskript erhalten. Die systemd-Einstellungen gelten auch nach einem Host-Neustart. Auch beide Shop-Bereitstellungswege behalten die vom Installationsdienst berechnete native RAM-Reserve im Auftrag. Installationsverzeichnisse werden nicht verschoben.

## Vollständige Agent-Prüfung ohne ausgelassene Tests

Der reproduzierbare Linux-Testlauf ist:

```sh
python3 scripts/ci-agent-tests.py --report-dir /tmp/easywi-agent-test-results
```

Voraussetzungen sind Go in der Version aus `agent/go.mod`, Python 3, Docker-Zugriff als normaler Benutzer und ein vertrauenswürdiges PEM-CA-Bundle. Unter Debian/Ubuntu wird `/etc/ssl/certs/ca-certificates.crt` verwendet; ein abweichender Pfad kann mit `EASYWI_TEST_CA_BUNDLE` hinterlegt werden. Paketdownloads erfolgen mit aktiver TLS-Prüfung. Das Testimage bleibt im lokalen Docker-Buildcache.

Alle Go-Pakete werden automatisch ermittelt. Die regulären Pakete laufen als normaler Benutzer. Die Dateiverwaltung und Voice-Bridge erhalten getrennte kurzlebige Container:

- **Agent/FileAPI:** echte Root- und Dateieigentümerprüfungen, einschließlich UID/GID-Wechsel, mit auf den Test begrenzten Linux-Fähigkeiten.
- **Voice/TeamSpeak-Bridge:** unprivilegierter Benutzer, gebaute Bridge sowie echte PulseAudio-/pactl-Programme.

Die Testcontainer haben keinen externen Netzwerkzugriff, ein schreibgeschütztes Dateisystem und schreibgeschützt eingebundene Testprogramme. Testdateien und Audiozustand liegen ausschließlich im temporären Container-Dateisystem. Container und temporäre Programme werden nach dem Lauf entfernt. Es werden keine Panel-, Gameserver- oder produktiven Audiodienste kontaktiert.

Beide Agent-CI-Workflows nutzen denselben Ablauf und laden JSON-Berichte als Artefakte hoch. `ci-agent-test-report.py` prüft alle erwarteten Pakete, vollständig beendete Tests, Fehler und tatsächliche Testauslassungen. Ein fehlender Bericht, ein abgebrochener Test oder ein übersprungener Testfall lässt den Job fehlschlagen. Go-Paketmeldungen für Verzeichnisse ohne Testdateien werden getrennt von einzelnen Testfällen behandelt. Die bisherigen sechs umgebungsabhängigen Tests bleiben enthalten und werden mit ihren tatsächlichen Voraussetzungen ausgeführt. Der lokale Gesamtlauf hat **1.308 Testfälle in 44 Paketen, 0 Fehler und 0 ausgelassene Tests** bestätigt.

## Bildschirmaufnahmen

Lokale Vorschau mit Beispieldaten; Agent-Antworten sind für die Browserprüfung simuliert. Es wurden keine echten Serverdateien oder Produktionssysteme verändert.

- [Setup – dunkel](../screenshots/gameserver-workspace/setup-dark.png)
- [Setup – hell](../screenshots/gameserver-workspace/setup-light.png)
- [Setup – mobil](../screenshots/gameserver-workspace/setup-mobile.png)
