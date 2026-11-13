# Proxmox-Erweiterung – Validierung am 12.11.2026

Basis ist der vom Benutzer hochgeladene Commit `ced620ac83b30ae54461591f34cfea74d9725378`.
Die zuvor implementierten Rechnungs-, Minecraft- und Sicherheitsänderungen sind
erhalten. Die Migration legt nur die neuen Proxmox-Tabellen an.

| Prüfung | Ergebnis |
|---|---|
| Gezielt: API-Vertrag, Geschäftsabläufe und HTTP-/Rechteprüfungen | 47 Tests, 139 Assertions, erfolgreich |
| Frontend-Verhalten in Node | 5 Tests erfolgreich |
| Chromium mit simuliertem PVE | Nodes und Gäste sichtbar; Snapshot angelegt; Taskstatus bis zum Abschluss abgefragt; Daten bei Ausfall erhalten; Kundenansicht begrenzt; keine JavaScript-Fehler |
| Einzelhost und mehrere Nodes | In den Fixtures geprüft, einschließlich aktuellem Node nach Gast-Migration |
| Getrennte Cluster | Getrennte HTTPS-Adressen und Authentifizierungsheader geprüft |
| TLS/Token | TLS aktiv; Redirects gesperrt; Zeitlimits; verschlüsselte Speicherung; keine Secrets in Formularen/JSON-Verbindungsdarstellung |
| Gäste und Datenträger | Fremde Backups/Storages und ungültige ISO-/Bridge-Auswahl abgelehnt; Cloud-Init und bestehende Festplatten beim ISO-Wechsel erhalten |
| Kundenrechte | Fremde Zuordnungen vor API-Zugriff abgelehnt; übermittelte Gast-ID überschreibt keine Zuordnung; wiederverwendete VM-ID ohne Ownership-Tag gesperrt |
| Snapshot-/Backup-Aktionen | API-Pfade, Bestätigungen, Gastbindung und gestoppter Restore-Zielgast geprüft; LXC-spezifische Parameter beim Klonen |
| MariaDB 10.11 | Alle 42 Migrationen erfolgreich; ORM-Mapping und Datenbankschema synchron |
| Parallele Auftragsannahme auf MariaDB | Vier unabhängige PHP-Prozesse mit verschiedenen Request-IDs für dieselbe VM: genau ein angenommener Auftrag und ein schreibender Aufruf im PVE-Dummy |
| Statusabgleich ohne Browser | Konsolenmonitor aktualisiert den Auftrag; die Gastsperre wird nach Abschluss freigegeben |
| PHPStan, Container und Twig | Erfolgreich |
| Gesamte PHP-Suite | 1425 Tests, 5210 Assertions; keine fehlgeschlagenen Assertions, zwei bekannte Fehler wegen fehlendem `ext-redis`, drei Warnungen, 301 PHPUnit-Hinweise, zehn übersprungene Tests |

Die Gesamtsuite wurde vor der letzten zusätzlichen ISO-Schutzprüfung ausgeführt;
die gezielten Proxmox-Prüfungen wurden danach erneut erfolgreich ausgeführt.
Die beiden Redis-Fehler liegen wie zuvor in `ConsoleStreamDiagnosticsTest` und
betreffen diese Cloud-PHP-Umgebung. Es wurden keine Tests unterdrückt.

Alle Infrastruktur-Aktionen dieser Prüfung gingen gegen simulierte PVE-Antworten.
Nur Datenbank-/Migrations- und Parallelitätstests liefen auf einer echten isolierten
MariaDB. Es wurden keine produktiven VMs gestartet, gestoppt, zurückgesetzt oder
gelöscht. Der echte Einzelhost des Benutzers wurde aus diesem Cloud-Arbeitsbereich
nicht angesprochen; dessen Version, Zertifikat, Token-Rechte und Storage-Fähigkeiten
müssen beim Live-Test verifiziert werden. Ein zusätzlicher Cluster-Live-Test bleibt
offen, sobald ein Cluster verfügbar ist.

Einrichtung, Rollen, Backup-/Restore-Verhalten und Live-Hinweise:
[Proxmox-Integration](../../wiki/Proxmox-Integration.md).
