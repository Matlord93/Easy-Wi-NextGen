# Erweiterungen des bestehenden Systems

Stand: 12.11.2026. Es werden die vorhandenen Symfony-Module und der Go-Agent erweitert. Bestehende Instanzen und Daten bleiben erhalten. Die vom Nutzer gewählte erste Priorität ist das **Rechnungssystem**. Die anderen Bereiche bleiben als Folgepakete erfasst; vorhandene Backendbausteine bedeuten noch keine abgeschlossene Integration.

## Paket 1: Rechnungssystem

- [x] Echte A4-PDFs mit Umlauten, Steueraufteilung, Empfänger-/Absenderdaten und Zahlungshinweisen statt HTML-Dateien erzeugen.
- [x] Neue Rechnungen mit Steuer-/Adresssnapshot und archivierter PDF ausstellen; vorhandene Archive unverändert lassen.
- [x] Automatische Zahlungserinnerungen an den zentralen Scheduler anbinden; Abstände, Gebühren, Aktivierung und Wiederholung fehlgeschlagener Zustellung berücksichtigen.
- [x] Rechnungshistorie mit Zahlungen, Gutschriften, Mahnstufen und eigenen PDF-Downloads im Kundenbereich bereitstellen.
- [x] Gutschriften und Storno mit Begründung, Betragsprüfung, Transaktion und Auditspur ergänzen.
- [x] Regelbesteuerung, steuerbefreite Leistung, Reverse Charge und Kleinunternehmerregelung mit konfigurierbarem Steuersatz unterstützen.
- [x] Teilzahlungen, idempotente Stripe-Bestätigungen, verzögerte Zahlungen und paginierten Zahlungsabgleich berücksichtigen.
- [x] CSV-Buchhaltungsexporte um Steuerdaten, Gutschriften und offene Beträge ergänzen; Tabellenformeln entschärfen.
- [x] Absenderdaten und Rechnungsvorlagen anpassbar machen; Vorlagen ohne Zugriff auf App-Services oder lokale Dateien rendern.
- [ ] Auf dem HomeServer nach gesonderter Übernahme der Änderungen mit Testkunde und Stripe-Testmodus prüfen.

Details, Migrationshinweise und Prüfschritte: [Rechnungssystem](../operations/billing.md).

## Paket 2: Proxmox

Vorhanden: `ProxmoxApiClient` und Verbindungsmodell, einschließlich API-Funktionen für Ressourcen und VM-Aktionen.

- [x] Bestehende API-Anbindung als durchgängigen Admin-/Kundenablauf anbinden.
- [x] VM Start, Stop und Restart mit berechtigten Aktionen und Taskfortschritt anbieten.
- [x] Ressourcen von Nodes und VMs anzeigen.
- [x] Snapshots anlegen, auflisten, zurückspielen und löschen.
- [x] Backups und Wiederherstellung integrieren.
- [x] ISO- und Template-Auswahl gegen vorhandene Storages validieren.
- [x] Cluster-Nodes und Ressourcen clusterweit berücksichtigen.

- [ ] Live-Abnahme am vorhandenen Einzelhost mit dedizierter Test-VM; zusätzliche Cluster-Live-Abnahme, sobald verfügbar.

Automatisiert geprüft: Admin-/Kundenabläufe, Einzelhost-/Cluster-Routing, Rechte-/Identitätsprüfungen, Datenträgervalidierung, Aufgabenverfolgung und atomare Auftragsannahme. Siehe `wiki/Proxmox-Integration.md`.

- [x] VNC-Konsole im Kundenbereich mit Einmalticket, Zuweisungsprüfung und serverseitigem Gateway ergänzen.

## Paket 3: KVM

Vorhanden: `KvmJobService` und Agenthandler für VM-, Snapshot-, Limit- und Netzwerkaktionen.

- [x] Verwaltung und Jobstatus im Panel vervollständigen.
- [x] Versionierte VM-Templates anbinden.
- [x] Cloud-Init mit eigenen Identitäten und instanzbezogenen Zugangsdaten integrieren.
- [x] Snapshots und Backups einschließlich Restore abdecken.
- [x] Netzwerk-Bridges verwalten und erlaubte Hostnetzwerke prüfen.
- [x] Ressourcenlimits je VM darstellen und durchsetzen.

- [ ] Live-Abnahme einer separaten VM auf dem HomeServer: erster Gaststart, Cloud-Init/SSH, Ressourcen, Snapshot und Restore.

Implementiert im Adminbereich mit verwaltetem Hostkatalog. Native qcow2-/Cloud-Init- und XML-Prüfungen sowie Agent-/Panel-/Browsertests; libvirt-Steuerung in den automatisierten Tests simuliert. Siehe `wiki/KVM-Integration.md` und `docs/reports/kvm-erweiterung.md`.

- [x] KVM-Kundenzuweisung und VNC-Konsole mit privatem libvirt-Socket ergänzen.

## Paket 4: Docker

Vorhanden: `DockerJobService`, Agenthandler, Compose-Aktionen sowie Volume-Backup/-Restore.

- [ ] Containerverwaltung und Imageverwaltung vollständig an die Oberfläche anbinden.
- [ ] Compose-Projekte mit validierter Konfiguration unterstützen.
- [ ] Containerlogs im Panel mit korrekten Zugriffsrechten anzeigen.
- [ ] Volumes und Netzwerke verwalten.
- [ ] Automatische Updates mit vorherigem Backup, Versionskontrolle und Fehlerstatus anbieten.

Prüfkriterium: Dediziertes Compose-Testprojekt; Lifecycle, Logs, Netzwerke, Volume-Backup und Restore geprüft.

## Paket 5: Gameserver über Docker

Vorhanden: `DockerGameTemplateCatalog` und Compose-Renderer mit Minecraft-, FiveM-, CS2- und Rust-Einträgen.

- [ ] Templates als vollständigen Installationsablauf verdrahten.
- [ ] Minecraft, FiveM, CS2 und Rust mit ihren tatsächlichen Laufzeitvoraussetzungen testen.
- [ ] Start, Stop und Restart einschließlich Rückmeldungen integrieren.
- [ ] Konfigurationsbearbeitung gegen tatsächliche Containerdateien anbinden.
- [ ] Gameserver-Backups und Restore integrieren.
- [ ] Shared Storage für unveränderliche Assets getrennt von Konfiguration, Identitäten und Spielständen verwenden. Ein gemeinsames vollständiges beschreibbares Datenvolume ist kein Ersatz für diese Trennung.

## Paket 6: TeamSpeak über Docker

Vorhanden: `TeamSpeakDockerComposeRenderer` und Tests des Renderers.

- [ ] Container-Template und automatische Installation vervollständigen.
- [ ] Start, Stop und Restart anbieten.
- [ ] Lizenzdaten und Tokens geschützt und instanzbezogen verwalten.
- [ ] Backup, Restore und Update mit eigenem Testserver prüfen.

## Paket 7: Shop und CMS

Vorhanden: Produkte, Varianten, Kategorien, Gutscheine, Preisberechnung, Bestellungen, Mietverträge und Provisioningbausteine.

- [ ] Shopabläufe vollständig ins CMS integrieren.
- [ ] Varianten und Kategorien durchgängig im Checkout berücksichtigen.
- [ ] Abo-Produkte mit Rechnungen, Laufzeit und Kündigung verknüpfen.
- [ ] Nach bestätigtem Zahlungseingang genau einmal provisionieren.
- [ ] Rabatte und Gutscheine innerhalb der Bestelltransaktion einlösen.
- [ ] Kunden-Bestellübersicht und Shop-Konfiguration im Adminbereich erweitern.

## Querschnittsanforderungen

- [ ] Rechte und Rollen je neuer Aktion prüfen.
- [ ] Aktivitäten, Fehler, Benachrichtigungen und Webhooks nachvollziehbar und ohne Zugangsdaten protokollieren.
- [ ] Neue API-Verträge dokumentieren und ihre tatsächlichen Antworten testen.
- [ ] Ladezeiten, Pagination, Queue-Verhalten und Datenmengen messen.
- [ ] Sicherheitsbefunde des Repository-Audits aufarbeiten, insbesondere bekannte Paketlücken.
- [ ] Shared Storage des nativen Gameserverbetriebs mit Install-/Updateabläufen, exklusiven Locks und instanzlokalen Schreibdaten prüfen. Die vorhandene Implementierung und ihre Dokumentation müssen vor einer Erweiterung abgeglichen werden.

Je Paket: erst automatisierte Prüfungen in einer isolierten Umgebung, anschließend ein gezielter Test auf dem HomeServer mit separaten Testressourcen. Bestehende Installationen werden nicht ohne nachvollziehbaren Änderungsstand ersetzt.
