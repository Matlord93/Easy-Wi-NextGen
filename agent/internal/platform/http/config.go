package http

import (
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
)

type Config struct {
	Listen string `json:"listen"`
}

func LoadConfig(path string) (Config, error) {
	cfg := Config{Listen: ":8081"}
	if strings.TrimSpace(path) != "" {
		data, err := os.ReadFile(path)
		if err != nil {
			return Config{}, fmt.Errorf("read config file: %w", err)
		}
		if err := json.Unmarshal(data, &cfg); err != nil {
			return Config{}, fmt.Errorf("parse config file: %w", err)
		}
	}
	if v := strings.TrimSpace(os.Getenv("EASYWI_MAIL_AGENT_LISTEN")); v != "" {
		cfg.Listen = v
	}
	if p := strings.TrimSpace(os.Getenv("EASYWI_MAIL_AGENT_PORT")); p != "" {
		if _, err := strconv.Atoi(p); err == nil {
			cfg.Listen = ":" + p
		}
	}
	return cfg, nil
}
