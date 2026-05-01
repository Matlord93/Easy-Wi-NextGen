package paneladapter

import (
	"fmt"
	"strings"
)

// Config holds the configuration needed to instantiate a panel adapter.
type Config struct {
	// Panel identifies which panel type to use.
	// Accepted values (case-insensitive): plesk, cpanel, ispconfig, fastpanel, aapanel, techpreview
	Panel string

	// Connection parameters — the relevant fields depend on the Panel type.
	BaseURL  string
	Username string
	Password string
	APIKey   string
	Token    string
}

// NewFromConfig returns the appropriate Adapter for the given Config.
// Returns an error if the panel type is unknown or required parameters are missing.
func NewFromConfig(cfg Config) (Adapter, error) {
	switch strings.ToLower(cfg.Panel) {
	case "plesk":
		if cfg.BaseURL == "" || cfg.APIKey == "" {
			return nil, fmt.Errorf("plesk adapter requires BaseURL and APIKey")
		}
		return NewPleskAdapter(cfg.BaseURL, cfg.APIKey), nil

	case "cpanel":
		if cfg.BaseURL == "" || cfg.Username == "" || (cfg.Token == "" && cfg.Password == "") {
			return nil, fmt.Errorf("cpanel adapter requires BaseURL, Username and Token (or Password)")
		}
		token := cfg.Token
		if token == "" {
			token = cfg.Password
		}
		return NewCPanelAdapter(cfg.BaseURL, cfg.Username, token), nil

	case "ispconfig":
		if cfg.BaseURL == "" || cfg.Username == "" || cfg.Password == "" {
			return nil, fmt.Errorf("ispconfig adapter requires BaseURL, Username and Password")
		}
		return NewISPConfigAdapter(cfg.BaseURL, cfg.Username, cfg.Password), nil

	case "fastpanel":
		if cfg.BaseURL == "" || cfg.Username == "" || cfg.Password == "" {
			return nil, fmt.Errorf("fastpanel adapter requires BaseURL, Username and Password")
		}
		return NewFastPanelAdapter(cfg.BaseURL, cfg.Username, cfg.Password), nil

	case "aapanel":
		if cfg.BaseURL == "" || cfg.APIKey == "" {
			return nil, fmt.Errorf("aapanel adapter requires BaseURL and APIKey")
		}
		return NewAAPanelAdapter(cfg.BaseURL, cfg.APIKey), nil

	case "techpreview", "":
		return TechPreviewAdapter{}, nil

	default:
		return nil, fmt.Errorf("unknown panel adapter type: %q (supported: plesk, cpanel, ispconfig, fastpanel, aapanel, techpreview)", cfg.Panel)
	}
}
