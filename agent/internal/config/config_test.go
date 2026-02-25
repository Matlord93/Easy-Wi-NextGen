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
		"max_concurrency=5",
		"bind_ip_addresses=10.0.0.10,192.168.1.10",
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
	if cfg.MaxConcurrency != 5 {
		t.Fatalf("MaxConcurrency = %v, want %v", cfg.MaxConcurrency, 5)
	}
	if len(cfg.BindIPAddresses) != 2 || cfg.BindIPAddresses[0] != "10.0.0.10" || cfg.BindIPAddresses[1] != "192.168.1.10" {
		t.Fatalf("BindIPAddresses = %#v", cfg.BindIPAddresses)
	}
}



func TestLoadAcceptsUtf8BomOnFirstKey(t *testing.T) {
	tmpDir := t.TempDir()
	configPath := filepath.Join(tmpDir, "agent.conf")
	if err := os.WriteFile(configPath, []byte(strings.Join([]string{
		"\ufeffagent_id=agent-123",
		"secret=super-secret",
		"api_url=https://api.example.test",
		"poll_interval=30s",
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

func TestLoadRejectsNegativeValues(t *testing.T) {
	tmpDir := t.TempDir()
	configPath := filepath.Join(tmpDir, "agent.conf")
	if err := os.WriteFile(configPath, []byte(strings.Join([]string{
		"agent_id=agent-123",
		"secret=super-secret",
		"api_url=https://api.example.test",
		"poll_interval=-5s",
		"heartbeat_interval=60s",
		"max_concurrency=2",
	}, "\n")), 0o600); err != nil {
		t.Fatalf("write config: %v", err)
	}

	_, err := Load(configPath)
	if err == nil {
		t.Fatal("Load() error = nil, want error")
	}
	if !strings.Contains(err.Error(), "poll_interval") {
		t.Fatalf("Load() error = %q, want poll_interval validation", err)
	}
}

func TestLoadRejectsNegativeConcurrency(t *testing.T) {
	tmpDir := t.TempDir()
	configPath := filepath.Join(tmpDir, "agent.conf")
	if err := os.WriteFile(configPath, []byte(strings.Join([]string{
		"agent_id=agent-123",
		"secret=super-secret",
		"api_url=https://api.example.test",
		"poll_interval=30s",
		"heartbeat_interval=60s",
		"max_concurrency=-1",
	}, "\n")), 0o600); err != nil {
		t.Fatalf("write config: %v", err)
	}

	_, err := Load(configPath)
	if err == nil {
		t.Fatal("Load() error = nil, want error")
	}
	if !strings.Contains(err.Error(), "max_concurrency") {
		t.Fatalf("Load() error = %q, want max_concurrency validation", err)
	}
}
