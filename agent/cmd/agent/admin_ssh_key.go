package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleAdminSshKeyStore(job jobs.Job) orchestratorResult {
	authorizedKeysPath := payloadValue(job.Payload, "authorized_keys_path")
	publicKey := strings.TrimSpace(payloadValue(job.Payload, "public_key"))
	adminEmail := payloadValue(job.Payload, "admin_email")
	userID := payloadValue(job.Payload, "user_id")

	if authorizedKeysPath == "" || publicKey == "" {
		return orchestratorResult{
			status:    "failed",
			errorText: "missing authorized_keys_path or public_key",
		}
	}

	entry := withAdminComment(publicKey, userID, adminEmail)
	normalizedKey := normalizeAuthorizedKey(publicKey)

	dir := filepath.Dir(authorizedKeysPath)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	existing, err := os.ReadFile(authorizedKeysPath)
	if err != nil && !os.IsNotExist(err) {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if keyAlreadyPresent(string(existing), normalizedKey) {
		return orchestratorResult{
			status: "success",
			resultPayload: map[string]any{
				"stored": false,
			},
		}
	}

	prefix := ""
	if len(existing) > 0 && !strings.HasSuffix(string(existing), "\n") {
		prefix = "\n"
	}

	if err := os.WriteFile(authorizedKeysPath, append(existing, []byte(prefix+entry+"\n")...), 0o600); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if err := os.Chmod(authorizedKeysPath, 0o600); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"stored":      true,
			"updated_at":  time.Now().UTC().Format(time.RFC3339),
			"target_path": authorizedKeysPath,
		},
	}
}

func normalizeAuthorizedKey(publicKey string) string {
	fields := strings.Fields(publicKey)
	if len(fields) < 2 {
		return strings.TrimSpace(publicKey)
	}
	return fmt.Sprintf("%s %s", fields[0], fields[1])
}

func withAdminComment(publicKey, userID, adminEmail string) string {
	fields := strings.Fields(publicKey)
	if len(fields) >= 3 {
		return publicKey
	}

	commentParts := []string{"easywi-admin"}
	if strings.TrimSpace(userID) != "" {
		commentParts = append(commentParts, strings.TrimSpace(userID))
	}
	if strings.TrimSpace(adminEmail) != "" {
		commentParts = append(commentParts, strings.TrimSpace(adminEmail))
	}

	comment := strings.Join(commentParts, "-")
	return fmt.Sprintf("%s %s %s", fields[0], fields[1], comment)
}

func keyAlreadyPresent(existing, normalizedKey string) bool {
	if normalizedKey == "" {
		return false
	}

	for _, line := range strings.Split(existing, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if strings.HasPrefix(line, normalizedKey+" ") || line == normalizedKey {
			return true
		}
	}

	return false
}
