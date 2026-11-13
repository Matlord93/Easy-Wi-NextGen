# Datenträger und Netzwerk bestehender VMs

## Proxmox: zusätzliche Datenträger

Im Einstellungsbereich einer VM steht „Zusätzlicher Datenträger“ bereit.
QEMU verwendet einen freien SCSI-/Virtio-Anschluss; LXC einen freien `mp`-Slot
mit neuem verwaltetem Volume unter `/mnt/<Name>` oder `/srv/<Name>`.
Es werden keine vorhandenen Slots überschrieben und keine Host-Bind-Mounts
erstellt. Ein in LXC belegter Gastpfad würde durch den Mount verdeckt; vorhandene
Dateien werden dabei nicht gelöscht.

Die Aktion verlangt Adminrechte, CSRF-Schutz, Bestätigung, eine gestoppte VM,
keine vorhandenen Snapshots und den unveränderten Konfigurationsdigest.
Ausgewählter Storage muss auf dem aktuellen Node aktiv sein und `images`
beziehungsweise `rootdir` unterstützen. Größen liegen zwischen 1 und 65536 GiB;
die tatsächliche Bereitstellung entscheidet der Storage auf Proxmox.

Neue Volumes erhalten ausdrücklich `backup=1`. QEMU erstellt den Datenträger
über den asynchronen Proxmox-Konfigurationsauftrag; LXC verwendet den synchronen
Konfigurationsendpunkt. Ein verlorenes Ergebnis wird nicht blind wiederholt,
sondern behält die Sperre bis zur [Auftragsprüfung](proxmox-task-recovery.md).

Neue QEMU-Datenträger müssen anschließend innerhalb von Windows oder Linux
initialisiert und eingebunden werden. Der Host greift nicht in Partitionen oder
Dateisysteme des Gasts ein. Kunden sehen angeschlossene Geräte und konfigurierte
Größen, erhalten aber weder Storage-Volume-Pfade noch die administrativen
Speicherformulare. Das Proxmox-Statusfeld `disk` wird bei QEMU ausdrücklich nicht
als belegter Dateisystemplatz interpretiert.

## KVM: vorhandenen Adapter umhängen

Im administrativen Einstellungsbereich einer verwalteten VM kann der bestehende
Adapter auf ein aktives libvirt-Netz oder eine Host-Bridge wechseln. Die Bridge
muss bereits existieren und in `EASYWI_KVM_BRIDGES` freigegeben sein. Das Modul
ändert keine Host-Netzwerkkonfiguration und unterbricht keine Host-Verbindung.

Voraussetzung ist ein aktualisierter Agent mit `network_config_version=1`.
Ältere Agents zeigen einen Aktualisierungshinweis. Die VM muss ausgeschaltet
sein und darf keine Snapshots besitzen. Bestätigung und die bisherige
Netzwerkkennung werden erneut am Agent geprüft; Fremd-Domains und veränderte
Gerätequellen werden nicht übernommen.

Die MAC-Adresse, Datenträger und Cloud-Init-Dateien bleiben erhalten. Der Gast
wird nicht automatisch gestartet. Seine IP-Konfiguration wird nicht verändert;
statische Adressen oder Routing müssen gegebenenfalls im Gast angepasst werden.
Kunden können diese administrativen Netz-/Bridge-Zuweisungen nicht ändern.

Schlägt eine Änderung fehl, versucht der Agent Domain und Manifest gemeinsam
wiederherzustellen und überprüft das Ergebnis. Ein fehlgeschlagener Rollback
behält den bestehenden Status `KVM_OPERATION_UNCONFIRMED` und sperrt weitere
Änderungen bis zur Prüfung des Hosts. Verlorene oder teilweise geschriebene
Konfigurationen werden damit nicht als erfolgreiche Änderung ausgegeben.

Dieser Ausbauschritt betrifft den vorhandenen einzelnen Adapter. Weitere
Adapter, VLAN-/IPAM-Verwaltung, zusätzliche KVM-Datenträger samt Mehrplatten-
Sicherungen und die weiteren Betriebsfunktionen sind noch in Arbeit.

## Bisherige lokale Abnahme

Proxmox-QEMU/LXC: Providerverträge, doppelte Requests, CSRF, freie Slots,
Snapshots, Storage-Auswahl, Digest, Kundentrennung und Browser-Formulare.
KVM: strukturierte Panel-Aufträge, Ablehnung bei alten Agents, Erhalt von MAC,
Disk und Cloud-Init, Fehler-/Rollback-Fälle, Race-Prüfung sowie echte QCOW2-
Dateien und libvirt-XML-Validierung in einem isolierten Testcontainer.

Dies startet keine echte VM und weist keine erfolgreiche Bridge-Kommunikation
auf dem HomeServer nach. Diese Live-Abnahme benötigt eine Verbindung zum Host.
