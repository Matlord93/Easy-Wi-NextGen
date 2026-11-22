# Shop, CMS, Mietverträge und Zahlungen

## Aktivierung und Migration

Unter PHP 8.4 oder PHP 8.5 die regulären Panel-Migrationen ausführen:

```sh
cd /var/www/easywi/core
sudo -u www-data php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction
sudo -u www-data php bin/console cache:clear --env=prod --no-debug
```

Das Schema wird additiv erweitert. Bestellungen, Zahlungen und bestehende
vorausbezahlte Mietsysteme bleiben erhalten. Sie werden nicht rückwirkend in
Minutenverträge umgestellt oder erneut berechnet. Bestehende Guthaben erhalten
eine Eröffnungsbuchung; bereits laufende, noch nicht fakturierte Guthabennutzung
wird dabei berücksichtigt. Alte Zahlungsreferenzen bleiben erhalten.

Administrationsseiten:

| Adresse | Aufgabe |
| --- | --- |
| `/admin/shop/products` bzw. `/admin/shop/rental-products` | Produktauswahl nach Gameserver, KVM, TeamSpeak 3/6, Webspace und Domain |
| `/admin/shop/rental-products/{kind}` | Getrennte Produktliste mit Suche und bis zu 24 Produkten je Seite |
| `/admin/shop/rental-products/{kind}/new` | Eigenes Formular für die ausgewählte Produktart |
| `/admin/shop/rental-products/{kind}/{id}/edit` | Einzelnes Produkt bearbeiten, Sichtbarkeit und Verkaufsstatus prüfen |
| `/admin/shop/rental-products/{kind}/{id}/capacity` | Gemeinsames Verkaufsbudget des zugeordneten Ziels prüfen und freigeben |
| `/admin/shop/contracts` | Verträge, Kundenlimits, bestehende Guthabengutschriften und manuelle Domainbestätigungen |
| `/admin/shop/operations` | Verkaufbare Kapazitäten, Aufbewahrung, Zeitlimits, Betriebsübersicht, Bankeingänge und Kontokorrekturen |
| `/admin/shop/registrar` | AutoDNS-Zugang, Test-/Produktivmodus und Registraraufträge |
| `/admin/shop/manage` | Produktauswahl für die Variantenverwaltung mit Suche und Typfilter |
| `/admin/shop/products/{id}/variants` | Varianten eines einzelnen Produkts; eigene Seiten für Anlage und Bearbeitung |
| `/admin/shop/coupons` | Gutscheinliste sowie eigene Anlage-/Bearbeitungsseiten |
| `/admin/shop/orders` | Getrennte, seitenweise geladene Bestellhistorie |
| `/admin/shop/settings` | Zahlungsanbieter, Währung und öffentliche Paneladresse |

Für neue Verkäufe Shop und Rechnungsmodul sowie das jeweilige Produktmodul
aktivieren. Vor dem Verkauf die Kapazitätspools einrichten. Bestehende Verträge
bleiben auch bei deaktiviertem Shop zugänglich; Abrechnung und Beendigung laufen
weiter. Den Agenten zusammen mit dem Panel aktualisieren: Die automatische
KVM-Bereinigung und die Löschung nativer Game-Backups benötigen seine neuen
Fähigkeitskennungen. Ohne diese Kennungen bleiben die Daten erhalten und der
Auftrag erscheint als Bearbeitungsbedarf.

## Admin-Shop: getrennte Produktbereiche

Der Einstieg zeigt sechs Produktarten statt eines Formulars mit allen Systemen. Jede Art besitzt eine eigene Liste sowie getrennte Seiten zum Anlegen und Bearbeiten. Gemeinsame Navigation führt zu Kategorien, Varianten/Gutscheinen, Verträgen, Betrieb/Kapazitäten, Registrar und Zahlungsarten. Die vorher doppelte Produktnavigation ist zusammengeführt; bestehende Adressen bleiben nutzbar. Frühere Editoraufrufe führen auch bei HTMX-Aufrufen zum neuen Editor. Die bisherigen Schreibschnittstellen bleiben für vorhandene Integrationen erhalten.

- Gameserver: Agent, Spiel-Template, CPU, RAM und Speicher.
- KVM: Agent, Katalog-Image, Netzwerk, CPU, RAM und Speicher.
- TeamSpeak 3 beziehungsweise 6: ausschließlich Nodes der passenden Version und Voice-Slots.
- Webserver/Webspace: Agent, PHP-Version und Speicher; keine sachfremden Voice-/KVM-Felder.
- Domains: Registrar, Endung und feste Laufzeit; kein Agent oder Kapazitätspool.

Produktart und Website lassen sich durch fremde IDs oder manipulierte Formularwerte nicht wechseln. Fehler erhalten die Eingaben einschließlich deaktivierter Sichtbarkeit. Bildadresse, Aktivierung und Sichtbarkeit bleiben bearbeitbar. Produkte mit Bestellhistorie werden weiterhin vor Löschung geschützt; unbenutzte Produkte lassen sich entfernen, ohne ihre gemeinsamen Kapazitätspools zu löschen. Preise und Konfiguration bestätigter Verträge werden nicht verändert. Produktanlage und Kapazitätsfreigabe erzeugen keine Bereitstellungsaufträge.

Die KVM-/Proxmox-Auswahl weist die bestehende Funktionsgrenze ausdrücklich aus: Automatische virtuelle Shop-Systeme verwenden den KVM-Agenten. Proxmox-VMs werden separat über die verlinkte Proxmox-Verwaltung erstellt und Kunden zugeordnet. Ein vollständiger automatischer Proxmox-Vertragsablauf einschließlich Kündigung und Bereinigung ist hier nicht hinzugefügt; Proxmox-Cluster werden nicht als Libvirt-Agenten behandelt.

## Installationsziel für Shop-Gameserver

Neue Shop-Gameserver verwenden vorrangig die **Instanz-Basisverzeichnisse des ausgewählten Nodes** unter **Nodes → Agent-Einstellungen**. Das erste dort hinterlegte Verzeichnis ist die automatische Auswahl. Im Gameserver-Produkt kann stattdessen ein anderes Verzeichnis desselben Nodes gewählt werden. Der Produkteditor zeigt das wirksame Installationsziel an. Dort erhält jeder neue Server seinen eigenen Instanzordner; mehrere Käufe überschreiben nicht denselben Serverordner.

Ohne eine Auswahl am Node bleibt ein gültiges globales Basisverzeichnis als bisheriger Rückfallwert verfügbar. Ein relativer Wert wie `var/easywi` wird abgewiesen und ausdrücklich nicht zu `/var/easywi` oder einem anderen Verzeichnis umgedeutet. Bei fehlender gültiger Konfiguration zeigt bereits die Vertragsvorschau einen Hinweis auf die Node-Einstellungen. Es wird kein Ersatzpfad erfunden und kein Installationsauftrag mit einem relativen Pfad erzeugt.

Die frühere Installer-Vorgabe `var/easywi` war relativ und konnte dadurch `install_path must be absolute` auslösen. Der Installer verwendet nun einen absoluten Standardwert und legt das Gameserver-Zielverzeichnis nicht mehr auf dem Panelhost an: Es gehört zum Zielagenten. Beim Bearbeiten der globalen Einstellung werden relative Pfade, Punktsegmente und Steuerzeichen bereits vor dem Speichern abgewiesen. Windows-Laufwerkspfade mit `/` werden auf die unterstützte Windows-Schreibweise normalisiert, ohne ihren Ort zu ändern.

**Vorhandene absolute `install_path`-Werte und ihre Daten bleiben unverändert.** Ein späterer Wechsel des Node-Verzeichnisses verschiebt keine bestehenden Server. Der Shop übernimmt die gewählte Basis nur bei der Anlage neuer Instanzen; Wiederholungen verwenden die bereits gespeicherte Instanz. Die Zuordnung gilt auch für die bisherige vorausbezahlte Gameserver-Bereitstellung.

## Varianten, Gutscheine und Bestellungen getrennt verwalten

Die frühere Sammelseite lädt nicht mehr gleichzeitig alle Kategorien, Produktformulare, Varianten, Gutscheine und Bestellungen. Unter „Varianten“ zunächst ein Produkt auswählen. Listen sind auf 24 Einträge begrenzt; Produktauswahl, Varianten, Gutscheine und Bestellungen laden keine weiteren Seiten im Hintergrund.

Varianten besitzen eigene Seiten zum Anlegen und Bearbeiten. Gameserver/KVM bieten CPU, RAM und Speicher, Webspace nur Speicher, Voice nur optionale Slots und Domains den Preis für die bestehende feste Laufzeit. Zielsystem, Template und Kapazitätspool bleiben am Produkt. Nicht relevante Ressourcenfelder werden aus bestehenden Werten beziehungsweise dem Standardprodukt übernommen. Die SKU bleibt beim Bearbeiten unverändert. Alle Varianteneingaben einschließlich Slots werden vor einer Änderung des verwalteten Datensatzes validiert; ein ungültiger letzter Wert darf zuvor eingegebene Preise oder Ressourcen nicht teilweise übernehmen. Fehler erhalten die eingegebenen Werte und den Aktivierungsschalter.

Gutscheine besitzen eine eigene Liste und einzelne Editoren für Rabattart/-wert, Mindestbetrag, Nutzungslimit, Zeitraum und Aktivierung. Codes bleiben nach der Anlage stabil. Bearbeiten verändert oder löscht keine früheren Verwendungen. Bestehende Gutscheine werden vor der Änderung unter Datenbanksperre neu geladen; gleichzeitig erfolgte Verwendungen bleiben erhalten. Ungültige Grenzen und Datumsbereiche verändern den Datensatz nicht. Bestätigte Vertrags- und Bestellpreise behalten weiterhin ihren Snapshot.

Die Bestellhistorie ist separat lesbar. Historische Bereitstellungskennungen werden erläutert; Vertrag, Rechnung und technische Instanz bleiben getrennt erkennbar. Die bisherigen Schreibschnittstellen unter `/admin/shop/manage` bleiben für vorhandene Integrationen nutzbar. Die neuen Oberflächen verwenden konkrete Produkt-/Varianten-/Gutscheinrouten mit Rollen-, Website-, Zuordnungs- und Tokenprüfung.

## „Derzeit nicht zur Bestellung freigegeben“ prüfen

Die ursprüngliche Meldung bedeutet, dass der Verkaufskapazitätspool des zugeordneten Agenten beziehungsweise Voice-Nodes fehlt oder deaktiviert ist. Die Aktivierung eines Produkts und seine CMS-Sichtbarkeit ersetzen diese Freigabe nicht. Fehlende Zielzuordnungen werden ebenfalls als gesperrt angezeigt. Ausreichende Hardwarewerte allein sind kein freigegebenes Shop-Budget.

Im Adminbereich die Produktart wählen, das Produkt bearbeiten und **Verkaufskapazität für dieses Ziel prüfen und freigeben** öffnen. Die Seite unterscheidet fehlende Zuordnung, fehlenden Pool, deaktivierten Pool und ausgeschöpftes Budget. Sie zeigt den Bedarf des Standardprodukts und bei aktivem Pool die aktuell freien sowie fehlenden Ressourcen. RAM und Speicher stehen hier ausdrücklich in **MiB**: Ein Tarif mit 4 CPU, 8 GiB RAM und 10 GiB Speicher benötigt 4 CPU, 8.192 MiB RAM, 10.240 MiB Speicher und einen freien Systemplatz. Gameserver benötigen außerdem die im Template festgelegte Portanzahl, KVM einen Netzwerkplatz und Voice die gebuchten Slots.

Das Budget gilt gemeinsam für alle Tarife desselben Ziels. Beim Speichern über die Produktseite werden nur die dort benötigten Ressourcen bearbeitet; andere Budgets dieses Pools bleiben erhalten. Vorhandene Verträge werden weiterhin übernommen. Die Freigabe muss bewusst gesetzt werden; es wird kein Hardwarebudget automatisch erfunden oder aktiviert. Bereits reservierte beziehungsweise noch aufbewahrte Systeme werden weiterhin abgezogen. Shoplisten teilen sich die Kapazitätsabfrage je Ziel und HTTP-Anfrage; neue Anfragen prüfen den aktuellen Stand erneut.

Im Checkout richtet sich die Anzeige nach der tatsächlich gewählten Variante einschließlich CPU, RAM, Speicher, Slots und Portbedarf. Bei fehlender oder ausgeschöpfter Kapazität erscheint kein verbindlicher Bestellknopf. Ein direkt nachgebauter Bestell-POST bleibt gesperrt; die endgültige Reservierung prüft unabhängig davon erneut unter Datenbanksperre. Tarifvorschau, Variantenwechsel und spätere erneute Prüfung bleiben möglich. Die Prüfung schafft keine Reservierung, Buchung oder Bereitstellung.

## Produkte, Varianten und CMS

Mietprodukte unterstützen Gameserver, TeamSpeak 3, TeamSpeak 6, KVM-Server,
Webhosting und Domains. Vorhandene Agenten, Gameserver-Templates, Voice-Nodes
und KVM-Katalog-Images verwenden. Hosting nutzt die vorhandenen Nginx-/PHP-FPM-
Aufträge und bietet Speicherplatz und die konfigurierte PHP-Version; separate
CPU- oder RAM-Garantien werden dafür nicht angeboten.

CPU-Angaben sind Kerne, RAM und Speicher GiB. Gameserver erhalten daraus
CPU-Prozent und MiB; KVM erhält die Einheiten des vorhandenen KVM-Moduls.
Varianten überschreiben Preis und Ressourcen; bei Voice zusätzlich optional die
Slotzahl. Bestehende Produkte mit behelfsmäßig eingetragenen Agent-Einheiten vor
neuen Verkäufen prüfen. Gekaufte Tarife und Ressourcen bleiben als Snapshot
erhalten; spätere Produktänderungen gelten für neue Bestellungen. Der
Tarifvergleich berücksichtigt identische JSON-Objekte unabhängig von der
Schlüsselreihenfolge, damit MySQLs JSON-Normalisierung keine unveränderten
Bestellungen sperrt. Echte Tarifänderungen benötigen weiterhin eine neue Bestätigung.

Kategorien haben Beschreibung, Reihenfolge und einen Verfügbarkeitsschalter.
Deaktivierte Kategorien sperren neue Käufe. Produkte mit Bestellhistorie können
deaktiviert werden; ihre Löschung bleibt zum Schutz der Historie gesperrt.
Gutscheine gelten für den ausgewiesenen Vertragstarif einschließlich späterer
Nutzungsabrechnungen; bei Domains für den Laufzeitpreis. Reservierungen werden
bei einer Kündigung vor Bereitstellung wieder freigegeben.

Auf veröffentlichten CMS-Seiten zeigt der Block `shop_products`, Version `2`,
öffentliche Produkte der jeweiligen Website, optional nach Kategorie gefiltert.
`/shop` bietet den vollständigen Katalog. Konfiguration und Vertragsabschluss
sind im CMS-Theme möglich. Für den Abschluss ist ein Kundenkonto erforderlich;
die Produktauswahl bleibt beim Login erhalten und wird anschließend bestätigt.
Im Panel steht derselbe Ablauf unter `/marketplace` bereit. Eigene Verträge:
`/marketplace/contracts`; Guthaben und Buchungen: `/marketplace/wallet`.

## Kapazitäten vor dem Verkauf

Unter `/admin/shop/operations` je Agent bzw. TS3-/TS6-Node einen Pool aktivieren.
Die Budgets geben an, was **für den Shop tatsächlich verkaufbar** ist: CPU,
RAM und Speicher in MiB, Portanzahl, KVM-Netzwerkplätze, Voice-Slots und
Systemanzahl. Bereits außerhalb des Shops verwendete Ressourcen und die
Betriebsreserve vorher abziehen. Das Budget wird nicht automatisch aus der
Hardwaretelemetrie ermittelt.

Vorhandene Minutenverträge dieses Ziels werden beim Speichern übernommen.
Jede neue Bestellung reserviert ihre Ressourcen unter einer Datenbanksperre;
parallele Bestellungen können dasselbe Restbudget nicht mehrfach verkaufen.
Bei Gameservern werden zusätzlich die konkreten Ports über die vorhandene
Portverwaltung reserviert. Das IPv4-Budget beschreibt freie Plätze im verwalteten
KVM-Netz bzw. DHCP-Pool; es ist keine neue statische IP-Adressverwaltung.

Fehlende oder ausgeschöpfte Pools sperren neue Bestellungen. Beendete Systeme
belegen die reservierte Kapazität bis zur bestätigten Datenlöschung weiter, da
beispielsweise ihr Datenträger noch vorhanden ist. Die Löschfunktion deshalb
bewusst konfigurieren und den Bestand in der Betriebsübersicht überwachen.

## Minutengenaue Mietverträge

Serververträge laufen unbefristet und können jederzeit beendet werden. Der
angezeigte Tarif gilt für **30 Tage = 43.200 Minuten**, unabhängig von der Länge
des Kalendermonats. Berechnet werden angefangene Minuten ab bestätigter
Bereitstellung, mindestens eine Minute. Bestellzeit und Installation verursachen
noch keine Nutzungsgebühren. Kündigung nach 61 Sekunden entspricht zwei Minuten.

Beim Abschluss wählt der Kunde:

- **Rechnung nach Nutzung:** monatliche Sammelrechnung nachträglich; bei
  Kündigung Nutzungsabrechnung. Das Limit pro Kunde und Währung berücksichtigt
  offene Rechnungen, noch nicht berechnete Nutzung und Reservierungen zusammen.
  Standard sind 100,00 Währungseinheiten; der Administrator kann das Limit ändern.
  Bei ausgeschöpftem Limit endet die weitere Nutzung.
- **Guthaben:** der Preis der ersten Minute wird vor Bereitstellung reserviert.
  Anschließend wird tatsächliche Nutzung abgezogen. Bei erschöpftem Guthaben
  endet die Abrechnung nach der letzten gedeckten Minute, mit Stoppauftrag.
  Nutzungsrechnungen dokumentieren die Zahlung aus Guthaben ohne zweiten Abzug.

Beträge werden als ganze Cent-Bruchteile ohne Gleitkommarechnung gespeichert.
Nur volle Cent stehen auf Rechnungen; Restbruchteile bleiben pro Kunde, Währung
und Abrechnungsart erhalten. Die Verarbeitung läuft in begrenzten Batches.
Es gibt **keinen Datenbankeintrag pro Minute**. Die Kundenübersicht zeigt
laufende Kosten und eine unverbindliche Hochrechnung bis Monatsende.

Bei Kündigung endet die Abrechnung sofort. Das Panel sperrt die Ressource und
übergibt den Stopp an die vorhandenen Agent-Aufträge; die technische Bestätigung
kann später eintreffen. Eine nachträglich fertiggestellte Installation aktiviert
den gekündigten Vertrag nicht erneut.

## Guthaben, Zahlungen und Rückzahlungen

Kunden erstellen unter `/marketplace/wallet` einen Aufladebeleg und bezahlen per
Stripe, PayPal oder Überweisung. Signierte Stripe-Ereignisse bzw. serverseitig
bestätigte PayPal-Zahlungen schreiben Guthaben automatisch gut. Betrag, Währung,
Kunde und Checkout müssen übereinstimmen. Wiederholte Zahlungsbestätigungen
buchen nicht erneut. Dieselbe Zahlungsreferenz kann nicht einem zweiten
Aufladebeleg zugeordnet werden, auch wenn Kunde und Betrag übereinstimmen.
Zwei tatsächlich getrennt eingegangene Zahlungen werden
auch dann beide berücksichtigt, wenn sie denselben Aufladebeleg betreffen.

Bei Überweisung zeigt der Beleg IBAN/BIC und seine `WLT-…`-Referenz. Ein
Administrator bestätigt unter `/admin/shop/operations` den **tatsächlichen**
Bankeingang mit exaktem Betrag und eindeutiger Bankreferenz. Erst dann erfolgt
die Gutschrift. Eine Bank-API oder automatische Kontoauszugsabfrage ist nicht
vorausgesetzt. Bestehende manuelle Guthabengutschriften bleiben verfügbar.

Die Aufladung ist ein Finanzierungsbeleg, keine zusätzliche steuerpflichtige
Verkaufsrechnung. Die eigentliche Leistung wird über die Nutzungsrechnung
abgerechnet. Das unveränderliche Buchungsjournal enthält Eröffnungsbestand,
Zahlungseingänge, abgerechnete Nutzung, Korrekturen und dokumentierte
Rückzahlungen. Noch nicht fakturierte Nutzung wird separat angezeigt; so bleibt
der aktuelle Saldo nachvollziehbar, ohne jede Nutzungsminute einzeln zu speichern.

Administratoren können mit Referenz und Begründung Gutschriften korrigieren oder
bereits ausgezahlte Rückzahlungen dokumentieren. Reserviertes Guthaben kann nicht
versehentlich ausgezahlt werden. Die tatsächliche Auszahlung erfolgt weiterhin
beim Zahlungsanbieter oder über die Bank; die Journalbuchung löst sie nicht aus.
Zahlungsanfechtungen und Anbietererstattungen im Rechnungs-/Gutschriftenprozess
und beim Anbieter bearbeiten.

## Zahlungsanbieter

Rechnung und Guthaben funktionieren ohne externe Zugangsdaten. Es gibt keine
Händler- oder Resellerzugänge im Panel. Für die eigenen Anbieterzugänge unter
`/admin/shop/settings` die öffentliche **HTTPS-Adresse ohne Pfad** und Währung
pflegen. Geheimnisse werden verschlüsselt gespeichert. Leere Eingabefelder
behalten vorhandene Werte; ein eigener Löschschalter entfernt sie. Während
laufender Checkouts Zugangsdaten, Webhook-Secrets und PayPal-Modus nicht wechseln.

- **Stripe:** Secret Key und Webhook Secret hinterlegen. Karte, Klarna und
  SEPA-Lastschrift können einzeln aktiviert werden und benötigen zusätzlich die
  Freischaltung im Stripe-Konto. Webhook:
  `https://panel.example.com/billing/webhook/stripe`; Events
  `checkout.session.completed` und `checkout.session.async_payment_succeeded`.
- **Klarna:** über Stripe Checkout. Verfügbare Sofort-, Rechnungs-, Raten- oder
  Lastschriftoptionen bestimmt Klarna anhand von Land, Freischaltung und Käufer.
  Eine bestimmte Unterzahlart ist nicht garantiert. SEPA-Lastschrift steht auch
  als eigene Stripe-Zahlart zur Verfügung.
- **PayPal:** Client ID, Secret und Webhook-ID hinterlegen. Sandbox und Live
  getrennt verwenden. Webhook: `https://panel.example.com/billing/webhook/paypal`;
  Event `PAYMENT.CAPTURE.COMPLETED`. Die Rückkehr allein bestätigt keine Zahlung.
- **Überweisung:** Firmenangaben, IBAN, BIC und Steuersatz im Rechnungsmodul
  pflegen. Rechnungseingänge mit Rechnungsnummer, Aufladungen mit `WLT-…`-Referenz
  zuordnen und nach tatsächlichem Eingang bestätigen.

Asynchrone Zahlungen bleiben bis zur Bestätigung offen. Die Verträge richten
keine wiederkehrenden Anbieterabbuchungen oder Lastschriftmandate ein.

## Domains mit AutoDNS / InterNetX

Domains haben **12, 24 oder 36 Monate** feste Laufzeit. Der Checkout weist den
vollständigen Preis aus; es gibt keine minutengenaue Erstattung einer bestätigten
Laufzeit. Tarif und Laufzeit müssen vom jeweiligen Registrar/TLD unterstützt
werden. Premiumdomains und besondere Zusatzleistungen werden nicht automatisch
zum Standardtarif bestellt.

Unter `/admin/shop/registrar` AutoDNS-Benutzer, Passwort und den zum Konto
gehörenden Kontext eintragen. Zunächst den **Testmodus** verwenden. API-Ziele
sind fest auf `https://api.demo.autodns.com/v1` bzw.
`https://api.autodns.com/v1` begrenzt. Erlaubte TLDs, zwei bis zehn Nameserver
sowie gültige Admin-C-, Tech-C- und Zone-C-Kontakt-IDs des Registrarzugangs
hinterlegen. Der Domaininhaber wird aus dem vollständigen Kundenprofil als
eigener Registrar-Kontakt angelegt; Betreiberkontakte ersetzen ihn nicht.

Beim Domainprodukt `AutoDNS` und die passende TLD wählen. Der Kauf prüft
Verfügbarkeit und Kundenprofil. Registrierung und eingehender Transfer laufen
als dauerhafte Hintergrundaufträge. Transfercodes werden verschlüsselt abgelegt
und nach der Übergabe entfernt. Zur Abrechnung müssen der Registrarauftrag
erfolgreich und Domaininhaber sowie tatsächliches Laufzeitende bestätigt sein.
Guthaben bzw. Rechnungslimit reservieren dafür den vollständigen Laufzeitpreis.

Ungekündigte Domains werden sieben Tage vor Ablauf zur Verlängerung vorgemerkt.
Fehlendes Guthaben oder Rechnungslimit hält den Auftrag in der begrenzten
Warteschlange zurück; erst eine erfolgreiche Reservierung erlaubt die Übergabe
an den Registrar. Andere Kunden werden dadurch nicht blockiert. Ein bis zum
Laufzeitende nicht übergebener Verlängerungsauftrag wird nicht nachträglich
kostenpflichtig eingereicht. Nur bestätigte Verlängerungen werden
berechnet. Ohne bestätigte Verlängerung endet der Vertrag an der bezahlten Frist.
Eine Kündigung verhindert die nächste Verlängerung; die vorhandene Laufzeit
bleibt bestehen. Das Panel deaktiviert Registrar-Autorenew und hinterlegt die
Beendigung zum Laufzeitende. Registrarbedingungen und Übertragungsfristen gelten
zusätzlich. Laufende kostenpflichtige Registraraufträge werden vor der Übergabe
der Kündigung abschließend abgeglichen: Eine spätere Verlängerung könnte eine
früher hinterlegte Registrar-Kündigung sonst wieder entfernen. Unklare Vorgänge
bleiben zur Betreiberprüfung stehen; danach wird die vorgemerkte Kündigung
verarbeitet. Für einen ausgehenden Transfer kann der Besitzer im Vertrag einen
Authcode abrufen; der Registrarinhaber wird vorher nochmals geprüft.

**Unklare kostenpflichtige API-Antworten werden nicht blind wiederholt.** Wenn
beispielsweise nach der Registrierung die Verbindung abbricht, erscheint der
Vorgang zur Prüfung. Unter `/admin/shop/registrar` den tatsächlichen
Registrarauftrag anhand seiner Job-ID zuordnen und abgleichen. Ohne verifizierten
Erfolg entsteht keine Nutzungsrechnung. Bei verspäteter Bestätigung oder späterem
Job-Abgleich bleibt der ursprüngliche Registrierungszeitraum erhalten; bereits
abgelaufene Laufzeitnachweise benötigen Betreiberprüfung. Registrarzugang und Test-/Live-Modus bei
laufenden Vorgängen nicht wechseln.

Bestehende manuelle Domainprodukte bleiben nutzbar. Deren Betreiber bestätigt
Registrierung/Verlängerung unter `/admin/shop/contracts` mit eindeutiger Referenz.
Für AutoDNS-Produkte ist diese manuelle Abrechnungsbestätigung gesperrt.

## Betrieb, Nachrichten und Aufbewahrung

Unter `/admin/shop/operations` sind Zeitlimits, Wiederholungen, Kapazitäten,
fehlgeschlagene Verträge, zurückgestellte Löschungen, ausstehende
Registrarvorgänge, Nachrichtenfehler und der letzte Verarbeitungslauf sichtbar.
Standard: 120 Minuten Bereitstellungsfrist, drei Bereitstellungsversuche mit
fünf Minuten Abstand und zehn Stoppversuche. Voraussetzungsfehler werden begrenzt
wiederholt. Kostenpflichtige Registrarvorgänge folgen ihrer eigenen sicheren
Statusabfrage. Unklare externe Ausführungen benötigen Betreiberprüfung.

Auftragsbestätigung, Aktivierung, Kündigung, Bereitstellungsfehler, Aufladung,
niedriges Guthaben, Domainablauf und Datenlöschung erzeugen passende Nachrichten.
E-Mail-Zustellung beachtet die Benachrichtigungs-/Rechnungseinstellungen des
Kunden und wird bei Fehlern höchstens fünfmal versucht. Fehlgeschlagene Nachrichten
bleiben als Betriebsbedarf sichtbar. SMTP und öffentliche Paneladresse einrichten.

Automatische Datenlöschung ist zunächst **ausgeschaltet**. Nach bewusster
Aktivierung gelten getrennte Aufbewahrungsfristen für Systemdaten und Backups,
standardmäßig jeweils 30 Tage nach Vertragsende. Zusätzlich liegt vor der ersten
Löschung mindestens eine siebentägige Ankündigungsfrist. Einzelne Verträge lassen
sich vor Beauftragung der Bereinigung zur Aufbewahrung sperren und später wieder
freigeben. Bereits übergebene Löschaufträge können durch einen Panel-Schalter
nicht zurückgerufen werden; das Panel weist einen nachträglichen Schutzversuch
deshalb zurück und zeigt den erforderlichen Prüfbedarf an. Bei verspäteter
Erstankündigung berücksichtigt das angezeigte Löschdatum die vollen sieben Tage
Vorankündigung.

Bei Game/Web bleibt das Systemverzeichnis mindestens bis zum Ende beider Fristen
erhalten, weil eigene Backupziele darin liegen können. KVM-Datenträger werden
nach bestätigtem Stillstand ausschließlich über ihren verwalteten Datensatz
gelöscht; eigene Backups bleiben bis zur Backupfrist erhalten. Kapazitäten werden
erst nach bestätigter Datenlöschung freigegeben. Unklare Pfade oder fehlgeschlagene
Aufträge bleiben zur Prüfung stehen.

Native Game-Backups werden nur mit dokumentiertem Pfad, Größe und SHA-256-Proof
entfernt. Remote-WebDAV/Nextcloud benötigt zusätzlich ein starkes ETag und
bedingtes Löschen; geänderte oder gemeinsam referenzierte Archive bleiben
unangetastet. Für generische Game-/Web-Backuppläne wird die bestehende sichere
Aufbewahrungsverarbeitung verwendet. Bei Voice werden nur Snapshots des exakt
zugehörigen virtuellen Servers entfernt, keine gemeinsamen Hostbackups.
Finanzielle Belege, Verträge und Buchungshistorien werden dabei nicht gelöscht.

## Scheduler und bestehende Systeme

Der vorhandene Scheduler muss mindestens einmal pro Minute laufen:

```cron
* * * * * cd /var/www/easywi/core && php bin/console app:run-schedules --once --env=prod --no-debug
```

- `rentals.usage`: Bereitstellung, Minutenabrechnung, Limits, Stoppbestätigungen,
  Sammelrechnungen, Kostenübersicht und Nachrichtenversand.
- `rentals.domains`: minütliche Verarbeitung und Abgleich der Registraraufträge.
- `rentals.cleanup`: Prüfung der Aufbewahrung alle 15 Minuten, nur bei Aktivierung.
- `shop.automation`: weiterhin historische vorausbezahlte Bestellungen und deren
  Verlängerungsrechnungen; neue Minutenverträge werden nicht nochmals verarbeitet.

Die vorhandenen Game-, Voice-, KVM- und Hosting-Aufträge führen technische
Aktionen aus. Vor Produktfreigabe Agent-Erreichbarkeit, Templates, Lizenzen,
Slotkapazität und Hosting-Runtimes prüfen.

## Panel ohne integriertes CMS

Unter **CMS → Einstellungen → Webseitenmodus** das integrierte CMS deaktivieren.
Öffentliche CMS-Seiten und der öffentliche Shop sind dann nicht erreichbar;
Login, Panel, Kunden-Shop und Zahlungs-Callbacks bleiben verfügbar. Angemeldete
Benutzer werden von `/` in ihren Panelbereich geleitet.

`core/website/public/` ist für eine eigene Website oder ein eigenes CMS vorgesehen
und bleibt bei Voll-, Delta- und Release-Updates erhalten. Eine eigene Domain bzw.
einen eigenen VirtualHost darauf zeigen lassen; die Panel-Domain behält
`core/public/` als Webroot. Siehe [core/website/README.md](../core/website/README.md).

## Prüfung vor produktiver Freigabe

Automatisierte Tests führen keine echten Abbuchungen oder Domainregistrierungen
aus. Vor Freigabe jedes Produkttyps Bereitstellung und Beendigung auf dem Zielagenten
prüfen. Bei Zahlungsanbietern einen vollständigen Sandbox-/Stripe-Testfall mit
Webhook-Zustellung ausführen. AutoDNS-Verfügbarkeit, Kundenkontakt, Registrierung,
Transfer, Verlängerung und Kündigung zunächst mit dem eigenen Testzugang prüfen;
Live-Zugang erst danach aktivieren. Zusätzlich Rechnungstexte, Steuern, Währung,
Bankverbindung, SMTP, Kapazitätspools und Aufbewahrung kontrollieren.

## Validierung der Shop-Überarbeitung

Die vollständige PHPUnit-Suite unter PHP 8.4.27 und PHP 8.5.12 besteht mit jeweils **2.481 Tests und 12.880 Assertions**, ohne Fehler, Warnungen, Deprecations oder übersprungene Tests.

Die vorherige gezielte Nachprüfung unter PHP 8.4 umfasste **55 Tests mit 567 Assertions**. Elf neue HTTP-Fälle prüfen getrennte Editoren und erfolgreiche Produktanlage für alle sechs Arten, erhaltene Fehlerwerte, fehlende/deaktivierte/ausgeschöpfte Pools, größere Varianten, benötigte Gameserver-Ports, erhaltene andere Poolbudgets, begrenzte typgetrennte Listen, Suche, Löschschutz und Website-/Rollengrenzen. Die bisherigen Vertrags-, Abrechnungs- und CMS-Fälle laufen weiterhin mit.

Psalm ohne Cache einschließlich Taint-Analyse meldet **0 Befunde**, ohne neue Unterdrückungen oder Baseline. Alle **573 Twig-Templates** und **78 JavaScript-Tests** bestehen. Die Produktionscontainer lassen sich unter PHP 8.4.27 und PHP 8.5.12 mit `cache:warmup --no-optional-warmers` aufbauen; eine Produktionsdatenbank ist lokal nicht eingerichtet.

Chromium prüft **162 Ansichten** im bestehenden Panelrahmen: sechs Produktarten, Produktanlage und Bearbeitung, Kapazitätsseiten sowie Kategorien, Varianten/Gutscheine, Verträge, Betrieb, Registrar und Zahlungsarten. Beide Farbmodi sowie 1.440, 390 und 320 Pixel bestehen ohne JavaScript-Ausnahmen oder horizontalen Überlauf. Nicht gespeicherte Formularwerte bleiben beim Themewechsel erhalten. Außer der Anmeldung entstehen keine Schreibanfragen; Produkt-, Bestell-, Vertrags-, Kapazitäts-, CMS- und Jobdaten bleiben unverändert. Gesperrte Produkte zeigen keinen verbindlichen Bestellknopf. Die Prüfung führt keine echten Käufe, Agent-Aufträge oder Registraraufrufe aus.

Für diesen Schritt ist keine Datenbankmigration erforderlich. Nach dem Einspielen den Produktionscache erneuern. Die tatsächlichen Verkaufsbudgets der eigenen Installation müssen im Adminbereich eingerichtet und ausdrücklich freigegeben werden.

## Bildschirmaufnahmen der Shop-Überarbeitung

Lokale Ansichten mit Beispieldaten; die Produktivinstallation wurde nicht verändert.

![Produktauswahl im hellen Modus](images/admin-shop-overview-light.png)

![Produktauswahl im dunklen Modus](images/admin-shop-overview-dark.png)

![Getrennter Gameserver-Editor](images/admin-shop-game-editor-light.png)

![Verkaufsbudget des Zielsystems mit Tarifbedarf](images/admin-shop-capacity-dark.png)

![Produktanlage auf Mobilgeräten](images/admin-shop-editor-mobile.png)

## Nachprüfung: Installationsverzeichnisse und getrennte Angebote

Die vollständige PHPUnit-Suite unter PHP 8.4.27 und PHP 8.5.12 besteht mit jeweils **2.481 Tests und 12.880 Assertions**, ohne Fehler, Warnungen, Deprecations oder übersprungene Tests.

Die gezielte Nachprüfung unter PHP 8.4 besteht mit **100 Tests und 747 Assertions**. 28 neue Fälle sichern Node-Verzeichnisse vor dem alten relativen Installerwert, bewusste Auswahl eines weiteren Node-Verzeichnisses, unveränderte bestehende Installationspfade, ungültige und Windows-Pfade, Produktpfad-Auswahl, Varianten aller sechs Arten, vollständige Validierung vor Änderungen, Gutscheinbearbeitung mit erhaltenen Verwendungen sowie Produkt-/Website-/Rollengrenzen ab. Bisherige Shop-, Vertrags-, Abrechnungs-, Einstellungs- und Installerfälle bleiben enthalten.

Psalm ohne Cache einschließlich Taint-Analyse meldet **0 Befunde** ohne neue Baseline oder Unterdrückungen. **579 Twig-Templates**, beide angepassten Installer-Übersetzungen und **78 JavaScript-Tests** bestehen. Produktionscontainer unter PHP 8.4 und 8.5 mit `cache:warmup --no-optional-warmers` aufgebaut; eine Produktionsdatenbank ist lokal nicht eingerichtet.

Zusätzliche **138 Browseransichten** prüfen Varianten, Gutscheine, Produktnavigation, Bestellübersicht und das angezeigte Node-Installationsziel in Hell/Dunkel bei 1.440/390/320 Pixeln. Felder passen zur Produktart, Formulare behalten Entwürfe beim Themewechsel, und die Vertragsvorschau zeigt bei gültigem Node-Verzeichnis keinen relativen Pfadfehler. Es entstehen keine JavaScript-Ausnahmen, Überläufe oder Schreibanfragen außerhalb der Anmeldung. Shop-, Kapazitäts-, CMS- und Jobdaten bleiben unverändert; temporäre lokale Verzeichnismetadaten und Beispielangebote werden nach dem Vergleich vollständig wiederhergestellt. Die Prüfung kontaktiert keine Agenten und führt keine Käufe aus.

Keine Datenbankmigration erforderlich. Nach dem Code-Update den Produktionscache erneuern. Die Instanz-Basisverzeichnisse in den Node-Einstellungen beziehungsweise das gewünschte Verzeichnis im Gameserver-Produkt prüfen; es werden keine vorhandenen Serververzeichnisse verschoben.

## Bildschirmaufnahmen: Installationsziel und Angebotsverwaltung

Tatsächliche lokale Ansichten mit Beispieldaten; das dargestellte Verzeichnis ist nur eine lokale Prüfkonfiguration. Die Produktivinstallation wurde nicht verändert.

![Gameserver-Produkt mit Installationsziel des gewählten Nodes](images/shop-game-install-directory-light.png)

![Eigene Variantenanlage für Gameserver](images/shop-variant-game-light.png)

![Voice-Variante mit passenden Slotfeldern](images/shop-variant-voice-dark.png)

![Getrennte Gutscheinübersicht](images/shop-coupons-light.png)

![Gutscheinbearbeitung mit unverändertem Nutzungszähler](images/shop-coupon-editor-dark.png)

![Gutscheinanlage auf einem mobilen Bildschirm](images/shop-coupon-mobile-dark.png)
