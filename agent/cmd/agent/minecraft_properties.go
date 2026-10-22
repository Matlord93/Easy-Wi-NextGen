package main

import (
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strconv"
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
	value = strings.NewReplacer("\\", "\\\\", "\r", "\\r", "\n", "\\n").Replace(value)
	result := make([]string, 0, len(lines)+1)
	found := false
	// Remove duplicate definitions as Java uses the last occurrence.
	for start := 0; start < len(lines); {
		end, logical := minecraftLogicalProperty(lines, start)
		parsed, ok := minecraftPropertyKey(logical)
		if ok && strings.EqualFold(parsed, key) {
			if !found {
				result = append(result, key+"="+value)
				found = true
			}
		} else {
			result = append(result, lines[start:end]...)
		}
		start = end
	}

	if !found {
		result = append(result, key+"="+value)
	}
	return result
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
	if isMinecraftProxyTemplate(payload) {
		return ensureBungeeConfiguration(instanceDir, payload)
	}
	if !isMinecraftTemplate(payload) {
		return nil
	}

	requiredPortsRaw := payloadValue(payload, "required_ports")
	allocatedPorts := parsePayloadPorts(payload)
	tv := buildInstanceTemplateValues(instanceDir, instanceDir, requiredPortsRaw, allocatedPorts, payload)

	if payloadValue(payload, "minecraft_managed") == "true" {
		if err := validateMinecraftPort(tv["PORT_GAME"]); err != nil {
			return err
		}
		if isMinecraftBedrockTemplate(payload) {
			if err := validateMinecraftPort(tv["PORT_GAMEV6"]); err != nil {
				return err
			}
		}
	}
	propsPath := filepath.Join(instanceDir, "server.properties")
	existing, err := readMinecraftConfig(instanceDir, "server.properties")
	if err != nil && !os.IsNotExist(err) {
		return err
	}

	lines := parsePropertyLines(string(existing))
	isBedrock := isMinecraftBedrockTemplate(payload)

	if port, ok := tv["PORT_GAME"]; ok && port != "" {
		lines = setServerProperty(lines, "server-port", port)
		if isBedrock {
			v6 := tv["PORT_GAMEV6"]
			if v6 == "" {
				v6 = port
			}
			lines = setServerProperty(lines, "server-portv6", v6)
		}
	}
	if maxPlayers, ok := tv["MAX_PLAYERS"]; ok && maxPlayers != "" {
		lines = setServerProperty(lines, "max-players", maxPlayers)
	}
	if pw, ok := tv["SERVER_PASSWORD"]; ok {
		lines = setServerProperty(lines, "server-password", pw)
	}

	if isBedrock {
		if payloadValue(payload, "minecraft_managed") == "true" {
			// Preserve the transport shipped by this version. New NetherNet
			// releases need TCP signaling and a constrained UDP gameplay port.
			transport := payloadValue(payload, "minecraft_bedrock_transport")
			if transport == "" {
				transport = minecraftPropertyValue(lines, "transport")
			}
			if transport == "" {
				transport = "raknet"
			}
			if transport != "raknet" && transport != "nethernet" {
				return fmt.Errorf("unsupported Bedrock transport %q", transport)
			}
			payload["minecraft_bedrock_transport"] = transport
			lines = setServerProperty(lines, "transport", transport)
			if transport == "nethernet" {
				lines = setServerProperty(lines, "server-udp-ports", tv["PORT_GAMEV6"])
				bind := payloadValue(payload, "bind_ip")
				if bind != "" && net.ParseIP(bind) == nil {
					return fmt.Errorf("invalid Bedrock bind address")
				}
				lines = setServerProperty(lines, "server-ip", bind)
			}
			// RakNet LAN discovery also binds unreserved default ports.
			lines = setServerProperty(lines, "enable-lan-visibility", "false")
		}
		if name, ok := tv["SERVER_NAME"]; ok && name != "" {
			if payloadValue(payload, "minecraft_managed") == "true" && payloadValue(payload, "minecraft_seed_config") != "true" {
				lines = defaultMinecraftProperty(lines, "server-name", name)
			} else {
				lines = setServerProperty(lines, "server-name", name)
			}
		}
	} else {
		// Java: Vanilla and Paper
		if name, ok := tv["SERVER_NAME"]; ok && name != "" {
			if payloadValue(payload, "minecraft_managed") == "true" && payloadValue(payload, "minecraft_seed_config") != "true" {
				lines = defaultMinecraftProperty(lines, "motd", name)
			} else {
				lines = setServerProperty(lines, "motd", name)
			}
		}
		lines = setServerProperty(lines, "enable-rcon", strconv.FormatBool(tv["PORT_RCON"] != "" && (tv["RCON_PASSWORD"] != "" || minecraftPropertyNonempty(lines, "rcon.password"))))
		if rconPw, ok := tv["RCON_PASSWORD"]; ok {
			if payloadValue(payload, "minecraft_managed") != "true" || !minecraftPropertyNonempty(lines, "rcon.password") {
				lines = setServerProperty(lines, "rcon.password", rconPw)
			}
		}
		if rconPort, ok := tv["PORT_RCON"]; ok && rconPort != "" {
			lines = setServerProperty(lines, "rcon.port", rconPort)
		}
		// Query is UDP and must have its own explicit reservation, even
		// when a pool deliberately assigns the same number as the TCP game port.
		queryPort := tv["PORT_QUERY"]
		if payloadValue(payload, "minecraft_managed") != "true" && queryPort == "" {
			queryPort = tv["PORT_GAME"]
		}
		lines = setServerProperty(lines, "enable-query", strconv.FormatBool(queryPort != ""))
		if queryPort != "" {
			if err := validateMinecraftPort(queryPort); err != nil {
				return err
			}
			lines = setServerProperty(lines, "query.port", queryPort)
		}
		bind := payloadValue(payload, "bind_ip")
		if payloadValue(payload, "minecraft_managed") != "true" && bind == "" {
			bind = payloadValue(payload, "node_ip", "public_ip")
		}
		if bind != "" && net.ParseIP(bind) == nil {
			return fmt.Errorf("invalid Minecraft bind address")
		}
		lines = setServerProperty(lines, "server-ip", bind)

	}

	content := strings.Join(lines, "\n") + "\n"
	return writeMinecraftConfig(instanceDir, propsPath, []byte(content))
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

func validateMinecraftPort(raw string) error {
	port, err := strconv.Atoi(raw)
	if err != nil || port < 1 || port > 65535 {
		return fmt.Errorf("missing or invalid reserved Minecraft port %q", raw)
	}
	return nil
}

func writeMinecraftConfig(dir, path string, content []byte) error {
	relative, err := filepath.Rel(dir, path)
	if err != nil {
		return err
	}
	return publishMinecraftFile(dir, relative, strings.NewReader(string(content)), 0640)
}

func defaultMinecraftProperty(lines []string, key, value string) []string {
	for start := 0; start < len(lines); {
		end, line := minecraftLogicalProperty(lines, start)
		if parsed, ok := minecraftPropertyKey(line); ok && parsed == key {
			return lines
		}
		start = end
	}
	return setServerProperty(lines, key, value)
}

func minecraftLogicalProperty(lines []string, start int) (int, string) {
	logical := strings.TrimLeft(lines[start], " \t\f")
	if strings.HasPrefix(logical, "#") || strings.HasPrefix(logical, "!") {
		return start + 1, logical
	}
	end := start + 1
	for end < len(lines) {
		backslashes := 0
		for i := len(logical) - 1; i >= 0 && logical[i] == '\\'; i-- {
			backslashes++
		}
		if backslashes%2 == 0 {
			break
		}
		logical = logical[:len(logical)-1] + strings.TrimLeft(lines[end], " \t\f")
		end++
	}
	return end, logical
}

func minecraftPropertyKey(line string) (string, bool) {
	line = strings.TrimLeft(line, " \t\f")
	if line == "" || line[0] == '#' || line[0] == '!' {
		return "", false
	}
	var key strings.Builder
	for i := 0; i < len(line); i++ {
		c := line[i]
		if strings.ContainsRune("=: \t\f", rune(c)) {
			break
		}
		if c == '\\' && i+1 < len(line) {
			i++
			c = line[i]
			if c == 'u' && i+4 < len(line) {
				if value, err := strconv.ParseUint(line[i+1:i+5], 16, 16); err == nil {
					key.WriteRune(rune(value))
					i += 4
					continue
				}
			}
			switch c {
			case 't':
				c = '\t'
			case 'n':
				c = '\n'
			case 'r':
				c = '\r'
			case 'f':
				c = '\f'
			}
		}
		key.WriteByte(c)
	}
	return key.String(), true
}

func minecraftPropertyNonempty(lines []string, key string) bool {
	nonempty := false
	for start := 0; start < len(lines); {
		end, line := minecraftLogicalProperty(lines, start)
		start = end
		if parsed, ok := minecraftPropertyKey(line); !ok || parsed != key {
			continue
		}
		escaped := false
		for i, c := range line {
			if escaped {
				escaped = false
				continue
			}
			if c == '\\' {
				escaped = true
				continue
			}
			if strings.ContainsRune("=: \t\f", c) {
				nonempty = strings.TrimLeft(line[i:], "=: \t\f") != ""
				break
			}
		}
	}
	return nonempty
}

func minecraftPropertyValue(lines []string, key string) string {
	value := ""
	for _, line := range lines {
		parts := strings.SplitN(strings.TrimSpace(line), "=", 2)
		if len(parts) == 2 && strings.TrimSpace(parts[0]) == key {
			value = strings.TrimSpace(parts[1])
		}
	}
	return value
}
