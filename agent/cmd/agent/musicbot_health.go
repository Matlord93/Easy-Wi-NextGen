package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

// handleMusicbotHealthCheck performs all low-level health checks for a musicbot
// instance and returns a structured payload that the PHP layer stores in
// runtimePayload and uses to derive the instance health report.
func handleMusicbotHealthCheck(job jobs.Job) orchestratorResult {
	layout, err := resolveMusicbotLayout(job)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	health := map[string]any{}

	// Binary present and executable
	if stat, err := os.Stat(layout.binaryPath); err == nil && !stat.IsDir() && (stat.Mode()&0o111) != 0 {
		health["binary_present"] = true
	} else {
		health["binary_present"] = false
	}

	// config.json present and non-empty
	if data, err := os.ReadFile(layout.configPath); err == nil && len(data) > 2 {
		health["config_present"] = true
		health["config_readable"] = true
	} else {
		health["config_present"] = false
		health["config_readable"] = false
	}

	// control.sock present
	sockStat, sockErr := os.Stat(layout.controlSock)
	sockPresent := sockErr == nil && sockStat.Mode()&os.ModeSocket != 0
	health["control_socket_present"] = sockPresent

	// Try a lightweight status call on the control socket
	sockResponsive := false
	if sockPresent {
		_, pingErr := NewRuntimeControlClient(layout.installPath).Command("ping", nil)
		sockResponsive = pingErr == nil
	}
	health["control_socket_responsive"] = sockResponsive

	// PulseAudio socket
	if stat, err := os.Stat(layout.pulseSock); err == nil && stat.Mode()&os.ModeSocket != 0 {
		health["pulseaudio_socket_present"] = true
	} else {
		health["pulseaudio_socket_present"] = false
	}

	// PulseAudio sink / source (Linux only, best-effort)
	health["pulseaudio_sink_ok"] = false
	health["pulseaudio_source_ok"] = false
	if runtime.GOOS != "windows" && layout.pulseSink != "" {
		pulseEnv := []string{"PULSE_SERVER=unix:" + layout.pulseSock}
		if out, err := runCommandCaptureWithEnv("pactl", []string{"list", "short", "sinks"}, pulseEnv); err == nil {
			health["pulseaudio_sink_ok"] = strings.Contains(out, layout.pulseSink)
		}
		if out, err := runCommandCaptureWithEnv("pactl", []string{"list", "short", "sources"}, pulseEnv); err == nil {
			health["pulseaudio_source_ok"] = strings.Contains(out, layout.pulseSource)
		}
	}

	// Xvfb running (Linux only, best-effort)
	health["xvfb_running"] = false
	if runtime.GOOS != "windows" && layout.xvfbDisplay != "" {
		display := layout.xvfbDisplay
		if out, err := runCommandOutput("ps", "aux"); err == nil {
			health["xvfb_running"] = strings.Contains(out, "Xvfb "+display) || strings.Contains(out, "Xvfb\t"+display)
		}
	}

	// TeamSpeak client running (best-effort via process list)
	health["teamspeak_client_running"] = false
	if runtime.GOOS != "windows" {
		if out, err := runCommandOutput("ps", "aux"); err == nil {
			health["teamspeak_client_running"] = strings.Contains(out, "ts3client") || strings.Contains(out, "TeamSpeak3") || strings.Contains(out, "ts3_client")
		}
	}

	// ffmpeg
	health["ffmpeg_present"] = commandExists("ffmpeg")

	// yt-dlp
	health["ytdlp_present"] = commandExists("yt-dlp")

	// Upload / tracks directory writable / readable
	tracksDir := layout.tracksDir
	health["upload_dir_writable"] = isDirWritable(tracksDir)
	health["tracks_dir_readable"] = isDirReadable(tracksDir)

	// Systemd service status
	running := false
	serviceExists := false
	systemdStatus := ""
	journalExcerpt := ""
	if runtime.GOOS != "windows" && layout.useSystemctl {
		serviceExists = runCommand("systemctl", "status", layout.serviceName, "--no-pager") == nil
		running = runCommand("systemctl", "is-active", "--quiet", layout.serviceName) == nil
		if out, err := runCommandOutput("systemctl", "status", layout.serviceName, "--no-pager", "--lines=0"); err == nil {
			systemdStatus = trimOutput(out, 2000)
		}
		if out, err := runCommandOutput("journalctl", "-u", layout.serviceName, "-n", "50", "--no-pager"); err == nil {
			journalExcerpt = trimOutput(out, 4000)
		}
	}

	// Last error from file
	lastError := ""
	if data, err := os.ReadFile(filepath.Join(layout.installPath, "last_error.txt")); err == nil {
		lastError = strings.TrimSpace(string(data))
	}

	// Try to get live runtime status from control socket
	runtimePayload := map[string]any{}
	if sockResponsive {
		if resp, err := NewRuntimeControlClient(layout.installPath).Command("status", nil); err == nil && resp.OK {
			runtimePayload = resp.Payload
		}
	}

	payload := map[string]any{
		"health":           health,
		"running":          running,
		"service_exists":   serviceExists,
		"systemd_status":   systemdStatus,
		"journal_excerpt":  journalExcerpt,
		"last_error":       lastError,
		"last_agent_job_at": time.Now().UTC().Format(time.RFC3339),
		"runtime":          runtimePayload,
		"status":           mapMusicbotRunningStatus(running),
	}

	// Merge live runtime payload fields at the top level
	for k, v := range runtimePayload {
		if _, exists := payload[k]; !exists {
			payload[k] = v
		}
	}

	return orchestratorResult{status: "success", resultPayload: payload}
}

// handleMusicbotHealthRepair performs a targeted repair action for a musicbot instance.
func handleMusicbotHealthRepair(job jobs.Job) orchestratorResult {
	action := strings.TrimSpace(payloadValue(job.Payload, "repair_action"))
	if action == "" {
		return orchestratorResult{status: "failed", errorText: "repair_action is required"}
	}

	layout, layoutErr := resolveMusicbotLayout(job)

	repaired := []string{}
	errors := []string{}

	switch action {
	case "service_restart":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if runtime.GOOS != "windows" && layout.useSystemctl {
			if err := runCommand("systemctl", "restart", layout.serviceName); err != nil {
				errors = append(errors, fmt.Sprintf("restart failed: %v", err))
			} else {
				repaired = append(repaired, "service_restarted")
			}
		}

	case "daemon_reload":
		if runtime.GOOS != "windows" {
			if err := runCommand("systemctl", "daemon-reload"); err != nil {
				errors = append(errors, fmt.Sprintf("daemon-reload failed: %v", err))
			} else {
				repaired = append(repaired, "daemon_reloaded")
			}
		}

	case "remove_stale_socket":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if err := os.Remove(layout.controlSock); err != nil && !os.IsNotExist(err) {
			errors = append(errors, fmt.Sprintf("remove socket: %v", err))
		} else {
			repaired = append(repaired, "stale_socket_removed")
		}

	case "repair_dir_permissions":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		dirs := []string{layout.dataDir, layout.tracksDir, layout.queueDir, layout.logDir, layout.pluginDir, layout.runtimeDir}
		for _, dir := range dirs {
			if dir == "" {
				continue
			}
			if err := os.MkdirAll(dir, 0o750); err != nil {
				errors = append(errors, fmt.Sprintf("mkdir %s: %v", dir, err))
			} else if err := os.Chmod(dir, 0o750); err != nil {
				errors = append(errors, fmt.Sprintf("chmod %s: %v", dir, err))
			}
		}
		if len(errors) == 0 {
			repaired = append(repaired, "dir_permissions_repaired")
		}

	case "create_missing_dirs":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if err := ensureMusicbotDirectories(layout); err != nil {
			errors = append(errors, err.Error())
		} else {
			repaired = append(repaired, "missing_dirs_created")
		}

	case "reinit_pulseaudio":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if runtime.GOOS != "windows" && layout.useSystemctl {
			_ = runCommand("systemctl", "restart", layout.serviceName)
			repaired = append(repaired, "service_restarted_for_pulseaudio")
		}

	case "restart_teamspeak_bridge":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if sockResponsive(layout.controlSock) {
			if _, err := NewRuntimeControlClient(layout.installPath).Command("reconnect", map[string]any{"platform": "teamspeak"}); err != nil {
				errors = append(errors, fmt.Sprintf("reconnect via socket: %v", err))
			} else {
				repaired = append(repaired, "teamspeak_reconnect_sent")
			}
		} else if layout.useSystemctl {
			_ = runCommand("systemctl", "restart", layout.serviceName)
			repaired = append(repaired, "service_restarted_for_teamspeak")
		}

	case "rewrite_config":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if err := writeMusicbotConfig(job, layout); err != nil {
			errors = append(errors, fmt.Sprintf("write config: %v", err))
		} else {
			repaired = append(repaired, "config_rewritten")
		}

	case "resend_config_apply":
		repaired = append(repaired, "config_apply_delegated")

	case "force_status_refresh":
		repaired = append(repaired, "status_refresh_requested")

	case "force_queue_sync":
		repaired = append(repaired, "queue_sync_requested")

	case "reinstall_binary":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		runtimeSource, err := resolveMusicbotRuntimeBinary(job)
		if err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if _, err := installMusicbotRuntimeBinary(runtimeSource, layout.binaryPath); err != nil {
			errors = append(errors, fmt.Sprintf("reinstall binary: %v", err))
		} else {
			repaired = append(repaired, "binary_reinstalled")
		}

	case "rewrite_systemd_unit":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if runtime.GOOS != "windows" {
			unit := musicbotSystemdUnit(layout.serviceName, layout.installPath, layout.binaryPath, layout.configPath)
			if err := os.WriteFile(layout.unitPath, []byte(unit), 0o644); err != nil {
				errors = append(errors, fmt.Sprintf("write unit: %v", err))
			} else {
				_ = runCommand("systemctl", "daemon-reload")
				repaired = append(repaired, "systemd_unit_rewritten")
			}
		}

	case "sync_plugin_status", "sync_playlist_status", "sync_autodj_status":
		repaired = append(repaired, action+"_requested")

	case "reconnect_connector":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		platform := strings.TrimSpace(payloadValue(job.Payload, "platform"))
		if platform == "" {
			platform = "teamspeak"
		}
		if sockResponsive(layout.controlSock) {
			if _, err := NewRuntimeControlClient(layout.installPath).Command("reconnect", map[string]any{"platform": platform}); err != nil {
				errors = append(errors, fmt.Sprintf("reconnect: %v", err))
			} else {
				repaired = append(repaired, "connector_reconnect_sent")
			}
		} else {
			errors = append(errors, "control socket not responsive")
		}

	case "ffmpeg_dependency_check":
		if commandExists("ffmpeg") {
			repaired = append(repaired, "ffmpeg_present")
		} else {
			errors = append(errors, "ffmpeg not found")
		}

	case "ytdlp_dependency_check":
		if commandExists("yt-dlp") {
			repaired = append(repaired, "ytdlp_present")
		} else {
			errors = append(errors, "yt-dlp not found")
		}

	case "rewrite_queue":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		queuePath := filepath.Join(layout.queueDir, "queue.json")
		if err := os.MkdirAll(layout.queueDir, 0o750); err != nil {
			errors = append(errors, fmt.Sprintf("create queue dir: %v", err))
		} else if err := os.WriteFile(queuePath, []byte("{\"items\":[]}\n"), 0o640); err != nil {
			errors = append(errors, fmt.Sprintf("write queue.json: %v", err))
		} else {
			repaired = append(repaired, "queue_rewritten")
		}

	case "repair_playlists":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if err := os.MkdirAll(layout.playlistsDir, 0o750); err != nil {
			errors = append(errors, fmt.Sprintf("create playlists dir: %v", err))
		} else {
			repaired = append(repaired, "playlists_dir_ensured")
		}

	case "repair_plugin_registry":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if err := os.MkdirAll(layout.pluginDir, 0o750); err != nil {
			errors = append(errors, fmt.Sprintf("create plugin dir: %v", err))
		} else {
			registryPath := filepath.Join(layout.pluginDir, "registry.json")
			if _, err := os.Stat(registryPath); os.IsNotExist(err) {
				if err := os.WriteFile(registryPath, []byte("{\"plugins\":[]}\n"), 0o640); err != nil {
					errors = append(errors, fmt.Sprintf("write registry.json: %v", err))
				} else {
					repaired = append(repaired, "plugin_registry_created")
				}
			} else {
				repaired = append(repaired, "plugin_registry_present")
			}
		}

	case "repair_autodj":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if sockResponsive(layout.controlSock) {
			if _, err := NewRuntimeControlClient(layout.installPath).Command("autodj.reset", nil); err != nil {
				errors = append(errors, fmt.Sprintf("autodj reset via socket: %v", err))
			} else {
				repaired = append(repaired, "autodj_reset")
			}
		} else {
			repaired = append(repaired, "autodj_repair_queued_for_next_start")
		}

	case "repair_youtube":
		if commandExists("yt-dlp") {
			if out, err := runCommandCaptureWithEnv("yt-dlp", []string{"--version"}, nil); err == nil {
				repaired = append(repaired, fmt.Sprintf("ytdlp_present_version_%s", strings.TrimSpace(out)))
			} else {
				repaired = append(repaired, "ytdlp_present")
			}
		} else {
			errors = append(errors, "yt-dlp not found; install with: pip3 install -U yt-dlp")
		}

	case "repair_upload_dirs":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		uploadDirs := []string{layout.tracksDir, layout.dataDir, filepath.Join(layout.dataDir, "uploads"), filepath.Join(layout.dataDir, "cache"), filepath.Join(layout.dataDir, "history")}
		for _, dir := range uploadDirs {
			if dir == "" {
				continue
			}
			if err := os.MkdirAll(dir, 0o750); err != nil {
				errors = append(errors, fmt.Sprintf("create %s: %v", dir, err))
			}
		}
		if len(errors) == 0 {
			repaired = append(repaired, "upload_dirs_ensured")
		}

	case "clear_cache":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		cacheDir := filepath.Join(layout.dataDir, "cache")
		if err := os.RemoveAll(cacheDir); err != nil {
			errors = append(errors, fmt.Sprintf("clear cache: %v", err))
		} else if err := os.MkdirAll(cacheDir, 0o750); err != nil {
			errors = append(errors, fmt.Sprintf("recreate cache dir: %v", err))
		} else {
			repaired = append(repaired, "cache_cleared")
		}

	case "restart_runtime":
		if layoutErr != nil {
			return orchestratorResult{status: "failed", errorText: layoutErr.Error()}
		}
		if runtime.GOOS != "windows" && layout.useSystemctl {
			if err := runCommand("systemctl", "restart", layout.serviceName); err != nil {
				errors = append(errors, fmt.Sprintf("restart failed: %v", err))
			} else {
				repaired = append(repaired, "runtime_restarted")
			}
		} else {
			errors = append(errors, "systemctl not available")
		}

	default:
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("unknown repair_action: %s", action)}
	}

	status := "success"
	if len(errors) > 0 && len(repaired) == 0 {
		status = "failed"
	} else if len(errors) > 0 {
		status = "partial"
	}

	return orchestratorResult{
		status: status,
		resultPayload: map[string]any{
			"action":   action,
			"repaired": repaired,
			"errors":   errors,
		},
	}
}

// isDirWritable returns true if the directory exists and can be written to.
func isDirWritable(path string) bool {
	if path == "" {
		return false
	}
	if stat, err := os.Stat(path); err != nil || !stat.IsDir() {
		return false
	}
	tmp := filepath.Join(path, ".easywi_write_test_"+fmt.Sprintf("%d", time.Now().UnixNano()))
	f, err := os.Create(tmp)
	if err != nil {
		return false
	}
	_ = f.Close()
	_ = os.Remove(tmp)
	return true
}

// isDirReadable returns true if the directory exists and can be listed.
func isDirReadable(path string) bool {
	if path == "" {
		return false
	}
	_, err := os.ReadDir(path)
	return err == nil
}

// sockResponsive returns true if the unix socket at the given path exists and
// looks like it might respond (it is a socket file).
func sockResponsive(sockPath string) bool {
	if sockPath == "" {
		return false
	}
	stat, err := os.Stat(sockPath)
	return err == nil && stat.Mode()&os.ModeSocket != 0
}

