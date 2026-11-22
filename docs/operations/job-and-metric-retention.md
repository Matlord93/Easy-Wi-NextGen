# Jobs, Aufgabenhistorie und Messwerte

Unter **Administration → Einstellungen → Logging** legt „Jobs und Aufgaben“ fest,
welche Historie gespeichert und in der zentralen Job-/Aufgabenübersicht angezeigt
wird. Der Standard `Alles` erhält die bisherige Speicherung.

| Auswahl | Historie und zusätzliche Job-Protokolle |
| --- | --- |
| Alles | Alle Jobs und Aufgabenläufe bis zur jeweiligen Aufbewahrungsfrist |
| Nur Kundensysteme und Fehler | Ressourcenbezogene Jobs für Gameserver, virtuelle Voice-Server, Webspaces, Datenbanken, Mailboxen, Musicbots und VMs; außerdem sämtliche Fehler |
| Nur Fehler | Fehlgeschlagene Jobs und Aufgabenläufe samt Fehlerergebnis |

Im Kundenmodus entfallen auch übersprungene automatische Aufgaben ohne ausgeführte
Aktion. Die Auswahl deaktiviert keine Aufgabe oder Wartung. Kundenansichten,
Aktionsprüfungen und Ergebnisabfragen verwenden weiterhin die operativen Jobs;
der Historienfilter greift nur in den zentralen Historienabfragen. Unbekannte
Job-Typen gelten im Kundenmodus als Systemjobs. Neue Modul-Typen müssen daher bei
Bedarf in `JobHistoryPolicy::CUSTOMER_PATTERNS` aufgenommen werden.

Jobs müssen bis zur Ausführung und Verarbeitung ihrer Ergebnisse in der Datenbank
stehen. Nicht ausgewählte erfolgreiche/abgebrochene Jobs werden nach **24 Stunden**
entfernt. Gewählte erfolgreiche Historie verwendet die Routinefrist, Fehler die
Fehlerfrist. Queued/Claimed/Running-Jobs und von vorhandenen Backup-Datensätzen
referenzierte Ergebnisse werden nicht entfernt. Abhängige Job-Logs und Ergebnisse
werden zusammen mit dem Job in einer Transaktion gelöscht. Agent-Jobs verwenden
dieselbe Auswahl und dieselben Fristen; ihre Ergebnisdaten bleiben während der
operativen Frist für Verbraucher verfügbar.

Die bisherige separate Einstellung für allgemeine Routine-Audit-Logs bleibt
bestehen. Sicherheits- und administrative Audit-Ereignisse werden weiterhin nach
den vorhandenen Audit-/Datenschutzregeln gespeichert.

## Aufbewahrung der Messwerte

- Systemmesswerte und deren Aggregate verwenden `metrics_retention_days`.
- Gameservermesswerte verwenden `metrics_instance_retention_days`. Die zusätzliche
  Mengenbegrenzung im Heartbeat berücksichtigt jetzt die gewählte Tageszahl
  (288 Messpunkte je Tag bei fünf Minuten Mindestabstand).
- Infrastruktur-/VM-Messwerte und Rollups verwenden weiterhin die Frist in den
  Monitoring-Einstellungen (`infrastructure_monitoring.retention_days`). Ihre
  Bereinigung läuft auch bei deaktivierter Erfassung.

Vorher verwendete der CLI-Cleanup unabhängig von den gespeicherten Werten 30 Tage,
mit einer zusätzlichen Mindestfrist von 30 Tagen für Aggregate. Die zentrale
Job-Bereinigung war ein deaktivierter Platzhalter; ein zentraler Metrik-Cleanup
fehlte. Infrastruktur-Bereinigung war an einen erfolgreichen Collector-Lauf
gebunden. Bei Gameservermesswerten konnten außerdem die feste Grenze von 288
Punkten und die Begrenzung auf sieben Tage längere Einstellungen vorzeitig
verkürzen. Ein globaler Löschlauf je Metrik-Heartbeat wurde in die stündliche
Wartung verschoben.

## Automatischer Betrieb

`cleanup.jobs` und `cleanup.metrics` sind aktive Handler des vorhandenen zentralen
Schedulers (`app:run-schedules`). Sie bereinigen stündlich; ein Cache-Marker
verhindert Wiederholungen innerhalb derselben Stunde. Die Fristen werden bei der
nächsten Bereinigung erneut gelesen. Unter „Geplante Aufgaben“ lassen sich die
beiden Handler auch direkt ausführen. Der bisherige CLI-Befehl
`app:metrics:cleanup` nutzt ohne Optionen die gespeicherten Fristen;
`--days=...` ist eine ausdrückliche temporäre Überschreibung aller Metrikfristen
(1–365 Tage).

Löschläufe arbeiten mit begrenzten Batches und separaten Locks. Ein großer alter
Datenbestand wird dadurch schrittweise abgebaut; nach einer verkürzten Frist kann
bis zum vollständigen Abbau mehr als ein Lauf nötig sein. Der vorhandene
Scheduler-Timer beziehungsweise der Request-Trigger muss laufen. Ohne Timer und
ohne Panel-Aufrufe werden keine Hintergrundaufgaben ausgelöst. Bei mehreren
Panel-Hosts sind ein gemeinsamer Lock-Store und Cache entsprechend der allgemeinen
Scheduler-Konfiguration erforderlich.

Die Expand-Migration `Version20261118030000` ergänzt ausschließlich Zeit-/Status-
Indizes für die fünf betroffenen Tabellen. Sie verändert oder löscht keine
Datensätze. Reguläre Migrationen anwenden, Cache erneuern und lang laufende PHP-
Prozesse mit der neuen Version starten. Ein Code-Rollback funktioniert mit den
zusätzlichen Indizes. Bereits nach einer gewählten Frist entfernte Historie wird
durch einen Rollback nicht wiederhergestellt.

## Einstellungen in laufenden Schedulern

Ein Scheduler im Loop-Modus verwirft vor jedem Durchlauf seinen lokalen
Einstellungs-Cache. Dabei werden nur die zwischengespeicherten AppSetting-Entities
freigegeben; wartende Jobs und andere Domain-Entities bleiben erhalten. Auch
Symfony-/Messenger-Service-Resets verwenden diesen Mechanismus.

Teilaktualisierungen schreiben nur angeforderte Werte und tatsächlich dadurch
geänderte abhängige Einstellungen. Ein Scheduler-Heartbeat schreibt so nur seinen
Zeitpunkt und Status, ohne unbeteiligte Geheimnisse erneut zu verschlüsseln oder
neue Aufbewahrungswerte aus einer anderen Admin-Anfrage zurückzusetzen. Innerhalb
eines Durchlaufs bleibt der Einstellungs-Cache wirksam.

Bestehende positive ganzzahlige Aufbewahrungsfristen werden nicht durch eine
kürzere Standardfrist ersetzt, auch wenn sie ungewöhnlich lang sind. Die
Infrastruktur-Einstellung behält ihren bisherigen Bereich von 1–365 Tagen.
