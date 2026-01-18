package main

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

type filesvcConfig struct {
	AgentID         string
	Secret          string
	ListenAddr      string
	BaseDir         string
	TLSCertPath     string
	TLSKeyPath      string
	TLSCAPath       string
	CacheSize       int
	MaxSkew         time.Duration
	ReadTimeout     time.Duration
	WriteTimeout    time.Duration
	IdleTimeout     time.Duration
	AgentConfigPath string
}

const (
	defaultFilesvcConfigPath = "/etc/easywi/filesvc.conf"
	defaultAgentConfigPath   = "/etc/easywi/agent.conf"
)

func loadFilesvcConfig(path string) (filesvcConfig, error) {
	if path == "" {
		path = defaultFilesvcConfigPath
	}

	cfg := filesvcConfig{
		ListenAddr:      ":8444",
		BaseDir:         "/home",
		CacheSize:       256,
		MaxSkew:         45 * time.Second,
		ReadTimeout:     15 * time.Second,
		WriteTimeout:    30 * time.Second,
		IdleTimeout:     60 * time.Second,
		AgentConfigPath: defaultAgentConfigPath,
	}

	file, err := os.Open(path)
	if err != nil {
		return cfg, fmt.Errorf("open config: %w", err)
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
			return cfg, fmt.Errorf("invalid config line: %q", line)
		}
		key = strings.ToLower(strings.TrimSpace(key))
		value = strings.TrimSpace(value)
		switch key {
		case "agent_id":
			cfg.AgentID = value
		case "secret":
			cfg.Secret = value
		case "listen_addr":
			cfg.ListenAddr = value
		case "base_dir":
			cfg.BaseDir = value
		case "tls_cert":
			cfg.TLSCertPath = value
		case "tls_key":
			cfg.TLSKeyPath = value
		case "tls_ca":
			cfg.TLSCAPath = value
		case "cache_size":
			if parsed, err := strconv.Atoi(value); err == nil {
				cfg.CacheSize = parsed
			}
		case "max_skew_seconds":
			if parsed, err := strconv.Atoi(value); err == nil {
				cfg.MaxSkew = time.Duration(parsed) * time.Second
			}
		case "read_timeout_seconds":
			if parsed, err := strconv.Atoi(value); err == nil {
				cfg.ReadTimeout = time.Duration(parsed) * time.Second
			}
		case "write_timeout_seconds":
			if parsed, err := strconv.Atoi(value); err == nil {
				cfg.WriteTimeout = time.Duration(parsed) * time.Second
			}
		case "idle_timeout_seconds":
			if parsed, err := strconv.Atoi(value); err == nil {
				cfg.IdleTimeout = time.Duration(parsed) * time.Second
			}
		case "agent_config":
			cfg.AgentConfigPath = value
		}
	}

	if err := scanner.Err(); err != nil {
		return cfg, fmt.Errorf("scan config: %w", err)
	}

	if cfg.AgentID == "" || cfg.Secret == "" {
		agentCfg, err := loadAgentIdentity(cfg.AgentConfigPath)
		if err != nil {
			return cfg, fmt.Errorf("load agent identity from %s: %w", cfg.AgentConfigPath, err)
		}
		if cfg.AgentID == "" {
			cfg.AgentID = agentCfg.AgentID
		}
		if cfg.Secret == "" {
			cfg.Secret = agentCfg.Secret
		}
	}

	if err := validateFilesvcConfig(cfg, path); err != nil {
		return cfg, err
	}

	cfg.ListenAddr = strings.TrimSpace(cfg.ListenAddr)
	cfg.BaseDir = strings.TrimSpace(cfg.BaseDir)
	cfg.TLSCertPath = strings.TrimSpace(cfg.TLSCertPath)
	cfg.TLSKeyPath = strings.TrimSpace(cfg.TLSKeyPath)
	cfg.TLSCAPath = strings.TrimSpace(cfg.TLSCAPath)

	if cfg.ListenAddr == "" {
		cfg.ListenAddr = ":8444"
	}
	if cfg.BaseDir == "" {
		cfg.BaseDir = "/home"
	}
	if cfg.CacheSize <= 0 {
		cfg.CacheSize = 256
	}

	return cfg, nil
}

type agentIdentity struct {
	AgentID string
	Secret  string
}

func loadAgentIdentity(path string) (agentIdentity, error) {
	if path == "" {
		path = defaultAgentConfigPath
	}
	file, err := os.Open(path)
	if err != nil {
		return agentIdentity{}, fmt.Errorf("open agent config: %w", err)
	}
	defer file.Close()

	identity := agentIdentity{}
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			return identity, fmt.Errorf("invalid agent config line: %q", line)
		}
		key = strings.ToLower(strings.TrimSpace(key))
		value = strings.TrimSpace(value)
		switch key {
		case "agent_id":
			identity.AgentID = value
		case "secret":
			identity.Secret = value
		}
	}
	if err := scanner.Err(); err != nil {
		return identity, fmt.Errorf("scan agent config: %w", err)
	}

	if identity.AgentID == "" || identity.Secret == "" {
		return identity, errors.New("agent config missing agent_id or secret")
	}
	return identity, nil
}

func validateFilesvcConfig(cfg filesvcConfig, path string) error {
	var missing []string
	if cfg.AgentID == "" {
		missing = append(missing, "agent_id")
	}
	if cfg.Secret == "" {
		missing = append(missing, "secret")
	}
	if cfg.TLSCertPath == "" {
		missing = append(missing, "tls_cert")
	}
	if cfg.TLSKeyPath == "" {
		missing = append(missing, "tls_key")
	}
	if cfg.TLSCAPath == "" {
		missing = append(missing, "tls_ca")
	}
	if len(missing) > 0 {
		return fmt.Errorf("missing config values in %s: %s", path, strings.Join(missing, ", "))
	}
	if !filepath.IsAbs(cfg.BaseDir) {
		return errors.New("base_dir must be absolute")
	}
	if !filepath.IsAbs(cfg.TLSCertPath) || !filepath.IsAbs(cfg.TLSKeyPath) || !filepath.IsAbs(cfg.TLSCAPath) {
		return errors.New("tls paths must be absolute")
	}
	return nil
}
