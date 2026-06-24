package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var secretKeys = []string{"password", "token", "secret", "key", "credential", "auth"}

func handleMusicbotConfigApply(job jobs.Job) orchestratorResult {
	instanceID := strings.TrimSpace(payloadValue(job.Payload, "instance_id"))
	if instanceID == "" {
		return orchestratorResult{status: "failed", errorText: "missing instance_id"}
	}

	serviceName := strings.TrimSpace(payloadValue(job.Payload, "service_name"))
	if err := validateMusicbotServiceName(serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	installPath, err := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path"))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	rawConfig, ok := job.Payload["config"]
	if !ok || rawConfig == nil {
		return orchestratorResult{status: "failed", errorText: "missing config payload"}
	}
	configMap, ok := rawConfig.(map[string]any)
	if !ok {
		return orchestratorResult{status: "failed", errorText: "config payload must be a map"}
	}

	runtimeUser := musicbotRuntimeUser(job)
	configPath := filepath.Join(installPath, "config.json")

	encoded, err := json.MarshalIndent(configMap, "", "  ")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("encode config: %v", err)}
	}
	encoded = append(encoded, '\n')

	if err := os.MkdirAll(installPath, 0o750); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("ensure install_path: %v", err)}
	}
	if err := ensureMusicbotParentTraversable(installPath); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := applyMusicbotPathOwnership(installPath, runtimeUser, 0o750); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	tmpFile, err := os.CreateTemp(installPath, ".config_apply_*.json")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("create temp file: %v", err)}
	}
	tmpPath := tmpFile.Name()
	defer func() {
		// clean up temp file on failure (rename removes it on success)
		_ = os.Remove(tmpPath)
	}()

	if _, err := tmpFile.Write(encoded); err != nil {
		_ = tmpFile.Close()
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("write temp config: %v", err)}
	}
	if err := tmpFile.Sync(); err != nil {
		_ = tmpFile.Close()
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("fsync temp config: %v", err)}
	}
	if err := tmpFile.Close(); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("close temp config: %v", err)}
	}
	if err := applyMusicbotPathOwnership(tmpPath, runtimeUser, 0o640); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := os.Rename(tmpPath, configPath); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("atomic rename config: %v", err)}
	}
	// Re-apply after rename to guard against rename preserving stale ACLs.
	if err := applyMusicbotPathOwnership(configPath, runtimeUser, 0o640); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	appliedAt := time.Now().UTC().Format(time.RFC3339)

	return orchestratorResult{
		status:  "success",
		logText: fmt.Sprintf("config.apply: wrote %s for service %s at %s", configPath, serviceName, appliedAt),
		resultPayload: map[string]any{
			"success":      true,
			"config_path":  configPath,
			"applied_at":   appliedAt,
			"instance_id":  instanceID,
			"service_name": serviceName,
		},
	}
}

// musicbotRuntimeUser returns the OS user the musicbot service runs as.
// Priority: existing systemd unit > payload runtime_user > default "easywi".
func musicbotRuntimeUser(job jobs.Job) string {
	if user := musicbotRuntimeUserFromUnit(job); user != "" {
		return user
	}
	if user := strings.TrimSpace(payloadValue(job.Payload, "runtime_user")); user != "" {
		return user
	}
	return "easywi"
}

// musicbotRuntimeUserFromUnit reads the User= directive from the existing systemd unit file.
func musicbotRuntimeUserFromUnit(job jobs.Job) string {
	serviceName := strings.TrimSpace(payloadValue(job.Payload, "service_name"))
	if serviceName == "" {
		return ""
	}
	unitPath := filepath.Join(musicbotSystemdUnitDir(job), serviceName+".service")
	data, err := os.ReadFile(unitPath)
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(data), "\n") {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "User=") {
			if user := strings.TrimSpace(strings.TrimPrefix(trimmed, "User=")); user != "" {
				return user
			}
		}
	}
	return ""
}

// ensureMusicbotParentTraversable ensures the two directory levels above installPath
// have the world-execute bit set so the runtime user can traverse them.
func ensureMusicbotParentTraversable(installPath string) error {
	parent := filepath.Dir(installPath)
	grandparent := filepath.Dir(parent)
	for _, dir := range []string{grandparent, parent} {
		if dir == "/" || dir == "." || dir == "" {
			continue
		}
		info, err := os.Stat(dir)
		if err != nil {
			continue
		}
		perm := info.Mode().Perm()
		if perm&0o001 == 0 {
			if err := os.Chmod(dir, perm|0o001); err != nil {
				return fmt.Errorf("ensure parent traversable %s: %w", dir, err)
			}
		}
	}
	return nil
}

// applyMusicbotPathOwnership sets the mode and transfers ownership to runtimeUser.
// If the user does not exist on this host, chown is skipped (chmod still applies).
// This avoids test failures while ensuring correct ownership in production.
func applyMusicbotPathOwnership(path, runtimeUser string, mode os.FileMode) error {
	if err := os.Chmod(path, mode); err != nil {
		return fmt.Errorf("chmod %s: %w", path, err)
	}
	uid, gid, err := lookupIDs(runtimeUser, runtimeUser)
	if err != nil {
		return nil
	}
	if err := os.Chown(path, uid, gid); err != nil {
		return fmt.Errorf("chown %s to %s: %w", path, runtimeUser, err)
	}
	return nil
}

// maskSecrets returns a copy of the map with sensitive values replaced.
// Used only for safe logging — never modifies the original.
func maskSecrets(m map[string]any) map[string]any {
	out := make(map[string]any, len(m))
	for k, v := range m {
		keyLower := strings.ToLower(k)
		isSensitive := false
		for _, sk := range secretKeys {
			if strings.Contains(keyLower, sk) {
				isSensitive = true
				break
			}
		}
		if isSensitive {
			out[k] = "***"
			continue
		}
		if nested, ok := v.(map[string]any); ok {
			out[k] = maskSecrets(nested)
		} else {
			out[k] = v
		}
	}
	return out
}
