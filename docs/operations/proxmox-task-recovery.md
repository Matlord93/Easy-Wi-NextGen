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
