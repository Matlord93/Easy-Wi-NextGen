package main

import (
	"encoding/json"
	"os"
	"time"
)

type gamesvcConfig struct {
	ListenAddr   string        `json:"listen_addr"`
	TemplateDir  string        `json:"template_dir"`
	BaseDir      string        `json:"base_dir"`
	ReadTimeout  time.Duration `json:"read_timeout"`
	WriteTimeout time.Duration `json:"write_timeout"`
	IdleTimeout  time.Duration `json:"idle_timeout"`
}

func loadGamesvcConfig(path string) (gamesvcConfig, error) {
	cfg := gamesvcConfig{
		ListenAddr:   ":8088",
		TemplateDir:  "/opt/easywi/templates",
		BaseDir:      "/opt/easywi/instances",
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 30 * time.Second,
		IdleTimeout:  30 * time.Second,
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
		cfg.ListenAddr = ":8088"
	}
	if cfg.TemplateDir == "" {
		cfg.TemplateDir = "/opt/easywi/templates"
	}
	if cfg.BaseDir == "" {
		cfg.BaseDir = "/opt/easywi/instances"
	}
	if cfg.ReadTimeout == 0 {
		cfg.ReadTimeout = 10 * time.Second
	}
	if cfg.WriteTimeout == 0 {
		cfg.WriteTimeout = 30 * time.Second
	}
	if cfg.IdleTimeout == 0 {
		cfg.IdleTimeout = 30 * time.Second
	}

	return cfg, nil
}
