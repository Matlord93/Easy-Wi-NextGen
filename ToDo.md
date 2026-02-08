Gesamtzusammenfassung – Was muss gemacht werden?

Das Projekt ist ein zentrales Hosting- und Server-Panel, bestehend aus einem Symfony-8-Webinterface und einem Go-Agenten für Windows & Linux. Ziel ist es, alle Hosting-, Infrastruktur-, Voice- und Game-Server-Dienste stabil, modular und transparent zu verwalten.

1. Grundsystem & Architektur
 -Zuerst muss eine saubere Basisarchitektur geschaffen werden:
 -Klare Trennung zwischen Webinterface, Agent, Nodes und Modulen
 -Einheitliche API-Kommunikation zwischen Webinterface und Agent
 -Agenten müssen zuverlässig ihren Status, Metriken und Fehler melden
 -Alle Aktionen (Provisioning, Backups, Policies) laufen über Jobs/Queues
 -Sicherheitsrelevante Daten (Tokens, Passwörter) werden sicher gespeichert
 -Jede relevante Aktion wird im Audit-Log dokumentiert

2. Benutzerkonten & Sicherheit
 -Das Login- und Account-System muss deutlich sicherer werden:
 -Überarbeitung des Login-Flows
 -Automatischer Logout nach Inaktivität
 -Sauberes Session-Handling (kein dauerhaftes Eingeloggt-sein)
 -Optionale und verpflichtende 2FA (TOTP)
 -Schutz vor Brute-Force-Angriffen
 -Nachvollziehbare Login- und Logout-Events

3. Monitoring & Instanzmetriken
 -Alle Server und Dienste müssen überwachbar und nachvollziehbar sein:
 -Der Agent sammelt CPU-, RAM-, Speicher-, Netzwerk-, Temperatur- und Prozessdaten
 -Diese Daten werden historisch gespeichert und aggregiert
 -Das Webinterface zeigt Live-Werte und Zeitverläufe
 -Server erhalten einen klaren Status (OK / Warnung / Kritisch)
 -Fehlerhafte oder nicht erreichbare Nodes sind sofort sichtbar

4. Security & DDoS-Management
 -Der komplette Security-Bereich muss neu aufgebaut werden:
 -Zentrale Verwaltung aller DDoS-, Firewall- und Fail2ban-Regeln
 -Transparente Anzeige, was geblockt wird und warum
 -Historie und Events zu Angriffen und Blocks
 -Konfigurierbare Regeln über das Panel
 -Einheitlicher Security-Bereich statt verteilter Einstellungen

5. Webspaces & Domains
 -Webhosting muss vollständig über das Panel steuerbar sein:
 -Kunden können Homepages und Subdomains verwalten
 -Saubere Provisionierung auf den Servern (z. B. Nginx/Apache)
 -Statusanzeige, ob Änderungen erfolgreich angewendet wurden
 -Sicherheitsprüfungen für Pfade und Konfigurationen

6. Mailserver & E-Mail-Hosting
 -E-Mail-Hosting wird direkt ins Panel integriert:
 -Kunden können eigene E-Mail-Adressen pro Domain erstellen
 -Verwaltung von Mailboxen, Passwörtern, Weiterleitungen und Aliases
 -Integration von Roundcube zur Webmail-Verwaltung
 -Anzeige aller Mail-Einstellungen für Clients wie Outlook oder Thunderbird
 -Admins können Mailserver und Limits verwalten

7. CMS-System
 -Das CMS wird funktional erweitert:
 -Wartungsmodus mit Wartungsseite (auch zeitgesteuert)
 -Umbau des Template-Systems, damit eigene Templates problemlos nutzbar sind
 -Template-Manager im Panel
 -Bereitstellung von drei Demo-Templates für Kunden

8. Datenbank-System
 -Datenbanken müssen als Self-Service funktionieren:
 -Admins hinterlegen Datenbank-Server (Nodes)
 -Kunden wählen DB-Typ, Namen, Benutzer und Passwort
 -Automatische Erstellung und Verwaltung
 -Übersicht, Passwort-Reset und Löschen über das Panel

9. Agenten-Verwaltung
 -Agenten müssen übersichtlich und wartbar sein:
 -Bessere Admin-Übersicht
 -Anzeige von Version, Betriebssystem und Status
 -Agenten müssen jederzeit sauber aus dem System entfernt werden können
 -Klare Zuordnung zu Nodes und Diensten

10. Backup-System (global)
 -Ein zentrales Backup-System für alle Dienste:
 -Backups lokal, über Netzwerk oder per Nextcloud
 -Kunden können eigene Backup-Ziele nutzen (ohne Limits)
 -Automatische und manuelle Backups
 -Wiederherstellung über das Panel
 -Einheitliches System für Web, DB, Mail, Voice und Game-Server

11. Voice-System
 -Das Voice-Modul muss stabilisiert und modularisiert werden:
 -Fix der Query-Probleme (Rate-Limit & Sperren)
 -Korrekte Ansteuerung von Teamspeak-Servern (v3 & v6)
 -Komplett überarbeiteter User-Bereich
 -Modularer Aufbau für zukünftige Voice-Systeme

12. Game-Server-Modul (zentrales Großmodul)
 -Der Game-Server-Bereich ist ein eigenes, komplexes Modul und muss komplett neu aufgebaut werden:
 -Kernprobleme, die gelöst werden müssen:
 -Serverstatus ist aktuell falsch oder inkonsistent
 -Query-Abfragen funktionieren nicht zuverlässig
 -CPU/RAM-Werte sind fehlerhaft (immer 100 %)
 -Live-Konsole zeigt keinen echten Zustand
 -Dateimanager, FTP/SFTP und Config-System funktionieren nicht
 -Addons lassen sich nicht installieren
 -Neuinstallation löscht keine alten Daten

Zielzustand:
 -Echte Prozess-Überwachung durch den Agent
 -Korrekte Status- und Ressourcenanzeige
 -Funktionierende Live-Konsole mit Befehlseingabe
 -Vollständiges Datei-, Config- und Addon-Management
 -Automatisierung (Backups, Neustarts, Updates, Versionssperren)
 -Saubere Neuinstallation inklusive kompletter Datenlöschung

13. Datenschutz (GDPR)
 -Eigener Datenschutz-Bereich im Panel
 -Übersicht über relevante Einstellungen und Logs
 -Trennung vom Marketplace

14. Mehrsprachigkeit & UI
 -Komplettes Interface auf Deutsch und Englisch
 -Kein Sprachmix mehr
 -Sprachumschaltung im Panel

Gesamtziel
 -Am Ende entsteht ein professionelles, modulares Hosting-Panel, das:
 -stabil läuft
 -transparent ist
 -sauber überwacht
 -sicher konfigurierbar ist
 -und sowohl für Kunden als auch Admins zuverlässig funktioniert
