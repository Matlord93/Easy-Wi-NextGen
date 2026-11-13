# Dauerhafte Node- und VM-Messwerte

## Einrichtung

Nach Datenbanksicherung und den Migrationen `Version20261113010000` und
`Version20261113030000` steht unter
`/admin/monitoring` die Konfiguration bereit. Der bestehende zentrale Scheduler
führt `infrastructure.monitoring` einmal je Minute aus. Es gibt keinen neuen
dauerhaften Worker. Der Scheduler muss auch ohne Browserzugriff durch den
vorhandenen systemd-Timer aufgerufen werden.

Erfassung ist standardmäßig aktiv; Benachrichtigungen benötigen zunächst eine
anhaltende Überschreitung beziehungsweise einen Ausfall. Ein frisches Upgrade
erfindet keine historischen Messwerte. Bestehende Zuweisungen erhalten kein
geschätztes Startdatum. Neue Zuweisungen grenzen Messwerte einschließlich der
I/O-Messintervalle auf die tatsächliche Besitzzeit ein.

Die Links „Dauerhafter Messwertverlauf“ stehen an Proxmox-/KVM-Servern und an
administrativen Nodes. Kunden können ausschließlich den Verlauf ihrer aktuellen
Serverzuweisung öffnen. Clusterkennung, VM-Typ, VM-Kennung, Zuweisung und Besitzer
bilden die Datenkennung. Bei einer neuen Zuweisung beginnt ein neuer Verlauf;
beim Proxmox-Gast wird zusätzlich das aktuelle Eigentümertag geprüft.

KVM verwendet die bereits vorhandenen Agent-Heartbeats und die gespeicherte
verwaltete VM-Inventur. Es wird kein zusätzlicher Guest-Agent-Prozess pro
Messdurchlauf gestartet. Proxmox verwendet die Clusterressourcen und echte
RRD-Raten. Ohne verwertbare Quelle bleibt die Messgröße unbekannt. Insbesondere ist das
QEMU-Statusfeld `disk` keine Dateisystembelegung des Gasts; daraus wird keine
Speicherauslastung oder vermeintlich leere Festplatte berechnet. Konfigurierte
Datenträgergrößen und echte LXC-/Node-Dateisystemwerte bleiben getrennt.

## Aufbewahrung und Last

Standardaufbewahrung: 30 Tage, konfigurierbar 1–365. CPU/RAM/Disk und I/O besitzen
getrennte Quellzeitpunkte. Je Ressource, Datenstrom und Quellminute wird höchstens
eine Messung gespeichert. Identische wiederholte RRD- oder Heartbeat-Werte werden
nicht als neue Messungen ausgegeben. Echte Nullwerte bleiben erhalten.

Das ergibt maximal 2880 Zeilen pro vollständig gemessener VM und Tag, zuzüglich
Nodes. Der tatsächliche Datenbankbedarf hängt von aktiven Metriken und Indizes ab.
Bei großen Installationen die Datenbankgröße beobachten und Aufbewahrung passend
wählen. Pro Durchlauf werden je Tabelle höchstens 10000 abgelaufene Zeilen
gelöscht; dieselbe Aufbewahrungsfrist gilt für Rohdaten und Stundenarchive.

Jede neue Messung aktualisiert im selben Datenbankvorgang ein Stundenarchiv.
Es enthält Anzahl, Mittelwert, Minimum und Maximum je bekannter Messgröße,
Quellen und echte Beobachtungszeiten. Fehlende Werte fließen nicht als Null ein.
Lange Abfragen verwenden diese Archive; angebrochene Stunden und Besitzgrenzen
werden anhand der Rohdaten exakt ausgewertet. PDO-MySQL-Abfragen lesen Rohdaten
ohne einen vollständigen Ergebnispuffer im PHP-Arbeitsspeicher.

Vorhandene Rohdaten bleiben erhalten. Der Collector erstellt fehlende Archive
einmalig in begrenzten Schritten von bis zu 100 Ressourcenstunden. Solange
Archive fehlen, verwendet die Anzeige dafür die Rohdaten. Für einen schnelleren
Nachlauf kann ein Administrator ausdrücklich begrenzte Stapel ausführen:

```bash
sudo -u www-data php bin/console app:infrastructure:archive --env=prod --batch-size=100 --batches=10
```

Der Befehl schreibt ausschließlich Archive. Er löscht keine Rohdaten und kann
bei `pending: true` wiederholt werden. Bei der Einführung zuerst den bisherigen
Scheduler anhalten, migrieren und anschließend Worker und Scheduler mit dem
neuen Code starten; alte und neue Collector-Versionen nicht parallel betreiben.

Proxmox-Verbindungen und Gäste werden rotierend abgearbeitet; standardmäßig
32 Gäste pro Cluster, maximal vier Cluster und insgesamt etwa 25 Sekunden pro
Durchlauf zuzüglich einer gegebenenfalls bereits laufenden API-Anfrage. Ein
einzelner langsamer Aufruf kann dieses Budget bis zu seinem Transporttimeout
überschreiten. Große Installationen erhalten deshalb nicht zwangsläufig jede
Minute einen neuen Wert für jede VM. Lokale Locks sowie eindeutige Datenbankkeys
und Zustandsrevisionen verhindern doppelte Messungen und Alarme.

## Anzeige und Export

Zeiträume reichen von einer Stunde bis zu einem Jahr; ein eigener UTC-Zeitraum
ist ebenfalls möglich. Der Server liefert höchstens 600 aggregierte Zeitfenster
je Datenstrom. Die Ansicht zeigt Mittelwerte; der CSV-Export enthält zusätzlich
Minimum, Maximum, Messungsanzahl, Quellen, tatsächliche Beobachtungszeiten und
I/O-Intervallgrenzen. Ein Zeitfenster ist keine garantierte Messfrequenz.

Die Anzeige verwendet Europe/Berlin, CSV-Zeitstempel UTC. Größere Messlücken
unterbrechen die Linie. Fehlende Werte werden nicht zu null Prozent oder null
Byte pro Sekunde umgerechnet. Ein Browser aktualisiert die Ansicht höchstens
einmal pro Minute für bis zu 24 Stunden, alle fünf Minuten für eine Woche,
alle 15 Minuten für bis zu 31 Tage und stündlich für längere Zeiträume. Eigene
feste Zeiträume werden manuell aktualisiert. Verdeckte Seiten fragen nicht ab;
die Erfassung läuft davon unabhängig. Der Mittelwert größerer Zeitfenster wird
nach der tatsächlichen Anzahl der Messungen gewichtet, nicht nach der Zahl
der Stundenarchive.

## Alarme

CPU, RAM und Datenträgerbelegung besitzen globale und optionale individuelle
Schwellwerte. Standard: 90 Prozent für mindestens 180 Sekunden. Bekannte Werte
müssen dafür zeitlich zusammenhängen. Ausfälle werden standardmäßig nach
180 Sekunden gemeldet. Die Rückkehr wird ebenfalls gemeldet; bei Lastwerten
verhindert eine Hysterese von fünf Prozentpunkten häufiges Hin- und Herschalten.

Meldungen erscheinen im bestehenden Benachrichtigungssystem bei Admins und dem
zugewiesenen Kunden. Dessen In-App-Präferenz für `infrastructure` wird beachtet.
Das Modul richtet keinen zusätzlichen E-Mail-/SMS-Versand ein. Der globale
Benachrichtigungsschalter hat Vorrang vor individuellen Einstellungen. Ohne
verlässliche Metrik meldet die Ansicht „Noch keine verlässlichen Messwerte“.
Ein bereits aktiver Alarm wird durch einen unbekannten Messwert nicht als
erholt gemeldet.

Der Collector muss selbst laufen, damit Ausfälle gemeldet werden können. Ein
vollständiger Panel-/Scheduler-Ausfall benötigt daher zusätzlich externes
Monitoring. Nach einer erfolgreichen Inventur werden bis zu 1024 bekannte Nodes je Cluster
gemerkt. Bei Verlust der Cluster-API werden diese Nodes sowie zugewiesene VMs
als „Erfassung nicht verfügbar“ behandelt. Ein physischer Ausfall wird daraus
nicht bewiesen. Die letzten Lastwerte eines ausdrücklich offline gemeldeten
Nodes werden nicht als frische VM-Messungen weiterverwendet.

Ein manueller Lauf ist möglich:

```bash
cd /var/www/easywi/core
sudo -u www-data php bin/console app:infrastructure:collect --env=prod --force
```

Dieser Befehl schreibt Messwerte, kann Benachrichtigungen erzeugen und führt die
Aufbewahrung aus. Er ist kein rein lesender Diagnosebefehl. Die HomeServer-
Abnahme muss echte Agent-Heartbeats, RRD-Werte und den vorhandenen Scheduler
einschließen; lokale Fixturetests ersetzen sie nicht.
