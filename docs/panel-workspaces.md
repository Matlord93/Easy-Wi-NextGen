# Einheitliche Arbeitsbereiche

Admin-, Kunden- und Resellerseiten verwenden dieselben Abstände, Karten, Formulare, Tabellen und Navigationsbausteine. Die bestehenden Themevariablen bestimmen die Oberflächenfarben und den Farbakzent. Entsprechend den freigegebenen Vorlagen bleibt die seitliche Navigation in beiden Modi dunkel. Admins und Kunden können unabhängig zwischen Hell und Dunkel wechseln; Branding und Modulfreigaben bleiben wirksam. Die öffentlichen CMS-Themes verwenden dieselben grundlegenden Abstände und Formgrößen, behalten ihre eigene Farbpalette, Navigation und Inhalte.

`core/public/panel-modules.css` gestaltet die Panelkomponenten; `workspace-public.css` enthält die gemeinsamen Regeln für öffentliche Seiten. Die jeweiligen Basislayouts laden sie zentral, sodass auch Rechnungen, Aufgaben, Support, Profile, Shop, CMS-Verwaltung und weitere Module denselben Stil erhalten.

## Navigation und Bedienung

- Server: Gameserver, Voice/TeamSpeak, virtuelle Server; Proxmox und KVM behalten ihre eigenen Backends.
- Hosting: Webspace, Datenbanken, Mail, Domains/DNS.
- Verwaltung: Kunden, Abrechnung, Aufgaben und Support; weitere Einstellungen bleiben in den zugehörigen Gruppen erreichbar.
- TeamSpeak 3 und 6 haben eine gemeinsame Adminübersicht unter `/admin/voice`. Die bisherigen Einzelübersichten und Nodeverwaltung bleiben bestehen.
- Gameserver können über `/admin/instances/{id}/edit` direkt administriert werden. Ressourcen verwenden die vorhandenen Regeln; Slots und Portzuordnung sind daneben erreichbar. Die neuen Schreibwege prüfen Adminrechte und CSRF.
- TeamSpeak-Bearbeitung umfasst Kundenzuordnung, öffentliche Verbindungsadresse und die bestehenden Start-/Stopp-/Neustartaufträge. Der vom Server gelieferte Name wird nicht nur lokal überschrieben. Vorgemerkte Aktionen werden als Aufträge angezeigt.

Die Kundenreiter gruppieren vorhandene Funktionen, ohne deren URLs oder Formulare zu ersetzen. Gameserver-Konfiguration und Verwaltung besitzen Unterpunkte; Voice gruppiert Rechte, Tokens und Sperren sowie Einstellungen, Protokolle und Viewer. Webspace, Mail und Datenbanken verwenden gemeinsame, per Tastatur bedienbare Reiter. Formularwerte bleiben beim Wechsel erhalten; bestehende Abschnittsanker und HTML-Formularvalidierung öffnen den richtigen Bereich. Ohne JavaScript bleiben die Inhalte der neuen allgemeinen Reiter sichtbar.

## Aufbau entsprechend den Modulvorlagen

Die gemeinsame Kopfzeile enthält eine Suche in den tatsächlich freigegebenen Navigationszielen, Sprache, Designwahl, Benachrichtigungen und Profil. Die Suche lässt sich mit den Pfeiltasten, Eingabe und Escape bedienen. Kompakte Überschriften, Kennzahlen und Tabellen ersetzen große Einführungskarten; selten benötigte Betriebsdetails bleiben aufklappbar.

- **Gameserver:** Adminbestand mit Kundenzuordnung, Ressourcen und Bearbeitung; Kunden sehen Serveraktionen, CPU/RAM/Speicher und aufgezeichnete Messwerte der letzten 15 Minuten. Die Übersicht bindet die bestehende Livekonsole und deren geschützten Befehlsweg ein. Die separate Konsolenseite bleibt erhalten. Die zusätzliche Messwertabfrage ist auf 240 Werte des eigenen Servers begrenzt und wird nur in der Übersicht ausgeführt.
- **Voice/TeamSpeak:** Gemeinsamer TS3-/TS6-Bestand mit Versionsfilter, Kundennamen und belegten Nodes. Kunden sehen Kanäle und Teilnehmer nebeneinander. Rechte, Tokens, Sperren und Sicherungen behalten ihre bisherigen Aktionen. Fehlende oder veraltete Probedaten werden nicht als neue Livewerte ausgegeben.
- **Datenbanken:** Kompakter Bestand und getrennte Reiter für Erstellung und Hostsysteme. Die Kundendetails zeigen Verbindungsdaten mit Kopieraktionen und unverändertem einmaligem Passwortabruf; Tabellenverwaltung bleibt über den vorhandenen Editor erreichbar.
- **Webspace:** Getrennte Bestands-, Domain- und Erstellen-Reiter im Adminbereich. Vorhandene Domainformulare werden in den passenden Reiter verschoben und behalten Eingaben und Ereignisbehandlung. Die Kundenübersicht zeigt konfiguriertes Speicherkontingent, SSL/Verfallsdatum, PHP, Domains, Pfade und die vorhandene Cron-/Git-Konfiguration. Ohne Messdaten wird kein belegter Speicher vorgetäuscht.

- **Proxmox/KVM:** Verbindungen, Hostsysteme und zugeordnete Kunden-VMs verwenden kompakte Bestandslisten mit Suche und tatsächlichen Statuswerten. Proxmox und KVM behalten ihre getrennten Backends, Kennungen und Verwaltungswege. Die ursprünglichen Nutzungsanzeigen und deren begrenzte Aktualisierung bleiben unter den aufklappbaren Bestandsdetails erhalten. Auf den VM-Detailseiten und der KVM-Hostseite sind die Messwerte im passenden Übersichts-/Serverreiter eingeordnet; Konsole, Einstellungen und Sicherungen bleiben ohne vorangestellten Messwerteblock bedienbar. Bestehende Bestätigungen, CSRF-Prüfungen und Eigentumsgrenzen werden weiterhin durchgesetzt.
- **Mail:** Die Adminübersichten für Postfächer und Aliase trennen Bestand und Editor. Doppelte Kennungen in der Aliasübersicht sind entfernt; nachgeladene Tabellen und Formulare behalten ihre eindeutigen Ziele, sodass wiederholtes Aktualisieren, Bearbeiten und Zurücksetzen funktioniert. „Neu“ lädt auch nach einer Bearbeitung wieder ein frisches Erstellen-Formular. Bearbeiten öffnet den zugehörigen Reiter. Kunden erreichen die vorhandene Postfachverwaltung direkt aus der Übersicht; Passwortwechsel und Löschen bleiben mit ihren bisherigen Tokens erreichbar. Die Detailseite zeigt Kontingent, Status, tatsächliche Speichernutzung beziehungsweise den bisherigen Hinweis bei fehlenden Messwerten. Verbindungs- und Setupdaten verwenden die vorhandene Kopierfunktion.

- **Domains/DNS:** Der Kundenbestand verlinkt zu bereits zugeordneten Webspaces und trennt Übersicht und Domainanlage. Abgewiesene Eingaben behalten Domainname und ausgewählten eigenen Webspace im Erstellen-Reiter. Die DNS-Adminverwaltung trennt Bestand und Editor; erneutes Aktualisieren, Bearbeiten und „Neu“ verwenden weiterhin dieselben Ziele. Validierungsfehler nachgeladener DNS-/Ticketformulare werden bei HTTP 400/422 angezeigt, ohne die serverseitige Prüfung zu umgehen.
- **Abrechnung:** Rechnungsliste und Gutschriften sind getrennt; Exporte und der vorhandene Mahnlauf bleiben erreichbar. Rechnungsdetails ordnen Übersicht, Archiv, Dokumentvorschau, Aktivität und Gutschrift/Storno in Reiter ein. Kunden sehen ihre Rechnungsliste mit Suche/Statusfilter, die einzelnen Positionen sowie die bisherigen Zahlungs- und Dokumentwege. Beträge, Steuerberechnung, Zahlungsanbieter, Archivunveränderlichkeit und PDF-Erzeugung bleiben unverändert; Kennzahlen der Kundenliste zählen Rechnungen und summieren keine verschiedenen Währungen.
- **Support:** Listen, Ticketanlage sowie Adminvorlagen/Schnellantworten haben eigene Reiter. Die bestehenden Such-/Status-/Zuweisungsfilter und Aktualisierungsschaltflächen bleiben erhalten. Auf Detailseiten stehen Konversation, Antwort und Adminmetadaten ohne zusätzliche linke Seitennavigation nebeneinander; mobil werden sie untereinander angezeigt. Interne Notizen bleiben für Kunden verborgen, geschlossene Tickets zeigen weiterhin kein Kundenantwortformular. Antworten, Anhänge, Schnellantworten und die vorhandenen CSRF-Prüfungen behalten ihre bisherigen Wege. „Abbrechen“ lässt die Ticketanlage anschließend wieder öffnen.

- **Shop und Mietverträge:** Produkt- und Kategoriebestände besitzen eigene Editorreiter und behalten ihre Ziele bei wiederholtem Nachladen. Varianten, Gutscheine, Bestellungen, Kapazitäten und Zahlungsanbietereinstellungen sind in passende Abschnitte eingeordnet. Kunden erreichen Katalog, Bestellungen, bestehende Prepaid-Dienste, Verträge und Guthaben getrennt. Tarifpräzision, minutengenaue Kündigung, feste Domainlaufzeiten, bestehende Zahlungswege und Buchungsprüfungen bleiben erhalten. Kennzahlen paginierter Bestände sind ausdrücklich auf die aktuelle Seite bezogen; Guthaben verschiedener Währungen werden getrennt angezeigt.
- **CMS:** Seitenbestand, Seiteneditor und Inhaltsblöcke verwenden dieselben kompakten Arbeitsbereiche. Die bisher fehlenden GET-Ansichten für Blockbestand, Neuanlage und Bearbeitung sind wieder erreichbar und prüfen Adminrechte sowie die aktuelle Website; ein fremder Block kann nicht über eine andere Seite geöffnet werden. Beim Bearbeiten bleiben Blockversion, Inhalte und Einstellungen erhalten. V2-Blöcke zeigen eine lesbare Inhaltsvorschau. Die vorhandenen Schreib- und Veröffentlichungsaktionen bleiben bestehen. CMS-Abschaltung und das Verzeichnis für ein eigenes CMS bleiben in den Einstellungen direkt erreichbar; öffentliche Vertragsseiten behalten das CMS-Layout.

- **Benutzer:** Bestand, Anlage und SSH-Freigaben besitzen getrennte Reiter. Superadmins behalten Konten-, Rollen-, Zugangs-, Limit-, Sprach- und Rechnungspräferenzen sowie die vorhandene Kundenanmeldung. Reguläre Admins bleiben bei der bisherigen lesenden Ansicht. Abgewiesene Neuanlagen öffnen den richtigen Reiter und erhalten nicht geheime Eingaben; Passwortfelder bleiben leer. Bearbeitungsmenüs passen auch auf kleine Bildschirme und lassen sich dort scrollen.
- **Aufgaben und Jobs:** Die Navigation verbindet geplante Aufgaben und Auftragswarteschlange. Zeitpläne zeigen Name/Quelle, Modul, Zustand, Cron-Ausdruck, Ausführungszeiten und letztes Ergebnis in kompakten Gruppen; der Verlauf hat einen eigenen Reiter. Ausführen, Aktivieren/Deaktivieren und Einzelverläufe behalten ihre Wege. Bei Jobs bleiben Seite und Aktualisierungsschaltfläche auch nach automatischem Nachladen auf der ausgewählten Seite. Details trennen Sperren/Zeitstempel, Auftragsdaten/Ergebnis und bestehende Liveprotokolle; Maskierung und Wiederholungsbedingungen bleiben erhalten.
- **Audit-Protokoll:** Aktion, Akteur und Startzeit verwenden die vorhandenen Serverfilter. Nachladen und CSV-Export erhalten dieselbe Auswahl. Die Übersicht zeigt höchstens 50 aktuelle Treffer; diese Anzeige wird nicht als Aufbewahrungsfrist ausgegeben. Die Kennung des nachgeladenen Bereichs ist eindeutig. Nutzdatenvorschau und Hashkette bleiben erreichbar, unbekannte Ereignisse zeigen ihre tatsächliche Ereigniskennung.
- **Backups:** Übersicht, Ziele und Einstellungen sind miteinander verknüpft. Die Übersicht zählt ihren tatsächlichen Bestand der letzten 100 Sicherungen, bietet eine lokale Suche und zeigt Größen mit Einheiten; genaue Bytewerte und Prüfsummen bleiben zugänglich. Globale Vorgaben, Zielbearbeitung und letzter Verlauf haben eigene Reiter. Vorhandene Zieltypen, Verbindungstests, Aufbewahrung, Kompression, TLS- und Zugangsdatenbehandlung bleiben erhalten. Die Vorschau führt keine realen Sicherungen, Wiederherstellungen oder Zieltests aus.

- **Native Musicbots:** Der Adminbereich verknüpft Bestand, Kundenlimits, Pluginmanifeste und TeamSpeak-Backend. Instanzdetails trennen Status, Wiedergabe, Queue, Verbindungen, Protokolle und Verwaltung. Die Pluginverwaltung nutzt wieder den vollständigen Panelrahmen. Im Kundenbereich bündeln sechs Hauptbereiche alle 16 bisherigen Reiter; Unterreiter, Gruppenauswahl, Direktlinks, Tastaturwechsel und Formularvalidierung erhalten den passenden Bereich. Wiedergabe und Lautstärke stehen in einer gemeinsamen Playerkarte. Gespeicherte und vom Runtime-Status gemeldete Queueanzahl bleiben getrennt erkennbar. Fehlende Überwachungsmeldungen werden nicht als Healthy/OK dargestellt; vorhandene CPU-/RAM- und Heartbeat-Werte bleiben einsehbar. Playlists, AutoDJ, Upload, YouTube, Verbindungen, Rollen, Plugins, Monitoring, Sicherungen und vorhandene Schreibwege bleiben erhalten.
- **Webradio:** Die vorhandene Katalogvorlage und Radio-API sind nun über `/musicbots/{id}/radio` aus dem Webradio-Bereich erreichbar. Der neue GET-Aufruf prüft Kundenrolle, Eigentümer und Webradio-Freigabe; fremde Bots liefern 404, gesperrtes Webradio 403. Die Radio-API behält ihre zusätzlichen Berechtigungsprüfungen und bestehenden Aktionen für Katalog, Favoriten, Verlauf und eigene Sender.
- **SinusBot:** Die eigenständige Verwaltung behält ihr Backend. Admins wechseln zwischen Installation, Mehrinstanzbestand, Aufträgen und Verwaltung; Solo-Nodes zeigen weiterhin keine Mehrinstanzanlage. Kunden behalten zugewiesene Solo-Nodes und eigene Instanzen. Zugangsdatenabruf, Quoten, Passwortwechsel, Verwaltung des Dienstes und vorhandene CSRF-Tokens bleiben erhalten. Native Musicbots und SinusBot werden nicht auf denselben Bereitstellungsweg umgestellt.
- **Dashboards:** Admins finden bestehende Messwerte und Nodeinformationen unter Übersicht sowie Modulbestand und Aktivität/Support in eigenen Reitern. Kunden behalten Dienstübersicht, Schnellzugriffe, Ressourcen und ihre bisherigen Serveraktionen; Abrechnung und Neuigkeiten erscheinen entsprechend ihrer bisherigen Freigaben und Daten. Bis zu vier echte Gameserver ersetzen dekorative leere Serverplätze. Die bisherigen Datenabfragen, Messwertquellen und begrenzte Adminaktualisierung bleiben bestehen.

Die übrigen Panelbereiche verwenden dieselben zentral geladenen Gestaltungselemente. Die Modulansichten behalten ihre jeweiligen Fachfunktionen und vorhandenen Reiter.

## Öffentliche CMS-Designs

Hosting, Clan & Community und Privat & Projekte besitzen eigene Designs mit gemeinsamem Seitenrahmen, Hell/Dunkel, Sprache, Branding, Navigation, Kontozugang und allen vorhandenen Modulansichten. Die bisherige Designauswahl bleibt erhalten; vorhandene Inhalte werden beim Wechsel nicht überschrieben. [Einrichtung, Prüfumfang und Bildschirmaufnahmen](cms-themes.md).

## Konten, Einstellungen und Datenschutz

Adminprofile trennen Kontodaten und SSH-Schlüssel von Passwort und Zwei-Faktor-Authentifizierung. Eine abgewiesene Passwortänderung öffnet wieder die Kontosicherheit; Passwortfelder bleiben leer. Kunden wechseln über eine gemeinsame Navigation zwischen Profil, Kontosicherheit, Einwilligungen, Datenexport und Kontolöschung. Die vorhandene erneute Passwortbestätigung und alle bisherigen Schutzprüfungen bleiben wirksam.

Benachrichtigungen zeigen die letzten 40 Meldungen mit lokaler Suche und Gelesen-/Ungelesen-Filter. Kennzahlen zählen diesen geladenen Bestand unabhängig vom Filter. Beim Markieren einer Meldung aktualisieren sich Kennzahlen, Filter und Leerzustand nach der bestehenden Antwort ohne zusätzliche Datenbankabfrage. Aktivitätslisten verwenden ebenfalls eine lokale Suche; Nutzdaten bleiben aufklappbar.

Die Admin-Sicherheitsübersicht trennt DDoS, Firewall, Fail2ban, Ereignisse, Regelwerk und Anbieter. Bestehende Abschnittsanker bleiben erreichbar; Filter, Validierungsfehler und Rückmeldungen öffnen den zugehörigen Reiter. Die bestehende DDoS-Aktualisierung pausiert bei verborgenem Dokument oder DDoS-Reiter. Die neun Bereiche der Systemeinstellungen behalten ihre Speicherwege. Mailvorlagen besitzen eigenständige Formulare anstelle verschachtelter Formulare innerhalb der globalen Einstellungen.

Die Datenschutzbereiche für Einwilligungen, Exporte und Löschstatus besitzen jeweils genau ein Nachladeziel. Dessen Ereignisbehandlung bleibt nach wiederholtem Austausch erhalten. Eigene Schreibantworten lösen keinen zweiten GET auf dasselbe Ziel aus; externe Aktualisierungsereignisse laden weiterhin nach. Aufbewahrungsfristen, Export- und Löschregeln sowie die eigentlichen Sicherheitsaktionen wurden durch diese Neugliederung nicht verändert.

Die vollständige PHPUnit-Suite besteht unter PHP 8.4.27 und PHP 8.5.12 mit jeweils 2.366 Tests und 11.155 Assertions ohne Warnungen oder übersprungene Tests. Psalm einschließlich Taint-Analyse meldet weiterhin 0 Befunde. Der Browserlauf umfasst 56 Seitenaufrufe, 21 mobile Ansichten und beide Farbmodi; zwölf zusätzliche Ansichten bei 320/768 Pixeln bestätigen die Kopfzeile und Seitennavigation ohne Überlagerung oder horizontalen Überlauf.

Die neuen HTTP-Prüfungen kontrollieren Formularfehler, vorhandene Speicherwege, eindeutige Nachladeziele, Einwilligungsverlauf und Rollen-/Empfängergrenzen. Die 56 JavaScript-Prüfungen laufen mit Node.js 24 in der Core-CI und umfassen auch die bisherigen Infrastrukturtests. Deren DOM-Testmodelle bilden nun angeschlossene Elemente und den anfänglichen Aktualisierungsdurchlauf ab; dadurch prüfen sie wieder tatsächliche Ausfälle, Wiederherstellung und Zugriffsentzug.

## Eigene Datenbanken und Postfächer

Ein Kunde sieht **Datenbanken** bereits dann, wenn ein aktives Datenbanksystem im Panel eingerichtet ist, auch vor seiner ersten Datenbank. Die bisherige Freigabe aktiver Datenbanksysteme gilt weiterhin; Kundenlimits und kundenbezogene Datenbanknamen werden beachtet. Die Navigation erteilt keine Rechte auf fremde Datenbanken.

**Mail** wird bei einer dem Kunden zugeordneten Domain angezeigt, auch vor seinem ersten Postfach. Er kann nur eigene Domains verwenden; vorhandene Postfach- und Quotalimits bleiben gültig. Eine Domain ohne eingerichtetes Bereitstellungsziel erhält eine verständliche Fehlermeldung, bevor ein Postfachdatensatz entsteht.

Unter **Admin → Mail → Mailserver** können Betreiber IMAP-/SMTP-Verbindung, Webmailadresse und den tatsächlichen Bereitstellungsagenten anlegen oder bearbeiten und Kundendomains zuordnen. Damit sind Maildomains ohne eigenen Webspace möglich. Eine vorhandene Domainbindung oder der Agent eines gebundenen Servers werden nicht stillschweigend auf einen anderen Server verschoben: Dafür ist zuerst eine tatsächliche Postfachmigration notwendig.

Die numerische ID eines Mailserver-Konfigurationsdatensatzes ist keine Agent-ID. Mailaufträge nutzen jetzt den ausdrücklich zugeordneten Agenten. Fehlt diese neue Zuordnung, verwenden bestehende Webspace-Domains weiterhin ihren bisherigen Webspace-Agenten. Das gilt auch für Aliase, Passwortwechsel und die betroffenen API-/Adminaktionen.

## Datenbankserver bearbeiten

**Admin → Datenbanken → Hostsysteme → Bearbeiten** öffnet den Servereditor. Anzeigename, TLS-/CA-Konfiguration, Tags und die auf dem Datenbankserver gültigen Adminzugangsdaten lassen sich dort hinterlegen. Neue Datenbankaufträge verwenden diese Zugangsdaten. Ein leeres Passwortfeld behält das verschlüsselt gespeicherte Passwort bei. Der Editor zeigt weder Passwort noch verschlüsselte Nutzdaten an; das Audit protokolliert die Änderung ohne Zugangsdaten.

Bei bereits zugeordneten Datenbanken bleiben Host, Port, Datenbanksystem und Bereitstellungsagent gesperrt, damit existierende Datenbanken weiterhin auf demselben Ziel liegen. Unbelegte Server können auch diese Zuordnung ändern. Eine geänderte Verbindung setzt das bisherige Prüfergebnis zurück; eine reine Umbenennung behält es bei. Der Zurück-Link öffnet wieder den Reiter für Hostsysteme.

Mail- und Datenbankformulare bleiben nach einer abgewiesenen Eingabe im Erstellen-Reiter. Domain beziehungsweise Datenbankserver, Name und Postfachquote bleiben erhalten. Passwortfelder werden nach dem Seitenwechsel geleert.

## Installation

Die neue optionale Agentzuordnung benötigt Migration `Version20261118170000`. Bei der üblichen Aktualisierung die Migrationen ausführen, anschließend den Produktionscache erneuern:

```sh
cd core
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console cache:clear --env=prod --no-debug
```

Bestehende Mailserver erhalten zunächst keine neue Agentzuordnung. Die Migration erhält vorhandene Domains, Postfächer und Verbindungsdaten. Neue oder eigenständige Maildomains werden in der Mailserververwaltung ausdrücklich einem Agenten zugeordnet.

Die automatisierten Prüfungen kontrollieren Berechtigungen, CSRF, tatsächliche Speicherung und Auftragsziele. Die Ausführung durch einen realen Mail-/Datenbankagenten und die tatsächliche Zustellung benötigen die eingerichtete Zielinstallation.

## Vorschau

Die Bildschirmaufnahmen zeigen die tatsächlichen Templates mit lokalen Beispieldaten. Für die Konsolenaufnahme wird ausschließlich im Vorschau-Browser der Transport simuliert; der Produktcode verwendet die bestehende Agentverbindung. Die Diagramme lesen lokal gespeicherte Beispielmesswerte über dieselbe Abfrage wie die Produktivansicht. Die zusätzlichen Proxmox-Aufnahmen verwenden ausschließlich im lokalen Vorschau-Router den vorhandenen Proxmox-Testadapter; KVM liest einen lokal gespeicherten Beispielbestand. Die zusätzlichen Musicbot-/SinusBot-Aufnahmen verwenden gespeicherte lokale Beispielbestände. Ausschließlich die Vorschau ersetzt die SinusBot-Statusabfrage durch einen Adapter ohne Schreibaktionen sowie den externen Radiokatalog und Ereignisstrom durch leere Testantworten. Diese Ersatzdaten und Adapter sind nicht Teil des Produktcodes. Die Kontoaufnahmen verwenden lokale Beispielkonten. Export- und Löschantworten wurden nur im Vorschau-Browser aus den echten GET-Fragmenten simuliert, ohne tatsächliche Aufträge anzulegen. Andere Sicherheits- oder Passwortaktionen wurden dort nicht ausgeführt. Die Vorschau bestätigt keine Verbindung zu einem realen Provider oder Agenten.

![Gameserver-Bestand im Adminbereich, hell](images/panel-reference-admin-light.png)

![Gameserver im Kundenbereich, dunkel](images/panel-reference-customer-dark.png)

![Voice im Adminbereich, dunkel](images/panel-reference-voice-dark.png)

![Webspace im Kundenbereich, hell](images/panel-reference-webspace-light.png)

![Datenbankserver direkt im Adminbereich bearbeiten](images/panel-database-server-editor.png)

![Proxmox-Verbindungen im Adminbereich, hell](images/panel-reference-proxmox-admin-light.png)

![KVM-Server im Kundenbereich, dunkel](images/panel-reference-kvm-customer-dark.png)

![Postfachübersicht im Kundenbereich, dunkel](images/panel-reference-mail-customer-dark.png)

![DNS-Verwaltung im Adminbereich, hell](images/panel-reference-dns-admin-light.png)

![Rechnungsdetails im Adminbereich, dunkel](images/panel-reference-billing-admin-dark.png)

![Support-Konversation im Kundenbereich, dunkel](images/panel-reference-ticket-customer-dark.png)

![Shop-Produkte im Adminbereich, hell](images/panel-reference-shop-admin-light.png)

![CMS-Inhaltsblöcke im Adminbereich, dunkel](images/panel-reference-cms-admin-dark.png)

![Mietverträge im Kundenbereich, dunkel](images/panel-reference-contracts-customer-dark.png)

![Benutzerverwaltung im Adminbereich, hell](images/panel-reference-users-admin-light.png)

![Geplante Aufgaben im Adminbereich, dunkel](images/panel-reference-schedules-admin-dark.png)

![Audit-Protokoll mit Filtern im Adminbereich, dunkel](images/panel-reference-audit-admin-dark.png)

![Backup-Übersicht im Adminbereich, hell](images/panel-reference-backups-admin-light.png)

![Native Musicbot-Verwaltung im Adminbereich, hell](images/panel-reference-musicbot-admin-light.png)

![Musicbot-Player und gruppierte Bereiche im Kundenpanel, dunkel](images/panel-reference-musicbot-customer-dark.png)

![SinusBot-Node im Mehrinstanzbetrieb, Adminbereich hell](images/panel-reference-sinusbot-admin-light.png)

![Kundendashboard mit echtem Beispielbestand, hell](images/panel-reference-dashboard-customer-light.png)

![Admindashboard, dunkel](images/panel-reference-dashboard-admin-dark.png)

![Adminprofil mit Kontodaten und SSH-Bereich, hell](images/panel-reference-profile-admin-light.png)

![Benachrichtigungen im Kundenbereich mit Suche und Lesefilter, dunkel](images/panel-reference-notifications-customer-dark.png)

![Systemeinstellungen mit geordneten Bereichen, hell](images/panel-reference-settings-admin-light.png)

![Admin-Sicherheitsübersicht mit getrennten Arbeitsbereichen, dunkel](images/panel-reference-security-admin-dark.png)
