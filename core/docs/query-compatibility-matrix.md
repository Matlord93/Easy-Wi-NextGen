# Query Compatibility Matrix

| template_slug | engine | query_protocol | host_source | game_port_source | query_port_rule | timeout_ms | expected_transport | expected_response_fields |
|---|---|---|---|---|---|---:|---|---|
| cs2 | source2 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | explicit | 4000 | udp | name,map,players,max_players,version,latency_ms |
| csgo_legacy | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| rust | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| ark | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| valheim | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| satisfactory | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| palworld | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| palworld_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| valheim_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| satisfactory_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| dayz | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| dayz_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| v_rising | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| v_rising_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| enshrouded_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| garrys_mod | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| terraria | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| seven_days_to_die | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| factorio | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| project_zomboid | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| project_zomboid_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| conan_exiles | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| conan_exiles_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| arma3 | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| arma3_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| cs2_windows | source2 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | explicit | 4000 | udp | name,map,players,max_players,version,latency_ms |
| csgo_legacy_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| tf2 | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| tf2_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| css | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| css_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| hl2dm | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| hl2dm_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| l4d2 | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| l4d2_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| l4d | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| l4d_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| dods | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| dods_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| minecraft_vanilla_all | minecraft_java | minecraft_java | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | tcp | motd,players,max_players,version,latency_ms |
| minecraft_paper_all | minecraft_java | minecraft_java | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | tcp | motd,players,max_players,version,latency_ms |
| minecraft_bedrock | bedrock | minecraft_bedrock | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | motd,players,max_players,version,latency_ms |
| hytale | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| ark_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| rust_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| enshrouded | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| squad | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| squad_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| fivem | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |
| fivem_windows | source1 | steam_a2s | bind_ip -> node_ip -> loopback(host-mode) | setup_vars/port_block/assigned_port | same_as_game_port | 4000 | udp | name,map,players,max_players,version,latency_ms |

## Canonical Query Rules
- **Source1 / Source2 (A2S):** send `A2S_INFO`, handle challenge (`0x41`), reassemble split packets (`0xFFFFFFFE`), retry once on timeout and once after challenge.
- **Minecraft Java:** TCP status handshake + request, parse JSON status (`version`, `players`, `description`).
- **Minecraft Bedrock:** RakNet unconnected ping/pong, parse semicolon-delimited MOTD/version/player counts.
- **Host resolution:** `bind_ip/connect_ip` -> `node.primary_ip` -> loopback only when network mode is `host`.
- **Port resolution:** explicit query port > `sv_queryport` > game port unless template sets `plus_one`.
