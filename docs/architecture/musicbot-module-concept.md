# Architekturkonzept: Eigenes Musicbot-Modul

## 1. Zielbild

Das Musicbot-Modul wird als eigenes First-Party-Modul im Symfony-Core umgesetzt und folgt der bestehenden Modularisierung unter `core/src/Module/*`. Es stellt verwaltete Musikbot-Instanzen für Kunden bereit, die Audio in TeamSpeak- und Discord-Zielsysteme streamen können, ohne SinusBot, TS3AudioBot oder vergleichbare Musicbot-Produkte als Backend zu nutzen.

Das Zielbild ist eine native EasyWi-Control-Plane:

- **Symfony als Control Plane**: Mandanten, Instanzen, Playlists, Connector-Konfiguration, Berechtigungen, Audits, Quotas und Job-Orchestrierung leben im Core.
- **Go-Agent als Runtime Plane**: Der Agent installiert und betreibt einen eigenen Musicbot-Worker pro Instanz, verwaltet Prozesse, Audio-Pipelines, Healthchecks, Updates und Connector-Sessions.
- **Connector-Abstraktion**: TeamSpeak und Discord werden über eigene Adapter angesprochen; die Playback-Engine bleibt connector-neutral.
- **Kein Fremd-Musicbot-Backend**: Es dürfen keine externen Musicbot-Systeme wie SinusBot oder TS3AudioBot vorausgesetzt werden. Zulässig sind generische, austauschbare Bibliotheken und Systemwerkzeuge für Audio-Dekodierung, Resampling, Prozessisolation oder Protokollzugriff.
- **Mandantenfähiger Panel-Betrieb**: Kunden verwalten nur eigene Bots, Admins verwalten Nodes, Limits, Runtime-Versionen, Connector-Richtlinien und globale Plugin-Freigaben.

## 2. Modulstruktur

Vorgeschlagene Symfony-Struktur:

```text
core/src/Module/Musicbot/
  Application/
    MusicbotNodeService.php
    MusicbotInstanceService.php
    MusicbotPlaybackService.php
    MusicbotConnectorService.php
    MusicbotPluginService.php
    MusicbotJobPayloadBuilder.php
    MusicbotQuotaGuard.php
  Domain/
    Entity/
      MusicbotNode.php
      MusicbotInstance.php
      MusicbotTrack.php
      MusicbotPlaylist.php
      MusicbotPlaylistItem.php
      MusicbotConnector.php
      MusicbotCredential.php
      MusicbotPlugin.php
      MusicbotPluginInstallation.php
      MusicbotRuntimeEvent.php
    Enum/
      MusicbotInstanceStatus.php
      MusicbotConnectorType.php
      MusicbotPlaybackState.php
      MusicbotJobType.php
      MusicbotPluginTrustLevel.php
  Infrastructure/
    Repository/
    AudioMetadata/
    Storage/
    Connector/
  UI/
    Controller/
      Admin/
      Customer/
      Api/
    Form/
  Dto/
  Event/
```

Die Controller werden wie die vorhandenen Module per Attribute-Routing eingebunden. Dafür wird in `core/config/routes.yaml` ein zusätzlicher Block `module_musicbot_controllers` mit `resource.path: ../src/Module/Musicbot/UI/Controller/`, `namespace: App\Module\Musicbot\UI\Controller` und `type: attribute` ergänzt.

Die Architektur trennt bewusst zwischen:

- **Core-Domain**: persistente Mandanten- und Konfigurationsdaten.
- **Application Services**: Validierung, Quotas, Job-Erzeugung, Statusprojektion.
- **Agent-Jobs**: idempotente Kommandos an die Runtime.
- **Agent-Runtime**: ausführende Musicbot-Prozesse und Connector-Sessions.

## 3. Domain-Entities

### `MusicbotNode`

Repräsentiert die Musicbot-Fähigkeit eines Agents bzw. Nodes.

Wichtige Felder:

- `id`
- `agent`
- `name`
- `installPath`
- `runtimeVersion`
- `serviceNamePrefix`
- `maxInstances`
- `maxConcurrentStreams`
- `maxDiskBytes`
- `supportedConnectors`
- `status`
- `lastHeartbeatAt`
- `lastError`

### `MusicbotInstance`

Eine kundenbezogene Bot-Instanz.

Wichtige Felder:

- `id`
- `node`
- `customerId`
- `name`
- `status`
- `playbackState`
- `volume`
- `defaultConnector`
- `activeConnector`
- `currentTrack`
- `processIdentity`
- `dataPath`
- `createdAt`, `updatedAt`, `lastStartedAt`

### `MusicbotConnector`

Konfiguration einer Zielplattform pro Instanz.

Wichtige Felder:

- `id`
- `instance`
- `type`: `teamspeak` oder `discord`
- `displayName`
- `enabled`
- `targetServerRef`
- `targetChannelRef`
- `botNickname`
- `encryptedCredentialRef`
- `policy`
- `lastConnectStatus`
- `lastConnectError`

### `MusicbotCredential`

Speichert Secrets nur verschlüsselt und getrennt von normaler Konfiguration.

Beispiele:

- Discord Bot Token
- TeamSpeak ServerQuery- oder Client-Identität
- optionales Channel-Passwort
- API-Schlüssel für erlaubte Metadaten-Provider

### `MusicbotPlaylist`, `MusicbotPlaylistItem`, `MusicbotTrack`

Persistieren Warteschlangen, Bibliothek und Metadaten. Track-Quellen werden als normalisierte Referenz gespeichert, nicht als frei ausführbare Shell-Kommandos.

### `MusicbotPlugin` und `MusicbotPluginInstallation`

Beschreiben zugelassene Erweiterungen und deren mandantenbezogene Installation. Plugins werden versioniert, signiert und mit Capability-Scopes betrieben.

### `MusicbotRuntimeEvent`

Append-only Ereignislog für Audit und UI:

- Connector verbunden/getrennt
- Track gestartet/gestoppt
- Plugin-Fehler
- Quota-Verletzung
- Runtime-Healthcheck

## 4. API-/Panel-Routen

### Admin-Panel

- `GET /admin/musicbot/nodes`
- `POST /admin/musicbot/nodes/{id}/install`
- `POST /admin/musicbot/nodes/{id}/status`
- `POST /admin/musicbot/nodes/{id}/restart-runtime`
- `GET /admin/musicbot/instances`
- `POST /admin/musicbot/instances/{id}/force-stop`
- `GET /admin/musicbot/plugins`
- `POST /admin/musicbot/plugins/{id}/approve`
- `POST /admin/musicbot/plugins/{id}/disable-globally`

### Customer-Panel

- `GET /customer/musicbot`
- `POST /customer/musicbot`
- `GET /customer/musicbot/{id}`
- `POST /customer/musicbot/{id}/start`
- `POST /customer/musicbot/{id}/stop`
- `POST /customer/musicbot/{id}/restart`
- `POST /customer/musicbot/{id}/connectors`
- `POST /customer/musicbot/{id}/connectors/{connectorId}/connect`
- `POST /customer/musicbot/{id}/connectors/{connectorId}/disconnect`
- `POST /customer/musicbot/{id}/playback/play`
- `POST /customer/musicbot/{id}/playback/pause`
- `POST /customer/musicbot/{id}/playback/skip`
- `POST /customer/musicbot/{id}/playback/volume`
- `GET /customer/musicbot/{id}/playlist`
- `POST /customer/musicbot/{id}/playlist/items`
- `DELETE /customer/musicbot/{id}/playlist/items/{itemId}`

### API

- `GET /api/musicbot/instances/{id}/state`
- `POST /api/musicbot/instances/{id}/commands`
- `GET /api/musicbot/instances/{id}/events`
- `GET /api/musicbot/connectors/capabilities`
- `POST /api/musicbot/webhooks/discord/interactions`

Alle mutierenden Routen prüfen Besitzer, Modulzugriff, CSRF im Panel und API-Token-Scopes in der API.

## 5. Agent-Job-Typen

Die Job-Typen orientieren sich am vorhandenen Schema `bereich.ressource.aktion` und werden über `AgentJobDispatcher` erzeugt und über `AgentJobValidator` auf Pflichtfelder geprüft.

Vorgeschlagene Typen:

| Job-Typ | Zweck | Pflichtfelder |
| --- | --- | --- |
| `musicbot.node.install` | Runtime und Systemabhängigkeiten installieren | `node_id`, `install_dir`, `service_name_prefix`, `runtime_version` |
| `musicbot.node.status` | Node-Fähigkeiten und Runtime-Version abrufen | `node_id` |
| `musicbot.node.update` | Eigene Runtime aktualisieren | `node_id`, `runtime_version` |
| `musicbot.instance.create` | Instanz-Verzeichnis, Config und Prozessdefinition anlegen | `instance_id`, `customer_id`, `node_id`, `data_dir` |
| `musicbot.instance.delete` | Instanz stoppen und Daten gemäß Retention entfernen | `instance_id`, `delete_data` |
| `musicbot.instance.action` | `start`, `stop`, `restart` | `instance_id`, `action` |
| `musicbot.instance.status` | Prozess-, Connector- und Playback-Status lesen | `instance_id` |
| `musicbot.connector.configure` | Connector-Konfiguration rendern | `instance_id`, `connector_id`, `connector_type` |
| `musicbot.connector.action` | `connect`, `disconnect`, `reconnect` | `instance_id`, `connector_id`, `action` |
| `musicbot.playback.command` | `play`, `pause`, `resume`, `skip`, `seek`, `volume` | `instance_id`, `command` |
| `musicbot.playlist.sync` | Queue/Playlist an Agent spiegeln | `instance_id`, `playlist_revision` |
| `musicbot.plugin.install` | Plugin in Sandbox installieren | `instance_id`, `plugin_id`, `version` |
| `musicbot.plugin.action` | Plugin aktivieren, deaktivieren, reloaden | `instance_id`, `plugin_id`, `action` |

Statusrückgaben werden durch einen Musicbot-spezifischen Result-Applier in Domain-Status, Runtime-Events und gecachte UI-Snapshots projiziert.

## 6. Runtime-Konzept auf dem Agent

Der Go-Agent erhält eine eigene Musicbot-Komponente, die nicht als Wrapper um bestehende Musicbot-Produkte arbeitet. Empfohlen ist eine pro Instanz isolierte Runtime:

```text
agent/internal/musicbot/
  manager/        # Job-Handler, Prozessverwaltung, Healthchecks
  runtime/        # eigener Musicbot-Worker
  audio/          # Decoder, Queue, Resampler, Lautstärke, Normalisierung
  connectors/
    teamspeak/
    discord/
  plugins/        # Sandbox, Manifest, Hooks
  storage/        # Config, Cache, Track-Dateien, Logs
```

Runtime-Eigenschaften:

- ein eigener Worker-Prozess pro Musicbot-Instanz;
- systemd-template oder Agent-supervised process mit stabiler `processIdentity`;
- lokale Unix-Socket- oder loopback-gRPC-Control-Schnittstelle zwischen Agent und Worker;
- Audio-Pipeline mit normalisierten PCM-Frames als internes Format;
- connector-spezifische Encoder/Packetizer am Ausgang;
- harte Limits für CPU, RAM, Dateigröße, Track-Länge, Queue-Länge und gleichzeitige Transcodes;
- Healthchecks für Prozess, Connector-Session, Audio-Backpressure und Plugin-Fehler;
- Logs und Runtime-Events werden ohne Secrets an den Core zurückgemeldet.

Die Runtime darf generische Tools wie FFmpeg/GStreamer-ähnliche Decoder nutzen, sofern diese als austauschbare Audio-Komponenten behandelt werden und kein fertiges Musicbot-System darstellen.

## 7. TeamSpeak-Connector

Der TeamSpeak-Connector wird als eigener Runtime-Adapter entworfen und nutzt die vorhandenen TeamSpeak-Objekte nur zur Zielauswahl und Rechteprüfung.

Kernpunkte:

- Connector-Typ `teamspeak` referenziert optional vorhandene TS3-/TS6-Serverdaten oder frei konfigurierte Serverziele, wenn der Mandant dazu berechtigt ist.
- Der Core speichert Server-ID, Channel-ID, Nickname und verschlüsselte Verbindungsdaten in `MusicbotConnector`/`MusicbotCredential`.
- Die bestehende TeamSpeak-Query-Schicht kann für Verwaltungsabfragen wie Channel-Listen, Servergruppen oder Vorvalidierung wiederverwendet werden.
- Die Agent-Runtime hält die eigentliche Voice-Session und streamt Audioframes in den Zielchannel.
- Rechte werden minimal gehalten: verbinden, sprechen/senden, optional Channel wechseln; keine globalen Adminrechte für den Bot.
- Verbindungsfehler werden in `lastConnectStatus`, `lastConnectError` und `MusicbotRuntimeEvent` abgebildet.

Wichtig: Der Connector ersetzt nicht TS3/TS6-Serververwaltung und benötigt keine SinusBot-Installation. Er ist ein eigener Audio-Client/Adapter innerhalb der Musicbot-Runtime.

## 8. Discord-Connector

Der Discord-Connector wird gleichwertig zum TeamSpeak-Connector als eigener Adapter implementiert.

Kernpunkte:

- Connector-Typ `discord` speichert Guild-ID, Voice-Channel-ID, Bot-Anwendungs-ID und verschlüsseltes Bot-Token.
- OAuth2-/Bot-Einladungsfluss wird im Panel geführt; Tokens werden ausschließlich verschlüsselt gespeichert.
- Slash Commands und Interactions sind optional und werden über Core-Webhooks angenommen, validiert und als Playback-Kommandos an die Instanz weitergereicht.
- Die Agent-Runtime hält Gateway-/Voice-Verbindung und übernimmt Audio-Encoding sowie Reconnect-Strategie.
- Pro Instanz kann genau eine aktive Discord-Voice-Session erzwungen werden, um Token- und Rate-Limit-Probleme zu vermeiden.
- Discord-Rate-Limits, Gateway-Disconnects und fehlende Channel-Rechte werden als strukturierte Runtime-Events gespeichert.

## 9. Plugin-System

Plugins erweitern die Musicbot-Runtime, ohne Zugriff auf Core-Secrets oder Host-Ressourcen zu erhalten.

### Plugin-Typen

- **Source Provider**: ergänzt erlaubte Track-Quellen oder Suchanbieter.
- **Command Plugin**: eigene Chat-/Panel-Kommandos.
- **Filter Plugin**: Audiofilter wie Normalisierung, Crossfade oder Ducking.
- **Automation Plugin**: zeitgesteuerte Playlists, Jingles, Regeln.
- **Metadata Plugin**: Cover, Titel, Interpret, Dauer, Kapitel.

### Manifest

Jedes Plugin benötigt ein Manifest:

```json
{
  "id": "vendor.plugin",
  "version": "1.0.0",
  "runtime": "musicbot-plugin-v1",
  "capabilities": ["playlist.read", "playback.command"],
  "network": { "allowed_hosts": [] },
  "hooks": ["track.resolved", "playback.started"]
}
```

### Sicherheitsmodell

- Plugins sind standardmäßig deaktiviert und benötigen Admin-Freigabe.
- Installationen sind mandanten- und instanzbezogen.
- Capabilities werden im Core geprüft und an die Agent-Sandbox gespiegelt.
- Keine Shell-Ausführung aus Plugin-Manifests.
- Netzwerkzugriffe sind deny-by-default und hostbasiert erlaubbar.
- Plugin-Logs werden getrennt vom Instanzlog geführt und maskieren Secrets.

## 10. Sicherheits- und Mandantenkonzept

### Mandantentrennung

- Jede `MusicbotInstance` besitzt genau einen `customerId`.
- Alle Panel- und API-Zugriffe filtern nach `customerId` oder Adminrolle.
- Instanzdaten liegen auf dem Agent unter einem isolierten Pfad, z. B. `/var/lib/easywi/musicbot/{customerId}/{instanceId}`.
- Prozessbenutzer, Dateirechte und cgroup/systemd-Limits trennen Instanzen voneinander.

### Secret-Schutz

- Credentials werden im Core verschlüsselt gespeichert und nur für konkrete Agent-Jobs entschlüsselt.
- Job-Payloads enthalten Secrets nur, wenn der Agent sie unmittelbar benötigt.
- Payload-Masking und Audit-Logs dürfen Tokens, Passwörter und Channel-Secrets nie im Klartext ausgeben.
- Connector-Tokens können rotiert und invalidiert werden.

### Autorisierung

- Admins verwalten Nodes, globale Limits, Runtime-Versionen und Plugin-Freigaben.
- Kunden verwalten eigene Instanzen, Connectoren, Playlists und erlaubte Plugins.
- API-Tokens erhalten granulare Scopes, z. B. `musicbot:read`, `musicbot:playback`, `musicbot:admin`.

### Quotas und Abuse-Schutz

- Limits pro Kunde: Instanzen, gleichzeitige Streams, Speicher, maximale Trackdauer, maximale Queue-Länge.
- Limits pro Node: CPU/RAM, maximale Worker, globale Transcode-Slots.
- Rate-Limits für Playback-Kommandos, Playlist-Importe und Suchanfragen.
- Content-Quellen werden allowlist-/policy-basiert validiert.

### Audit und Compliance

- Mutierende Aktionen werden mit Actor, Customer, Instanz, Job-ID und Request-ID auditiert.
- Runtime-Events sind mandantenbezogen lesbar, aber sicherheitsrelevante Details bleiben admin-only.
- Löschungen beachten Retention-Regeln für Logs, Track-Caches und Plugin-Daten.

### Resilienz

- Agent-Jobs sind idempotent und können gefahrlos erneut zugestellt werden.
- Runtime-Status ist projektionsfähig: Der Core kann aus `musicbot.instance.status` und Events einen konsistenten UI-Zustand rekonstruieren.
- Connector-Reconnects laufen agentseitig mit Backoff; der Core bleibt Quelle der gewünschten Konfiguration.
