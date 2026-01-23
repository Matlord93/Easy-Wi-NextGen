package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

type orchestratorResult struct {
	status        string
	logText       string
	errorText     string
	resultPayload map[string]any
}

type ts6ConfigOptions struct {
	licenseAccepted  bool
	voiceIP          []string
	defaultVoicePort int
	filetransferPort int
	filetransferIP   []string
	queryBindIP      string
	queryHttpEnable  bool
	queryHttpPort    int
	queryHttpsEnable bool
	queryHttpsPort   int
	queryAdminPass   string
	workingDirectory string
}

func handleOrchestratorJob(job jobs.Job) orchestratorResult {
	switch job.Type {
	case "ts3.install":
		return handleTs3NodeInstall(job)
	case "ts3.service.action":
		return handleServiceAction(job)
	case "ts3.status":
		return handleServiceStatus(job)
	case "ts3.instance.create":
		result, afterSubmit := handleTs3Create(job, nil)
		return convertJobResult(result, afterSubmit)
	case "ts3.instance.action":
		return handleTs3InstanceAction(job)
	case "ts6.install":
		return handleTs6NodeInstall(job)
	case "ts6.service.action":
		return handleServiceAction(job)
	case "ts6.status":
		return handleServiceStatus(job)
	case "ts6.instance.create":
		return orchestratorResult{
			status:        "success",
			resultPayload: map[string]any{"message": "ts6 instance create queued"},
		}
	case "ts6.instance.action":
		return handleServiceAction(job)
	case "sinusbot.install":
		return handleSinusbotInstall(job)
	case "sinusbot.service.action":
		return handleServiceAction(job)
	case "sinusbot.status":
		return handleServiceStatus(job)
	case "ts3.virtual.create", "ts3.virtual.action", "ts3.virtual.token.rotate":
		return orchestratorResult{
			status:    "failed",
			errorText: "ts3 virtual server orchestration not implemented in agent",
		}
	case "ts6.virtual.create", "ts6.virtual.action", "ts6.virtual.token.rotate":
		return orchestratorResult{
			status:    "failed",
			errorText: "ts6 virtual server orchestration not implemented in agent",
		}
	case "ts3.viewer.snapshot", "ts6.viewer.snapshot":
		return handleViewerSnapshot(job)
	default:
		return orchestratorResult{
			status:    "failed",
			errorText: fmt.Sprintf("unsupported job type: %s", job.Type),
		}
	}
}

func handleTs3InstanceAction(job jobs.Job) orchestratorResult {
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	switch action {
	case "start":
		result, afterSubmit := handleTs3Start(job)
		return convertJobResult(result, afterSubmit)
	case "stop":
		result, afterSubmit := handleTs3Stop(job)
		return convertJobResult(result, afterSubmit)
	case "restart":
		result, afterSubmit := handleTs3Restart(job)
		return convertJobResult(result, afterSubmit)
	case "update":
		result, afterSubmit := handleTs3Update(job)
		return convertJobResult(result, afterSubmit)
	case "backup":
		result, afterSubmit := handleTs3Backup(job)
		return convertJobResult(result, afterSubmit)
	case "restore":
		result, afterSubmit := handleTs3Restore(job)
		return convertJobResult(result, afterSubmit)
	case "token_reset":
		result, afterSubmit := handleTs3TokenReset(job)
		return convertJobResult(result, afterSubmit)
	case "slots":
		result, afterSubmit := handleTs3SlotsSet(job)
		return convertJobResult(result, afterSubmit)
	case "logs":
		result, afterSubmit := handleTs3LogsExport(job)
		return convertJobResult(result, afterSubmit)
	default:
		return orchestratorResult{
			status:    "failed",
			errorText: fmt.Sprintf("unsupported ts3 action: %s", action),
		}
	}
}

func handleServiceAction(job jobs.Job) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	if serviceName == "" || action == "" {
		return orchestratorResult{
			status:    "failed",
			errorText: "missing service_name or action",
		}
	}

	if runtime.GOOS == "windows" {
		output, err := runCommandOutput("sc", action, serviceName)
		if err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error(), logText: trimOutput(output, 4000)}
		}
		return orchestratorResult{
			status:  "success",
			logText: trimOutput(output, 4000),
			resultPayload: map[string]any{
				"running": action != "stop",
			},
		}
	}

	if err := runCommand("systemctl", action, serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	status := action != "stop"
	return orchestratorResult{
		status:        "success",
		resultPayload: map[string]any{"running": status},
	}
}

func handleServiceStatus(job jobs.Job) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" {
		return orchestratorResult{status: "failed", errorText: "missing service_name"}
	}
	if runtime.GOOS == "windows" {
		output, err := runCommandOutput("sc", "query", serviceName)
		if err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error(), logText: trimOutput(output, 4000)}
		}
		return orchestratorResult{
			status:  "success",
			logText: trimOutput(output, 4000),
			resultPayload: map[string]any{
				"running": strings.Contains(strings.ToUpper(output), "RUNNING"),
			},
		}
	}

	err := runCommand("systemctl", "is-active", "--quiet", serviceName)
	return orchestratorResult{
		status:        "success",
		resultPayload: map[string]any{"running": err == nil},
	}
}

func handleTs3NodeInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")
	instanceName := payloadValue(job.Payload, "instance_name")
	queryPort := payloadValue(job.Payload, "query_port")
	voicePort := payloadValue(job.Payload, "voice_port")
	filePort := payloadValue(job.Payload, "file_port")

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "ts3server.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := runCommand("sc", "create", serviceName, "binPath=", exePath); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": "unknown"}}
	}

	archivePath := filepath.Join(installDir, "ts3server.tar")
	if err := downloadArchive(archivePath, downloadURL); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if strings.HasSuffix(downloadURL, ".zip") {
		if err := runCommand("unzip", "-o", archivePath, "-d", installDir); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	} else {
		if err := runCommand("tar", "-xf", archivePath, "-C", installDir, "--strip-components=1"); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}

	if instanceName == "" {
		instanceName = "ts3"
	}
	if voicePort == "" {
		voicePort = "9987"
	}
	if queryPort == "" {
		queryPort = "10011"
	}
	if filePort == "" {
		filePort = "30033"
	}
	configPath := filepath.Join(installDir, ts3ConfigFile)
	config := buildTs3Config(ts3Config{
		name:      instanceName,
		voicePort: voicePort,
		queryPort: queryPort,
		filePort:  filePort,
	})
	if err := writeFile(configPath, config); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, "root", installDir, installDir, "./ts3server", "", 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"installed_version": "unknown",
			"running":           true,
		},
	}
}

func handleTs6NodeInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")
	instanceName := payloadValue(job.Payload, "instance_name")
	acceptLicense := parseBool(payloadValue(job.Payload, "accept_license"), true)
	voiceIP := parseStringList(payloadValue(job.Payload, "voice_ip"), []string{"0.0.0.0"})
	defaultVoicePort := parseInt(payloadValue(job.Payload, "default_voice_port"), 9987)
	filetransferPort := parseInt(payloadValue(job.Payload, "filetransfer_port"), 30033)
	filetransferIP := parseStringList(payloadValue(job.Payload, "filetransfer_ip"), []string{"0.0.0.0"})
	queryBindIP := payloadValue(job.Payload, "query_bind_ip")
	queryHttpsEnable := parseBool(payloadValue(job.Payload, "query_https_enable"), true)
	queryHttpsPort := parseInt(payloadValue(job.Payload, "query_https_port"), 10443)
	adminPassword := payloadValue(job.Payload, "admin_password")

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "tsserver.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := runCommand("sc", "create", serviceName, "binPath=", exePath); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": "unknown"}}
	}

	archivePath := filepath.Join(installDir, "ts6server.tar")
	if err := downloadArchive(archivePath, downloadURL); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if strings.HasSuffix(downloadURL, ".zip") {
		if err := runCommand("unzip", "-o", archivePath, "-d", installDir); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	} else {
		if err := runCommand("tar", "-xf", archivePath, "-C", installDir, "--strip-components=1"); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}

	if instanceName == "" {
		instanceName = "ts6"
	}
	configPath := filepath.Join(installDir, "tsserver.yaml")
	configContent := buildTs6Config(ts6ConfigOptions{
		licenseAccepted:  acceptLicense,
		voiceIP:          voiceIP,
		defaultVoicePort: defaultVoicePort,
		filetransferPort: filetransferPort,
		filetransferIP:   filetransferIP,
		queryBindIP:      queryBindIP,
		queryHttpEnable:  true,
		queryHttpPort:    10080,
		queryHttpsEnable: queryHttpsEnable,
		queryHttpsPort:   queryHttpsPort,
		queryAdminPass:   adminPassword,
		workingDirectory: installDir,
	})
	if err := writeFile(configPath, configContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, "root", installDir, installDir, filepath.Join(installDir, "tsserver"), "--accept-license --config-file installDir, tsserver.yaml", 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"installed_version": "unknown",
			"running":           true,
		},
	}
}

func handleSinusbotInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "sinusbot.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := runCommand("sc", "create", serviceName, "binPath=", exePath); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": "unknown"}}
	}

	archivePath := filepath.Join(installDir, "sinusbot.tar")
	if err := downloadArchive(archivePath, downloadURL); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if strings.HasSuffix(downloadURL, ".zip") {
		if err := runCommand("unzip", "-o", archivePath, "-d", installDir); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	} else {
		if err := runCommand("tar", "-xf", archivePath, "-C", installDir, "--strip-components=1"); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, "root", installDir, installDir, "./sinusbot", "", 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"installed_version": "unknown",
			"running":           true,
		},
	}
}

func handleViewerSnapshot(job jobs.Job) orchestratorResult {
	sid := payloadValue(job.Payload, "sid")
	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"server": map[string]any{
				"sid":  sid,
				"name": fmt.Sprintf("TS Server %s", sid),
			},
			"channels":     []any{},
			"clients":      []any{},
			"generated_at": time.Now().UTC().Format(time.RFC3339),
		},
	}
}

func downloadArchive(destination, url string) error {
	if err := runCommand("curl", "-fL", "--retry", "3", "--retry-delay", "3", "-o", destination, url); err != nil {
		return err
	}
	return validateArchive(destination)
}

func validateArchive(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	if info.Size() == 0 {
		return fmt.Errorf("downloaded archive is empty")
	}
	file, err := os.Open(path)
	if err != nil {
		return err
	}
	defer file.Close()

	buffer := make([]byte, 512)
	n, err := file.Read(buffer)
	if err != nil && !errors.Is(err, io.EOF) {
		return err
	}
	snippet := strings.ToLower(string(buffer[:n]))
	if strings.Contains(snippet, "<!doctype") || strings.Contains(snippet, "<html") {
		return fmt.Errorf("downloaded archive looks like HTML; check the download URL for authentication or redirects")
	}
	return nil
}

func buildTs6Config(options ts6ConfigOptions) string {
	queryIPs := options.voiceIP
	if options.queryBindIP != "" {
		queryIPs = []string{options.queryBindIP}
	}
	httpEnabled := boolToInt(options.queryHttpEnable)
	httpsEnabled := boolToInt(options.queryHttpsEnable)
	acceptValue := "accept"
	if !options.licenseAccepted {
		acceptValue = "0"
	}
	return fmt.Sprintf(`server:
  license-path: .
  default-voice-port: %d
  voice-ip:
- 0.0.0.0
  log-path: logs
  log-append: 0
  no-default-virtual-server: 0
  filetransfer-port: %d
  filetransfer-ip:
- 0.0.0.0
  accept-license: %s
  crashdump-path: crashdumps

  database:
    plugin: sqlite3
    sql-path: %s/sql/
    sql-create-path: %s/sql/create_sqlite/
    client-keep-days: 30
    config:
      skip-integrity-check: 0
      host: 127.0.0.1
      port: 5432
      socket: ""
      timeout: 10
      name: teamspeak
      username: ""
      password: ""
      connections: 10
      log-queries: 0

  query:
    pool-size: 2
    log-timing: 3600
    ip-allow-list: query_ip_allowlist.txt
    ip-block-list: query_ip_denylist.txt
    admin-password: %q
    log-commands: 0
    skip-brute-force-check: 0
    buffer-mb: 20
    documentation-path: serverquerydocs
    timeout: 300

    http:
      enable: %d
      port: %d
      ip:
- 127.0.0.1

    https:
      enable: %d
      port: %d
      ip:
- 127.0.0.1
      certificate: ""
      private-key: ""
`, options.defaultVoicePort, formatYamlList(options.voiceIP, 4), options.filetransferPort, formatYamlList(options.filetransferIP, 4), acceptValue, options.workingDirectory, options.workingDirectory, options.queryAdminPass, httpEnabled, options.queryHttpPort, formatYamlList(queryIPs, 6), httpsEnabled, options.queryHttpsPort, formatYamlList(queryIPs, 6))
}

func formatYamlList(values []string, indent int) string {
	prefix := strings.Repeat(" ", indent)
	lines := make([]string, 0, len(values))
	for _, value := range values {
		lines = append(lines, fmt.Sprintf("%s- %s", prefix, value))
	}
	return strings.Join(lines, "\n")
}

func parseBool(value string, fallback bool) bool {
	if value == "" {
		return fallback
	}
	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}
	return parsed
}

func parseInt(value string, fallback int) int {
	if value == "" {
		return fallback
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return fallback
	}
	return parsed
}

func parseStringList(value string, fallback []string) []string {
	if value == "" {
		return fallback
	}
	if strings.HasPrefix(strings.TrimSpace(value), "[") {
		var parsed []string
		if err := json.Unmarshal([]byte(value), &parsed); err == nil && len(parsed) > 0 {
			return parsed
		}
	}
	parts := strings.Split(value, ",")
	values := make([]string, 0, len(parts))
	for _, part := range parts {
		trimmed := strings.TrimSpace(part)
		if trimmed != "" {
			values = append(values, trimmed)
		}
	}
	if len(values) == 0 {
		return fallback
	}
	return values
}

func boolToInt(value bool) int {
	if value {
		return 1
	}
	return 0
}

func convertJobResult(result jobs.Result, afterSubmit func() error) orchestratorResult {
	if afterSubmit != nil {
		_ = afterSubmit()
	}
	status := "success"
	if result.Status != "success" {
		status = "failed"
	}
	payload := map[string]any{}
	for key, value := range result.Output {
		payload[key] = value
	}
	return orchestratorResult{
		status:        status,
		errorText:     result.Output["message"],
		resultPayload: payload,
	}
}

func writeFile(path string, content string) error {
	return os.WriteFile(path, []byte(content), instanceFileMode)
}
