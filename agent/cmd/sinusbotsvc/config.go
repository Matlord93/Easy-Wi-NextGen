package main

import (
	"encoding/json"
	"fmt"
	"os"
	"time"
)

type sinusbotsvcConfig struct {
	ListenAddr   string        `json:"listen_addr"`
	InstallDir   string        `json:"install_dir"`
	InstanceRoot string        `json:"instance_root"`
	WebBindIP    string        `json:"web_bind_ip"`
	WebPortBase  int           `json:"web_port_base"`
	ServiceUser  string        `json:"service_user"`
	AgentID      string        `json:"agent_id"`
	Secret       string        `json:"secret"`
	MaxSkew      time.Duration `json:"max_skew"`
	AgentConfig  string        `json:"agent_config"`
}

func loadSinusbotConfig(path string) (sinusbotsvcConfig, error) {
	cfg := sinusbotsvcConfig{
		ListenAddr:   ":8091",
		InstallDir:   "/opt/sinusbot",
		InstanceRoot: "/opt/sinusbot/instances",
		WebBindIP:    "0.0.0.0",
		WebPortBase:  8087,
		ServiceUser:  "sinusbot",
		MaxSkew:      45 * time.Second,
		AgentConfig:  "/etc/easywi/agent.conf",
	}
	if path == "" {
		return cfg, nil
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return cfg, err
	}
	if err := json.Unmarshal(data, &cfg); err != nil {
		return cfg, err
	}

	if cfg.ListenAddr == "" {
		cfg.ListenAddr = ":8091"
	}
	if cfg.InstallDir == "" {
		cfg.InstallDir = "/opt/sinusbot"
	}
	if cfg.InstanceRoot == "" {
		cfg.InstanceRoot = "/opt/sinusbot/instances"
	}
	if cfg.WebBindIP == "" {
		cfg.WebBindIP = "0.0.0.0"
	}
	if cfg.WebPortBase == 0 {
		cfg.WebPortBase = 8087
	}
	if cfg.ServiceUser == "" {
		cfg.ServiceUser = "sinusbot"
	}
	if cfg.MaxSkew == 0 {
		cfg.MaxSkew = 45 * time.Second
	}
	if cfg.AgentConfig == "" {
		cfg.AgentConfig = "/etc/easywi/agent.conf"
	}

	if cfg.AgentID == "" || cfg.Secret == "" {
		identity, err := loadAgentIdentity(cfg.AgentConfig)
		if err != nil {
			return cfg, fmt.Errorf("load agent identity: %w", err)
		}
		if cfg.AgentID == "" {
			cfg.AgentID = identity.AgentID
		}
		if cfg.Secret == "" {
			cfg.Secret = identity.Secret
		}
	}

	return cfg, nil
}

type agentIdentity struct {
	AgentID string
	Secret  string
}

func loadAgentIdentity(path string) (agentIdentity, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return agentIdentity{}, err
	}
	lines := bytesLines(string(data))
	id := ""
	secret := ""
	for _, line := range lines {
		if line == "" || line[0] == '#' || line[0] == ';' {
			continue
		}
		key, value, ok := cutKeyValue(line)
		if !ok {
			continue
		}
		switch key {
		case "agent_id":
			id = value
		case "secret":
			secret = value
		}
	}
	if id == "" || secret == "" {
		return agentIdentity{}, fmt.Errorf("missing agent_id or secret")
	}
	return agentIdentity{AgentID: id, Secret: secret}, nil
}

func cutKeyValue(line string) (string, string, bool) {
	for i := 0; i < len(line); i++ {
		if line[i] == '=' {
			key := trimSpace(line[:i])
			value := trimSpace(line[i+1:])
			return key, value, key != ""
		}
	}
	return "", "", false
}

func bytesLines(input string) []string {
	lines := []string{}
	start := 0
	for i := 0; i < len(input); i++ {
		if input[i] == '\n' {
			lines = append(lines, trimSpace(input[start:i]))
			start = i + 1
		}
	}
	if start <= len(input) {
		lines = append(lines, trimSpace(input[start:]))
	}
	return lines
}

func trimSpace(input string) string {
	start := 0
	end := len(input)
	for start < end && (input[start] == ' ' || input[start] == '\t' || input[start] == '\r') {
		start++
	}
	for end > start && (input[end-1] == ' ' || input[end-1] == '\t' || input[end-1] == '\r') {
		end--
	}
	return input[start:end]
}
