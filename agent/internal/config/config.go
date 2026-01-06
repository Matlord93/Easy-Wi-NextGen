package config

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// Config represents the agent configuration loaded from disk.
type Config struct {
	AgentID           string
	Secret            string
	APIURL            string
	PollInterval      time.Duration
	HeartbeatInterval time.Duration
	Version           string
	UpdateURL         string
	UpdateSHA256      string
}

// DefaultPath returns the default configuration path for the host OS.
func DefaultPath() (string, error) {
	switch runtime.GOOS {
	case "windows":
		programData := os.Getenv("PROGRAMDATA")
		if programData == "" {
			programData = `C:\\ProgramData`
		}
		return filepath.Join(programData, "easywi", "agent.conf"), nil
	case "linux":
		return "/etc/easywi/agent.conf", nil
	default:
		return "", fmt.Errorf("unsupported OS for default config path: %s", runtime.GOOS)
	}
}

// Load reads the configuration from the provided path, or the default path when empty.
func Load(path string) (Config, error) {
	if path == "" {
		defaultPath, err := DefaultPath()
		if err != nil {
			return Config{}, err
		}
		path = defaultPath
	}

	file, err := os.Open(path)
	if err != nil {
		return Config{}, fmt.Errorf("open config: %w", err)
	}
	defer file.Close()

	cfg := Config{}
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			return Config{}, fmt.Errorf("invalid config line: %q", line)
		}
		key = strings.TrimSpace(key)
		value = strings.TrimSpace(value)
		switch strings.ToLower(key) {
		case "agent_id":
			cfg.AgentID = value
		case "secret":
			cfg.Secret = value
		case "api_url":
			cfg.APIURL = value
		case "poll_interval":
			cfg.PollInterval, err = time.ParseDuration(value)
			if err != nil {
				return Config{}, fmt.Errorf("parse poll_interval: %w", err)
			}
		case "heartbeat_interval":
			cfg.HeartbeatInterval, err = time.ParseDuration(value)
			if err != nil {
				return Config{}, fmt.Errorf("parse heartbeat_interval: %w", err)
			}
		case "version":
			cfg.Version = value
		case "update_url":
			cfg.UpdateURL = value
		case "update_sha256":
			cfg.UpdateSHA256 = value
		}
	}

	if err := scanner.Err(); err != nil {
		return Config{}, fmt.Errorf("scan config: %w", err)
	}

	if err := validate(cfg); err != nil {
		return Config{}, err
	}

	applyDefaults(&cfg)
	return cfg, nil
}

func validate(cfg Config) error {
	var missing []string
	if cfg.AgentID == "" {
		missing = append(missing, "agent_id")
	}
	if cfg.Secret == "" {
		missing = append(missing, "secret")
	}
	if cfg.APIURL == "" {
		missing = append(missing, "api_url")
	}
	if len(missing) > 0 {
		return errors.New("missing config values: " + strings.Join(missing, ", "))
	}
	return nil
}

func applyDefaults(cfg *Config) {
	if cfg.PollInterval == 0 {
		cfg.PollInterval = 30 * time.Second
	}
	if cfg.HeartbeatInterval == 0 {
		cfg.HeartbeatInterval = 60 * time.Second
	}
}
