package sinusbotsvcembed

import "time"

type Config struct {
	InstallDir   string
	InstanceRoot string
	WebBindIP    string
	WebPortBase  int
	ServiceUser  string
	AgentID      string
	Secret       string
	MaxSkew      time.Duration
}

func (c Config) withDefaults() Config {
	if c.InstallDir == "" {
		c.InstallDir = "/opt/sinusbot"
	}
	if c.InstanceRoot == "" {
		c.InstanceRoot = "/opt/sinusbot/instances"
	}
	if c.WebBindIP == "" {
		c.WebBindIP = "0.0.0.0"
	}
	if c.WebPortBase == 0 {
		c.WebPortBase = 8087
	}
	if c.ServiceUser == "" {
		c.ServiceUser = "sinusbot"
	}
	if c.MaxSkew == 0 {
		c.MaxSkew = 45 * time.Second
	}
	return c
}

func NewServer(cfg Config) *Server {
	resolved := cfg.withDefaults()
	return &Server{cfg: resolved}
}
