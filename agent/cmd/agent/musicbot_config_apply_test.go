package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleMusicbotConfigApplySuccess(t *testing.T) {
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "test-instance")
	if err := os.MkdirAll(installPath, 0o750); err != nil {
		t.Fatal(err)
	}

	result := handleMusicbotConfigApply(jobs.Job{
		ID:   "job-1",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"instance_id":  "42",
			"service_name": "musicbot-demo",
			"install_path": installPath,
			"config": map[string]any{
				"teamspeak_server": "ts.example.com",
				"teamspeak_port":   "9987",
			},
		},
	})

	if result.status != "success" {
		t.Fatalf("expected success, got %q: %s", result.status, result.errorText)
	}
	configPath, _ := result.resultPayload["config_path"].(string)
	if configPath == "" {
		t.Fatal("missing config_path in result")
	}
	if _, ok := result.resultPayload["applied_at"]; !ok {
		t.Error("missing applied_at in result")
	}

	data, err := os.ReadFile(configPath)
	if err != nil {
		t.Fatalf("config file not written: %v", err)
	}
	var parsed map[string]any
	if err := json.Unmarshal(data, &parsed); err != nil {
		t.Fatalf("config file is not valid JSON: %v", err)
	}
	if parsed["teamspeak_server"] != "ts.example.com" {
		t.Errorf("unexpected config content: %v", parsed)
	}
}

func TestHandleMusicbotConfigApplyCreatesDirectory(t *testing.T) {
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "new-instance")
	// directory does not exist yet

	result := handleMusicbotConfigApply(jobs.Job{
		ID:   "job-2",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"instance_id":  "10",
			"service_name": "musicbot-new",
			"install_path": installPath,
			"config":       map[string]any{"mode": "test"},
		},
	})

	if result.status != "success" {
		t.Fatalf("expected success, got %q: %s", result.status, result.errorText)
	}
	if _, err := os.Stat(filepath.Join(installPath, "config.json")); err != nil {
		t.Fatalf("config.json not created: %v", err)
	}
}

func TestHandleMusicbotConfigApplySecurePermissions(t *testing.T) {
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "perm-test")
	if err := os.MkdirAll(installPath, 0o750); err != nil {
		t.Fatal(err)
	}

	result := handleMusicbotConfigApply(jobs.Job{
		ID:   "job-3",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"instance_id":  "5",
			"service_name": "musicbot-perm",
			"install_path": installPath,
			"config":       map[string]any{"x": "y"},
		},
	})

	if result.status != "success" {
		t.Fatalf("expected success: %s", result.errorText)
	}
	configPath := filepath.Join(installPath, "config.json")
	info, err := os.Stat(configPath)
	if err != nil {
		t.Fatalf("stat config.json: %v", err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("expected 0600, got %04o", perm)
	}
}

func TestHandleMusicbotConfigApplyMissingInstanceID(t *testing.T) {
	result := handleMusicbotConfigApply(jobs.Job{
		ID:   "job-4",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"service_name": "musicbot-demo",
			"install_path": "/opt/musicbot/demo",
			"config":       map[string]any{},
		},
	})
	if result.status != "failed" {
		t.Fatalf("expected failed, got %q", result.status)
	}
	if !strings.Contains(result.errorText, "instance_id") {
		t.Errorf("unexpected error: %s", result.errorText)
	}
}

func TestHandleMusicbotConfigApplyUnsafePath(t *testing.T) {
	tests := []struct {
		name string
		path string
	}{
		{"root path", "/"},
		{"etc path", "/etc"},
		{"no musicbot in path", "/opt/other/service"},
		{"relative path", "opt/musicbot/demo"},
		{"null byte", "/opt/musicbot/demo\x00"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			result := handleMusicbotConfigApply(jobs.Job{
				ID:   "job-unsafe",
				Type: "musicbot.config.apply",
				Payload: map[string]any{
					"instance_id":  "1",
					"service_name": "musicbot-demo",
					"install_path": tc.path,
					"config":       map[string]any{},
				},
			})
			if result.status != "failed" {
				t.Errorf("path %q: expected failed, got %q", tc.path, result.status)
			}
		})
	}
}

func TestHandleMusicbotConfigApplyMissingConfig(t *testing.T) {
	result := handleMusicbotConfigApply(jobs.Job{
		ID:   "job-5",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"instance_id":  "1",
			"service_name": "musicbot-demo",
			"install_path": "/opt/easywi/musicbot/demo",
		},
	})
	if result.status != "failed" {
		t.Fatalf("expected failed, got %q", result.status)
	}
	if !strings.Contains(result.errorText, "config") {
		t.Errorf("unexpected error: %s", result.errorText)
	}
}

func TestHandleMusicbotConfigApplyAtomicWrite(t *testing.T) {
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "atomic-test")
	if err := os.MkdirAll(installPath, 0o750); err != nil {
		t.Fatal(err)
	}
	configPath := filepath.Join(installPath, "config.json")
	// write an initial config so we can verify it gets atomically replaced
	if err := os.WriteFile(configPath, []byte(`{"old":"data"}`), 0o600); err != nil {
		t.Fatal(err)
	}

	result := handleMusicbotConfigApply(jobs.Job{
		ID:   "job-6",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"instance_id":  "7",
			"service_name": "musicbot-atomic",
			"install_path": installPath,
			"config":       map[string]any{"new": "data"},
		},
	})

	if result.status != "success" {
		t.Fatalf("expected success: %s", result.errorText)
	}
	data, _ := os.ReadFile(configPath)
	var parsed map[string]any
	_ = json.Unmarshal(data, &parsed)
	if parsed["new"] != "data" || parsed["old"] != nil {
		t.Errorf("atomic replace failed: %v", parsed)
	}
	// no leftover temp files
	entries, _ := os.ReadDir(installPath)
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), ".config_apply_") {
			t.Errorf("temp file not cleaned up: %s", e.Name())
		}
	}
}

func TestMaskSecrets(t *testing.T) {
	input := map[string]any{
		"teamspeak_server":   "ts.example.com",
		"teamspeak_password": "s3cr3t",
		"api_token":          "tok_abc123",
		"nested": map[string]any{
			"db_secret": "hunter2",
			"db_host":   "localhost",
			"auth_key":  "mykey",
		},
		"public_value": "visible",
	}
	masked := maskSecrets(input)

	if masked["teamspeak_password"] != "***" {
		t.Errorf("teamspeak_password not masked: %v", masked["teamspeak_password"])
	}
	if masked["api_token"] != "***" {
		t.Errorf("api_token not masked: %v", masked["api_token"])
	}
	if masked["teamspeak_server"] != "ts.example.com" {
		t.Errorf("non-secret should not be masked: %v", masked["teamspeak_server"])
	}
	if masked["public_value"] != "visible" {
		t.Errorf("public_value should not be masked: %v", masked["public_value"])
	}
	nested, _ := masked["nested"].(map[string]any)
	if nested == nil {
		t.Fatal("nested map missing")
	}
	if nested["db_secret"] != "***" {
		t.Errorf("nested db_secret not masked: %v", nested["db_secret"])
	}
	if nested["auth_key"] != "***" {
		t.Errorf("nested auth_key not masked: %v", nested["auth_key"])
	}
	if nested["db_host"] != "localhost" {
		t.Errorf("nested db_host should not be masked: %v", nested["db_host"])
	}
	// original must be unchanged
	if input["teamspeak_password"] != "s3cr3t" {
		t.Error("maskSecrets must not modify the original map")
	}
}

func TestHandleMusicbotConfigApplyJobDispatch(t *testing.T) {
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "dispatch-test")

	result := handleOrchestratorJob(jobs.Job{
		ID:   "job-dispatch",
		Type: "musicbot.config.apply",
		Payload: map[string]any{
			"instance_id":  "99",
			"service_name": "musicbot-dispatch",
			"install_path": installPath,
			"config":       map[string]any{"dispatched": true},
		},
	})

	if result.status != "success" {
		t.Fatalf("expected success via handleOrchestratorJob, got %q: %s", result.status, result.errorText)
	}
}
