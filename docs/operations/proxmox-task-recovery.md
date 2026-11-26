# Unbestätigte Proxmox-Aufträge prüfen

Ein Transportfehler, eine verlorene Antwort oder ein HTTP-5xx-Fehler nach einem
Schreibaufruf beweist nicht, dass die Aktion unterblieben ist. Das Panel setzt
solche Aufträge auf `uncertain` und behält die exklusive VM-Sperre. Ein nach einem
Panelabbruch mindestens zwei Minuten alter `submitting`-Auftrag ohne UPID wechselt
ebenfalls in diesen Zustand. Die Sperre wird dabei nicht durch Zeitablauf gelöst.

Eine eindeutige HTTP-4xx-Ablehnung beendet den Auftrag dagegen als fehlgeschlagen.
Bekannte UPID-Aufträge werden weiterhin über den tatsächlichen Proxmox-Status
abgeschlossen. Derselbe Anforderungsschlüssel führt keine Aktion erneut aus;
ein anderer Schlüssel kann eine unbestätigte VM-Sperre nicht umgehen.

## Administrativer Ablauf

1. In der Aufgabenliste „Auftragsdetails“ öffnen. Der Kundenbereich zeigt den
   Zustand, bietet aber keine Freigabe an.
2. Direkt in Proxmox die Auftragsliste und den tatsächlichen VM-/Storage-Zustand
   prüfen. Insbesondere nach Backup-, Datenträger-, Clone- oder Restore-Aktionen
   nicht aus einer fehlenden Panelantwort auf einen Fehlschlag schließen.
3. Erst nach dieser Prüfung Erfolg oder Fehlschlag auswählen, eine Prüfnotiz
   eingeben und die Bestätigung aktivieren. Die Aktion verlangt Adminrechte und
   einen CSRF-Token.
4. Das Panel liest die Cluster-Nodes erneut. Alle Nodes einschließlich des
   ursprünglichen Nodes müssen erreichbar sein. Das Token braucht `Sys.Audit`
   auf allen Nodes, damit die Liste fremder aktiver Aufträge nicht unbemerkt
   unvollständig ist. Passende laufende Jobs und eine Proxmox-Konfigurationssperre
   verhindern die Freigabe.
5. Nur der Panelauftrag wird mit dem manuell geprüften Ergebnis beendet; es wird
   keine Aktion auf der VM wiederholt. Ergebnis, Prüfer und Notiz werden im
   Auditprotokoll gespeichert. Danach sind neue Panelaktionen wieder möglich.

Die frischen API-Prüfungen bestätigen Ruhe und Erreichbarkeit; sie ersetzen nicht
die inhaltliche Prüfung des Ergebnisses durch den Betreiber. Verlorene Antworten
werden deshalb nicht automatisch als Erfolg oder Fehlschlag interpretiert.
Ein späterer separater Root-Eingriff direkt auf Proxmox liegt außerhalb der
Panel-Sperre.

## Passwortübergabe bei der Erstinstallation

Nach einer verlorenen Antwort auf die Passwortänderung prüft das Panel beim
nächsten Versuch die Ziel-VM und verwendet die verschlüsselt am Auftrag
hinterlegten Zugangsdaten. Nach einer Ablehnung oder fünf Minuten ohne
Bestätigung ist eine manuelle Prüfung erforderlich. In diesem Zustand bleiben
die Kundenzuordnung, der Passwortabruf und der Abrechnungsbeginn ausstehend.

## Unterbrochene Neuinstallation

Unter **Auftragsdetails** kann ein Admin die vorherige Systemdisk wiederherstellen.
Bei einer verlorenen Importantwort muss die eingebundene Disk die beim Import
gesetzte Seriennummer des Auftrags tragen und die bisherige Disk als ungenutzt
vorhanden sein. Das Panel prüft beides bei der Bestätigung. Ein laufender Import,
ausstehende Änderungen oder eine uneindeutige Zuordnung blockieren den Rücktausch.
Die Ersatzdisk bleibt nach der Wiederherstellung auf dem Storage erhalten.

Nach einer verlorenen Antwort beim Ändern der Laufwerksoptionen, beim Vergrößern
oder beim Start prüft das Panel bis zu fünf Minuten lang die VM-Konfiguration,
den Datenträger und den Auftragsstatus. Ist der erwartete Zustand bestätigt,
läuft die Neuinstallation mit dem nächsten Schritt weiter. Bei fehlender
Bestätigung bleiben die Auftragsdetails für die manuelle Prüfung verfügbar.

Bei einer unterbrochenen Antwort auf die Passwortänderung prüft das Panel die
VM und sendet denselben Passwort-Hash erneut. Eine Ablehnung oder der Ablauf
der Bestätigungsfrist hält die bisherige Systemdisk für die Prüfung vor.

Geht die Antwort beim Entfernen der bisherigen Systemdisk verloren, prüft das
Panel die VM und den Storage bis zu fünf Minuten lang. In **Auftragsdetails**
steht anschließend **Entfernung der bisherigen Systemdisk prüfen** zur Verfügung.
Der Abschluss setzt eine passende VM-Konfiguration, eine sichtbare Ersatzdisk
und das Fehlen der ursprünglichen Disk im Storage voraus. Für die Abfrage
benötigt der Token `Datastore.Audit` auf dem Ziel-Storage.
