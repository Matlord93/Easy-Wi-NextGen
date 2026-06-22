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

	configPath := filepath.Join(installPath, "config.json")

	encoded, err := json.MarshalIndent(configMap, "", "  ")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("encode config: %v", err)}
	}
	encoded = append(encoded, '\n')

	if err := os.MkdirAll(installPath, 0o750); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("ensure install_path: %v", err)}
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
	if err := tmpFile.Close(); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("close temp config: %v", err)}
	}
	if err := os.Chmod(tmpPath, 0o600); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("chmod temp config: %v", err)}
	}
	if err := os.Rename(tmpPath, configPath); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("atomic rename config: %v", err)}
	}

	appliedAt := time.Now().UTC().Format(time.RFC3339)

	return orchestratorResult{
		status:  "success",
		logText: fmt.Sprintf("config.apply: wrote %s for service %s at %s", configPath, serviceName, appliedAt),
		resultPayload: map[string]any{
			"success":     true,
			"config_path": configPath,
			"applied_at":  appliedAt,
			"instance_id": instanceID,
			"service_name": serviceName,
		},
	}
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
