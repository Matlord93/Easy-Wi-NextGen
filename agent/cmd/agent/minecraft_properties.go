package main

import (
	"os"
	"path/filepath"
	"strings"
)

// isMinecraftTemplate returns true for any Minecraft server (Java or Bedrock).
func isMinecraftTemplate(payload map[string]any) bool {
	return isMinecraftJavaTemplate(payload) || isMinecraftBedrockTemplate(payload)
}

// isMinecraftBedrockTemplate returns true for Minecraft Bedrock Edition servers.
func isMinecraftBedrockTemplate(payload map[string]any) bool {
	for _, key := range []string{"game_type", "template_slug", "template_key", "game_key", "template_name", "game_name"} {
		candidate := strings.ToLower(strings.TrimSpace(payloadValue(payload, key)))
		if candidate != "" && strings.Contains(candidate, "bedrock") {
			return true
		}
	}
	return false
}

// setServerProperty updates the value of a key in a server.properties lines slice,
// or appends a new key=value entry. Comments and blank lines are preserved.
func setServerProperty(lines []string, key, value string) []string {
	lowerKey := strings.ToLower(key)
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") || strings.HasPrefix(trimmed, "!") {
			continue
		}
		eqIdx := strings.IndexByte(trimmed, '=')
		if eqIdx < 0 {
			continue
		}
		if strings.ToLower(strings.TrimSpace(trimmed[:eqIdx])) == lowerKey {
			lines[i] = key + "=" + value
			return lines
		}
	}
	return append(lines, key+"="+value)
}

// ensureMinecraftServerProperties writes or updates server.properties for any Minecraft server.
// Applies panel values (port, name, max players, passwords, rcon) from the job payload.
// Non-Minecraft servers are skipped entirely.
//
// Sequence:
//  1. server.jar install/update  (sniper.go)
//  2. eula.txt                   (minecraft_eula.go)
//  3. server.properties          (this file) ← guaranteed before start
//  4. systemctl start/restart
func ensureMinecraftServerProperties(instanceDir string, payload map[string]any) error {
	if !isMinecraftTemplate(payload) {
		return nil
	}

	requiredPortsRaw := payloadValue(payload, "required_ports")
	allocatedPorts := parsePayloadPorts(payload)
	tv := buildInstanceTemplateValues(instanceDir, instanceDir, requiredPortsRaw, allocatedPorts, payload)

	propsPath := filepath.Join(instanceDir, "server.properties")
	existing, err := os.ReadFile(propsPath)
	if err != nil && !os.IsNotExist(err) {
		return err
	}

	lines := parsePropertyLines(string(existing))
	isBedrock := isMinecraftBedrockTemplate(payload)

	if port, ok := tv["PORT_GAME"]; ok && port != "" {
		lines = setServerProperty(lines, "server-port", port)
		if isBedrock {
			lines = setServerProperty(lines, "server-portv6", port)
		}
	}
	if maxPlayers, ok := tv["MAX_PLAYERS"]; ok && maxPlayers != "" {
		lines = setServerProperty(lines, "max-players", maxPlayers)
	}
	if pw, ok := tv["SERVER_PASSWORD"]; ok {
		lines = setServerProperty(lines, "server-password", pw)
	}

	if isBedrock {
		if name, ok := tv["SERVER_NAME"]; ok && name != "" {
			lines = setServerProperty(lines, "server-name", name)
		}
	} else {
		// Java: Vanilla and Paper
		if name, ok := tv["SERVER_NAME"]; ok && name != "" {
			lines = setServerProperty(lines, "motd", name)
		}
		lines = setServerProperty(lines, "enable-rcon", "true")
		if rconPw, ok := tv["RCON_PASSWORD"]; ok {
			lines = setServerProperty(lines, "rcon.password", rconPw)
		}
		if rconPort, ok := tv["PORT_RCON"]; ok && rconPort != "" {
			lines = setServerProperty(lines, "rcon.port", rconPort)
		}
		// Query protocol: must use the same port as the game port so external
		// status checkers can reach the server.
		lines = setServerProperty(lines, "enable-query", "true")
		if gamePort, ok := tv["PORT_GAME"]; ok && gamePort != "" {
			lines = setServerProperty(lines, "query.port", gamePort)
		}
		// Bind to the agent's public IP so the server is reachable externally.
		if hostIP := payloadValue(payload, "node_ip", "public_ip", "bind_ip"); hostIP != "" {
			lines = setServerProperty(lines, "server-ip", hostIP)
		}
	}

	content := strings.Join(lines, "\n") + "\n"
	return os.WriteFile(propsPath, []byte(content), instanceFileMode)
}

// parsePropertyLines splits raw server.properties content into lines,
// normalising line endings and stripping trailing blank lines.
func parsePropertyLines(raw string) []string {
	if raw == "" {
		return []string{}
	}
	normalised := strings.ReplaceAll(raw, "\r\n", "\n")
	lines := strings.Split(normalised, "\n")
	for len(lines) > 0 && lines[len(lines)-1] == "" {
		lines = lines[:len(lines)-1]
	}
	return lines
}
