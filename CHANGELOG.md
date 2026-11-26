# Changelog

Änderungen am Easy-Wi NextGen Panel, Agent und Installer.

## 2026-11-26 · Unveröffentlicht

### Gameserver

- Installation und Start verwenden den für die Instanz gespeicherten Installationspfad. Pfade mit Leerzeichen werden an SteamCMD und systemd korrekt übergeben.
- Source-1- und Source-2-Server übernehmen ihre Startparameter auch bei der Installation. Leere Workshop-Angaben blockieren den Installationsjob nicht mehr.
- Der zentrale Scheduler aktualisiert die Festplattenauslastung der Gameserver und ordnet die Messergebnisse der richtigen Instanz zu.

### Agent und Proxmox

- Der Agent verwendet eine gegen GO-2026-6278 abgesicherte WebSocket-Bibliothek.
- Die Proxmox-Aktionsprüfungen wurden so umgebaut, dass Psalm sie mit weniger Analyseaufwand verarbeiten kann.

### CI und Releases

- Der Changelog wird manuell in dieser Datei gepflegt. Die CI prüft die datierten Abschnitte und ermittelt den Veröffentlichungsstatus auf GitHub.
- Panel, Installer, Agent-Pakete, Manifeste und Prüfsummen werden in einem gemeinsamen Release-Entwurf gesammelt. Der Workflow veröffentlicht ihn nach erfolgreichem Upload.

## 2026-11-23 · Unveröffentlicht

### KVM und Proxmox

- Unterbrochene Neuinstallationen können an gespeicherten Zwischenschritten fortgesetzt werden. Der Ablauf erkennt unter anderem bereits entfernte oder importierte Systemdisks.
- Proxmox speichert das initiale Root-Passwort vor der Bestätigung im Gast und prüft die Passwortübergabe, bevor die VM als fertig bereitgestellt gilt.
- Abgelaufene Zugangsdaten zur einmaligen Passwortübergabe werden bei der geplanten Bereinigung entfernt.

## 2026-11-22 · Unveröffentlicht

### Updates und Agent

- Panel und Agent prüfen neuere Versionen im jeweils eingestellten Release-Kanal.
- Das Linux-Agent-Update erhält die Ausführungsrechte der Agent-Datei, die auch von Gameserver-Diensten verwendet wird.

### Kundenbereich und CMS

- Kunden können ihre Gameserver gesammelt neu starten.
- Das öffentliche Kontaktformular schützt Einsendungen mit einer CAPTCHA-Prüfung und weiteren Spam-Schutzmaßnahmen.

## 2026-11-21 · Unveröffentlicht

### Kundenbereich und Shop

- Mietverträge sind direkt im Kundenbereich erreichbar.
- Der Shop übernimmt die im Produkt hinterlegten Gameserver-Slots. Neue Kunden können ihre für die Bestellung nötigen Daten bei der Registrierung erfassen.
- Nach dem Erstellen eines Tickets erscheint eine Bestätigung und der Kunde gelangt zur Ticketübersicht. Fehler beim Antworten im Adminbereich wurden behoben.

### Virtualisierung

- Aktivierte KVM- und Proxmox-Module erscheinen wieder in der Navigation.
- Kunden können VM-Zugangsdaten abrufen, das Root-Passwort ändern und nach Bestätigung eine Neuinstallation starten. VM-Backups können im Kundenbereich wiederhergestellt werden.

## 2026-11-20 · Unveröffentlicht

### Gameserver

- Source-Server laden ihre `server.cfg` beim Start. Fehlt sie, kann das Panel sie beim Speichern der Konfiguration anlegen.
- Starten, Stoppen und Neustarten bleiben im Kundenbereich auf der Gameserver-Ansicht.
- Der Agent verwendet für Spielabfragen eine geeignete Adresse statt eines Hostnamens, der auf `127.0.1.1` auflöst.
- Bei 7 Days to Die verwaltet das Panel Spiel-, Dashboard- und Telnet-Port. Die ausführliche Unity-Speicherdiagnose wird aus der Live-Konsole ausgefiltert.

### Panel-Updates

- Der Updater kann Release-Archive mit langen Dateipfaden entpacken.
