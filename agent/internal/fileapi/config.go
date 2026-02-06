package fileapi

import (
	"errors"
	"fmt"
	"path/filepath"
	"strings"
	"time"
)

type Config struct {
	AgentID        string
	Secret         string
	BaseDir        string
	CacheSize      int
	MaxSkew        time.Duration
	ReadTimeout    time.Duration
	WriteTimeout   time.Duration
	IdleTimeout    time.Duration
	MaxUploadBytes int64
	Version        string
}

func (c Config) Validate() error {
	var missing []string
	if strings.TrimSpace(c.AgentID) == "" {
		missing = append(missing, "agent_id")
	}
	if strings.TrimSpace(c.Secret) == "" {
		missing = append(missing, "secret")
	}
	if strings.TrimSpace(c.BaseDir) == "" {
		missing = append(missing, "base_dir")
	}
	if len(missing) > 0 {
		return fmt.Errorf("missing config values: %s", strings.Join(missing, ", "))
	}
	if !filepath.IsAbs(c.BaseDir) {
		return errors.New("base_dir must be absolute")
	}
	return nil
}
