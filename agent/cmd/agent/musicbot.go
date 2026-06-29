package main

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const musicbotRuntimeVersion = "easywi-musicbot-preview"

func handleMusicbotInstall(job jobs.Job) orchestratorResult {
	layout, err := resolveMusicbotLayout(job)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := validateMusicbotServiceName(layout.serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	runtimeSource, err := resolveMusicbotRuntimeBinary(job)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureMusicbotDirectories(layout); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	installedBinary, err := installMusicbotRuntimeBinary(runtimeSource, layout.binaryPath)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := writeMusicbotConfig(job, layout); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if runtime.GOOS != "windows" {
		unitContent := musicbotSystemdUnit(layout.serviceName, layout.installPath, installedBinary, layout.configPath)
		if err := os.WriteFile(layout.unitPath, []byte(unitContent), 0o644); err != nil {
			return orchestratorResult{status: "failed", errorText: fmt.Sprintf("write systemd unit: %v", err)}
		}
		if layout.useSystemctl {
			if err := runCommand("systemctl", "daemon-reload"); err != nil {
				return orchestratorResult{status: "failed", errorText: fmt.Sprintf("systemd daemon-reload: %v", err)}
			}
			if payloadBool(job.Payload, "enable") {
				if err := runCommand("systemctl", "enable", layout.serviceName); err != nil {
					return orchestratorResult{status: "failed", errorText: fmt.Sprintf("enable service: %v", err)}
				}
			}
		}
	}
	return orchestratorResult{status: "success", resultPayload: musicbotInstallPayload(layout, installedBinary)}
}

func handleMusicbotUpdate(job jobs.Job) orchestratorResult {
	layout, err := resolveMusicbotLayout(job)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	runtimeSource, err := resolveMusicbotRuntimeBinary(job)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if _, err := os.Stat(layout.configPath); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("preserve config.json: %v", err)}
	}
	if err := ensureMusicbotDirectories(layout); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	backupPath := ""
	if _, err := os.Stat(layout.binaryPath); err == nil {
		backupPath = fmt.Sprintf("%s.bak-%s", layout.binaryPath, time.Now().UTC().Format("20060102150405"))
		if err := copyMusicbotFile(layout.binaryPath, backupPath, 0o755); err != nil {
			return orchestratorResult{status: "failed", errorText: fmt.Sprintf("backup runtime binary: %v", err)}
		}
	}
	installedBinary, err := installMusicbotRuntimeBinary(runtimeSource, layout.binaryPath)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if payloadBool(job.Payload, "restart") && layout.useSystemctl {
		if err := runCommand("systemctl", "restart", layout.serviceName); err != nil {
			return orchestratorResult{status: "failed", errorText: fmt.Sprintf("restart service: %v", err)}
		}
	}
	return orchestratorResult{status: "success", resultPayload: map[string]any{"updated": true, "runtime_binary": installedBinary, "backup_binary": backupPath, "config_preserved": true}}
}

func handleMusicbotRepair(job jobs.Job) orchestratorResult {
	layout, err := resolveMusicbotLayout(job)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureMusicbotDirectories(layout); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	binaryRepaired := false
	if stat, err := os.Stat(layout.binaryPath); err != nil || stat.IsDir() {
		runtimeSource, sourceErr := resolveMusicbotRuntimeBinary(job)
		if sourceErr != nil {
			return orchestratorResult{status: "failed", errorText: sourceErr.Error()}
		}
		if _, installErr := installMusicbotRuntimeBinary(runtimeSource, layout.binaryPath); installErr != nil {
			return orchestratorResult{status: "failed", errorText: installErr.Error()}
		}
		binaryRepaired = true
	} else if err := os.Chmod(layout.binaryPath, 0o755); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("chmod runtime binary: %v", err)}
	}
	configValid := false
	if data, err := os.ReadFile(layout.configPath); err == nil {
		var decoded map[string]any
		configValid = json.Unmarshal(data, &decoded) == nil && strings.TrimSpace(fmt.Sprint(decoded["service_name"])) != ""
	}
	if !configValid {
		if err := writeMusicbotConfig(job, layout); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		configValid = true
	} else {
		_ = os.Chmod(layout.configPath, 0o600)
	}
	if runtime.GOOS != "windows" {
		unitContent := musicbotSystemdUnit(layout.serviceName, layout.installPath, layout.binaryPath, layout.configPath)
		if err := os.WriteFile(layout.unitPath, []byte(unitContent), 0o644); err != nil {
			return orchestratorResult{status: "failed", errorText: fmt.Sprintf("write systemd unit: %v", err)}
		}
		if layout.useSystemctl {
			if err := runCommand("systemctl", "daemon-reload"); err != nil {
				return orchestratorResult{status: "failed", errorText: fmt.Sprintf("systemd daemon-reload: %v", err)}
			}
		}
	}
	return orchestratorResult{status: "success", resultPayload: map[string]any{"repaired": true, "binary_repaired": binaryRepaired, "runtime_binary": layout.binaryPath, "config_valid": configValid, "config_permissions": "0600", "systemd_unit": layout.unitPath, "install_path": layout.installPath}}
}

func handleMusicbotUninstall(job jobs.Job) orchestratorResult {
	layout, err := resolveMusicbotLayout(job)
	if err != nil {
		// uninstall can still remove the unit with just a valid service name
		serviceName := strings.TrimSpace(payloadValue(job.Payload, "service_name"))
		if err := validateMusicbotServiceName(serviceName); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		layout = musicbotLayout{serviceName: serviceName, unitPath: filepath.Join(musicbotSystemdUnitDir(job), fmt.Sprintf("%s.service", serviceName)), useSystemctl: musicbotUseSystemctl(job)}
	}
	if runtime.GOOS == "windows" {
		return orchestratorResult{status: "success", resultPayload: map[string]any{"windows_service": "TODO: remove native Easy-Wi Musicbot Windows service wrapper"}}
	}
	if layout.useSystemctl {
		_ = runCommand("systemctl", "stop", layout.serviceName)
		_ = runCommand("systemctl", "disable", layout.serviceName)
	}
	if err := os.Remove(layout.unitPath); err != nil && !os.IsNotExist(err) {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("remove systemd unit: %v", err)}
	}
	if layout.useSystemctl {
		_ = runCommand("systemctl", "daemon-reload")
	}
	keepData := payloadBool(job.Payload, "keep_data") || (!payloadBool(job.Payload, "delete_data") && payloadValue(job.Payload, "delete_data") != "1")
	if !keepData && layout.installPath != "" {
		_ = os.RemoveAll(layout.installPath)
	}
	return orchestratorResult{status: "success", resultPayload: map[string]any{"uninstalled": true, "running": false, "data_kept": keepData, "install_path": layout.installPath}}
}

func handleMusicbotStatus(job jobs.Job) orchestratorResult {
	serviceName := strings.TrimSpace(payloadValue(job.Payload, "service_name"))
	if err := validateMusicbotServiceName(serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	installPath, pathErr := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path", "install_dir"))
	lastError := ""
	if pathErr == nil {
		if data, err := os.ReadFile(filepath.Join(installPath, "last_error.txt")); err == nil {
			lastError = strings.TrimSpace(string(data))
		}
	}

	if pathErr == nil {
		if response, err := NewRuntimeControlClient(installPath).Command("status", nil); err == nil {
			return orchestratorResult{status: "success", resultPayload: response.Payload}
		} else {
			lastError = err.Error()
		}
	}

	if runtime.GOOS == "windows" {
		output, err := runCommandOutput("sc", "query", serviceName)
		exists := err == nil
		return orchestratorResult{status: "success", logText: trimOutput(output, 4000), resultPayload: map[string]any{
			"service_exists": exists,
			"running":        exists && strings.Contains(strings.ToUpper(output), "RUNNING"),
			"status":         mapMusicbotRunningStatus(exists && strings.Contains(strings.ToUpper(output), "RUNNING")),
			"runtime":        map[string]any{"version": musicbotRuntimeVersion, "native": true},
			"last_error":     lastError,
		}}
	}

	exists := runCommand("systemctl", "status", serviceName, "--no-pager") == nil
	running := runCommand("systemctl", "is-active", "--quiet", serviceName) == nil
	return orchestratorResult{status: "success", resultPayload: map[string]any{
		"service_exists": exists,
		"running":        running,
		"status":         mapMusicbotRunningStatus(running),
		"runtime":        map[string]any{"version": musicbotRuntimeVersion, "native": true},
		"last_error":     lastError,
	}}
}

func handleMusicbotPlaybackAction(job jobs.Job) orchestratorResult {
	installPath, err := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path", "install_dir"))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	if !allowedMusicbotPlaybackActions[action] {
		return orchestratorResult{status: "failed", errorText: "invalid playback action"}
	}
	args := map[string]any{}
	for key, value := range job.Payload {
		if key != "install_path" && key != "install_dir" && key != "service_name" {
			args[key] = value
		}
	}
	response, err := NewRuntimeControlClient(installPath).Command(action, args)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: map[string]any{"last_error": err.Error()}}
	}
	if !response.OK {
		lastError := response.Error
		if lastError == "" {
			lastError = "runtime rejected playback command"
		}
		return orchestratorResult{status: "failed", errorText: lastError, resultPayload: map[string]any{"accepted": false, "action": action, "runtime": response.Payload, "last_error": lastError}}
	}
	return orchestratorResult{status: "success", resultPayload: map[string]any{"accepted": true, "action": action, "runtime": response.Payload}}
}

func handleMusicbotConnectionTest(job jobs.Job) orchestratorResult {
	platform := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "platform")))
	if platform != "teamspeak" && platform != "discord" {
		return orchestratorResult{status: "failed", errorText: "platform must be teamspeak or discord"}
	}
	installPath, pathErr := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path", "install_dir"))
	if pathErr == nil {
		response, err := NewRuntimeControlClient(installPath).Command("connection_status", map[string]any{"platform": platform})
		if err == nil {
			return orchestratorResult{status: "success", resultPayload: response.Payload}
		}
	}
	return orchestratorResult{status: "success", resultPayload: map[string]any{
		"platform":   platform,
		"status":     "placeholder",
		"runtime":    map[string]any{"version": musicbotRuntimeVersion, "native": true},
		"last_error": "runtime control unavailable; returned placeholder connector status",
		"message":    "Connection adapter placeholder prepared; no external Musicbot backend was used.",
	}}
}

func handleMusicbotQueueSync(job jobs.Job) orchestratorResult {
	installPath, err := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path", "install_dir"))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	queue, ok := job.Payload["queue"]
	if !ok {
		return orchestratorResult{status: "failed", errorText: "missing queue payload"}
	}
	response, err := NewRuntimeControlClient(installPath).Command("queue.sync", map[string]any{"queue": queue})
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: map[string]any{"last_error": err.Error()}}
	}
	items := 0
	if p := response.Payload; p != nil {
		if n, ok := p["items"].(float64); ok {
			items = int(n)
		}
	}
	return orchestratorResult{status: "success", resultPayload: map[string]any{"synced": true, "items": items, "runtime": response.Payload}}
}

var allowedMusicbotPlaybackActions = map[string]bool{
	"play": true, "pause": true, "resume": true, "stop": true,
	"skip": true, "volume": true, "shuffle": true, "repeat": true, "seek": true,
	"reconnect": true, "reload_config": true,
}

func validateMusicbotServiceName(serviceName string) error {
	if serviceName == "" {
		return fmt.Errorf("missing service_name")
	}
	if !serviceNameRegex.MatchString(serviceName) {
		return fmt.Errorf("invalid service_name: contains disallowed characters")
	}
	return nil
}

func validateMusicbotInstallPath(rawPath string) (string, error) {
	installPath := strings.TrimSpace(rawPath)
	if installPath == "" {
		return "", fmt.Errorf("missing install_path")
	}
	if strings.ContainsAny(installPath, "\x00\n\r") {
		return "", fmt.Errorf("install_path contains disallowed characters")
	}
	if !filepath.IsAbs(installPath) {
		return "", fmt.Errorf("install_path must be absolute")
	}
	cleaned := filepath.Clean(installPath)
	if cleaned == string(filepath.Separator) || cleaned == "." {
		return "", fmt.Errorf("install_path is not allowed")
	}
	parts := strings.Split(filepath.ToSlash(cleaned), "/")
	for _, part := range parts {
		if part == ".." {
			return "", fmt.Errorf("install_path must not contain parent traversal")
		}
	}
	if !strings.Contains(strings.ToLower(filepath.ToSlash(cleaned)), "/musicbot") {
		return "", fmt.Errorf("install_path must target a musicbot directory")
	}
	return cleaned, nil
}

func payloadBool(payload map[string]any, key string) bool {
	value := strings.ToLower(strings.TrimSpace(payloadValue(payload, key)))
	return value == "1" || value == "true" || value == "yes" || value == "on"
}

func mapMusicbotRunningStatus(running bool) string {
	if running {
		return "running"
	}
	return "stopped"
}

type musicbotLayout struct {
	instanceID   string
	customerID   string
	nodeID       string
	installPath  string
	dataDir      string
	tracksDir    string
	queueDir     string
	playlistsDir string
	logDir       string
	pluginDir    string
	runtimeDir   string
	tmpDir       string
	controlSock  string
	pulseSock    string
	tsHome       string
	tsProfile    string
	pulseSink    string
	pulseSource  string
	xvfbDisplay  string
	binDir       string
	binaryPath   string
	configPath   string
	serviceName  string
	unitPath     string
	useSystemctl bool
}

func resolveMusicbotLayout(job jobs.Job) (musicbotLayout, error) {
	installPath, err := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path", "install_dir"))
	if err != nil {
		return musicbotLayout{}, err
	}
	serviceName := strings.TrimSpace(payloadValue(job.Payload, "service_name"))
	if err := validateMusicbotServiceName(serviceName); err != nil {
		return musicbotLayout{}, err
	}
	instanceID := strings.TrimSpace(payloadValue(job.Payload, "instance_id"))
	customerID := strings.TrimSpace(payloadValue(job.Payload, "customer_id"))
	nodeID := strings.TrimSpace(payloadValue(job.Payload, "node_id"))
	if instanceID == "" || customerID == "" || nodeID == "" {
		return musicbotLayout{}, fmt.Errorf("musicbot job must include instance_id, customer_id, node_id, service_name and install_path")
	}
	slug := safeMusicbotSlug(fmt.Sprintf("customer_%s_instance_%s_%s", customerID, instanceID, serviceName))
	binDir := filepath.Join(installPath, "bin")
	runtimeDir := filepath.Join(installPath, "runtime")
	dataDir := filepath.Join(installPath, "data")
	return musicbotLayout{
		instanceID:   instanceID,
		customerID:   customerID,
		nodeID:       nodeID,
		installPath:  installPath,
		dataDir:      dataDir,
		tracksDir:    filepath.Join(dataDir, "tracks"),
		queueDir:     filepath.Join(dataDir, "queue"),
		playlistsDir: filepath.Join(dataDir, "playlists"),
		logDir:       filepath.Join(installPath, "logs"),
		pluginDir:    filepath.Join(installPath, "plugins"),
		runtimeDir:   runtimeDir,
		tmpDir:       filepath.Join(runtimeDir, "tmp"),
		controlSock:  filepath.Join(installPath, "control.sock"),
		pulseSock:    filepath.Join(runtimeDir, "pulse", "pulse.sock"),
		tsHome:       filepath.Join(dataDir, "teamspeak-client", "ts3home"),
		tsProfile:    filepath.Join(dataDir, "teamspeak-client", "profile"),
		pulseSink:    "easywi_sink_" + slug,
		pulseSource:  "easywi_source_" + slug,
		xvfbDisplay:  musicbotDisplayForSlug(slug),
		binDir:       binDir,
		binaryPath:   filepath.Join(binDir, "easywi-musicbot"),
		configPath:   filepath.Join(installPath, "config.json"),
		serviceName:  serviceName,
		unitPath:     filepath.Join(musicbotSystemdUnitDir(job), fmt.Sprintf("%s.service", serviceName)),
		useSystemctl: musicbotUseSystemctl(job),
	}, nil
}

func safeMusicbotSlug(value string) string {
	replacer := strings.NewReplacer(".", "_", "-", "_", "@", "_")
	return strings.Trim(replacer.Replace(value), "_")
}

func musicbotDisplayForSlug(slug string) string {
	sum := 0
	for _, ch := range slug {
		sum += int(ch)
	}
	return fmt.Sprintf(":%d", 200+(sum%10000))
}

func musicbotSystemdUnitDir(job jobs.Job) string {
	if dir := strings.TrimSpace(payloadValue(job.Payload, "systemd_unit_dir")); dir != "" {
		return filepath.Clean(dir)
	}
	return "/etc/systemd/system"
}

func musicbotUseSystemctl(job jobs.Job) bool {
	if payloadBool(job.Payload, "skip_systemd") {
		return false
	}
	return musicbotSystemdUnitDir(job) == "/etc/systemd/system"
}

func resolveMusicbotRuntimeBinary(job jobs.Job) (string, error) {
	binary := strings.TrimSpace(payloadValue(job.Payload, "runtime_binary", "runtime_binary_path"))
	if binary == "" {
		binary = "/usr/local/bin/easywi-musicbot"
	}
	if strings.ContainsAny(binary, "\x00\n\r") {
		return "", fmt.Errorf("runtime_binary contains disallowed characters")
	}
	if !filepath.IsAbs(binary) {
		return "", fmt.Errorf("runtime_binary must be absolute")
	}
	stat, err := os.Stat(binary)
	if err != nil {
		return "", fmt.Errorf("runtime binary not available at %s: install easywi-musicbot to /usr/local/bin/easywi-musicbot or pass an admin-configured absolute runtime_binary path; %v", binary, err)
	}
	if stat.IsDir() {
		return "", fmt.Errorf("runtime binary path is a directory")
	}
	return filepath.Clean(binary), nil
}

func ensureMusicbotDirectories(layout musicbotLayout) error {
	for _, stale := range []string{layout.controlSock, layout.pulseSock} {
		if stale != "" {
			_ = os.Remove(stale)
		}
	}
	dirs := []string{layout.installPath, layout.dataDir, layout.tracksDir, layout.queueDir, layout.playlistsDir, layout.logDir, layout.pluginDir, layout.runtimeDir, layout.tmpDir, filepath.Dir(layout.pulseSock), layout.tsHome, layout.tsProfile, layout.binDir, filepath.Dir(layout.unitPath)}
	for _, dir := range dirs {
		if dir == "" || dir == "." {
			continue
		}
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return fmt.Errorf("create runtime directory %s: %v", dir, err)
		}
	}
	return nil
}

func installMusicbotRuntimeBinary(source string, destination string) (string, error) {
	if err := copyMusicbotFile(source, destination, 0o755); err != nil {
		return "", fmt.Errorf("install runtime binary: %v", err)
	}
	return destination, nil
}

func writeMusicbotConfig(job jobs.Job, layout musicbotLayout) error {
	config := map[string]any{
		"instance_id":  payloadValue(job.Payload, "instance_id"),
		"customer_id":  payloadValue(job.Payload, "customer_id"),
		"node_id":      payloadValue(job.Payload, "node_id"),
		"service_name": layout.serviceName,
		"install_path": layout.installPath,
		"data_dir":     layout.dataDir,
		"tracks_dir":   layout.tracksDir,
		"queue_dir":    layout.queueDir,
		"log_dir":      layout.logDir,
		"plugin_dir":   layout.pluginDir,
		"runtime_dir":  layout.runtimeDir,
		"tmp_dir":      layout.tmpDir,
		"control": map[string]any{
			"unix_socket": layout.controlSock,
		},
		"pulse": map[string]any{
			"socket": layout.pulseSock, "sink": layout.pulseSink, "source": layout.pulseSource, "blackhole_sink": "easywi_ts3_playback_blackhole_" + safeMusicbotSlug(fmt.Sprintf("customer_%s_instance_%s_%s", layout.customerID, layout.instanceID, layout.serviceName)),
		},
		"xvfb": map[string]any{
			"display": layout.xvfbDisplay,
		},
		"teamspeak": map[string]any{
			"enabled":       payloadBool(job.Payload, "teamspeak_enabled"),
			"instance_path": layout.installPath,
			"runtime_dir":   layout.runtimeDir,
			"identity_path": filepath.Join(layout.tsHome, "identity.ini"),
			"profile":       "ts3",
			"nickname":      fmt.Sprintf("Easy-Wi Bot %s", layout.instanceID),
			"config":        map[string]any{"mode": "placeholder", "ts3_home": layout.tsHome, "ts3_profile": layout.tsProfile, "pulse_socket": layout.pulseSock, "playback_device": layout.pulseSink, "capture_device": layout.pulseSource},
		},
		"discord": map[string]any{
			"enabled": payloadBool(job.Payload, "discord_enabled"),
			"config":  map[string]any{"command_mode": "placeholder"},
		},
		"limits": map[string]any{
			"cpu":  payloadValue(job.Payload, "cpu_limit"),
			"ram":  payloadValue(job.Payload, "ram_limit"),
			"disk": payloadValue(job.Payload, "disk_limit"),
		},
		"runtime":    musicbotRuntimeVersion,
		"updated_at": time.Now().UTC().Format(time.RFC3339),
		"note":       "Easy-Wi native Musicbot runtime placeholder; no SinusBot or TS3AudioBot binaries are installed.",
	}
	if _, err := os.Stat(layout.configPath); os.IsNotExist(err) {
		config["created_at"] = time.Now().UTC().Format(time.RFC3339)
	}
	encoded, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		return fmt.Errorf("encode config: %v", err)
	}
	if err := os.WriteFile(layout.configPath, append(encoded, '\n'), 0o600); err != nil {
		return fmt.Errorf("write config.json: %v", err)
	}
	if err := os.Chmod(layout.configPath, 0o600); err != nil {
		return fmt.Errorf("chmod config.json: %v", err)
	}
	return nil
}

func musicbotInstallPayload(layout musicbotLayout, binaryPath string) map[string]any {
	return map[string]any{
		"installed":      true,
		"running":        false,
		"instance_id":    layout.instanceID,
		"customer_id":    layout.customerID,
		"node_id":        layout.nodeID,
		"service_name":   layout.serviceName,
		"install_path":   layout.installPath,
		"control_socket": layout.controlSock,
		"runtime_binary": binaryPath,
		"config_path":    layout.configPath,
		"config_mode":    "0600",
		"systemd_unit":   layout.unitPath,
		"runtime": map[string]any{
			"version": musicbotRuntimeVersion,
			"native":  true,
		},
	}
}

// musicbotSystemdUnit returns a systemd unit for the easywi-musicbot service.
// PrivateDevices is disabled so child processes like Xvfb and PulseAudio can
// access audio/DRI devices. PrivateTmp=false lets Xvfb write to the real
// /tmp/.X11-unix socket directory (required on systems where the private
// tmpfs namespace is not visible to xdpyinfo/Xvfb before the display is ready).
// TMPDIR is overridden to keep TS3 temp files inside the instance directory.
func musicbotSystemdUnit(serviceName, installPath, binaryPath, configPath string) string {
	command := fmt.Sprintf("%s --config %s", binaryPath, configPath)
	limits := buildSystemdLimits(0, 0)
	return fmt.Sprintf(`[Unit]
Description=Easy-Wi Musicbot Instance %s
After=network.target
StartLimitIntervalSec=60
StartLimitBurst=3

[Service]
Type=simple
User=easywi
WorkingDirectory=%s
Environment=HOME=%s
Environment=XDG_CONFIG_HOME=%s/.config
Environment=XDG_DATA_HOME=%s/.local/share
Environment=TMPDIR=%s/runtime/tmp
Environment=PULSE_SERVER=unix:%s/runtime/pulse/pulse.sock
Environment=EASYWI_MUSICBOT_INSTANCE=%s
ExecStartPre=/usr/bin/test -d %s
ExecStart=%s
StandardOutput=journal
StandardError=journal
Restart=on-failure
RestartSec=10
UMask=0027
LimitNOFILE=1048576
NoNewPrivileges=true
PrivateTmp=false
PrivateDevices=false
ProtectSystem=strict
ProtectHome=false
ReadWritePaths=%s
%s

[Install]
WantedBy=multi-user.target
`, serviceName, installPath, installPath, installPath, installPath, installPath, installPath, serviceName, installPath, command, installPath, limits)
}

// handleMusicbotServiceAction wraps handleServiceAction with pre-flight ownership
// checks before a start/restart so systemd never fails mid-launch on EPERM.
func handleMusicbotServiceAction(job jobs.Job) orchestratorResult {
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	if (action == "start" || action == "restart") && runtime.GOOS != "windows" {
		installPath, err := validateMusicbotInstallPath(payloadValue(job.Payload, "install_path", "install_dir"))
		if err == nil {
			if err := checkMusicbotRuntimeAccess(installPath, job); err != nil {
				return orchestratorResult{status: "failed", errorText: err.Error()}
			}
		}
	}
	return handleServiceAction(job)
}

// checkMusicbotRuntimeAccess verifies that the runtime user can actually read the
// config and execute the binary before systemd is told to start the service.
func checkMusicbotRuntimeAccess(installPath string, job jobs.Job) error {
	runtimeUser := musicbotRuntimeUser(job)
	configPath := filepath.Join(installPath, "config.json")
	binaryPath := filepath.Join(installPath, "bin", "easywi-musicbot")

	if err := runCommand("sudo", "-u", runtimeUser, "test", "-d", installPath); err != nil {
		return fmt.Errorf("runtime user %s cannot access install directory %s", runtimeUser, installPath)
	}
	if err := runCommand("sudo", "-u", runtimeUser, "test", "-r", configPath); err != nil {
		return fmt.Errorf("runtime user cannot read config.json")
	}
	if err := runCommand("sudo", "-u", runtimeUser, "test", "-x", binaryPath); err != nil {
		return fmt.Errorf("runtime user cannot execute %s", binaryPath)
	}
	return nil
}

func copyMusicbotFile(source string, destination string, mode os.FileMode) error {
	input, err := os.Open(source)
	if err != nil {
		return err
	}
	defer func() { _ = input.Close() }()
	if err := os.MkdirAll(filepath.Dir(destination), 0o750); err != nil {
		return err
	}
	tmp := destination + ".tmp"
	output, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
	if err != nil {
		return err
	}
	_, copyErr := io.Copy(output, input)
	closeErr := output.Close()
	if copyErr != nil {
		_ = os.Remove(tmp)
		return copyErr
	}
	if closeErr != nil {
		_ = os.Remove(tmp)
		return closeErr
	}
	if err := os.Chmod(tmp, mode); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, destination)
}
