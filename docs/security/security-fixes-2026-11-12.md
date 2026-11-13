# Sicherheitskorrekturen vom 12.11.2026

Im Quellrepo umgesetzt:

- Agent-HMAC: beide Zeitstempelpfade konsumieren dieselbe Nonce. Eine eindeutige
  Datenbank-ID verhindert parallele Replays über PHP-Prozesse und Panel-Hosts
  hinweg. Aufbewahrung mindestens bis zum Ende des zulässigen Zeitfensters,
  einschließlich zukünftig datierter Anfragen. Cache-Leerungen entfernen den
  Replay-Verlauf nicht.
- Symfony aktualisiert; 19 Advisory-Treffer in zwölf Laufzeitpaketen sind im neuen
  Lockfile behoben. Die bekannten verwundbaren Versionen werden zusätzlich durch
  Composer-Konfliktregeln ausgeschlossen. Composer-Audit danach: null Treffer.
- `/files/health`: Anmeldung und Eigentümerschaft vor SFTP-Zugriff erzwingen;
  keine internen Pfade, Dateilisten oder ungefilterten Exceptions in Antworten.
- SFTP: SHA256-Host-Fingerprint pro Node, getrennt von Agent-Metadaten gespeichert.
  Prüfung vor dem Login; fehlende oder abweichende Schlüssel sperren die
  Verbindung. Adminformular mit CSRF-Prüfung und Auditprotokoll. Der Webspace-
  SFTP-Konstruktor verwendet die korrekten benannten Bibliotheksparameter.
- Neue Agent-Installationen und fehlende Listener-Konfigurationen verwenden
  `127.0.0.1:7456`. Explizite Bestandskonfigurationen bleiben erhalten. Die
  gemeinsame Panel/Agent-Installation registriert den lokalen Service-Endpunkt
  als Teil des signierten Registrierungspayloads. Remote-Nodes benötigen eine
  explizite Bind-Adresse und eine Freigabe ausschließlich für das Panel.

## Validierung

31 gezielte PHP-Tests, 110 Assertions: erfolgreich. Vier gleichzeitige Prozesse
mit gleicher Nonce: genau eine Annahme auf SQLite und auf MariaDB 10.11.
Alle 41 Migrationen auf einer isolierten MariaDB erfolgreich; Doctrine-Schema
synchron. Go-Konfigurationstests und Installerprüfungen einschließlich zweier
isolierter Funktionstests erfolgreich. PHPStan, Container, Twig und Composer
validiert. Gesamtsuite: 1385 Tests, 5094 Assertions; keine fehlgeschlagenen
Assertions, zwei bekannte Umgebungsfehler wegen fehlendem `ext-redis`, drei
Warnungen, 301 PHPUnit-Hinweise und zehn übersprungene Tests.

## Übernahme auf ameserver

Diese Änderungen wurden in diesem Cloud-Arbeitsbereich geprüft. Ihre Aktivierung
auf dem HomeServer wurde hier nicht verifiziert. Vor dem Wechsel auf den neuen
Panel-Code muss die additive Migration `Version20261112020000` ausgeführt werden;
sie legt den Replay-Speicher und die optionale Host-Fingerprint-Spalte an.
Bestehende Geschäftsdaten werden dabei nicht geändert oder gelöscht.

1. Release aus dem aktualisierten Lockfile bauen und im üblichen Wartungsablauf
   installieren. Migrationen mit dem für das Panel vorgesehenen PHP-CLI ausführen,
   dann Symfony-Cache und laufende PHP-Worker erneuern. Ohne Replay-Tabelle werden
   Agent-Anfragen nicht akzeptiert. Die Nonce-Tabelle nicht bei Deployments leeren.
2. Bei gemeinsamem Panel und Agent im Node-Formular die Agent-URL auf
   `http://127.0.0.1:7456` setzen. In `/etc/easywi/agent.conf` den bestehenden Wert
   auf `service_listen=127.0.0.1:7456` ändern und den Agent neu starten. Danach
   Listener, lokalen Healthcheck und Panel-Aufträge prüfen. Der Agent benötigt
   für seine bisherigen Provisionierungsaufgaben weiterhin erhöhte Rechte.
3. Den tatsächlichen Hostschlüssel des verwendeten SFTP-Dienstes über eine
   vertrauenswürdige Node-Konsole prüfen (`ssh-keygen -lf <Host-Public-Key> -E
   sha256`) und unter Nodes → vertrauenswürdiger SFTP-Hostschlüssel speichern.
   Ein ungeprüftes `ssh-keyscan` ist kein Vertrauensnachweis. Ohne hinterlegten
   Fingerprint bleiben SFTP-Dateizugriffe absichtlich gesperrt.
4. Bei getrennten Hosts Port 7456 ausschließlich für die Panel-IP/VPN-Verbindung
   freigeben. Beispiel für den Menü-Installer: `EASYWI_AGENT_SERVICE_LISTEN` und
   `EASYWI_AGENT_ADVERTISED_URL` auf den vorgesehenen privaten Endpunkt setzen.
5. Den HTTP-Backend-Port 80 bei vorgeschaltetem TLS-Proxy nur diesem Proxy
   zugänglich machen. Bei lokalem Proxy an Loopback binden; bei entferntem Proxy
   auf dessen Quell-IP begrenzen. Ohne TLS-Proxy HTTPS direkt am Panel einrichten.
   Die konkrete Firewall-/Proxy-Topologie von ameserver wurde hier nicht geändert.

Die Ubuntu-PHP2Shell-Patches und die Erreichbarkeit aus dem Internet wurden in
diesem Arbeitsbereich nicht erneut geprüft. Vorherige Minecraft- und
Rechnungserweiterungen bleiben erhalten.
