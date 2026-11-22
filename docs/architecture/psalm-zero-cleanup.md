# Psalm: verbleibende 78 Meldungen

Ergebnis: **78 auf 0 Meldungen**, einschließlich vollständiger Taint-Analyse.

Die Bereinigung betrifft ungenutzte Klassen, Methoden, Eigenschaften und
Rückgabewerte. Die Analyse bleibt auf Stufe 1 mit `findUnusedCode="true"`;
`core/psalm.xml`, der Doctrine-Scanner und die CI-Ergebnisprüfung bleiben
unverändert. Es wurde keine Baseline angelegt.

## Aktive Abläufe erhalten

- Alle 1.248 Routendefinitionen stimmen mit dem vorherigen Stand überein.
- Native Gameserver-, TS3-/TS6-, SFTP-, CMS- und Monitoring-Abläufe bleiben
  angebunden. Datenbank-Entities und Migrationen wurden nicht entfernt.
- TeamSpeak-Profilnamen und Monitoring-Anbieter/-Art werden in PHP ausdrücklich
  in die Twig-Daten übertragen. So erkennt Psalm die tatsächliche Verwendung
  dieser bisher nur über Twig gelesenen Werte.
- Der Null-Client für Konsolentests liegt jetzt unter `tests/Support`. Die
  produktive gRPC-Anbindung bleibt unverändert; die bisherigen Tests verwenden
  denselben Test-Client über den Testcontainer.
- Metrikhistorie wird weiterhin in begrenzten Batches gekürzt. Der ungenutzte
  Zähler-Rückgabewert entfällt; der Datenbanktest prüft erhaltene Datensätze und
  die wiederholte Ausführung direkt.

## Öffentliche Integrationsverträge

Vier Klassen bleiben ausdrücklich als öffentliche API (`@api`) erhalten:

- `KvmJobService`: dokumentierter Legacy-Vertrag für bestehende Integrationen;
  das eingebaute Panel verwendet bereits `KvmPanelService`.
- `DockerJobService`: dokumentierte PHP-API für Docker-Agentjobs; eine eingebaute
  Docker-Verwaltungsoberfläche ist noch nicht angebunden.
- `MailControlPlaneJobMessage` und `UnifiSyncInstancePortsMessage`: bestehende
  Messenger-Verträge für externe Produzenten und bereits gespeicherte Nachrichten.
  Konstruktoren, Feldnamen und Standardwerte bleiben erhalten. Neue UniFi-Aufträge
  verwenden weiterhin die Outbox.

Die Kennzeichnung beschreibt diese externen Einstiegspunkte. Eine Suche nach
internen PHP-Aufrufen kann externe Produzenten und bestehende Queue-Inhalte nicht
erkennen. Ein Regressionstest prüft die Mail-/UniFi-Verträge durch den tatsächlichen
Messenger-Serializer einschließlich des optionalen Felds `maxAttempts`.

## Entfernte, nicht angebundene Bausteine

Vor dem Entfernen wurden Referenzen in Quellcode, Service-Konfiguration,
Routen, Tests und Dokumentation geprüft. Die folgenden 22 Quelldateien hatten
keinen produktiven Aufrufer oder Einstiegspunkt:

| Bereich | Entfernte Klassen |
| --- | --- |
| Infrastruktur | `ConfiguredKeyFile`, `ConfiguredAgentEndpoint`, `TypedStreamResponse` |
| CMS | `CmsTemplateArchiveValidator`, `CmsTemplateDeploymentManager` |
| Gameserver | `ConsoleSseEncoder`, `InstanceConfigService`, `DockerGameComposeRenderer`, `DockerGameTemplateCatalog`, `DockerGameTemplate` |
| HostingPanel | `PanelActionResult`, `PanelAdapterContext`, `PanelAdapterError` einschließlich zugehörigem Fehlercode-Enum, `PanelAdapterInterface`, `AuditLogWriter`, `GenericPanelJobType`, `JobStateMachine`, `PanelJobTypeInterface`, `SecretVault` |
| Setup/Navigation | `InstallEnvFileWriter`, `InstallEnvKeyGenerator`, `AdminNavigationPlan` |

Die ungenutzten expliziten Service-Definitionen und die Routenausnahme für den
reinen Navigationsplan entfallen ebenfalls. Zehn Testdateien, die ausschließlich
diese entfernten Implementierungen testeten, entfallen. Tests für die aktiven
Pfade bleiben erhalten; bestehende Sicherheits- und Persistenzprüfungen wurden
auf die tatsächlich verwendeten Methoden ausgerichtet.

Nicht aufgerufene Methoden entfallen unter anderem für alte Gameserver-REST-Resets,
Installer-Aliase und Cache-Löschung, einen alten Proxmox-Inventarabruf und
Musicbot-Komfortfunktionen. Aktive Entsprechungen bleiben bestehen:
Agentjobs für Zugangsdaten, `InstallEnvBootstrap`, clusterweite Proxmox-Ressourcen,
kanonische Playback-Aktionen, Secret-Sanitizer und die getrennten Radio-Favoriten-
und Verlauf-Repositories.

## Verwendete Ergebnisse und Secret-Schutz

Proxmox-Controller werten den zurückgegebenen Task aus. Fehlgeschlagene oder
unklare Auftragsannahmen zeigen einen Fehler über die vorhandene Fehleranzeige;
sie werden nicht erneut ausgeführt. Die vorhandene Fehler-Auditmeldung enthält
zusätzlich den Proxmox-Anfragepfad, ohne Token oder Antwortinhalt zu übernehmen.

Der Installer zeigt einen Fehler an, wenn `var/easywi` nicht angelegt werden kann.
Musicbot-Playliständerungen und Log-/Event-Schreiboperationen liefern `void`, da
alle produktiven Aufrufer nur ihre Wirkung benötigen. Musicbot-Event- und
Plugin-Logtexte verwenden jetzt den vorhandenen Secret-Sanitizer auch für freie
Meldungstexte; der Event-Test prüft die Maskierung am tatsächlich persistierten
Objekt und verhindert einen zusätzlichen Flush. Die Maskierung erfasst auch
vollständige zitierte Werte mit Leerzeichen und escaped Anführungszeichen;
JSON-Meldungen bleiben nach der Maskierung gültig.

## Weiterhin offene Funktionspakete

Psalm-Freiheit vervollständigt keine bisher fehlenden Produktabläufe:

- Docker-Verwaltungsoberfläche und vollständige Docker-Gameserver-Installation
  sind weiterhin offen. Der entfernte Katalog/Renderer war dafür nur eine
  unverbundene Vorbereitung. Siehe `docs/roadmap/system-erweiterungen.md` und
  `wiki/Docker-Gameserver.md`.
- Der entfernte CMS-ZIP-Deployment-/Rollback-Helfer war nicht in Upload- oder
  Veröffentlichungsrouten eingebunden. Ein solcher vollständiger Ablauf ist
  separat umzusetzen; die bestehende CMS-Ausgabe bleibt erhalten.
- Automatischer Musicbot-Workflow-Ereignisdispatch und automatische Vergabe von
  Standardrollen hatten keine Aufrufer. Die unverbundenen Methoden entfallen;
  Workflow-Verwaltung, manuelle Testläufe und explizite Rollenvergabe bleiben
  erhalten. Ein zukünftiger automatischer Anschluss benötigt echte Ereignisquellen,
  Rechteprüfung und Schutz vor rekursiven Ausführungen.

## Abschlussprüfung

| Prüfung | Ergebnis |
| --- | --- |
| Vollständiger Psalm-Lauf, PHP 8.5.12 / Psalm 7.4.0 | 0 Meldungen |
| Vollständiger Psalm-Lauf mit Taint-Analyse und normalisiertem SARIF | 0 Meldungen / 0 SARIF-Befunde |
| PHPUnit, PHP 8.4.27 | 2.209 Tests, 9.516 Assertions, keine Warnungen oder übersprungenen Tests |
| PHPUnit, PHP 8.5.12 | 2.209 Tests, 9.516 Assertions, keine Warnungen oder übersprungenen Tests |
| Produktionscontainer: `APP_ENV=prod APP_DEBUG=0 php bin/console lint:container` | Erfolgreich |
| PHP-Syntaxprüfung der 52 geänderten/neuen PHP-Dateien unter beiden Versionen | Erfolgreich |
| Routendefinitionen vor/nach der Bereinigung | Alle 1.248 unverändert |

Psalm und PHPUnit aus `core/` ausführen:

```sh
APP_ENV=dev APP_DEBUG=1 php bin/console cache:warmup
php -d memory_limit=-1 ../tools/psalm/vendor/bin/psalm --config=psalm.xml --no-cache --no-diff --threads=4
php -d memory_limit=-1 ../tools/psalm/vendor/bin/psalm --config=psalm.xml --no-cache --no-diff --threads=4 --taint-analysis
php bin/phpunit
```
