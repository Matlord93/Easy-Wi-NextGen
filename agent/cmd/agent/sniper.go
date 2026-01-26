package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var (
	buildIDRegex         = regexp.MustCompile(`(?i)build(?:[_\s-]?id)?[:\s]+([0-9]+)`)
	versionRegex         = regexp.MustCompile(`(?i)version[:\s]+([0-9a-zA-Z._-]+)`)
	jsonLineRegex        = regexp.MustCompile(`\{.*\}`)
	forceInstallDirRegex = regexp.MustCompile(`(?i)(\+force_install_dir\s+)(\.(?:/)?|"\."|'\.'|"\./"|'\./')`)
	steamcmdCommandRegex = regexp.MustCompile(`(^|\s)(/var/lib/easywi/game/steamcmd/steamcmd\.sh|/usr/local/bin/steamcmd|steamcmd)(\s|$)`)
	steamcmdArchiveURL   = "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz"
)

func handleSniperInstall(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	return handleSniperAction(job, "install", logSender)
}

func handleSniperUpdate(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	return handleSniperAction(job, "update", logSender)
}

func handleSniperAction(job jobs.Job, action string, logSender JobLogSender) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	steamAppID := payloadValue(job.Payload, "steam_app_id")
	installCommand := payloadValue(job.Payload, "install_command")
	updateCommand := payloadValue(job.Payload, "update_command")
	baseDir := payloadValue(job.Payload, "base_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	startParams := payloadValue(job.Payload, "start_params")
	requiredPortsRaw := payloadValue(job.Payload, "required_ports")
	templateKey := payloadValue(job.Payload, "template_key", "game_key")
	cpuLimitValue := payloadValue(job.Payload, "cpu_limit")
	ramLimitValue := payloadValue(job.Payload, "ram_limit")
	autostart := parsePayloadBool(payloadValue(job.Payload, "autostart", "auto_start"), true)

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
		{key: "start_params", value: startParams},
		{key: "cpu_limit", value: cpuLimitValue},
		{key: "ram_limit", value: ramLimitValue},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if baseDir == "" {
		baseDir = "/home"
	}
	if serviceName == "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	osUsername := buildInstanceUsername(customerID, instanceID)
	instanceDir := fmt.Sprintf("%s/%s", strings.TrimRight(baseDir, "/"), osUsername)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, instanceDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureBaseDir(baseDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureInstanceDir(instanceDir); err != nil {
		return failureResult(job.ID, err)
	}
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.Chown(instanceDir, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", instanceDir, err))
	}
	if err := os.Chmod(instanceDir, instanceDirMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("chmod %s: %w", instanceDir, err))
	}

	allocatedPorts := parsePayloadPorts(job.Payload)
	templateValues := buildInstanceTemplateValues(instanceDir, requiredPortsRaw, allocatedPorts, job.Payload)

	cpuLimit, err := parsePositiveInt(cpuLimitValue, "cpu_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	ramLimit, err := parsePositiveInt(ramLimitValue, "ram_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}

	var command string
	if action == "install" {
		command = installCommand
	} else {
		command = updateCommand
	}

	usesSteamCmd := false
	if command == "" {
		command = buildSteamCmdCommand(instanceDirSteamCmdPath(instanceDir), instanceDir, steamAppID, action == "install")
		usesSteamCmd = true
	}

	if command == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "no install or update command configured"},
			Completed: time.Now().UTC(),
		}, nil
	}

	renderedCommand, err := renderTemplateStrict(command, templateValues)
	if err != nil {
		return failureResult(job.ID, err)
	}

	command = renderedCommand
	command = normalizeSteamCmdInstallDir(command, instanceDir)

	steamCmdPath := instanceDirSteamCmdPath(instanceDir)
	if !usesSteamCmd && steamcmdCommandRegex.MatchString(command) {
		usesSteamCmd = true
		command = replaceSteamCmdExecutable(command, steamCmdPath)
	}

	installSnippet := ""
	if usesSteamCmd {
		installSnippet = steamCmdInstallSnippet(instanceDirSteamCmdDir(instanceDir), steamCmdPath)
	}
	postInstallSnippet := ""
	if usesSteamCmd {
		postInstallSnippet = steamCmdClientSnippet(instanceDirSteamCmdDir(instanceDir), instanceDir)
	}

	shellCmd := fmt.Sprintf(
		"export HOME=%[1]s; export XDG_DATA_HOME=%[1]s/.local/share; "+
			"mkdir -p %[1]s/.steam %[1]s/.local/share; "+
			"%[3]s"+
			"cd %[1]s && %[2]s; "+
			"%[4]s",
		instanceDir, command, installSnippet, postInstallSnippet,
	)

	output, err := runCommandOutputAsUserWithLogs(osUsername, shellCmd, job.ID, logSender)
	if err != nil {
		return failureResult(job.ID, err)
	}

	renderedStartParams, err := renderTemplateStrict(startParams, templateValues)
	if err != nil {
		return failureResult(job.ID, err)
	}
	startScriptPath, err := writeStartScript(instanceDir, renderedStartParams)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := validateBinaryExists(instanceDir, renderedStartParams); err != nil {
		return failureResult(job.ID, err)
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, instanceDir, startScriptPath, "", cpuLimit, ramLimit)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return failureResult(job.ID, err)
	}
	if autostart {
		_ = runCommand("systemctl", "enable", serviceName)
	}
	if err := runCommand("systemctl", "start", serviceName); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureServiceActive(serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	maskedCommand := maskSensitiveValues(renderedStartParams, templateValues)
	log.Printf("instance=%s template=%s start_command=%s start_script_path=%s", instanceID, templateKey, maskedCommand, startScriptPath)

	buildID, version := extractBuildInfo(output)
	resultOutput := map[string]string{
		"message":           "sniper " + action + " completed",
		"start_script_path": startScriptPath,
		"service_name":      serviceName,
		"cpu_limit":         strconv.Itoa(cpuLimit),
		"ram_limit":         strconv.Itoa(ramLimit),
		"autostart":         strconv.FormatBool(autostart),
	}
	if trimmed := trimOutput(output, 4000); trimmed != "" {
		resultOutput["install_log"] = trimmed
	}
	if buildID != "" {
		resultOutput["build_id"] = buildID
	}
	if version != "" {
		resultOutput["version"] = version
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    resultOutput,
		Completed: time.Now().UTC(),
	}, nil
}

func buildSteamCmdCommand(steamCmdPath, instanceDir, steamAppID string, validate bool) string {
	if steamAppID == "" {
		return ""
	}
	parts := []string{
		steamCmdPath,
		"+force_install_dir", instanceDir,
		"+login", "anonymous",
		"+app_update", steamAppID,
	}
	if validate {
		parts = append(parts, "validate")
	}
	parts = append(parts, "+quit")
	return strings.Join(parts, " ")
}

func instanceDirSteamCmdDir(instanceDir string) string {
	return filepath.Join(instanceDir, ".steamcmd")
}

func instanceDirSteamCmdPath(instanceDir string) string {
	return filepath.Join(instanceDirSteamCmdDir(instanceDir), "steamcmd.sh")
}

func steamCmdInstallSnippet(steamCmdDir, steamCmdPath string) string {
	escapedDir := strings.ReplaceAll(steamCmdDir, "$", "$$")
	escapedPath := strings.ReplaceAll(steamCmdPath, "$", "$$")
	return fmt.Sprintf(
		"if [ ! -x %[2]s ]; then "+
			"mkdir -p %[1]s; "+
			"archive=%[1]s/steamcmd_linux.tar.gz; "+
			"if command -v curl >/dev/null 2>&1; then "+
			"curl -fsSL %[3]q -o $archive; "+
			"elif command -v wget >/dev/null 2>&1; then "+
			"wget -qO $archive %[4]q; "+
			"else "+
			"echo \"steamcmd download failed: missing curl or wget\" >&2; exit 1; "+
			"fi; "+
			"tar -xzf $archive -C %[1]s; "+
			"rm -f $archive; "+
			"chmod 755 %[2]s; "+
			"fi; ",
		escapedDir,
		escapedPath,
		steamcmdArchiveURL,
		steamcmdArchiveURL,
	)
}

func steamCmdClientSnippet(steamCmdDir, instanceDir string) string {
	escapedDir := strings.ReplaceAll(steamCmdDir, "$", "$$")
	escapedInstance := strings.ReplaceAll(instanceDir, "$", "$$")
	return fmt.Sprintf(
		"if [ -d %[1]s ]; then "+
			"mkdir -p %[2]s/.steam/sdk32 %[2]s/.steam/sdk64; "+
			"if [ -f %[1]s/linux32/steamclient.so ]; then "+
			"cp -f %[1]s/linux32/steamclient.so %[2]s/.steam/sdk32/steamclient.so; "+
			"fi; "+
			"if [ -f %[1]s/linux64/steamclient.so ]; then "+
			"cp -f %[1]s/linux64/steamclient.so %[2]s/.steam/sdk64/steamclient.so; "+
			"fi; "+
			"fi; ",
		escapedDir,
		escapedInstance,
	)
}

func replaceSteamCmdExecutable(command, steamCmdPath string) string {
	if command == "" {
		return ""
	}
	escapedPath := strings.ReplaceAll(steamCmdPath, "$", "$$")
	return steamcmdCommandRegex.ReplaceAllString(command, "${1}"+escapedPath+"${3}")
}

func normalizeSteamCmdInstallDir(command, instanceDir string) string {
	if command == "" || instanceDir == "" {
		return command
	}

	normalized := strings.ReplaceAll(command, "{{INSTANCE_DIR}}", instanceDir)
	normalized = strings.ReplaceAll(normalized, "{{INSTALL_DIR}}", instanceDir)

	escapedDir := strings.ReplaceAll(instanceDir, "$", "$$")
	return forceInstallDirRegex.ReplaceAllString(normalized, "${1}"+escapedDir)
}

func extractBuildInfo(output string) (string, string) {
	trimmed := strings.TrimSpace(output)
	if trimmed == "" {
		return "", ""
	}

	lines := strings.Split(trimmed, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		line := strings.TrimSpace(lines[i])
		if line == "" {
			continue
		}
		if jsonLineRegex.MatchString(line) {
			match := jsonLineRegex.FindString(line)
			var data map[string]any
			if err := json.Unmarshal([]byte(match), &data); err == nil {
				buildID, _ := data["build_id"].(string)
				version, _ := data["version"].(string)
				return buildID, version
			}
		}
		break
	}

	buildID := ""
	version := ""

	if match := buildIDRegex.FindStringSubmatch(output); len(match) > 1 {
		buildID = match[1]
	}

	if match := versionRegex.FindStringSubmatch(output); len(match) > 1 {
		version = match[1]
	}

	return buildID, version
}
