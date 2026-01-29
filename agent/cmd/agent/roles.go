package main

import (
	"bufio"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
)

const rolesDir = "/etc/easywi/roles.d"

func collectRoles() []string {
	roles := []string{}
	roles = append(roles, rolesFromEnv()...)
	roles = append(roles, rolesFromDir(rolesDir)...)
	return normalizeRoles(roles)
}

func rolesFromEnv() []string {
	raw := strings.TrimSpace(os.Getenv("EASYWI_ROLES"))
	if raw == "" {
		return nil
	}
	fields := strings.FieldsFunc(raw, func(r rune) bool {
		return r == ',' || r == ';' || r == ' ' || r == '\n' || r == '\t'
	})
	roles := make([]string, 0, len(fields))
	for _, field := range fields {
		field = strings.TrimSpace(field)
		if field != "" {
			roles = append(roles, field)
		}
	}
	return roles
}

func rolesFromDir(path string) []string {
	entries, err := os.ReadDir(path)
	if err != nil {
		return nil
	}

	roles := []string{}
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		role := roleFromFile(filepath.Join(path, entry.Name()))
		if role == "" {
			continue
		}
		roles = append(roles, role)
	}
	return roles
}

func roleFromFile(path string) string {
	file, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		if strings.EqualFold(strings.TrimSpace(key), "role") {
			value = strings.TrimSpace(value)
			if value != "" {
				return value
			}
		}
	}

	base := strings.TrimSuffix(filepath.Base(path), filepath.Ext(path))
	return strings.TrimSpace(base)
}

func normalizeRoles(raw []string) []string {
	seen := map[string]bool{}
	roles := []string{}
	for _, role := range raw {
		role = strings.TrimSpace(role)
		if role == "" {
			continue
		}
		canonical := canonicalRoleName(role)
		key := strings.ToLower(canonical)
		if key == "" || seen[key] {
			continue
		}
		seen[key] = true
		roles = append(roles, canonical)
	}
	return roles
}

func canonicalRoleName(role string) string {
	switch strings.ToLower(strings.TrimSpace(role)) {
	case "web":
		return "Web"
	case "mail":
		return "Mail"
	case "dns":
		return "DNS"
	case "game":
		return "Game"
	case "db":
		return "DB"
	case "core":
		return "Core"
	case "ts3":
		return "TS3"
	case "ts6":
		return "TS6"
	case "sinusbot":
		return "Sinusbot"
	default:
		return strings.TrimSpace(role)
	}
}

func collectMetadata() map[string]any {
	metadata := map[string]any{
		"ts6_supported": detectTS6Support(),
	}
	if phpVersions := detectPhpVersions(); len(phpVersions) > 0 {
		metadata["php_versions"] = phpVersions
	}
	appendFilesvcMetadata(metadata)
	if hostname, err := os.Hostname(); err == nil && hostname != "" {
		metadata["hostname"] = hostname
	}
	if release := readOSRelease(); release != nil {
		metadata["os_release"] = release
	}
	if runtime.GOOS == "windows" {
		metadata["capabilities"] = []string{
			"heartbeat",
			"job_polling",
			"agent.self_update",
			"agent.diagnostics",
		}
	}
	if len(metadata) == 0 {
		return nil
	}
	return metadata
}

func appendFilesvcMetadata(metadata map[string]any) {
	if metadata == nil {
		return
	}
	if value := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_URL")); value != "" {
		metadata["filesvc_url"] = value
		return
	}
	if value := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_HOST")); value != "" {
		metadata["filesvc_host"] = value
	}
	if value := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_PORT")); value != "" {
		if parsed, err := strconv.Atoi(value); err == nil {
			metadata["filesvc_port"] = parsed
		}
	}
	if value := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_SCHEME")); value != "" {
		metadata["filesvc_scheme"] = value
	}
}

func readOSRelease() map[string]string {
	file, err := os.Open("/etc/os-release")
	if err != nil {
		return nil
	}
	defer file.Close()

	data := map[string]string{}
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.Trim(value, `"'`)
		switch key {
		case "ID", "VERSION_ID", "NAME":
			if value != "" {
				data[strings.ToLower(key)] = value
			}
		}
	}

	if len(data) == 0 {
		return nil
	}
	return data
}

func detectTS6Support() bool {
	if envValue := strings.TrimSpace(os.Getenv("EASYWI_TS6_SUPPORTED")); envValue != "" {
		switch strings.ToLower(envValue) {
		case "1", "true", "yes", "on":
			return true
		default:
			return false
		}
	}

	candidates := []string{
		"/usr/local/bin/ts6server",
		"/usr/bin/ts6server",
		"/opt/teamspeak/ts6/tsserver",
	}
	for _, candidate := range candidates {
		if _, err := os.Stat(candidate); err == nil {
			return true
		}
	}
	return false
}
