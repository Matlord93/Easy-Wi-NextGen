# Core: Festplattenmessung und Last

## Messwerte und behobene Ursachen

Der Heartbeat aktualisiert die vom Agent gemeldeten Fähigkeiten. Er erhält die im
Panel gespeicherten Einstellungen sowie `disk_stat` und `disk_protection` aus den
Job-Ergebnissen. Zuvor ersetzte er die gesamten Metadaten: Dadurch verschwanden
Festplattenwerte und ihr Messzeitpunkt; der Scheduler konnte unnötig weitere
Scans einplanen. Weggefallene native Fähigkeiten werden weiterhin entfernt.

Der zentrale Scheduler (`app:run-schedules`) plant Messungen für Linux-Gameserver
auch bei gestoppten Instanzen anhand des eingestellten Agent-Intervalls ein.
`disk:scan:reconcile` verwendet dieselbe Logik für einen manuellen Lauf.
Beide berücksichtigen wartende, beanspruchte und laufende Disk-Jobs. Der
Scheduler wählt über alle Agenten zuerst die ältesten fälligen Messungen aus.
Erfolgreiche Hintergrundmessungen erzeugen keine Admin-Benachrichtigung.
Ein Symfony-Lock verhindert gleichzeitig laufende Scheduler auf demselben
Panel-Host. Fehlgeschlagene oder abgeschlossene Jobs blockieren den nächsten Scan
nicht. Die Scanintervalle und das Instanz-Batchlimit bleiben erhalten.

Die Systemmetriken des Agent messen das Root-Dateisystem (`/`, unter Windows das
Systemlaufwerk). Unter Linux sind freie Bytes jetzt die verfügbaren Bytes aus
`Bavail`; belegte Bytes schließen reservierte Dateisystemblöcke aus. Der zusätzliche
Wert `reserved` erklärt die Differenz zwischen Gesamtgröße, belegtem und verfügbarem
Speicher. Der Prozentwert entspricht `used / (used + available)` wie bei `df`.

Davon getrennt misst `node.disk.stat` das Dateisystem des Instanz-Basisverzeichnisses
(`EASYWI_INSTANCE_BASE_DIR`, standardmäßig `/home`), und `instance.disk.scan` misst
den einzelnen Instanzordner mit `du`. Bei getrennten Volumes sind unterschiedliche
Kapazitäten in diesen Anzeigen richtig. Diese Änderung addiert keine Mounts oder
Datenträger und ändert keine Instanzpfade.

Das Dashboard lädt nur die sechs angezeigten Agenten; die Statussummen werden als
skalare Datenbankabfrage berechnet. Begrenzte Sparkline-Abfragen wählen die jüngsten
Messpunkte und geben diese anschließend chronologisch zurück. Dadurch fehlen die
aktuellen Werte nicht mehr hinter älteren Punkten des gewählten Zeitfensters.

## Ausrollen und Rücknahme

Die Migration `Version20261118020000` ist eine Expand-Migration: Sie ergänzt nur
die Indizes `jobs(type, status)` und `metric_samples(agent_id, recorded_at)`.
Bestehende Zeilen, Spalten und JSON-Nutzdaten bleiben unverändert; ein Backfill
ist nicht erforderlich. Alte und neue Anwendungsversionen können beide mit den
Indizes arbeiten. Die Laufzeit des Indexaufbaus hängt von der vorhandenen
Tabellengröße und der Datenbank ab; auf großen Installationen zuerst in Staging
prüfen und das übliche Migrationsfenster verwenden.

Beim Deployment die regulären Doctrine-Migrationen anwenden und den Agent über den
bestehenden Release-/Updateweg aktualisieren, damit auch die Linux-Messkorrektur
aktiv ist. Ein App-Rollback benötigt keinen Datenbank-Restore. Das `down()` dieser
Migration entfernt ausschließlich die beiden neu benannten Indizes.

Nach dem Rollout müssen `disk_stat.checked_at` und die Werte mehrere Heartbeats
überstehen, aktive Disk-Jobs dürfen durch wiederholte Scheduler-Aufrufe nicht
anwachsen, und der letzte Sparkline-Punkt muss dem jüngsten Messwert entsprechen.
Die Gameserver-Übersicht liest den gespeicherten Messwert einmal pro Minute.
Sie kennzeichnet fehlende oder veraltete Werte. Nach einem Messfehler bleibt
der letzte gültige Wert erhalten. Die Größe eines Gameserver-Verzeichnisses
lässt sich mit `du -sb -- "$INSTALL_PATH"` vergleichen.
Kapazitäten mit `df -B1 /` beziehungsweise `df -B1 "$EASYWI_INSTANCE_BASE_DIR"`
auf dem Agent vergleichen. Der bisherige Lock-Store ist `flock`; mehrere
Panel-Hosts benötigen weiterhin einen gemeinsam konfigurierten Lock-Store.
