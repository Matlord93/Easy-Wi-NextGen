package gamesvcembed

import "os/exec"

type Config struct {
	TemplateDir string
	BaseDir     string
}

func (c Config) withDefaults() Config {
	if c.TemplateDir == "" {
		c.TemplateDir = "/opt/easywi/templates"
	}
	if c.BaseDir == "" {
		c.BaseDir = "/opt/easywi/instances"
	}
	return c
}

func NewServer(cfg Config) *Server {
	resolved := cfg.withDefaults()
	return &Server{config: resolved, processes: map[string]*exec.Cmd{}}
}
