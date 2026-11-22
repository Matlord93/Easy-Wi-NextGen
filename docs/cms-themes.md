# CMS-Designs für Hosting, Clan und private Websites

Unter **Admin → CMS → Einstellungen → Design** sind Hosting, Clan & Community sowie Privat & Projekte auswählbar. Die sieben bisherigen Designs bleiben verfügbar. Administration, Installation und Vorschau verwenden dieselbe Liste unterstützter Designs.

Ein Designwechsel überschreibt oder löscht keine vorhandenen Seiten und Inhaltsblöcke. Die bisherigen Inhaltspakete und die Darstellung sind unabhängig voneinander. Neue Funktionen müssen dafür nicht eingerichtet werden: Shop und Vertragsseiten, Blog, Events, Teams, Medien, Forum, öffentliche Server, Kontakt, rechtliche Seiten, Anmeldung und Registrierung verwenden den ausgewählten Seitenrahmen. Die bisherigen Freigaben, Zahlungswege und Vertragsregeln gelten weiter.

- **Hosting:** klare Angebotsbereiche mit direktem Zugang zum freigegebenen Shop.
- **Clan & Community:** Community-Akzent, Zugänge zu aktivierten Teams und Events sowie vorhandene Serverblöcke.
- **Privat & Projekte:** ruhigere Gestaltung und schmalerer Inhaltsbereich für persönliche Inhalte und Projekte.

Alle drei Designs unterstützen Hell/Dunkel, Deutsch/Englisch, Logo und eine gültige konfigurierte Akzentfarbe. Die Designwahl nutzt dieselbe gespeicherte Hell-/Dunkel-Einstellung wie das Panel. Die Navigation funktioniert ohne JavaScript; mit JavaScript erhält sie mobil eine Schaltfläche mit Zustandsanzeige und Escape-Bedienung. Der Konto-Zugang berücksichtigt Admin-, Reseller- und Kundenzugang; Mitglieder ohne Kundenzugang bekommen keinen zusätzlichen Panel-Zugang.

## Hosting-Shop und gemeinsame Module

Hosting ergänzt auch eine bereits gespeicherte eigene Navigation um die freigegebenen Modulzugänge. Eigene Links und Beschriftungen bleiben erhalten; gleiche URLs erscheinen nur einmal. Shop und Abrechnung müssen aktiviert sein, damit der Shop angeboten wird. Deaktivierte Teams, Events, Blog und Forum erscheinen weiterhin nicht. Der Kontaktzugang bleibt erreichbar.

Die Hosting-Startseite zeigt höchstens sechs tatsächlich freigegebene Produkte derselben Website. Es werden ausschließlich aktive Produkte aus aktiven Kategorien derselben Website gelesen. Ein vorhandener Shop-Produktblock verhindert eine doppelte automatische Produktvorschau. Vorschau und Katalog verwenden dieselben Karten und führen in die vorhandene Produktkonfiguration. Kategorien, Varianten, Preisberechnung, Domainlaufzeiten und die bestehende Vertragsanlage bleiben erhalten. Nicht öffentliche Produkte und fremde beziehungsweise deaktivierte Kategorien sind auch über öffentliche Produkt-IDs nicht erreichbar.

Teams, Events, Blog, Forum und Kontakt verwenden gemeinsame Seitenabstände, Karten, Formularfarben und responsive Raster innerhalb des gewählten Designs. Die allgemeinen Einführungstexte sind nicht mehr ausschließlich auf Clans ausgerichtet; Teamdetails verwenden bei Hosting und Privat die Bezeichnung „Bereich“. Die sieben bisherigen Designs behalten ihre Funktionen. Shopkarten laden ihre Gestaltung auch dort als eigene statische Datei. Eine Änderung am Theme veröffentlicht oder überschreibt keine Inhalte.

Die gespeicherte Seitenvorschau und die allgemeine Designvorschau berücksichtigen die gewählte Vorschauvariante bei den Modulzugängen. Die Designvorschau kann die bereits öffentlichen Produkte der aktuellen Website zeigen. Ihre Inhalte bleiben isoliert und nicht bedienbar; Vorschauaufrufe legen weder Bestellungen noch Vertrags- oder Zahlungsaufträge an. Die zusätzlichen Shop- und Cookie-Styles sind als genaue lokale Dateipfade in der Vorschau zugelassen.

## Einheitliche Seitenköpfe und vollständige Übersetzungen

Teams, Events, Blog, Forum, Medien, Serververzeichnis und Shop verwenden für Hosting, Clan und Privat dieselbe Kopfvorlage wie Startseite und „Über uns“. Schrift, Inhaltsbreite, Seitenabstände, Hintergrund und Trennlinie kommen aus der vorhandenen Theme-Gestaltung; es gibt keine zweite Modul-Kopfgestaltung mehr. Der Shop heißt im Seitenkopf „Shop“. Einführungstexte sind auf Deutsch und Englisch verfügbar. Startseitenaktionen, Filter, Listen und Verwaltungs- beziehungsweise Bestellfunktionen bleiben erhalten. Die sieben bisherigen Designs behalten einen kompatiblen Seitenkopf.

Die sichtbaren Schlüssel wie `team_intro`, `media_home` und `shop_title` können entstehen, wenn ein älterer Produktionskatalog neu hinzugekommene Übersetzungen noch nicht enthält. CMS-Texte nutzen weiterhin zuerst die normalen Framework-Übersetzungen und vorhandene Anpassungen. Fehlt dort eine Nachricht, wird sie aus der aktuell ausgelieferten deutschen beziehungsweise englischen CMS-Datei gelesen. Diese Ergänzung wird nur bei Bedarf und höchstens einmal je Sprache und HTTP-Anfrage geladen; ein neuer Request liest den aktuellen Stand. Erfolgreich ergänzte Nachrichten erzeugen keine Übersetzungswarnung. Tatsächlich unbekannte Schlüssel behalten das normale Framework-Verhalten. Nach einem Code-Update sollte der Produktionscache weiterhin erneuert werden.

Zwölf neue PHPUnit-Fälle prüfen die sieben Modulübersichten mit Startseite und „Über uns“ in allen drei aktuellen Designs und beiden Sprachen sowie einen absichtlich alten Übersetzungskatalog. Sie sichern normale Übersetzungsanpassungen, Parameter, begrenzte Sprachwahl, erneutes Laden bei neuen Requests und warnungsfreie Ergänzung fehlender Nachrichten ab. Die gezielte Nachprüfung umfasst insgesamt **39 Tests und 937 Assertions**.

Chromium prüft zusätzlich **156 Ansichten** für alle sieben Bereiche, drei Designs, Deutsch/Englisch sowie Hell/Dunkel und Mobil bei 320/390 Pixeln. Die berechneten Schriftwerte, Farben, Seitenbreiten und Abstände stimmen mit Startseite beziehungsweise „Über uns“ überein. Es erscheinen keine Übersetzungsschlüssel, JavaScript-Ausnahmen oder Schreibanfragen. CMS-, Shop-, Server- und Jobtabellen bleiben unverändert. Damit das lokale Serververzeichnis dabei keine Statusjobs anlegt, werden ausschließlich die Prüfzeitpunkte seiner Beispieldaten vorübergehend verschoben und anschließend exakt wiederhergestellt. Diese Prüfung kontaktiert keine Agenten.

## Cookie-Präferenzen und Richtlinie im Dialog

Cookie-Präferenzen und Cookie-Richtlinie öffnen sich im selben Dialog auf der aktuellen Seite. Der Fußbereich bietet beide Zugänge auch bei einer eigenen gespeicherten Linkliste; bereits konfigurierte Cookie-Zugänge werden nicht doppelt hinzugefügt. Der Banner, vorhandene Cookie-Links und alte eigene Cookie-URLs öffnen dieselbe Oberfläche. Ein direkter Aufruf von `/cookies` oder `/cookie-richtlinie` führt auf die Startseite mit geöffnetem Richtlinienreiter; Präferenz-Aliasse öffnen den Einstellungsreiter. Eine übergebene deutsche oder englische Sprachwahl bleibt erhalten. Es gibt keine eigenständige Richtlinienseite mehr.

Der Dialog unterstützt Hell/Dunkel, mobile Bildschirme, Tastaturwechsel zwischen den Reitern, Escape, Fokusführung und eine weiterhin erreichbare Schließen-Schaltfläche. Notwendige Cookies bleiben aktiv. Statistik und Marketing lassen sich einzeln erlauben oder ablehnen. Öffnen, Reiterwechsel und Abbrechen speichern nichts. Speichern, alle akzeptieren und optionale Cookies ablehnen erhalten die aktuelle URL und ungespeicherte Formulareingaben; es erfolgt kein Neuladen. Die Auswahl wird im bisherigen Erstanbieter-Cookie und über den vorhandenen Endpunkt `/cookie-consent` gespeichert. Bei einem Verbindungsfehler bleibt die lokale Auswahl erhalten; verspätete Antworten dürfen eine neuere Auswahl nicht überschreiben.

Die bisherigen Frontend-Zustimmungswerte werden aktualisiert; das Ereignis `easywi:cookie-consent` stellt die neue Auswahl für Integrationen bereit. Die Richtlinie verwendet den vorhandenen Cookie-Katalog mit Zweck, Kategorie, Dauer und Anbieter. Die doppelte Cookie-Implementierung im bisherigen öffentlichen Seitenrahmen ist durch denselben gemeinsamen Dialog ersetzt. Alle zehn CMS-Designs behalten einen Zugang zur Cookie-Auswahl.

## Inhalte und Navigation

Ohne eigene Navigationskonfiguration werden veröffentlichte Seiten derselben Website und aktivierte Module verlinkt. Eigene Links bleiben erhalten; doppelte URLs werden nur einmal gezeigt. Deaktivierte Module erhalten keine zusätzlichen Navigations- oder Startseitenlinks. Externe Links verwenden `noopener noreferrer`. Die Vorschau speichert keine Designänderung.

Legacy-Blöcke sowie V2-Hero, Richtext, Bilder, Karten und Shop-Produktblöcke bleiben verfügbar. Richtext stellt die im geschützten Admineditor gepflegte HTML-Formatierung jetzt ebenso wie Legacy-HTML dar, statt HTML-Tags als Text auszugeben. Nur berechtigte Administratoren können diese Inhalte bearbeiten. Die Kontaktseite folgt dem ausgewählten Design und behält ihre bestehenden Formularfelder, Honeypot- und Ratenbegrenzung bei.

Serverlisten und hervorgehobene Serverblöcke lesen wieder tatsächlich öffentlich freigegebene Server derselben Website. Spielauswahl, Limit, Spieleranzeige und Beitreten-Schaltfläche verwenden die gespeicherten Blockeinstellungen. Listen sind auf höchstens 50 Server begrenzt, hervorgehobene Blöcke auf einen Server; identische Serverblöcke einer Seite teilen ihre Abfrage. Ausgegeben werden gespeicherte Statuswerte und deren Prüfzeitpunkt. Es werden keine neuen Livewerte oder Betriebszusagen erfunden und keine direkten Agentabfragen für diese Blöcke ausgeführt. Interne Notizen und nicht öffentlich freigegebene Server werden nicht ausgegeben.

CMS-Einstellungen werden innerhalb einer HTTP-Anfrage pro Website nur einmal aus dem Repository gelesen. Neue Anfragen prüfen den aktuellen Stand erneut; ein zwischengespeicherter fehlender Datensatz wird beim Anlegen ersetzt. Die CMS-Einstellungen besitzen jetzt zusätzlich einen geprüften CSRF-Token beim Speichern. CMS-Abschaltung und das vorhandene Verzeichnis für eine eigene Website bleiben unverändert erreichbar.

## Vorschau gespeicherter Seiten und Entwürfe

Unter **Admin → CMS → Seiten** führt „Seitenvorschau“ aus der Übersicht, dem Seiteneditor oder der Blockverwaltung in eine eigene Ansicht. Die Vorschau zeigt den **zuletzt gespeicherten Stand**, auch bei unveröffentlichten Entwürfen. Noch nicht gespeicherte Eingaben zuerst speichern. Der Link öffnet einen eigenen Tab, damit Eingaben im Editor erhalten bleiben.

Theme, Hell/Dunkel und Desktop/Mobil lassen sich unabhängig vom veröffentlichten Design vergleichen. Die Vorschau nutzt dieselbe Darstellung der gespeicherten Inhaltsblöcke und dieselbe Website-Zuordnung wie die öffentliche Seite. Eine Auswahl in der Vorschau speichert und veröffentlicht nichts. Besucher und Kunden können diese Adminvorschau nicht abrufen; ein Entwurf bleibt auch nach dem Aufruf öffentlich unerreichbar.

Der Vorschauinhalt steht in einem isolierten Frame mit zusätzlicher serverseitiger Sandbox-Richtlinie. Links, Formulare, Skripte und eingebettete Fremdseiten sind deaktiviert; Bilder, Schriften und Styles sind auf lokale statische Verzeichnisse begrenzt. Damit löst das Öffnen der Vorschau keine Käufe, Abmeldungen oder anderen Panelaktionen aus. Externe Einbettungen und interaktive Funktionen müssen anschließend auf einer freigegebenen Testseite geprüft werden. Die allgemeine Designvorschau unter `/preview/{theme}` verwendet weiterhin Beispieldaten und dieselben Beschränkungen.

Adressen wie `/events`, `/teams`, `/forum` oder `/shop` gehören zu eigenen Modulen. Bei einer gleichnamigen CMS-Seite erscheint deshalb ein Hinweis: Die Vorschau zeigt deren CMS-Inhalt, ersetzt aber nicht die öffentliche Modulroute. Modulfreigaben und Inhaltsdaten bleiben vom ausgewählten Theme unabhängig.

## Trennung der Websites und Module

CMS-Inhalte, Navigation und die öffentlichen Seiten einschließlich Anmeldung werden über den aktuellen Hostnamen zugeordnet. Bei nur einer hinterlegten Website funktionieren bestehende Alias-Adressen weiter. Sind mehrere Websites eingerichtet, erhält ein unbekannter Host keine beliebige Standardwebsite mehr. Zusätzliche Alias-Domains dann im Webserver auf den jeweils konfigurierten Host weiterleiten. Die allgemeine Panel-/API-Zuordnung bleibt erhalten.

Bearbeitung und Löschung von Events, Teammitgliedern, Teamgruppen und Medien prüfen zusätzlich die aktuelle Website. Forum-Meldungen werden nur für deren Website geladen und bearbeitet; eine fremde Beitrags-ID oder ein gültiger CSRF-Token allein erlaubt keine websiteübergreifende Moderation. Die vorhandenen Seiten- und Blockprüfungen gelten auch für die Vorschau.

Neue Medienuploads liegen unter `/uploads/cms/{website-id}/`; MIME-Typ und Größe werden vor dem Verschieben der Datei ermittelt. Bestehende Medienpfade bleiben gültig. Dies trennt Verwaltung und neue Ablage; öffentlich veröffentlichte Mediendateien sind weiterhin öffentliche Dateien.

Deaktivierte Team-, Event-, Forum- und Gameservermodule liefern keine Startseiten-Daten oder Kennzahlen und verursachen dort keine entsprechenden Inhaltsabfragen. Ausgeblendete Forum-Boards erscheinen weder in der Startseitenaktivität noch in der öffentlichen Suche. Veröffentlichte Inhalte anderer Websites werden nicht als Ersatz geladen.

## Lokale Link- und Medienprüfung

Die Blockverwaltung und die Seitenvorschau besitzen einen aufklappbaren Bereich **Links und Medien prüfen**. Er meldet fehlende CMS-Seiten der aktuellen Website, noch unveröffentlichte Ziele, deaktivierte Modulbereiche und fehlende lokale Mediendateien. In der Blockverwaltung öffnet „Bearbeiten“ direkt den betroffenen Inhaltsblock; Hinweise zu Navigation, Fußzeile oder Logo führen in die CMS-Einstellungen.

Geprüft werden gespeicherte Hero-, Bild-, Karten-, Richtext- und Legacy-HTML-Verweise sowie konfigurierte Navigation, Fußzeile und Logo. Relative Pfade und absolute URLs des eigenen Hostnamens werden berücksichtigt. Ziele anderer Websites werden nicht als Ersatz geladen. Für die Seitenprüfung liest eine gemeinsame Abfrage nur Slugs und gespeicherte Veröffentlichungszustände der aktuellen Website; vollständige Seitenobjekte werden dafür nicht geladen. Wiederholte Referenzen teilen sich innerhalb des Prüflaufs dieselbe Auswertung.

Die Prüfung ruft keine Zielseiten auf und verändert keine Inhalte oder Freigaben. Externe Ziele, Sprungmarken, eigene Protokolle, dynamische Medienadressen und Moduldetails bleiben zur manuellen Prüfung ausgewiesen. HTML-Formulare, Skripte, eingebettete Frames, Stylesheet-Inhalte und `srcset` werden nicht ausgeführt oder vollständig geprüft. Hinweise verhindern weder das Speichern noch die Veröffentlichung. Bei sehr großen Inhalten erscheint ein Hinweis auf die Begrenzung auf 500 Verweise und höchstens 256 KiB HTML je Block.

Lokale Dateien werden innerhalb der öffentlichen Ablage geprüft. Ein für die Installation eingerichteter Upload-Verzeichnislink bleibt unterstützt; einzelne Dateilinks dürfen dessen Ablage nicht verlassen. Zugangsangaben und URL-Abfragen werden nicht in der Hinweisliste ausgegeben. Neue Anfragen prüfen den aktuellen Stand erneut, sodass behobene Verweise sofort aus der Liste verschwinden.

Nach dem Speichern aktualisieren sich Liste und Prüfung automatisch. Der Editor übernimmt seine eigene Speicherantwort direkt, ohne einen konkurrierenden zweiten Formularabruf. Externe Aktualisierungsereignisse bleiben wirksam. Manuelles Nachprüfen ersetzt ausschließlich die Hinweisliste und erhält ungespeicherte Formulareingaben.

## Bilder im Blockeditor auswählen

Am passenden Bildfeld führt **Bild auswählen** in die Mediathek derselben Website. Unterstützt sind V2-Hero-Hintergründe, V2-Bildblöcke sowie das Einfügen eines Bildes in V2-Richtext und Legacy-HTML. Die Schaltfläche richtet sich nach dem ausgewählten Blocktyp und der Version; vorhandene manuelle Bildpfade bleiben möglich.

Die Auswahl bietet Suche nach Titel, Alternativtext und Pfad, Blättern und erneutes Laden. Sie lädt erst beim Öffnen und zeigt höchstens 24 Bilder pro Ansicht; eine Abfrage liest höchstens 25 Kandidaten ohne zusätzliche Gesamtzählung. Auch `%` und `_` werden als normale Suchzeichen behandelt. Bilder fremder Websites und Einträge ohne Website werden nicht angeboten. Fehlende Dateien, ungeeignete Dateitypen und Datei-Verknüpfungen außerhalb der Upload-Ablage bleiben ausgeschlossen. Ältere Bilddateien ohne MIME-Angabe können anhand ihrer Dateiendung angeboten werden.

Die Auswahl ersetzt ausschließlich den gewählten Bildpfad. Ein leerer Alternativtext eines Bildblocks wird aus den Medienangaben ergänzt; ein eigener Alternativtext, die Bildunterschrift und andere Editorwerte bleiben erhalten. In HTML-Texten wird das Bild an der Cursorposition beziehungsweise anstelle der markierten Stelle eingefügt. Alternativtexte werden dabei als Text maskiert und nicht als HTML ausgeführt.

**Erst das reguläre Speichern des Blocks übernimmt die Auswahl.** Suche, Blättern, Auswahl und Abbrechen schreiben keine CMS- oder Mediendaten. Schließen und Escape brechen ausstehende Abrufe ab; verzögerte Antworten können keinen inzwischen gewechselten Editor ersetzen. Ladefehler erhalten die Eingaben und lassen sich durch erneutes Öffnen beheben. „Medien verwalten“ öffnet die bestehende Uploadverwaltung in einem eigenen Tab; anschließend lässt sich die Auswahl neu laden. Laufzeitdateien unter `public/uploads` werden nicht in Git aufgenommen.

Der Dialog unterstützt Hell/Dunkel, Deutsch/Englisch, Tastaturbedienung und schmale Bildschirme. Die Bildschirmaufnahmen verwenden ausschließlich lokale Beispielbilder und Beispielkonten.

## Medienbestand und Metadaten bearbeiten

Unter **Admin → CMS → Medien** sind Bestand und Upload getrennt erreichbar. Die Übersicht zeigt höchstens 24 Einträge pro Seite, eine Suche nach Titel, Alternativtext oder Pfad und eine feste Reihenfolge nach Uploadzeit und ID. Die Kennzahl bezeichnet ausdrücklich nur die aktuelle Ansicht. Die Liste lädt höchstens 25 Datensätze ohne zusätzliche Gesamtzählung; öffentliche Galerien behalten ihre bisherigen Freigaben.

„Bearbeiten“ öffnet einen eigenen Editor für Titel und Alternativtext. Beide Angaben sind optional und auf jeweils 180 Zeichen begrenzt; auch der Wert `0` bleibt erhalten. Dateipfad, Website-Zuordnung, MIME-Typ, Größe, Uploadzeit und Dateiinhalt lassen sich über diesen Editor nicht verändern. Bereits in Inhaltsblöcken gespeicherte Bildtexte bleiben erhalten. Die Bildauswahl liest nach dem Speichern die aktuellen Medienangaben; sie schreibt bestehende Blöcke nicht nachträglich um.

Bearbeitung und Suche verwenden ausschließlich die aktuelle Website. Fremde und nicht zugeordnete Einträge lassen sich nicht über IDs bearbeiten. Suche und aktuelle Seite bleiben nach einer Metadatenänderung erhalten. Alle Schreibwege der Medienverwaltung – Upload, Metadatenänderung und Löschen – prüfen Formular-Tokens. Fehlerhafte Angaben und abgebrochene Uploads bleiben mit verständlichen Hinweisen korrigierbar; die Datei wird vor der erfolgreichen Prüfung nicht verschoben und muss im Browser erneut ausgewählt werden.

Die Übersicht und der Metadateneditor verwenden dieselben lokalen Bildprüfungen wie die Bildauswahl. Für fehlende Bilder, sonstige Dateien und ungeeignete Pfade erscheint eine Dateikennzeichnung statt eines Bildabrufs. Dadurch werden zum Beispiel PDF-Dateien oder ein fehlerhaft gespeicherter Aktionspfad nicht als Bild angefordert. Sonstige Dateitypen bleiben in der Verwaltung erhalten.

## Verkleinerte Medienvorschau

Bestand, Bildauswahl und Metadateneditor verwenden für Rasterbilder serverseitig erzeugte Miniaturbilder mit maximal 144 × 108, 400 × 240 beziehungsweise 800 × 440 Pixeln. Seitenverhältnisse und Transparenz bleiben erhalten; kleine Bilder werden nicht vergrößert. JPEG-Ausrichtung und Spiegelung werden berücksichtigt, ohne eine zusätzliche EXIF-Erweiterung zu benötigen. Animierte Bilder erhalten eine statische Vorschau; SVG und ICO verwenden weiterhin die vorhandene Darstellung. Originaldateien, öffentliche Bildpfade und die in Inhaltsblöcken gespeicherten Verweise bleiben unverändert. Die Auswahl übernimmt immer den Originalpfad, niemals den Vorschaulink.

Die lokale GD-Erweiterung ist jetzt eine ausdrückliche Composer-Anforderung; Installer und CI installieren sie bereits. Vorschauen werden als WebP oder, ohne WebP-Unterstützung, als PNG erzeugt. Welche Eingabeformate verfügbar sind, hängt vom GD-Build ab. Beschädigte oder nicht decodierbare Rasterbilder erhalten eine kleine Ersatzgrafik und bleiben auswählbar. Das gilt auch bei mehr als 16 MiB Quelldatei, 24 Millionen Pixeln, über 16.384 Pixeln Seitenlänge oder unzureichendem verfügbarem PHP-Speicher. Die Grenzen werden vor dem vollständigen Decodieren geprüft; es werden keine externen Bilddienste oder SVG-Konverter aufgerufen.

Die drei Varianten je Medien-ID liegen ausschließlich im privaten Symfony-Cache. Ein Inhalts-Hash erkennt Dateiänderungen auch bei identischer Größe und gleichem Zeitstempel; Titel- und ALT-Änderungen erfordern keine neue Bildberechnung. Atomare Cache-Schreibvorgänge vermeiden teilweise Dateien. Wiederholte Aufrufe brauchen keinen erneuten GD-Decodiervorgang. Zugelassene bedingte Browserabrufe erhalten einen ETag und gegebenenfalls HTTP 304. Adminsitzung und Website-Zuordnung werden davor erneut geprüft; fehlende Originale und ausbrechende Dateilinks bleiben unerreichbar. Löschen entfernt die zugehörigen Cache-Dateien; ein Symfony-Cachewechsel baut die Vorschauen bei Bedarf neu auf.

## Öffentliche Mediengalerie

Die Medienseite hat jetzt eine eigene responsive Galerie mit Suche und seitenweisem Laden. Titel, Bildbeschreibungen und Bedienelemente sind auf Deutsch und Englisch verfügbar; der bisherige Clan-spezifische Einführungstext ist durch eine allgemeine Beschreibung ersetzt. Hosting, Clan und Privat behalten ihre eigene Navigation, Farbpalette und Hell-/Dunkel-Auswahl. Die sieben bisherigen Designs erhalten dieselben Galeriefunktionen. Bei Clan Nova, Neon Arena und Titan Squad akzeptiert das Layout jetzt zusätzlich den gemeinsamen Modul-Inhaltsblock; ihre bestehenden Seiten mit eigenem `body`-Block bleiben erhalten. Die drei Designs verwenden außerdem die tatsächlich gewählte Dokumentsprache statt einer fest hinterlegten deutschen Kennung. Damit erscheinen auch Blog, Events und Teams in diesen drei bisherigen Designs wieder im Inhaltsbereich.

Die Abfrage liest höchstens 25 Bildmedien der aktuellen Website und zeigt davon bis zu 24. Nicht als Bild gekennzeichnete, nicht zugeordnete und fremde Medien erscheinen nicht in der Galerie. Die bisherige Auswahl der Bildmedien bleibt erhalten; es wird keine zusätzliche Veröffentlichungsfreigabe pro Bild eingeführt. Suche nach Titel, Alternativtext oder Dateipfad sowie Sortierung nach Uploadzeit und ID erfolgen bereits in der Datenbank. Suchzeichen wie `%`, `_` und `!` werden wörtlich behandelt. Blättern erhält Suche und Sprache; neue Suchvorgänge beginnen auf der ersten Seite. Es erfolgt keine zusätzliche Gesamtzählung und es werden keine weiteren Seiten im Hintergrund geladen.

Lokale Rasterbilder nutzen die vorhandene Variante mit maximal 400 × 240 Pixeln und denselben begrenzten privaten Cache. Die Bilder werden erst in der Nähe des sichtbaren Bereichs geladen. Ein eigener öffentlicher Bildabruf prüft jedes Mal Website-Zuordnung, Bild-MIME-Typ, CMS-/Modulfreigabe und Wartungsmodus, auch vor einer möglichen HTTP-304-Antwort. Fremde Medien liefern HTTP 404, deaktivierte Module HTTP 404 und aktive Wartung HTTP 503. Admin-Vorschaurouten bleiben ausschließlich für Admins erreichbar. Originale öffnen über einen eigenen Link; gespeicherte Bildpfade bleiben unverändert. Die öffentliche Originalablage wird dadurch nicht zu einem privaten Dateispeicher.

Vorhandene SVG-/ICO-Bilder und gültige externe HTTP(S)-Bildadressen bleiben nutzbar. Externe Bilder werden direkt vom Browser eingebunden; das Panel lädt oder konvertiert sie nicht. Fehlende oder ungeeignete lokale Vorschauen erhalten eine Ersatzdarstellung statt automatischer Bildabrufe von Aktionspfaden. Unsichere Protokolle, ausbrechende Dateilinks und URLs mit Zugangsdaten werden nicht als Galerieziele verwendet. Die Medien bleiben in der Adminverwaltung bearbeitbar.

## Validierung und Vorschau

Die vollständige PHPUnit-Suite besteht unter PHP 8.4.27 und PHP 8.5.12 mit jeweils **2.442 Tests und 12.522 Assertions**, ohne Warnungen oder übersprungene Tests. Der bisherige CMS-/Theme-/Isolationsumfang besteht unter beiden Versionen mit **99 Tests**. Die zuvor abgeschlossene Mediennachprüfung bestand unter beiden Versionen mit **43 Tests und 818 Assertions**. Sie kontrolliert die Modul- und Authentifizierungsseiten aller drei Designs, alle zehn Designs bei gespeicherten Entwürfen, Registrierungssperren, Vorschau ohne Speicherung oder Veröffentlichung, Websitegrenzen bei Vorschau und Inhaltsverwaltung, Rollen-/Sitzungsgrenzen, unbekannte Hosts, erhaltene lokale Bearbeitung und Medienuploads, deaktivierte Module, ausgeblendete Foren sowie die bisherigen Navigations-, CSRF-, Block- und Einstellungsprüfungen. Fünf weitere Fälle prüfen die Medienauswahl einschließlich Suche und Pagination, Website-/Sitzungsgrenzen, verfügbare Bilder und Legacy-Metadaten, unveränderte Mediendaten, erhaltene und abgewiesene Eingaben sowie ausbrechende Datei-Symlinks. Die Medienverwaltungsfälle sichern Metadatenänderungen ohne Änderungen an Datei und Inhaltsblöcken, Rollen-/Websitegrenzen, Tokenprüfungen, Längengrenzen, erhaltene Fehlerwerte einschließlich unterbrochener Uploads, leere Angaben und den Wert `0`, vorhandene Upload-/Löschwege sowie begrenzte Suche und Listen ohne automatische Bildabrufe von Aktionspfaden. Die Link-/Medienfälle prüfen die Link-/Medienauswertung, unveränderte gespeicherte Inhalte, Website- und Sitzungsgrenzen, neue Auswertung nach Korrekturen, relative URLs, begrenzte Inhalte und das Zurückweisen ausbrechender Datei-Symlinks.

Sieben neue Vorschaufälle prüfen alle drei Größen, erhaltene Originale und ausgewählte Originalpfade, Cache-Wiederverwendung und Inhaltswechsel bei gleichem Zeitstempel und gleicher Größe, bedingte Abrufe, Website-/Sitzungsgrenzen vor HTTP 304, fehlende und beschädigte Dateien, Datei-/Pixelgrenzen, ausbrechende Dateilinks, Transparenz, nicht beschreibbaren Cache und Cache-Entfernung. Die unter beiden Laufzeiten verfügbaren PNG-, JPEG-, GIF-, BMP-, WebP- und AVIF-Codecs sowie alle acht JPEG-Ausrichtungen mit beiden EXIF-Bytefolgen sind enthalten.

Psalm einschließlich Taint-Analyse meldet **0 Befunde**, ohne neue Unterdrückungen oder Baseline. Alle **569 Twig-Templates**, beide neuen Übersetzungen und **78 JavaScript-Tests** bestehen. Der Produktionscontainer lässt sich unter beiden PHP-Versionen mit `cache:warmup --no-optional-warmers` aufbauen; eine vollständige Produktions-Datenbankverbindung ist in der lokalen Vorschau nicht eingerichtet.

Chromium prüft **87 Seitenaufrufe und 24 mobile Ansichten** bei 320/390 Pixeln in beiden Farbmodi, ohne JavaScriptfehler oder horizontalen Überlauf. Dazu kommen **zwölf neue Ansichten** der gespeicherten Seitenvorschau: alle drei Designs in Hell/Dunkel und Desktop/Mobil. Die Sandbox verhindert den Dokumentzugriff aus dem Adminrahmen; eingebettete Skripte und Aktionsziele erzeugen keine ausgeführten Aktionen. Nach dem Vergleich sind die CMS-Tabellen unverändert, nicht gespeicherte Editorwerte erhalten, fremde Seiten abgewiesen und Entwürfe weiterhin öffentlich unerreichbar. Die frühere Adminauswahl aller zehn Designs, allgemeine Designvorschau ohne Speicherung sowie Designwechsel in beide Richtungen mit erhaltenem Seiteninhalt bleiben geprüft. Anmeldung mit Rücksprungziel, Abmeldung, Kontomenü, mobile Navigation und Cookie-Einstellungen sind ebenfalls geprüft.

Die zusätzliche Browserprüfung kontrolliert manuelle Nachprüfung mit genau einem GET, erhaltene ungespeicherte Editorwerte, das unmittelbare Verschwinden korrigierter Verweise nach einem regulären Speichervorgang, die aktualisierte Hinweisliste ohne konkurrierende Formularabfrage, beide Farbmodi, mobile Darstellung und englische Beschriftungen. Es treten keine JavaScriptfehler auf. Die absichtlich fehlerhaften Beispielverweise der neuen Aufnahmen zeigen die Hinweisliste.

Die Bildauswahl ist zusätzlich im Browser für Hero-, Bild-, Richtext- und Legacy-Blöcke geprüft: Abruf erst beim Öffnen, 24/4-Pagination, Suche per GET, Erhalt von Überschrift und Bildunterschrift, Ergänzung ausschließlich leerer Alternativtexte, Einfügen an der Cursorposition mit maskierten Metadaten, Abbrechen, erneut nutzbare Ladefehler und Abbruch verzögerter Antworten vor einem Editorwechsel. Dabei bleiben alle CMS-/Medientabellen unverändert und es entstehen keine Schreibanfragen. Beide Farbmodi und ein auf 390 Pixeln passender Dialog sind ohne JavaScript-Ausnahmen geprüft.

Die Metadatenverwaltung ist zusätzlich im Browser mit regulärem Speichern und Abbrechen, erhaltenen Suchfiltern, sofort aktuellen Medienangaben in der Bildauswahl, korrigierbaren Validierungsfehlern, abgewiesenen Tokenfehlern sowie echten lokalen Upload- und Löschvorgängen geprüft. Eine Metadatenänderung erhält Dateiinhalt, Zuordnung und vorhandene Inhaltsblöcke; die ursprünglichen Beispielangaben wurden anschließend wiederhergestellt. Beide Farbmodi und eine auf 390 Pixeln passende Medienübersicht laufen ohne JavaScript-Ausnahmen.

Die öffentliche Galerie ist zusätzlich in zwölf Desktop-/Mobilansichten der drei aktuellen Designs geprüft: beide Farbmodi, 320/390 Pixel, 24/6-Pagination mit getrennten Websitebeständen, deutsche und englische Suche mit Zurücksetzen und leerem Ergebnis, erhaltene Originalverweise, kleinere Rasterbilder und bedingte Abrufe. Fremde Bild-IDs liefern HTTP 404; die Admin-Vorschau bleibt ohne Sitzung mit HTTP 401 geschützt. Dabei bleiben die CMS-/Medientabellen unverändert; es entstehen keine Schreibanfragen oder JavaScript-Ausnahmen. Die zusätzlichen HTTP-Fälle prüfen außerdem alle zehn Designs einschließlich erhaltener Seiteninhalte und der bisherigen Modulansichten, wörtliche Suchzeichen, begrenzte Datenbankauswahl, unbekannte Hosts, globales CMS-Aus, deaktivierte Medien, Wartung, nicht als Bild gekennzeichnete Medien, fehlende Dateien, externe Bilder, SVG und ausbrechende Dateilinks.

Die neue Rastervorschau ist zusätzlich im Browser mit lokalem JPEG-Upload, allen drei Größen, HTTP 304, Auswahl des Originalpfads und anschließendem tokengeprüftem Löschen geprüft. Das Beispiel verkleinert 464.596 Bytes auf 14.486 Bytes bei 400 × 225 Pixeln; dies ist ein Einzelbeispiel, keine allgemeine Kompressionszusage. Hell/Dunkel und die 390-Pixel-Ansicht funktionieren ohne JavaScript-Ausnahmen. Die bisherige vollständige Bildauswahlprüfung besteht weiterhin ohne Schreibanfragen oder Änderungen an CMS-/Medientabellen.

Die Hosting-/Cookie-Nachprüfung umfasst zusätzliche 30 Browseransichten der drei aktuellen Designs und mobile Seiten bei 320/390 Pixeln. Alle sechs Modulzugänge sind erreichbar; der Hosting-Shop zeigt die fünf dafür gespeicherten Beispielprodukte. Im Produkteditor bleiben Abrechnungswahl und URL auch nach dem Speichern der Cookie-Auswahl erhalten. Präferenzen, Richtlinie, Ablehnen, Speichern, Escape und erneutes Öffnen funktionieren ohne JavaScript-Ausnahmen. Als einzige Schreibanfragen entstehen Cookie-Zustimmungen; CMS-, Produkt-, Bestell-, Vertrags- und Kontakttabellen bleiben unverändert. Elf neue JavaScript-Fälle prüfen unter anderem Einwilligung, Widerruf, alte Cookie-Links, Tastaturbedienung, fehlende beziehungsweise alte Auswahl, Offline-Fallback und verspätete Antworten. Vier HTTP-Fälle sichern gespeicherte Hosting-Menüs, begrenzte eigene Produktvorschauen, Modulabschaltung und Cookie-Dialogzugänge einschließlich alter URLs ab.

Die Aufnahmen verwenden drei getrennte lokale Beispiel-Websites mit gespeicherten Beispieldaten. Bestellungen, Zahlungen, Bereitstellungen und reale Agentzugriffe wurden dabei nicht ausgeführt. Es wurde keine Produktivinstallation verändert.

![Hosting, hell](images/cms-theme-hosting-light.png)

![Clan und Community, dunkel](images/cms-theme-clan-dark.png)

![Privat und Projekte, hell](images/cms-theme-private-light.png)

![Designauswahl im Adminbereich](images/cms-theme-admin-picker-light.png)

![Geschützte Seitenvorschau für Hosting](images/cms-page-preview-hosting-light.png)

![Seitenvorschau für Clan und Community in Dunkel](images/cms-page-preview-clan-dark.png)

![Mobile Auswahl der Seitenvorschau](images/cms-page-preview-private-mobile.png)

![Link- und Medienprüfung in der Blockverwaltung](images/cms-content-checks-admin-light.png)

![Link- und Medienprüfung in Dunkel](images/cms-content-checks-admin-dark.png)

![Mobile Link- und Medienprüfung](images/cms-content-checks-mobile.png)

![Bildauswahl im Blockeditor, hell](images/cms-media-picker-light.png)

![Bildauswahl im Blockeditor, dunkel](images/cms-media-picker-dark.png)

![Bildauswahl auf einem schmalen Bildschirm](images/cms-media-picker-mobile.png)

![Medienbestand mit Suche und Upload-Reiter, hell](images/cms-media-library-light.png)

![Medienbestand in Dunkel](images/cms-media-library-dark.png)

![Mobile Medienübersicht](images/cms-media-library-mobile.png)

![Titel und Alternativtext bearbeiten](images/cms-media-metadata-dark.png)

![Medienübersicht mit verkleinerter Rastervorschau, hell](images/cms-thumbnails-light.png)

![Medienübersicht mit verkleinerter Rastervorschau, dunkel](images/cms-thumbnails-dark.png)

![Verkleinerte Rastervorschau auf einem schmalen Bildschirm](images/cms-thumbnails-mobile.png)

![Öffentliche Galerie für Hosting, hell](images/cms-gallery-hosting-light.png)

![Öffentliche Galerie für Clan und Community, dunkel](images/cms-gallery-clan-dark.png)

![Öffentliche Galerie für private Websites auf Mobilgeräten](images/cms-gallery-private-mobile.png)

![Hosting-Shop mit echten lokalen Beispielprodukten](images/cms-hosting-shop-light.png)

![Teams im Hosting-Design](images/cms-hosting-teams-light.png)

![Events im Hosting-Design](images/cms-hosting-events-light.png)

![Blog im Hosting-Design](images/cms-hosting-blog-light.png)

![Forum im Hosting-Design](images/cms-hosting-forum-light.png)

![Kontakt im Hosting-Design](images/cms-hosting-kontakt-light.png)

![Cookie-Präferenzen in Dunkel](images/cms-cookie-settings-dark.png)

![Cookie-Richtlinie im mobilen Dialog](images/cms-cookie-policy-mobile.png)

## Sinnvolle nächste Arbeiten vor neuen Modulen

1. Auf einer Testinstallation Kauf, Bereitstellung, Abrechnung und Kündigung sowie Sicherung und Wiederherstellung mit echten Agenten und Zahlungs-Sandboxen vollständig abnehmen.
2. Langsame Seiten und Datenbankabfragen messen. Besonders die Startseiten sollten nur die tatsächlich benötigten Community- und Inventardaten laden; Aufbewahrungs- und Löschläufe mit größeren Datenbeständen prüfen.
3. Ladezustände, veraltete Messwerte und verständliche Fehler mit korrigierbaren Formulareingaben über alle Bereiche vereinheitlichen.
4. Interaktive CMS-Inhalte und die tatsächlich verwendeten Bildformate auf einer freigegebenen Testinstallation abnehmen.
5. Ein komplettes Probe-Update einschließlich Migrationen, Rückfallweg und Wiederherstellung durchführen.

## Nachprüfung: Modulübersichten wie Startseite und „Über uns“

Die folgenden Aufnahmen zeigen die tatsächlichen lokalen Ansichten mit Beispieldaten im Hosting-Design; es sind keine Entwurfsbilder. Weitere Designs, Sprachen und Farbmodi sind im Browservergleich enthalten.

![Referenz: Über uns im vorhandenen dunklen Design](images/cms-module-reference-about-dark.png)

![Teams mit demselben Seitenkopf und lesbarem Einführungstext](images/cms-module-teams-dark.png)

![Events](images/cms-module-events-dark.png)

![Blog](images/cms-module-blog-dark.png)

![Forum](images/cms-module-forum-dark.png)

![Medien](images/cms-module-media-dark.png)

![Serververzeichnis](images/cms-module-servers-dark.png)

![Shop](images/cms-module-shop-dark.png)

![Serververzeichnis auf einem mobilen Bildschirm](images/cms-module-servers-mobile.png)
