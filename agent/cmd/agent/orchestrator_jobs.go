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

type orchestratorResult struct {
	status        string
	logText       string
	errorText     string
	resultPayload map[string]any
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
	if err := runCommand("curl", "-L", "-o", archivePath, downloadURL); err != nil {
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

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "ts6server.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := runCommand("sc", "create", serviceName, "binPath=", exePath); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": "unknown"}}
	}

	archivePath := filepath.Join(installDir, "ts6server.tar")
	if err := runCommand("curl", "-L", "-o", archivePath, downloadURL); err != nil {
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
	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, "root", installDir, installDir, "./ts6server", "", 0, 0)
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
	if err := runCommand("curl", "-L", "-o", archivePath, downloadURL); err != nil {
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
