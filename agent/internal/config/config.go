package config

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"runtime/debug"
	"strconv"
	"strings"
	"time"
)

// Config represents the agent configuration loaded from disk.
type Config struct {
	AgentID           string
	Secret            string
	APIURL            string
	BootstrapToken    string
	PollInterval      time.Duration
	HeartbeatInterval time.Duration
	MaxConcurrency    int
	Version           string
	UpdateURL         string
	UpdateSHA256      string
	ServiceListen     string
	HealthListen      string
	FileBaseDir       string
	FileCacheSize     int
	FileMaxSkew       time.Duration
	FileReadTimeout   time.Duration
	FileWriteTimeout  time.Duration
	FileIdleTimeout   time.Duration
	FileMaxUploadMB   int64
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
func Load(path string) (cfg Config, err error) {
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
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close config: %w", closeErr)
		}
	}()

	cfg = Config{}
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
		case "bootstrap_token":
			cfg.BootstrapToken = value
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
		case "max_concurrency":
			parsed, parseErr := strconv.Atoi(value)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse max_concurrency: %w", parseErr)
			}
			cfg.MaxConcurrency = parsed
		case "version":
			cfg.Version = value
		case "update_url":
			cfg.UpdateURL = value
		case "update_sha256":
			cfg.UpdateSHA256 = value
		case "health_listen":
			cfg.HealthListen = value
		case "service_listen":
			cfg.ServiceListen = value
		case "file_base_dir":
			cfg.FileBaseDir = value
		case "file_cache_size":
			parsed, parseErr := strconv.Atoi(value)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse file_cache_size: %w", parseErr)
			}
			cfg.FileCacheSize = parsed
		case "file_max_skew_seconds":
			parsed, parseErr := strconv.Atoi(value)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse file_max_skew_seconds: %w", parseErr)
			}
			cfg.FileMaxSkew = time.Duration(parsed) * time.Second
		case "file_read_timeout_seconds":
			parsed, parseErr := strconv.Atoi(value)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse file_read_timeout_seconds: %w", parseErr)
			}
			cfg.FileReadTimeout = time.Duration(parsed) * time.Second
		case "file_write_timeout_seconds":
			parsed, parseErr := strconv.Atoi(value)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse file_write_timeout_seconds: %w", parseErr)
			}
			cfg.FileWriteTimeout = time.Duration(parsed) * time.Second
		case "file_idle_timeout_seconds":
			parsed, parseErr := strconv.Atoi(value)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse file_idle_timeout_seconds: %w", parseErr)
			}
			cfg.FileIdleTimeout = time.Duration(parsed) * time.Second
		case "file_max_upload_mb":
			parsed, parseErr := strconv.ParseInt(value, 10, 64)
			if parseErr != nil {
				return Config{}, fmt.Errorf("parse file_max_upload_mb: %w", parseErr)
			}
			cfg.FileMaxUploadMB = parsed
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
	if cfg.PollInterval < 0 {
		return errors.New("poll_interval must be positive")
	}
	if cfg.HeartbeatInterval < 0 {
		return errors.New("heartbeat_interval must be positive")
	}
	if cfg.MaxConcurrency < 0 {
		return errors.New("max_concurrency must be positive")
	}
	if cfg.FileBaseDir != "" && !filepath.IsAbs(cfg.FileBaseDir) {
		return errors.New("file_base_dir must be absolute")
	}
	if cfg.FileCacheSize < 0 {
		return errors.New("file_cache_size must be positive")
	}
	if cfg.FileMaxUploadMB < 0 {
		return errors.New("file_max_upload_mb must be positive")
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
	if cfg.MaxConcurrency == 0 {
		cfg.MaxConcurrency = max(2, runtime.NumCPU())
	}
	if cfg.Version == "" {
		cfg.Version = defaultVersion()
	}
	if cfg.HealthListen == "" {
		cfg.HealthListen = ""
	}
	if cfg.ServiceListen == "" {
		if cfg.HealthListen != "" {
			cfg.ServiceListen = cfg.HealthListen
		} else {
			cfg.ServiceListen = "0.0.0.0:7456"
		}
	}
	if cfg.FileBaseDir == "" {
		cfg.FileBaseDir = "/home"
	}
	if cfg.FileCacheSize == 0 {
		cfg.FileCacheSize = 256
	}
	if cfg.FileMaxSkew == 0 {
		cfg.FileMaxSkew = 45 * time.Second
	}
	if cfg.FileReadTimeout == 0 {
		cfg.FileReadTimeout = 15 * time.Second
	}
	if cfg.FileWriteTimeout == 0 {
		cfg.FileWriteTimeout = 30 * time.Second
	}
	if cfg.FileIdleTimeout == 0 {
		cfg.FileIdleTimeout = 60 * time.Second
	}
}

func max(a int, b int) int {
	if a > b {
		return a
	}
	return b
}

func UpdateSecret(path string, secret string) error {
	if strings.TrimSpace(path) == "" {
		defaultPath, err := DefaultPath()
		if err != nil {
			return err
		}
		path = defaultPath
	}

	content, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("read config: %w", err)
	}

	lines := strings.Split(string(content), "\n")
	replaced := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") || strings.HasPrefix(trimmed, ";") {
			continue
		}
		key, _, ok := strings.Cut(trimmed, "=")
		if !ok {
			continue
		}
		if strings.EqualFold(strings.TrimSpace(key), "secret") {
			lines[i] = "secret=" + secret
			replaced = true
			break
		}
	}
	if !replaced {
		lines = append(lines, "secret="+secret)
	}

	tmpPath := path + ".tmp"
	updated := strings.Join(lines, "\n")
	if err := os.WriteFile(tmpPath, []byte(updated), 0o600); err != nil {
		return fmt.Errorf("write temp config: %w", err)
	}
	if err := os.Rename(tmpPath, path); err != nil {
		_ = os.Remove(tmpPath)
		return fmt.Errorf("replace config: %w", err)
	}

	return nil
}

func defaultVersion() string {
	info, ok := debug.ReadBuildInfo()
	if !ok {
		return ""
	}
	if info.Main.Version != "" && info.Main.Version != "(devel)" {
		return info.Main.Version
	}
	for _, setting := range info.Settings {
		if setting.Key == "vcs.revision" && setting.Value != "" {
			if len(setting.Value) > 12 {
				return setting.Value[:12]
			}
			return setting.Value
		}
	}
	if info.Main.Version != "" {
		return info.Main.Version
	}
	return ""
}
