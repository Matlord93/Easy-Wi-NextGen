package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const coreSshPolicyConfigPath = "/etc/ssh/sshd_config.d/70-easywi-core.conf"

func handleCoreSshPolicyApply(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "ssh policy is not supported on windows"},
			Completed: time.Now().UTC(),
		}, nil
	}
	if runtime.GOOS != "linux" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "ssh policy is only supported on linux agents"},
			Completed: time.Now().UTC(),
		}, nil
	}

	accessMode := strings.TrimSpace(payloadValue(job.Payload, "access_mode", "ssh_access_mode"))
	authorizedKeysPath := strings.TrimSpace(payloadValue(job.Payload, "authorized_keys_path"))
	sftpGroup := strings.TrimSpace(payloadValue(job.Payload, "sftp_group"))
	if sftpGroup == "" {
		sftpGroup = defaultSftpGroup
	}

	if accessMode != "ssh_key_only" && accessMode != "ssh_key_password" {
		return failureResult(job.ID, fmt.Errorf("invalid access_mode"))
	}

	if accessMode == "ssh_key_only" && !hasAnyAuthorizedKeys(authorizedKeysPath) && !hasAnyAuthorizedKeys("/root/.ssh/authorized_keys") {
		return failureResult(job.ID, fmt.Errorf("no authorized ssh keys found; refusing to enable key-only access"))
	}

	content := buildCoreSshPolicy(accessMode, sftpGroup)
	if err := writeSshdPolicyConfig(content); err != nil {
		return failureResult(job.ID, err)
	}

	if err := validateSshdConfig(); err != nil {
		_ = os.Remove(coreSshPolicyConfigPath)
		return failureResult(job.ID, err)
	}

	if err := reloadSshd(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"access_mode": accessMode,
			"config_path": coreSshPolicyConfigPath,
			"sftp_group":  sftpGroup,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func buildCoreSshPolicy(accessMode, sftpGroup string) string {
	passwordValue := "yes"
	if accessMode == "ssh_key_only" {
		passwordValue = "no"
	}

	var builder strings.Builder
	_, _ = fmt.Fprintf(&builder, "PasswordAuthentication %s\n", passwordValue)
	builder.WriteString("KbdInteractiveAuthentication no\n")
	builder.WriteString("PubkeyAuthentication yes\n")
	builder.WriteString("Subsystem sftp internal-sftp\n")

	if sftpGroup != "" {
		_, _ = fmt.Fprintf(&builder, "\nMatch Group %s\n", sftpGroup)
		builder.WriteString("  ForceCommand internal-sftp\n")
		builder.WriteString("  AllowTcpForwarding no\n")
		builder.WriteString("  X11Forwarding no\n")
		builder.WriteString("  PasswordAuthentication yes\n")
		builder.WriteString("  PubkeyAuthentication yes\n")
	}

	return builder.String()
}

func writeSshdPolicyConfig(content string) error {
	if err := os.MkdirAll(filepath.Dir(coreSshPolicyConfigPath), 0o755); err != nil {
		return fmt.Errorf("create sshd config dir: %w", err)
	}
	tmpPath := coreSshPolicyConfigPath + ".tmp"
	if err := os.WriteFile(tmpPath, []byte(content), 0o640); err != nil {
		return fmt.Errorf("write sshd config: %w", err)
	}
	if err := os.Rename(tmpPath, coreSshPolicyConfigPath); err != nil {
		return fmt.Errorf("activate sshd config: %w", err)
	}
	return nil
}

func validateSshdConfig() error {
	if err := runCommand("sshd", "-t"); err != nil {
		return fmt.Errorf("sshd config test failed: %w", err)
	}
	return nil
}

func hasAnyAuthorizedKeys(path string) bool {
	if strings.TrimSpace(path) == "" {
		return false
	}
	content, err := os.ReadFile(path)
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(content), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		return true
	}
	return false
}
