# Easy-Wi NextGen (Core + Agent Platform)

## 🚀 Überblick

Dieses Projekt ist ein kompletter **Neuaufbau und Erweiterung** des bestehenden Webinterfaces **Easy-Wi V6.2.5**.
Ziel ist eine moderne, sichere und modulare Hosting-Plattform für:

- 🎮 **Gameserver (Steam / Steam Sniper / Templates)**
- 🎧 **Teamspeak (TS3 voll, TS6 später)**
- 🌐 **Webhosting (Webspaces + Domains + SSL)**
- 📧 **Mailserver (voll verwaltbar über Panel)**
- 🌍 **DNS-Server Verwaltung**
- 🗄️ **Datenbank Verwaltung (MariaDB + PostgreSQL)**
- 🔥 **Firewall / Fail2Ban / Updates / Monitoring**
- 🎫 **Ticketsystem**
- 💶 **rechtssichere Rechnungen (B2C, später Mahnungen)**

Wichtig ist: **Keine Verwaltung mehr über Panel-SSH**.  
Alle Aktionen laufen über **Agenten**, die auf den Servern installiert werden.

---

## 🎯 Ziele & Motivation

Easy-Wi ist funktional, aber veraltet.
Dieses NextGen-Projekt verfolgt folgende Ziele:

✅ PHP8.4 / PHP8.5 kompatibel  
✅ Moderne UI (neues Panel als Primary)  
✅ Agent-basierte Verwaltung (Linux + später Windows)  
✅ Sichere Architektur (kein Backdoor / kein unkontrolliertes SSH)  
✅ Strikte Rechteverwaltung (Customer/Admin, Quotas, Limits)  
✅ Support von getrennten Servertypen (Game/Web/Mail/DNS/DB Nodes)  
✅ GitHub-basierte Releases & Updates (Core + Agent)  
✅ Modular und erweiterbar wie Plesk, aber fokussiert auf Gameserver

---

## 🏗️ Architektur

### 🔥 Core (Symfony, PHP 8.4+)
Der **Core** ist das Herzstück.
Er bietet:

- Auth & Roles (Admin / Customer)
- API (REST v1)
- DB Modell für Kunden, Instanzen, Domains, Webspaces etc.
- Job Queue / Orchestration
- Audit Logging (tamper-evident)
- Billing / Tickets / Monitoring

### 🤖 Agent System (Linux/Windows)
Alle Server bekommen einen **Agent**, der:

- Jobs vom Core abholt (Pull alle 2–3 Sekunden)
- Whitelisted Aktionen ausführt (kein shell exec!)
- Dienste installiert/konfiguriert (role-based)
- Status/Monitoring zurückmeldet
- sich selbst über GitHub aktualisieren kann

### 🌐 UI (Neu)
Das neue Panel wird direkt modern aufgebaut:

- Symfony + Twig + Tailwind + HTMX
- keine Abhängigkeit von alter UI
- alte Easy-Wi UI wird optional später nachgezogen oder komplett ersetzt

---

## 🧩 Server Rollen / Node Types (separat!)

Das System unterstützt getrennte Servertypen:

- `game_node` → Gameserver/TS
- `web_node` → Webhosting (nginx + php-fpm)
- `mail_node` → Mailserver (Postfix + Dovecot)
- `dns_node` → DNS (PowerDNS)
- `db_node` → Datenbanken (MariaDB + PostgreSQL)

✅ Jeder Node wird über einen Installer provisioniert  
✅ Jede Rolle hat eigene `ensure_base` / Provisioning Jobs  
✅ Firewall/Fail2Ban/Updates sind role-aware

---

## 🔐 Sicherheits-Design (kein Backdoor)

Das Panel ist von Anfang an sicher aufgebaut:

### SSH / SFTP Trennung
- Admin SSH: **Port 22**, Key-only, IP Whitelist
- Customer SFTP: **Port 2222**, Chroot, internal-sftp, Password+Key

### Firewall
- Default deny incoming
- Ports werden nur explizit geöffnet
- Instanz-Ports werden automatisch per Job geöffnet/geschlossen

### Fail2Ban
- sshd + sftp jails
- mail auth jails

### Secrets & Encryption
- Sensitive DB Felder werden verschlüsselt (libsodium / AES-GCM)
- Master-Key liegt außerhalb DB
- Passwörter immer Argon2id

### Audit Logging
- Jede Aktion wird geloggt (Admin/Customer/Agent)
- Hash-Chain gegen Manipulation
- Jobs, Logs und Audit sind verknüpft

---

## ✅ Features (Endzustand)

### 🎮 Gameserver Plattform
- Templates für feste Spiel-Profile
- Steam / Steam Sniper Integration
- Install, Start, Stop, Restart
- Reinstall & GameSwitch (Port bleibt gleich)
- Updates manuell durch Kunden + optional auto-update opt-in
- Limits pro Instanz: CPU/RAM/Disk
- Ports über Port Pool + Port Blocks pro Kunde (Standard: 5 Ports)
- Addons/Plugins Upload via SFTP (z.B. CS2 Metamod, CounterStrikeSharp)

### 🎧 Teamspeak
- TS3: voll verwaltbar im Panel
  - SQLite oder MySQL beim Erstellen
  - Token reset, settings, logs, backup, update
- TS6: später als Provider (CLI/SSH-based, modular)

### 🌐 Webhosting
- Webspaces (admin erstellt)
- Nginx vHost + PHP-FPM Pool pro Webspace
- PHP 8.4 / 8.5 auswählbar
- Domains + Subdomains
- SSL via Let’s Encrypt
- Logs im Panel
- Upload via SFTP (Web Node)

### 🌍 DNS
- PowerDNS mit API
- DNS Zones + Records (A/AAAA/CNAME/TXT/MX/SRV)
- Templates für SPF/DKIM/DMARC

### 📧 Mail
- Zentraler Mail Hostname (z.B. mail.yourdomain.tld)
- Postfix + Dovecot
- Domains + Mailboxes + Aliases
- DKIM key generation + record output
- Abuse protection (verification / DKIM check)
- Logs im Panel

### 🗄️ Datenbanken
- MariaDB + PostgreSQL
- DB + User + Grants (ALL / READ_ONLY)
- Password reset
- Internal only (DB Ports nicht öffentlich)

### 🎫 Ticketsystem
- Support Tickets (Billing/Tech/General)
- Message Threads
- Status Workflow (open / waiting / closed)

### 💶 Billing
- rechtssichere Rechnungen (EU B2C)
- Immutable PDFs + Hash
- Recurring plans
- Payment tracking
- später Mahnungen

### 🔥 Server Management
- Updates (multi OS provider)
- Reboot handling
- Monitoring + KPIs
- role-aware firewall rules
- fail2ban management (admin-only)

---

## 📦 Installer & Updates (GitHub Release Based)

### Installer
- Installiert Agent + Rollenmodule
- Bootstrap Token Registrierung am Core
- Security Baseline direkt beim Install
- Multi OS Support (Linux MVP, Windows später)

### Updates
- Core Updates aus GitHub Releases (fertige Bundle inkl vendor)
- Agent Updates aus GitHub Releases
- SHA256 verification (optional GPG)
- Rollback support

---

## 🛣️ Roadmap (MVP Fokus)
Das Projekt wird in klaren Phasen gebaut:

1. Core Foundation (Auth, API, Jobs, Audit, Encryption)
2. Installer + Agent (Linux)
3. Node Roles + Security Baseline
4. DB Node (MariaDB + Postgres)
5. Webhosting + Domains + SSL
6. DNS (PowerDNS)
7. Mail (Postfix+Dovecot)
8. Game Nodes + Templates + Steam Sniper
9. Tickets + Billing + Dashboard
10. Teamspeak TS3
11. Neue UI komplett, Legacy optional später

---

## ✅ Status
Dieses Repo enthält aktuell:

- ✅ MasterPlan / Architektur / Phasen
- ✅ Taskliste (GitHub Issues Backlog)
- ✅ Installer Design & Update Strategie
- ✅ Definition aller Module (Gameserver, Hosting, DB, Mail, DNS, Billing)

---

## 📌 Mitwirken / Entwicklung
Dieses Projekt ist groß und modular.
Empfohlenes Vorgehen:

- erst Core + Agent + Installer (Foundation)
- danach Module Schritt für Schritt (MVP scope strikt einhalten)

---

## ⚠️ Hinweis
Dieses Projekt ist **nicht** das alte Easy-Wi selbst, sondern der neue Core und die neue Plattform.
Legacy Easy-Wi kann später optional als UI nachgezogen werden.

