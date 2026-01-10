# EasyWI NextGen – Installation & Betrieb

Willkommen bei **EasyWI NextGen**. Diese README liefert eine moderne, klare Schritt-für-Schritt-Anleitung
für die Installation des Panels sowie die Inbetriebnahme von Agent/Runner.

## Inhalt
1. [Systemvoraussetzungen](#systemvoraussetzungen)
2. [Server vorbereiten](#server-vorbereiten)
3. [Installation des Panels](#installation-des-panels)
   - [Automatischer Installer (Linux/Windows)](#automatischer-installer-linuxwindows)
   - [Standalone / Manuell](#standalone--manuell)
   - [Installation mit Plesk](#installation-mit-plesk)
   - [Installation mit aaPanel](#installation-mit-aapanel)
   - [Weitere gängige Setups](#weitere-gängige-setups)
4. [Agent & Runner: Installation und Inbetriebnahme](#agent--runner-installation-und-inbetriebnahme)
5. [Typische Fehler & Lösungen](#typische-fehler--lösungen)

---

## Systemvoraussetzungen

**Panel (Core/Web):**
- Linux-Server (Debian/Ubuntu, RHEL/Alma/Rocky, Arch)
- PHP **>= 8.4** (siehe `core/composer.json`)
- Nginx oder Apache
- MariaDB/MySQL oder PostgreSQL
- Composer
- Git

**Agent/Runner:**
- Linux-Server (systemd erforderlich)
- Root-Zugriff
- `curl` oder `wget`
- `sha256sum`

> Hinweis: Die automatische Installation nutzt systemd und lädt Agent/Runner aus GitHub Releases.

---

## Server vorbereiten

1. **System aktualisieren**
   - Debian/Ubuntu: `apt update && apt -y upgrade`
   - RHEL/Alma/Rocky: `dnf -y upgrade`

2. **Basis-Pakete installieren**
   - Debian/Ubuntu: `apt -y install curl git unzip`
   - RHEL/Alma/Rocky: `dnf -y install curl git unzip`

3. **DNS & Firewall prüfen**
   - Sicherstellen, dass der Server den Core API-Endpunkt erreichen kann.
   - Firewall-Regeln für HTTP/HTTPS (Panel) sowie Agent-Verbindungen freigeben.

---

## Installation des Panels

### Automatischer Installer (Linux/Windows)

Der Panel-Installer übernimmt das Herunterladen des Webinterfaces, die `.env.local`-Konfiguration,
Composer-Installation sowie optional die Migrationen. Für Linux gibt es zusätzlich eine Auswahl
zwischen **Standalone**, **Plesk** und **aaPanel**.

**Linux (mit Auswahl Plesk/aaPanel/Standalone):**

```bash
curl -fsSL https://raw.githubusercontent.com/easywi/easywi/main/installer/easywi-installer-panel-linux.sh -o easywi-installer-panel-linux.sh
chmod +x easywi-installer-panel-linux.sh
sudo ./easywi-installer-panel-linux.sh \
  --mode standalone \
  --install-dir /var/www/easywi \
  --db-driver mysql \
  --db-host 127.0.0.1 \
  --db-name easywi \
  --db-user easywi \
  --db-password <PASSWORT> \
  --web-hostname panel.example.com
```

**Windows (Standalone):**

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force
Invoke-WebRequest -Uri https://raw.githubusercontent.com/easywi/easywi/main/installer/easywi-installer-panel-windows.ps1 -OutFile easywi-installer-panel-windows.ps1
.\easywi-installer-panel-windows.ps1 -InstallDir C:\easywi -DbPassword "<PASSWORT>"
```

Nach dem Abschluss öffnet ihr `/install` im Browser, um das erste Admin-Konto anzulegen.

### Standalone / Manuell

Diese Variante eignet sich für eigene Server oder VMs ohne Hosting-Panel.

1. **Quellcode bereitstellen**
   ```bash
   git clone <REPOSITORY_URL> easywi
   cd easywi/core
   ```

2. **Abhängigkeiten installieren**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Umgebung konfigurieren**
   - `.env` bzw. `.env.local` anlegen und Datenbankzugang eintragen.
   - Beispielwerte:
     - `DATABASE_URL=mysql://user:pass@127.0.0.1:3306/easywi`
     - `APP_ENV=prod`

4. **Datenbank initialisieren**
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

5. **Webserver konfigurieren**
   - Document Root auf `core/public` setzen.
   - PHP-FPM aktivieren.

6. **Ersten Login testen**
   - Panel im Browser öffnen.
   - Admin-Login prüfen.

---

### Installation mit Plesk

1. **Domain anlegen** (z. B. `panel.example.com`).
2. **PHP-Version einstellen** (>= 8.4) und `composer` aktivieren.
3. **Git-Repository verbinden** (Plesk Git Integration) oder manuell hochladen.
4. **Document Root** auf `core/public` setzen.
5. **Composer installieren**:
   ```bash
   cd core
   composer install --no-dev --optimize-autoloader
   ```
6. **Datenbank erstellen** (MariaDB/PostgreSQL) und `.env` konfigurieren.
7. **Migrationen ausführen**:
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

---

### Installation mit aaPanel

1. **Website hinzufügen** (z. B. `panel.example.com`).
2. **PHP-Version** auf **8.4+** stellen.
3. **Datenbank erstellen** (MariaDB/PostgreSQL).
4. **Dateien hochladen** (Git oder Upload).
5. **Document Root** auf `core/public` setzen.
6. **Composer-Installation**:
   ```bash
   cd core
   composer install --no-dev --optimize-autoloader
   ```
7. **Migrationen ausführen**:
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

---

### Weitere gängige Setups

**Docker / Container:**
- PHP-FPM + Nginx Container kombinieren.
- `core/public` als Webroot.
- Datenbank als separater Container.

**Caddy / Apache:**
- Webroot `core/public`.
- PHP-FPM via Proxy/fastcgi einbinden.

---

## Agent & Runner: Installation und Inbetriebnahme

Der Agent verbindet den Server mit dem Panel und führt Aufgaben aus. Der Runner wird für Game-Server
und externe Prozesse benötigt.

### Automatische Installation (empfohlen)

1. **Installer herunterladen**
   ```bash
   curl -fsSL https://raw.githubusercontent.com/easywi/easywi/main/installer/easywi-installer-linux.sh -o easywi-installer-linux.sh
   chmod +x easywi-installer-linux.sh
   ```

2. **Installer starten**
   ```bash
   sudo ./easywi-installer-linux.sh \
     --core-url https://panel.example.com \
     --bootstrap-token <TOKEN> \
     --roles game,web,dns,mail,db
   ```

3. **Agent prüfen**
   ```bash
   systemctl status easywi-agent.service
   journalctl -u easywi-agent.service -n 50 --no-pager
   ```

### Manuelle Agent-Installation

1. **Agent herunterladen** (Release-Binary):
   ```bash
   curl -fsSL https://github.com/easywi/easywi/releases/latest/download/easywi-agent-linux-amd64 -o /usr/local/bin/easywi-agent
   chmod +x /usr/local/bin/easywi-agent
   ```

2. **Agent konfigurieren**
   ```bash
   sudo tee /etc/easywi/agent.conf <<'CONF'
   API_URL=https://panel.example.com
   AGENT_TOKEN=<TOKEN>
   CONF
   sudo chmod 600 /etc/easywi/agent.conf
   ```

3. **Systemd Service anlegen**
   ```bash
   sudo tee /etc/systemd/system/easywi-agent.service <<'SERVICE'
   [Unit]
   Description=EasyWI Agent
   After=network-online.target
   Wants=network-online.target

   [Service]
   Type=simple
   ExecStart=/usr/local/bin/easywi-agent --config /etc/easywi/agent.conf
   Restart=on-failure

   [Install]
   WantedBy=multi-user.target
   SERVICE

   sudo systemctl daemon-reload
   sudo systemctl enable --now easywi-agent.service
   ```

4. **Test**
   ```bash
   easywi-agent --version
   ```

### Runner-Installation (optional, für Game-Role)

1. **Runner herunterladen**
   ```bash
   curl -fsSL https://github.com/easywi/easywi/releases/latest/download/easywi-runner-linux-amd64 -o /usr/local/bin/easywi-runner
   chmod +x /usr/local/bin/easywi-runner
   ```

2. **Funktion testen**
   ```bash
   easywi-runner --version
   ```

---

## Typische Fehler & Lösungen

- **„Bootstrap token missing“**
  - Lösung: `--bootstrap-token` setzen oder `EASYWI_BOOTSTRAP_TOKEN` exportieren.

- **„Unsupported distribution“**
  - Lösung: Linux-Distribution prüfen oder manuelle Installation verwenden.

- **Agent verbindet nicht**
  - Firewall/Ports prüfen, `API_URL` korrekt setzen.
  - `journalctl -u easywi-agent.service` prüfen.

- **PHP/Composer Fehler**
  - PHP-Version prüfen (>= 8.4).
  - `composer install` erneut ausführen.

---

## Nächste Schritte

- Panel einrichten, Rollen definieren, Agenten registrieren.
- Bei Problemen Logs in `/var/log/easywi/` prüfen.
