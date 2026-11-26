# Ausbau von Proxmox und KVM

Die am 12. November 2026 bereitgestellten Screenshots und der Funktionskatalog
für VPS-/Cloud-Produkte dienen als fachliche Referenz. Priorität laut Nutzer:
zuerst neues Design mit vorhandenen Funktionen, zusätzliche Module danach.

## Aktueller Umbau

- Einheitliche Dashboard-, Node- und Serveransichten im Admin- und Kundenbereich.
- Node- und Server-Auslastung mit getrennten Ansichten für Messwerte und Limits.
- Serverlisten, Suche, Statusfilter und Verwaltungsreiter statt einer langen Formularseite.
- Bestehende Proxmox-Funktionen: Power-Aktionen, Snapshots, Backups, ISO-Auswahl,
  Vorlagen klonen, VMs anlegen, Kundenzuordnung, Aufgabenverfolgung, Kunden-noVNC.
- Bestehende KVM-Funktionen im Adminbereich: Cloud-Init-Anlage, Vorlagenimport,
  Power-Aktionen, Ressourcenlimits, Snapshots, Backups, libvirt-Netzwerke und
  Kundenzuordnung. Kunden sehen zugeordnete VMs, Messwerte und ihre VNC-Konsole.

Ausbau vom 13. November 2026: KVM-Kundensteuerung für Power, Snapshots und
Backups mit Auftragsübersicht; CPU-/RAM-Konfiguration bestehender Proxmox-VMs
und LXC-Container im Adminbereich. Details und Grenzen stehen im
[Umsetzungsbericht](../reports/vm-verwaltung-erweiterung.md).

Weiterer Ausbau vom 13. November 2026: Cloud-Init-Vorgaben für Proxmox-QEMU
und KVM im Admin-/Kundenbereich, Boot-Konfiguration und Guest-Agent-Anbindung.
Bereits initialisierte Gäste werden nicht automatisch neu initialisiert.
[Umsetzung, Voraussetzungen und Grenzen](../reports/vm-cloud-init-erweiterung.md).

Weitere Ergänzung: lesende Guest-Agent-Prüfung mit gemeldeter Agent-Version,
Hostname und Betriebssystem für Proxmox-QEMU und KVM im Admin-/Kundenbereich.
[Verhalten und Voraussetzungen](../reports/vm-gaststatus-erweiterung.md).

Weitere Ergänzung: vom Gast gemeldete Adapter, MAC-Adressen und IPv4-/IPv6-
Adressen mit Präfix im Admin-/Kundenbereich. Dies ergänzt die Anzeige; IPAM und
Netzwerk-Konfiguration bleiben weitere Module.
[Netzwerkinformationen](../reports/vm-netzwerkinformationen.md).

Weitere Ergänzung: VM-Netzwerk-/Datenträgerraten und begrenzte Browser-Verläufe
für CPU/RAM und VM-I/O. Proxmox verwendet gemittelte RRD-Werte, KVM vollständige
Zählerdifferenzen. [Durchsatz und Kurzverlauf](../reports/vm-durchsatz-und-kurzverlauf.md).

Weitere Ergänzung: CSV-Download des letzten verfügbaren Messwerts oder des
begrenzten Browser-Kurzverlaufs, getrennt für Server und Node, mit Quelle,
UTC-Zeitpunkt, Intervall und Messlücken.
[CSV-Messwertexport](../reports/vm-messwerte-csv-export.md).

Weitere Ergänzung: vorhandene Festplatten im Adminbereich vergrößern, für
Proxmox-QEMU, geeignete LXC-Speichervolumes und verwaltete KVM-VMs. Mit
Bestätigung, Zustands-/Größenprüfung und Snapshot-Sperre.
[Festplatten-Erweiterung und Grenzen](../reports/vm-festplatten-erweitern.md).

## Nachfolgende Module aus dem gelieferten Katalog

| Modul | Zu ergänzende Funktionen |
| --- | --- |
| VPS-/Cloud-Produkte | Einzel-VM- und Mehr-VM-Produkte, Kontingente, Up-/Downgrade, Bereitstellung, Suspend/Unsuspend und kontrollierte Kündigung |
| VM-Konfiguration | Reinstallation, Passwort und bestätigte Änderungen im bereits eingerichteten Gast, zusätzliche Disks, weitere Guest-Agent-Aktionen, Migration und Vorlagenkonvertierung; Cloud-Init-Vorgaben, Boot-Konfiguration und administratives Vergrößern bestehender Disks sind teilweise umgesetzt |
| Netzwerk/IPAM | IPv4/IPv6/Subnetze, IP-Pools, private Netze, SDN/VLAN/Bridge-Zuweisung, Zuweisungsprotokolle, Firewall |
| Sicherungen | Zeitpläne, Limits, Schutz vor Löschen, Sicherung vor Reinstallation, PBS, Wiederherstellung einzelner Dateien |
| Automatisierung | Trigger, Gruppen, geplante Power-/Snapshot-Aktionen, Wiederholungen und Benachrichtigungen |
| Platzierung/Verfügbarkeit | Node-Auswahl nach Kapazität, Ressourcen-Gewichte, HA-Einstellungen und Wartungsmodus |
| Katalog | KVM/LXC-App-Vorlagen, ISO-/Archivkataloge, Cloud-Init-Skripte, Gruppen und Medien |
| Monitoring | Persistente VM-Verläufe mit Zeitbereich und Langzeitexport, Schwellwerte, Prüfungen und Benachrichtigungen; VM-I/O-Raten, Browser-Kurzverläufe und deren CSV-Export sind ergänzt |
| Verwaltung | VM-ID-Bereiche, Import/Detach, verwaiste VMs, Wiederherstellungskonfigurationen, Scheduler-/Task-Ansichten |
| Konsolen | Weitere Konsolentypen wie SPICE und Terminal sowie zusätzliche Proxy-Topologien |

Diese Tabelle ist ein Ausbauplan, keine Liste bereits implementierter Funktionen.
WHMCS-spezifische Produkt-/Account-Flows müssen auf das EasyWI-Shop- und
Rechtesystem übertragen werden. Im aktuellen Umbau werden dafür keine
funktionslosen Verwaltungsaktionen eingeblendet.
