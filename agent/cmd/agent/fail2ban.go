package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

type fail2banPolicy struct {
	Enabled        bool
	BanTime        string
	FindTime       string
	MaxRetry       int
	IgnoreIPs      []string
	Jails          []string
	AdvancedConfig string
	DryRun         bool
}

func handleFail2banPolicyApply(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS != "linux" {
		return failureResult(job.ID, fmt.Errorf("fail2ban policy apply is only supported on linux agents"))
	}

	if !commandExists("fail2ban-client") {
		return failureResult(job.ID, fmt.Errorf("fail2ban-client is required to apply fail2ban policy"))
	}

	policy := fail2banPolicyFromPayload(job.Payload)
	config := buildFail2banConfig(policy)

	output := map[string]string{
		"enabled":    strconv.FormatBool(policy.Enabled),
		"bantime":    policy.BanTime,
		"findtime":   policy.FindTime,
		"maxretry":   strconv.Itoa(policy.MaxRetry),
		"ignore_ips": strings.Join(policy.IgnoreIPs, ","),
		"jails":      strings.Join(policy.Jails, ","),
		"dry_run":    strconv.FormatBool(policy.DryRun),
		"config":     config,
	}

	if policy.DryRun {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    output,
			Completed: time.Now().UTC(),
		}, nil
	}

	configPath := "/etc/fail2ban/jail.d/easywi.conf"
	previousConfig, previousExists := readFail2banConfig(configPath)

	if err := os.MkdirAll(filepath.Dir(configPath), 0o755); err != nil {
		return failureResult(job.ID, fmt.Errorf("create fail2ban config dir: %w", err))
	}

	if err := os.WriteFile(configPath, []byte(config), 0o640); err != nil {
		return failureResult(job.ID, fmt.Errorf("write fail2ban config: %w", err))
	}

	if err := reloadFail2ban(); err != nil {
		_ = rollbackFail2banConfig(configPath, previousConfig, previousExists)
		return failureResult(job.ID, fmt.Errorf("reload fail2ban: %w", err))
	}

	output["applied_at"] = time.Now().UTC().Format(time.RFC3339)
	output["message"] = "fail2ban policy applied"

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleFail2banStatusCheck(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS != "linux" {
		return failureResult(job.ID, fmt.Errorf("fail2ban status check is only supported on linux agents"))
	}

	if !commandExists("fail2ban-client") {
		return failureResult(job.ID, fmt.Errorf("fail2ban-client is required to check fail2ban status"))
	}

	jails, err := listFail2banJails()
	if err != nil {
		return failureResult(job.ID, err)
	}

	type jailStatus struct {
		Name      string   `json:"name"`
		Banned    int      `json:"banned"`
		BannedIPs []string `json:"banned_ips"`
	}

	var statuses []jailStatus
	for _, jail := range jails {
		status, err := fetchFail2banJailStatus(jail)
		if err != nil {
			continue
		}
		statuses = append(statuses, status)
	}

	payload, _ := json.Marshal(statuses)

	output := map[string]string{
		"jails":       string(payload),
		"reported_at": time.Now().UTC().Format(time.RFC3339),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func fail2banPolicyFromPayload(payload map[string]any) fail2banPolicy {
	raw := payload
	if nested, ok := payload["policy"].(map[string]any); ok {
		raw = nested
	}

	policy := fail2banPolicy{
		Enabled:        parseBool(raw["enabled"], true),
		BanTime:        parseString(raw["bantime"], "10m"),
		FindTime:       parseString(raw["findtime"], "10m"),
		MaxRetry:       parseInt(raw["maxretry"], 5),
		IgnoreIPs:      parseStringList(raw["ignore_ips"], "127.0.0.1/8"),
		Jails:          parseStringList(raw["jails"], "sshd"),
		AdvancedConfig: parseString(raw["advanced_config"], ""),
		DryRun:         parseBool(raw["dry_run"], false),
	}

	if len(policy.Jails) == 0 {
		policy.Jails = []string{"sshd"}
	}

	return policy
}

func buildFail2banConfig(policy fail2banPolicy) string {
	var builder strings.Builder
	builder.WriteString("# Managed by Easy-Wi\n")
	builder.WriteString("[DEFAULT]\n")
	builder.WriteString(fmt.Sprintf("bantime = %s\n", policy.BanTime))
	builder.WriteString(fmt.Sprintf("findtime = %s\n", policy.FindTime))
	builder.WriteString(fmt.Sprintf("maxretry = %d\n", policy.MaxRetry))
	if len(policy.IgnoreIPs) > 0 {
		builder.WriteString(fmt.Sprintf("ignoreip = %s\n", strings.Join(policy.IgnoreIPs, " ")))
	}

	for _, jail := range policy.Jails {
		jail = strings.TrimSpace(jail)
		if jail == "" {
			continue
		}
		builder.WriteString(fmt.Sprintf("\n[%s]\n", jail))
		builder.WriteString(fmt.Sprintf("enabled = %v\n", policy.Enabled))
	}

	if policy.AdvancedConfig != "" {
		builder.WriteString("\n# Advanced overrides\n")
		builder.WriteString(policy.AdvancedConfig)
		builder.WriteString("\n")
	}

	return builder.String()
}

func readFail2banConfig(path string) (string, bool) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", false
	}

	return string(data), true
}

func rollbackFail2banConfig(path string, previousConfig string, previousExists bool) error {
	if !previousExists {
		return os.Remove(path)
	}

	return os.WriteFile(path, []byte(previousConfig), 0o640)
}

func reloadFail2ban() error {
	if err := runCommandWithOutput("fail2ban-client", []string{"reload"}, &strings.Builder{}); err == nil {
		return nil
	}

	if commandExists("systemctl") {
		return runCommandWithOutput("systemctl", []string{"restart", "fail2ban"}, &strings.Builder{})
	}

	return fmt.Errorf("fail2ban reload failed")
}

func listFail2banJails() ([]string, error) {
	output, err := runCommandOutput("fail2ban-client", "status")
	if err != nil {
		return nil, err
	}

	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "Jail list:") {
			continue
		}
		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}
		jailsRaw := strings.Fields(strings.ReplaceAll(parts[1], ",", " "))
		return jailsRaw, nil
	}

	return []string{}, nil
}

func fetchFail2banJailStatus(jail string) (struct {
	Name      string   `json:"name"`
	Banned    int      `json:"banned"`
	BannedIPs []string `json:"banned_ips"`
}, error) {
	output, err := runCommandOutput("fail2ban-client", "status", jail)
	if err != nil {
		return struct {
			Name      string   `json:"name"`
			Banned    int      `json:"banned"`
			BannedIPs []string `json:"banned_ips"`
		}{}, err
	}

	status := struct {
		Name      string   `json:"name"`
		Banned    int      `json:"banned"`
		BannedIPs []string `json:"banned_ips"`
	}{
		Name:      jail,
		Banned:    0,
		BannedIPs: []string{},
	}

	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "Currently banned:") {
			parts := strings.SplitN(line, ":", 2)
			if len(parts) == 2 {
				count := strings.TrimSpace(parts[1])
				if value, err := strconv.Atoi(count); err == nil {
					status.Banned = value
				}
			}
			continue
		}

		if strings.HasPrefix(line, "Banned IP list:") {
			parts := strings.SplitN(line, ":", 2)
			if len(parts) == 2 {
				ips := strings.Fields(strings.ReplaceAll(parts[1], ",", " "))
				status.BannedIPs = append(status.BannedIPs, ips...)
			}
		}
	}

	return status, nil
}

func parseBool(value any, fallback bool) bool {
	switch typed := value.(type) {
	case bool:
		return typed
	case string:
		normalized := strings.ToLower(strings.TrimSpace(typed))
		if normalized == "true" || normalized == "1" || normalized == "yes" {
			return true
		}
		if normalized == "false" || normalized == "0" || normalized == "no" {
			return false
		}
	case int:
		return typed == 1
	case float64:
		return typed == 1
	}

	return fallback
}

func parseString(value any, fallback string) string {
	if str, ok := value.(string); ok {
		str = strings.TrimSpace(str)
		if str != "" {
			return str
		}
	}

	return fallback
}

func parseInt(value any, fallback int) int {
	switch typed := value.(type) {
	case int:
		return typed
	case float64:
		return int(typed)
	case string:
		if parsed, err := strconv.Atoi(strings.TrimSpace(typed)); err == nil {
			return parsed
		}
	}

	return fallback
}

func parseStringList(value any, fallback string) []string {
	var raw string
	switch typed := value.(type) {
	case string:
		raw = typed
	case []string:
		return typed
	case []any:
		entries := make([]string, 0, len(typed))
		for _, entry := range typed {
			if item, ok := entry.(string); ok && strings.TrimSpace(item) != "" {
				entries = append(entries, strings.TrimSpace(item))
			}
		}
		if len(entries) > 0 {
			return entries
		}
	}

	if raw == "" {
		raw = fallback
	}

	entries := []string{}
	for _, part := range strings.Split(raw, ",") {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		entries = append(entries, part)
	}

	return entries
}
