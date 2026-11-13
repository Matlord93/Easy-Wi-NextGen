# Prüfbericht: VNC-Kundenkonsolen

Stand: 12.11.2026. Ergänzt auf der bestehenden Proxmox- und KVM-Erweiterung; bestehende uncommittete KVM-Dateien wurden vor Beginn separat gesichert. Aus dieser Aufgabe wurden keine Aktionen auf `ameserver`, einem realen Proxmox-Cluster oder einer produktiven KVM-VM ausgeführt.

## Ergebnis

Proxmox und KVM haben im Kundenbereich eine interaktive noVNC-Konsole. KVM besitzt dafür eine Admin-Kundenzuweisung und eigene, auf den jeweiligen Kunden begrenzte VM-Ansichten. Das Gateway verwendet den bestehenden Agent-Release als eigenen Betriebsmodus und meldet sich selbst an der upstream RFB-Konsole an. Weder Proxmox-API-Token/VNC-Passwort noch Agent-Secret oder interne Ziele werden an noVNC durchgereicht.

Ergänzt sind Einmaltickets, Login-Sitzungsbindung, regelmäßige Prüfung von Berechtigung und VM-Identität sowie ein festes Sitzungsende. Proxmox löst den aktuellen Cluster-Node erneut auf und prüft den Eigentums-Tag zusätzlich in der aktuellen Konfiguration. KVM nutzt automatisch verwaltete private libvirt-Sockets. Ein beim Browsertest aufgedeckter Fehler mit leerer Proxmox-Kundenauswahl wurde behoben, damit ein Entzug tatsächlich gespeichert wird.

## Prüfungen

| Bereich | Ergebnis |
| --- | --- |
| PHP-Konsole / Rechte | 14 neue HTTP-Tests erfolgreich: eigene/fremde Proxmox- und KVM-Zuweisungen, CSRF, Gateway-Authentisierung, Nodewechsel, einmalige Einlösung, Loginbindung, Ablauf, Logout, Entzug, frische Konfiguration trotz veraltetem Ressourcen-Tag, Rate Limit, keine Geheimnisse in Kundenansichten und keine entfernten Klartextverbindungen |
| Go / RFB-Gateway | Sieben Gateway-/RFB-Tests mit Race Detector erfolgreich: VNC-DES-Testvektor, echte WebSocket-/TLS-Verbindung, serverseitige RFB-Anmeldung, Bild-/Eingabebytes, Origin, Wiederverwendung, Laufzeitgrenze, Entzug und Redirect-Sperre |
| KVM-Agent | 24 KVM-/Adaptertests mit Race Detector erfolgreich; darin der tatsächlich verwendete Unix-Socket mit Bearer-Prüfung, Origin-Sperre und festem VM-Ziel sowie Vorbereitung nur bei gestoppter VM |
| Native KVM-Werkzeuge | 24 Tests erfolgreich mit echten qcow2-, Cloud-Init-, Unix-Socket- und XML-Schema-Prüfungen; libvirt-Aufrufe weiterhin simuliert |
| Browser mit echtem Panel | Beide Anbieter über reales noVNC und Gateway: synthetischer RFB-Desktop wird tatsächlich als Canvas gerendert; Maus, Tastatur, Ctrl+Alt+Del, Vollbild und Trennen erfolgreich; keine JavaScript-Fehler oder Infrastruktur-Secrets im HTML |
| Browser / Widerruf | Proxmox-Zuweisung im echten Adminformular entfernt; bestehender Konsolenstream endet, erneutes Verbinden wird abgewiesen |
| MariaDB 10.11 | Alle 43 Migrationen erfolgreich, ORM-Mapping und Datenbankschema synchron; auch bei abweichendem Datenbank-Default passt der String-Fremdschlüssel zur vorhandenen Agent-Tabelle |
| Gleichzeitige Ticketeinlösung | Vier PHP-Prozesse gegen MariaDB, ein angenommener Vorgang und genau ein Proxmox-`vncproxy`-Aufruf |
| Statische Prüfung | PHPStan für neue Services/Controller/Entitäten/Migration, Twig, Übersetzungen und DI-Container erfolgreich; Setup-Skript mit `bash -n` geprüft |
| Plattformen | Agent baut auf Linux und Windows-amd64; der KVM-Hostbetrieb bleibt Linux/x86_64 |
| Gesamte PHP-Suite, isolierte DB | 1.456 Tests, 5.404 Assertions; zwei bekannte Fehler wegen fehlender PHP-Klasse `Redis`, keine Test-Failures. Drei Warnings, 301 Notices, zehn Skips |
| Gesamte Go-Suite | Nur der bereits am unveränderten Ausgangscommit nachgewiesene TeamSpeak-/Opus-Testfehler `TestTeamspeakBackendMissingLibraryAndOpus`; die übrigen Pakete bestehen |

Die erste Gesamtprüfung wurde wegen einer versehentlich gemeinsam verwendeten Testdatenbank verworfen. Die oben ausgewiesene vollständige PHP-Suite lief anschließend isoliert in einer eigenen SQLite-Datei; native MariaDB- und Browserprüfungen nutzten jeweils eigene Datenbanken.

## Bereitstellung und offene Live-Abnahme

Migration `Version20261112040000`, aktualisiertes Agentbinary, Gateway-Secret und die WebSocket-Route im vorhandenen HTTPS-Proxy sind erforderlich. Der Setup-Helfer installiert den Dienst und ein Nginx-Snippet, verändert aber keine bestehenden VHosts. Die Anleitung steht in [VM-Konsolen](../../wiki/VM-Konsolen.md), der Vertrag in [API](../api/vm-console.md).

Die Browserprüfung nutzt synthetische Proxmox-/Agent-Ziele mit echter TLS-/WebSocket-/RFB-Übertragung. Sie bestätigt keine reale VM-Grafikkonfiguration, Hardwarevirtualisierung, Host-DAC/AppArmor-Regeln, private CA oder konkrete Proxmox-Version. Auf `ameserver` müssen nach Übernahme eine separate zugewiesene Test-VM pro Anbieter und der tatsächliche HTTPS-Proxy geprüft werden. Das neue Gateway und seine Nginx-Konfiguration wurden dort nicht installiert.
