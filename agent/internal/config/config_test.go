package config

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

func TestDefaultPathLinux(t *testing.T) {
	if runtime.GOOS != "linux" {
		t.Skip("linux-specific default path")
	}

	path, err := DefaultPath()
	if err != nil {
		t.Fatalf("DefaultPath() error = %v", err)
	}
	if path != "/etc/easywi/agent.conf" {
		t.Fatalf("DefaultPath() = %q, want %q", path, "/etc/easywi/agent.conf")
	}
}

func TestLoadParsesConfigAndDefaults(t *testing.T) {
	tmpDir := t.TempDir()
	configPath := filepath.Join(tmpDir, "agent.conf")
	if err := os.WriteFile(configPath, []byte(strings.Join([]string{
		"agent_id=agent-123",
		"secret=super-secret",
		"api_url=https://api.example.test",
		"poll_interval=45s",
		"heartbeat_interval=90s",
	}, "\n")), 0o600); err != nil {
		t.Fatalf("write config: %v", err)
	}

	cfg, err := Load(configPath)
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if cfg.AgentID != "agent-123" {
		t.Fatalf("AgentID = %q, want %q", cfg.AgentID, "agent-123")
	}
	if cfg.Secret != "super-secret" {
		t.Fatalf("Secret = %q, want %q", cfg.Secret, "super-secret")
	}
	if cfg.APIURL != "https://api.example.test" {
		t.Fatalf("APIURL = %q, want %q", cfg.APIURL, "https://api.example.test")
	}
	if cfg.PollInterval != 45*time.Second {
		t.Fatalf("PollInterval = %v, want %v", cfg.PollInterval, 45*time.Second)
	}
	if cfg.HeartbeatInterval != 90*time.Second {
		t.Fatalf("HeartbeatInterval = %v, want %v", cfg.HeartbeatInterval, 90*time.Second)
	}
}

func TestLoadMissingRequiredFields(t *testing.T) {
	tmpDir := t.TempDir()
	configPath := filepath.Join(tmpDir, "agent.conf")
	if err := os.WriteFile(configPath, []byte(strings.Join([]string{
		"agent_id=agent-123",
		"secret=super-secret",
	}, "\n")), 0o600); err != nil {
		t.Fatalf("write config: %v", err)
	}

	_, err := Load(configPath)
	if err == nil {
		t.Fatal("Load() error = nil, want error")
	}
	if !strings.Contains(err.Error(), "api_url") {
		t.Fatalf("Load() error = %q, want missing api_url", err)
	}
}
