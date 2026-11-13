# Proxmox-Zertifikatsprüfung einstellen

Unter **Admin → Proxmox → Verbindung → Verbindungseinstellungen** kann bei
**Zertifikatsprüfung** die Auswahl **Ohne Zertifikatsprüfung verbinden** gespeichert
werden. Dieselbe Auswahl steht beim Anlegen einer Verbindung bereit. Sie gilt
für sämtliche API-Aufrufe und die Kunden-VNC-Konsole dieser Verbindung.

HTTPS bleibt verschlüsselt. Bei abgeschalteter Prüfung werden Zertifikatskette
und Hostname nicht überprüft; der Server wird damit nicht authentifiziert.
Die Einstellung kann jederzeit wieder aktiviert werden.

## Installation eines Updates

Nach dem Einspielen der aktualisierten Panel-Dateien auf ameserver:

```bash
sudo -u www-data /usr/bin/php8.5 /var/www/easywi/core/bin/console doctrine:migrations:migrate --env=prod --no-interaction
sudo -u www-data /usr/bin/php8.5 /var/www/easywi/core/bin/console cache:clear --env=prod --no-interaction
```

Migration `Version20261112050000` ergänzt `proxmox_clusters.verify_tls` mit dem
Standardwert `true`. Bestehende Verbindungen, verschlüsselte Tokens,
Kundenzuordnungen und Aufträge bleiben erhalten. Anschließend die gewünschte
Verbindung im Panel bearbeiten und die Prüfung ausschalten.

Für VNC muss zusätzlich die aktualisierte `easywi-agent`-Binärdatei auf dem
VM-Konsolen-Gateway installiert werden. Danach dort:

```bash
sudo systemctl restart easywi-vm-console.service
```

Ein älteres Gateway ignoriert die neue Einstellung und prüft weiterhin das
Upstream-Zertifikat. Die API-Einstellung im aktualisierten Panel funktioniert
unabhängig vom Gateway-Update. KVM- und Panel-Verbindungen erhalten keine
globale TLS-Ausnahme.
