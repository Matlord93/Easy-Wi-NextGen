# Core: Bereinigung der Psalm-Meldungen

Der Core wurde anhand eines vollständigen Psalm-Laufs einschließlich
Taint-Analyse und einer Referenzprüfung im gesamten Git-Bestand bereinigt.
Der Core-Befund ging von 93 auf 0 zurück. Psalm-Konfiguration, Baselines und
Unterdrückungen wurden dafür nicht geändert. Die verbleibenden projektweiten
Meldungen betreffen andere Module.

## Entfernte, nicht angebundene Bestandteile

Die folgenden Klassen hatten keine produktiven Aufrufer außerhalb ihres eigenen
parallelen Aufbaus. Ihre Entfernung verändert keine registrierte Panel-Route und
entfernt keine Datenbanktabelle.

| Bereich | Entfernte Bestandteile | Weiterhin maßgeblich |
| --- | --- | --- |
| Dateien | `SftpFileService`, `WebspaceFileServiceClient` | Aktive Agent-Dateiaktionen, `FileServiceClient`, Gameserver-Client, vorhandene SFTP-Backups und Host-Key-Verifikation |
| Backups | `InMemoryBackupPlanStore` | Verschlüsselter persistenter Plan- und Archivbestand |
| Mail | `MailBackendContext`, `MailDkimKeyGenerator`, `MailControlPlaneJobEnqueuer`, sechs nicht konsumierte Mail-Request-/Job-DTOs | Aktive Mail-Controller, native Agent-Jobs und deren bestehende Payloads; vorhandener Messenger-Consumer bleibt für sein Nachrichtenformat erhalten |
| Benachrichtigungen | Unbenutzter Bot-/Discord-Provider-Aufbau und Registry | `NotificationService`, Präferenzen und aktive Versandwege |
| Webhooks | Nicht aufgerufener Core-Dispatcher samt eigenem URL-Validator | Bestehende tatsächlich angebundene Modul- und Erweiterungswege |
| Weitere Prototypen | `DdosProviderRegistry`/-Interface, `GithubWorkflowDispatcher`, `PermissionChecker`, `ShopPriceCalculator`, `UiRefactorFlagService`, `DeployStatus` | Aktive DDoS-Verarbeitung, Update-Dienste, Zugriffskontrollen, Shop-Bereitstellung und aktuelle Oberfläche |

Sieben ausschließlich zu diesen Komponenten gehörende Testdateien und drei
isolierte SFTP-Dateidienst-Fälle wurden mit ihren unbenutzten Komponenten
entfernt. Der echte Admin-/CSRF-Test für das Hinterlegen des SFTP-Hostschlüssels
und die Tests der aktiven SFTP-Backup-Schreiber bleiben erhalten. Alle aktiven
Produktionspfade werden weiter geprüft.

## Angebundene Verträge und erhaltene Abläufe

- SFTP- und Agent-Dateifehler teilen ihre unveränderten Fehlercodes, HTTP-Status,
  Details und Ursachen über `RemoteFileException`. Ihre konkreten Exception-
  Klassen bleiben bestehen.
- Konfigurationsparser werden über ihr eigenes Format gewählt. Die bisherigen
  Aliase und der Key-Value-Fallback bleiben erhalten.
- Die Release-Auswahl nutzt den gemeinsamen `GithubReleaseResolver` samt seinem
  Cache. Private Test-Brücken und nicht konsumierte Core-Versionshüllen wurden
  entfernt; die Auswahltests prüfen den echten Resolver. Der Core-Checker
  benötigt dadurch keine unbenutzten zusätzlichen Cache-Argumente mehr.
- Zahlungsanbieter können über ihren Namen und über eine unterstützte Zahlungsart
  gefunden werden. Erzeugte Referenzen erscheinen in den bestehenden
  Diagnoseprotokollen, ohne Provider-Geheimnisse zu protokollieren.
- Archiv-/Bereitstellungsergebnisse liefern die maßgeblichen Ressourcen für die
  Rückmeldung. Miet-, Auftrags-, Sinusbot-, Modul-, DDoS- und Firewall-Antworten enthalten
  zusätzliche Bestätigungsheader. Ein gespeicherter Logging-/Retention-Wechsel
  erhält einen Audit-Eintrag und dessen Hash als `X-Audit-Hash`.
- Der Job-Logger arbeitet als Befehl ohne ungenutzten Entity-Rückgabewert; er
  erzeugt ein Log-Objekt erst, wenn die Historieneinstellung dessen Speicherung
  vorsieht.
- Domänenereignisse und Benachrichtigungsbatches erhalten strukturierte
  Debug-Informationen. GDPR-Läufe protokollieren ihre tatsächlichen Löschzahlen.
- Scheitert der Start des Recovery-Migrationsrunners, wird der Auftrag als
  fehlgeschlagen vermerkt. Er bleibt nicht irreführend auf „pending“ stehen.
- Gemeldete Agent-Rollen werden bei einem Metadaten-Heartbeat als
  `reported_roles` diagnostisch festgehalten. Die im Panel zugewiesenen Rollen
  bleiben unabhängig davon maßgeblich.
- Zeitpläne zeigen vorhandene Erstellungs-/Änderungszeiten. Die Update-Seite
  zeigt verfügbare SHA-256-Werte und einen Link zur angebotenen Signaturdatei.
  Der Link ist Metadatenanzeige; er ersetzt keine Signaturprüfung. Der vorhandene
  SHA-256-Prüfpfad bleibt bestehen.

Die Konfigurationsspeicherung schreibt bei Teilaktualisierungen nur die
angeforderten beziehungsweise davon abhängigen geänderten Werte. Der Scheduler
setzt seinen lokalen Einstellungs-Cache vor jedem Durchlauf zurück. Ein
Heartbeat überschreibt damit keine inzwischen von einem Administrator geänderte
Aufbewahrungsfrist und verschlüsselt keine unbeteiligten Geheimnisse erneut.

## Kompatibilität und Deployment

Interne Erweiterungen mit direkten Imports entfernter Prototypen, direkter
Konstruktion des alten `CoreReleaseChecker` oder Auswertung des bisherigen
`JobLogger::log()`-Rückgabewerts müssen angepasst werden. Die registrierten
Anwendungsrouten und die im Repository verwendeten Dienstaufrufe bleiben
kompatibel. Diese Bereinigung benötigt keine weitere Datenmigration; die
[Job-/Metrik-Aufbewahrung](../operations/job-and-metric-retention.md) benötigt
weiterhin ihre bereits beschriebene Index-Migration.

Nach dem Deployment Autoload und Symfony-Cache erneuern und lang laufende
PHP-/Messenger-Prozesse mit der neuen Version starten.
