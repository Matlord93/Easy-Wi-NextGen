package main

import (
	"os"
	"path/filepath"
	"strings"
)

// isMinecraftJavaTemplate returns true for Minecraft Java Edition servers (Vanilla, Paper).
// Returns false for Bedrock and all non-Minecraft servers.
func isMinecraftJavaTemplate(payload map[string]any) bool {
	for _, key := range []string{"game_type", "template_slug", "template_key", "game_key", "template_name", "game_name"} {
		candidate := strings.ToLower(strings.TrimSpace(payloadValue(payload, key)))
		if candidate == "" {
			continue
		}
		if strings.Contains(candidate, "bedrock") {
			return false
		}
		if strings.Contains(candidate, "minecraft") {
			return true
		}
	}
	return false
}

// ensureMinecraftEula writes or updates eula.txt in instanceDir so eula=true is set.
// Only applies to Minecraft Java Edition servers (Vanilla, Paper). Bedrock is skipped.
// Existing comments and unrelated lines in eula.txt are preserved.
func ensureMinecraftEula(instanceDir string, payload map[string]any) error {
	if !isMinecraftJavaTemplate(payload) {
		return nil
	}
	eulaPath := filepath.Join(instanceDir, "eula.txt")
	existing, err := os.ReadFile(eulaPath)
	if err != nil {
		if os.IsNotExist(err) {
			return os.WriteFile(eulaPath, []byte("eula=true\n"), instanceFileMode)
		}
		return err
	}

	lines := strings.Split(string(existing), "\n")
	found := false
	for i, line := range lines {
		if strings.HasPrefix(strings.ToLower(strings.TrimSpace(line)), "eula=") {
			lines[i] = "eula=true"
			found = true
		}
	}
	if !found {
		lines = append(lines, "eula=true")
	}
	return os.WriteFile(eulaPath, []byte(strings.Join(lines, "\n")), instanceFileMode)
}
