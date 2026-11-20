<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

/** Descriptions only describe supported keys actually found in the server file. */
final class GameConfigurationFields
{
    /** @return array<string,array{0:string,1:string,2:string,3?:list<string>}> */
    public static function descriptions(): array
    {
        return [
            'accepts-transfers' => ['Serverwechsel annehmen', 'Erlaubt Verbindungen, die von einem anderen Minecraft-Server übertragen werden.', 'Netzwerk'],
            'bug-report-link' => ['Link für Fehlermeldungen', 'Adresse, unter der Spieler Fehler dieses Servers melden können.', 'Allgemein'],
            'debug' => ['Debug-Protokollierung', 'Erzeugt zusätzliche Diagnoseausgaben und kann die Protokollgröße erhöhen.', 'Erweitert'],
            'enable-code-of-conduct' => ['Verhaltensregeln anzeigen', 'Zeigt die auf dem Server hinterlegten Verhaltensregeln beim Beitritt an.', 'Allgemein'],
            'enable-jmx-monitoring' => ['JMX-Messwerte', 'Stellt JVM-Messwerte für eine gesondert eingerichtete Überwachung bereit.', 'Performance'],
            'initial-disabled-packs' => ['Anfangs deaktivierte Datenpakete', 'Datenpakete, die beim Erstellen einer neuen Welt nicht aktiviert werden.', 'Welt'],
            'initial-enabled-packs' => ['Anfangs aktivierte Datenpakete', 'Datenpakete, die beim Erstellen einer neuen Welt aktiviert werden.', 'Welt'],
            'max-chained-neighbor-updates' => ['Verkettete Blockaktualisierungen', 'Begrenzt aufeinanderfolgende Nachbaraktualisierungen von Blöcken. Sehr hohe Werte können den Server belasten.', 'Performance'],
            'pause-when-empty-seconds' => ['Leeren Server pausieren', 'Sekunden ohne Spieler bis zum Pausieren der Welt. Ein negativer Wert deaktiviert die automatische Pause.', 'Performance'],
            'rate-limit' => ['Paketlimit', 'Begrenzt eingehende Pakete je Spieler; 0 deaktiviert das Limit.', 'Sicherheit'],
            'region-file-compression' => ['Kompression der Weltdateien', 'Verfahren zum Komprimieren gespeicherter Regionen. Nur vom eingesetzten Server unterstützte Verfahren verwenden.', 'Performance'],
            'status-heartbeat-interval' => ['Statusintervall', 'Intervall für die Aktualisierung der Serverstatusinformationen.', 'Performance'],
            'text-filtering-version' => ['Textfilter-Version', 'Protokollversion des konfigurierten Textfilterdienstes.', 'Chat'],
            'enable-query' => ['Query-Abfragen', 'Vom Panel eingerichtete Abfrage des Gameserver-Status.', 'Netzwerk'],
            'enable-rcon' => ['RCON-Zugang', 'Vom Panel verwalteter Fernzugriff auf die Serverkonsole.', 'Netzwerk'],
            'rcon.password' => ['RCON-Passwort', 'Zugangsdaten für die vom Panel verwaltete Serverkonsole.', 'Sicherheit'],
            'server-portv6' => ['IPv6-Spielport', 'Vom Panel reservierter IPv6-Port.', 'Netzwerk'],
            'management-server-enabled' => ['Verwaltungsdienst', 'Vom Panel verwalteter zusätzlicher Minecraft-Verwaltungszugang.', 'Netzwerk'],
            'management-server-host' => ['Verwaltungsadresse', 'Bind-Adresse des zusätzlichen Verwaltungsdienstes.', 'Netzwerk'],
            'management-server-port' => ['Verwaltungsport', 'Reservierter Port des zusätzlichen Verwaltungsdienstes.', 'Netzwerk'],
            'management-server-secret' => ['Verwaltungsschlüssel', 'Zugangsschlüssel des vom Panel verwalteten Dienstes.', 'Sicherheit'],
            'management-server-tls-enabled' => ['TLS für den Verwaltungsdienst', 'Verschlüsselung des vom Panel verwalteten Dienstes.', 'Sicherheit'],
            'management-server-tls-keystore' => ['TLS-Schlüsselspeicher', 'Vom Betreiber bereitgestellter Schlüsselspeicher des Verwaltungsdienstes.', 'Sicherheit'],
            'management-server-tls-keystore-password' => ['TLS-Schlüsselspeicher-Passwort', 'Zugang zum vom Betreiber verwalteten Schlüsselspeicher.', 'Sicherheit'],
            'motd' => ['Serverbeschreibung', 'Text, der in der Multiplayer-Serverliste angezeigt wird.', 'Allgemein'],
            'hostname' => ['Servername', 'Öffentlicher Name des Gameservers in der Serverliste.', 'Allgemein'],
            'server-name' => ['Servername', 'Name dieses Servers in der Serverliste.', 'Allgemein'],
            'gamemode' => ['Standard-Spielmodus', 'Spielmodus für neue Spieler. Bei erzwungenem Spielmodus auch beim erneuten Beitritt.', 'Spielmechaniken', ['survival', 'creative', 'adventure', 'spectator']],
            'difficulty' => ['Schwierigkeit', 'Beeinflusst Gegner, Schaden und die Herausforderung der Spielwelt.', 'Spielmechaniken', ['peaceful', 'easy', 'normal', 'hard']],
            'hardcore' => ['Hardcore-Modus', 'Höchste Schwierigkeit und besondere Folgen beim Tod. Wirkt bei Minecraft vor allem auf neu erzeugte Welten.', 'Spielmechaniken'],
            'force-gamemode' => ['Spielmodus erzwingen', 'Setzt Spieler beim Beitritt auf den vorgegebenen Spielmodus.', 'Spielmechaniken'],
            'allow-flight' => ['Fliegen erlauben', 'Verhindert das Entfernen von Spielern wegen unerlaubten Fliegens.', 'Spielmechaniken'],
            'pvp' => ['Spielerkämpfe', 'Erlaubt Schaden zwischen Spielern.', 'Spielmechaniken'],
            'spawn-monsters' => ['Monster erzeugen', 'Erlaubt das Erscheinen feindlicher Kreaturen.', 'Welt'],
            'spawn-animals' => ['Tiere erzeugen', 'Erlaubt das Erscheinen friedlicher Tiere.', 'Welt'],
            'spawn-npcs' => ['Dorfbewohner erzeugen', 'Erlaubt das Erscheinen von NPCs.', 'Welt'],
            'allow-nether' => ['Nether aktivieren', 'Erlaubt den Zugang zur Nether-Dimension.', 'Welt'],
            'level-name' => ['Weltordner', 'Name des Weltverzeichnisses. Eine Änderung kann eine neue Welt laden; vorhandene Welten werden nicht automatisch verschoben.', 'Welt'],
            'level-seed' => ['Welt-Seed', 'Seed für neu erzeugte Welten. Ändert eine bereits bestehende Welt nicht.', 'Welt'],
            'level-type' => ['Welttyp', 'Generator für eine neu erzeugte Welt. Bestehende Weltdateien bleiben erhalten.', 'Welt'],
            'generate-structures' => ['Strukturen erzeugen', 'Erzeugt Strukturen in neu generierten Weltbereichen.', 'Welt'],
            'generator-settings' => ['Generator-Einstellungen', 'Zusätzliche Einstellungen des gewählten Weltgenerators.', 'Welt'],
            'max-world-size' => ['Maximale Weltgröße', 'Begrenzt die Größe der Welt in Blöcken.', 'Welt'],
            'spawn-protection' => ['Spawn-Schutz', 'Radius um den Spawn, in dem Änderungen besonders geschützt werden.', 'Sicherheit'],
            'online-mode' => ['Anmeldung prüfen', 'Prüft Minecraft-Konten beim Anmelden. Nur bei bewusst eingerichteten Proxy-Netzen deaktivieren.', 'Sicherheit'],
            'white-list' => ['Whitelist aktivieren', 'Beschränkt den Zugang auf freigegebene Spieler.', 'Sicherheit'],
            'enforce-whitelist' => ['Whitelist erzwingen', 'Entfernt nicht freigegebene Spieler, wenn die Liste geändert wird.', 'Sicherheit'],
            'enforce-secure-profile' => ['Sichere Profile erzwingen', 'Erfordert gültige signierte Spielerprofile.', 'Sicherheit'],
            'enable-command-block' => ['Befehlsblöcke', 'Erlaubt die Ausführung von Befehlsblöcken.', 'Sicherheit'],
            'op-permission-level' => ['Operator-Rechte', 'Berechtigungsstufe für Operatoren.', 'Sicherheit'],
            'function-permission-level' => ['Funktionsrechte', 'Berechtigungsstufe für Serverfunktionen.', 'Sicherheit'],
            'view-distance' => ['Sichtweite', 'Anzahl der Chunks, die an Spieler gesendet werden. Höhere Werte benötigen mehr Ressourcen.', 'Performance'],
            'simulation-distance' => ['Simulationsdistanz', 'Bereich aktiver Spielsimulation um die Spieler. Höhere Werte erhöhen CPU- und RAM-Bedarf.', 'Performance'],
            'max-tick-time' => ['Maximale Tickzeit', 'Zeitlimit des Watchdogs in Millisekunden.', 'Performance'],
            'network-compression-threshold' => ['Netzwerkkompression', 'Größe, ab der Netzwerkpakete komprimiert werden.', 'Performance'],
            'sync-chunk-writes' => ['Chunks synchron schreiben', 'Synchronisiert das Speichern von Chunks.', 'Performance'],
            'entity-broadcast-range-percentage' => ['Entitäten-Reichweite', 'Prozentuale Reichweite für die Übertragung von Entitäten.', 'Performance'],
            'use-native-transport' => ['Native Netzwerkfunktionen', 'Verwendet unterstützte Betriebssystemfunktionen für den Netzwerktransport.', 'Performance'],
            'player-idle-timeout' => ['Leerlauf-Zeitlimit', 'Minuten ohne Aktivität bis zum Trennen eines Spielers. 0 deaktiviert das Limit.', 'Spielmechaniken'],
            'max-players' => ['Maximale Spielerzahl', 'Gleichzeitig erlaubte Spieler. Darf die gebuchte Slotzahl nicht überschreiten.', 'Spielmechaniken'],
            'player_limit' => ['Proxy-Spielerlimit', 'Verbindliches Spielerlimit des Proxys innerhalb der gebuchten Slots.', 'Spielmechaniken'],
            'max_players' => ['Angezeigte Spielerzahl', 'Spielerlimit innerhalb der gebuchten Slots. Beim Proxy wird zusätzlich das verbindliche Spielerlimit gesetzt.', 'Spielmechaniken'],
            'maxplayers' => ['Maximale Spielerzahl', 'Gleichzeitig erlaubte Spieler innerhalb der gebuchten Slots.', 'Spielmechaniken'],
            'sv_maxplayers' => ['Maximale Spielerzahl', 'Gleichzeitig erlaubte Spieler innerhalb der gebuchten Slots.', 'Spielmechaniken'],
            'resource-pack' => ['Ressourcenpaket-URL', 'Adresse des Ressourcenpakets, das Spielern angeboten wird.', 'Spielmechaniken'],
            'resource-pack-id' => ['Ressourcenpaket-ID', 'Kennung des angebotenen Ressourcenpakets.', 'Spielmechaniken'],
            'resource-pack-sha1' => ['Prüfsumme des Ressourcenpakets', 'SHA-1-Prüfsumme zum Prüfen des heruntergeladenen Pakets.', 'Spielmechaniken'],
            'resource-pack-prompt' => ['Hinweis zum Ressourcenpaket', 'Text für die Nachfrage beim Beitritt.', 'Chat'],
            'require-resource-pack' => ['Ressourcenpaket erforderlich', 'Verlangt, dass Spieler das Ressourcenpaket akzeptieren.', 'Spielmechaniken'],
            'enable-status' => ['Serverlisten-Status', 'Erlaubt den Abruf öffentlicher Serverinformationen.', 'Netzwerk'],
            'hide-online-players' => ['Spielerliste verbergen', 'Verbirgt Spielernamen bei öffentlichen Statusabfragen.', 'Sicherheit'],
            'broadcast-console-to-ops' => ['Konsolennachrichten an Operatoren', 'Sendet Konsolenausgaben an Operatoren.', 'Chat'],
            'broadcast-rcon-to-ops' => ['RCON-Nachrichten an Operatoren', 'Sendet RCON-Ausgaben an Operatoren.', 'Chat'],
            'text-filtering-config' => ['Textfilter', 'Konfiguration des Textfilterdienstes.', 'Chat'],
            'log-ips' => ['IP-Adressen protokollieren', 'Steuert die Protokollierung von Spieleradressen.', 'Sicherheit'],
            'server-port' => ['Server-Port', 'Vom Panel reservierter Spielport.', 'Netzwerk'],
            'server-ip' => ['Bind-Adresse', 'Vom Panel verwaltete IP-Adresse.', 'Netzwerk'],
            'query.port' => ['Query-Port', 'Vom Panel reservierter Statusport.', 'Netzwerk'],
            'rcon.port' => ['RCON-Port', 'Vom Panel reservierter RCON-Port.', 'Netzwerk'],
            'sv_password' => ['Serverpasswort', 'Passwort für den Beitritt zum Server.', 'Sicherheit'],
            'sv_lan' => ['LAN-Modus', 'Beschränkt den Server auf ein lokales Netz.', 'Netzwerk'],
            'mp_timelimit' => ['Zeitlimit', 'Spieldauer pro Karte in Minuten.', 'Spielmechaniken'],
            'mp_roundtime' => ['Rundenzeit', 'Dauer einer Runde in Minuten.', 'Spielmechaniken'],
            'friendlyfire' => ['Teambeschuss', 'Erlaubt Schaden an eigenen Teammitgliedern.', 'Spielmechaniken'],
        ];
    }

    public static function slots(string $key): bool
    {
        $tail = preg_replace('/^.*[.]/', '', strtolower($key)) ?? strtolower($key);

        return in_array($tail, ['max-players', 'maxplayers', 'sv_maxplayers', 'max_players', 'playerlimit', 'player_limit', 'slots', 'maxclients', 'sv_maxclients', 'serverplayermaxnum', 'servermaxplayercount', 'maxplayercount', 'maxconnectedusers', 'slotcount'], true);
    }

    public static function managed(string $key): bool
    {
        $key = strtolower($key);
        $tail = preg_replace('/^.*[.]/', '', $key) ?? $key;
        $fixed = ['ip', 'hostip', 'host_ip', 'serverip', 'server_ip', 'server-ip', 'bind', 'bind_ip', 'bind-address', 'bind_address', 'ip_address', 'listenaddress', 'listen_address', 'hostport', 'host_port', 'server-port', 'server-portv6', 'serverport', 'server_port', 'port', 'queryport', 'query_port', 'rconport', 'rcon_port', 'gameport', 'game_port', 'net_port', 'sv_port', 'enable-rcon', 'enable-query', 'transport', 'enable-lan-visibility', 'server-udp-ports', 'ram_limit', 'server_memory', 'cpu_limit', 'disk_limit', 'memory_limit', 'java_xmx', 'java_xms', 'max_slots', 'booked_slots', 'max_players_booked', 'endpoint_add_tcp', 'endpoint_add_udp', 'publicip', 'publicport', 'beaconport', 'directconnectionserverport', 'steamqueryport', 'queryport', 'gameport', 'rconport', 'bindip'];

        return 1 === preg_match('/^listeners[.][0-9]+[.](?:host|query_port|query_enabled)$/D', $key) || str_starts_with($tail, 'management-server-') || str_ends_with($tail, '-port') || str_ends_with($tail, '_port') || in_array($key, ['query.port', 'rcon.port', 'rcon.password'], true) || in_array($tail, $fixed, true) || str_starts_with($tail, 'port_');
    }
}
