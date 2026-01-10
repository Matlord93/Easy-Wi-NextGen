package main

import (
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var (
	buildIDRegex         = regexp.MustCompile(`(?i)build(?:[_\s-]?id)?[:\s]+([0-9]+)`)
	versionRegex         = regexp.MustCompile(`(?i)version[:\s]+([0-9a-zA-Z._-]+)`)
	jsonLineRegex        = regexp.MustCompile(`\{.*\}`)
	forceInstallDirRegex = regexp.MustCompile(`(?i)(\+force_install_dir\s+)(\.(?:/)?|"\."|'\.'|"\./"|'\./')`)
)

func handleSniperInstall(job jobs.Job) (jobs.Result, func() error) {
	return handleSniperAction(job, "install")
}

func handleSniperUpdate(job jobs.Job) (jobs.Result, func() error) {
	return handleSniperAction(job, "update")
}

func handleSniperAction(job jobs.Job, action string) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	steamAppID := payloadValue(job.Payload, "steam_app_id")
	installCommand := payloadValue(job.Payload, "install_command")
	updateCommand := payloadValue(job.Payload, "update_command")
	baseDir := payloadValue(job.Payload, "base_dir")

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
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

	osUsername := buildInstanceUsername(customerID, instanceID)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, baseDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureBaseDir(baseDir); err != nil {
		return failureResult(job.ID, err)
	}
	instanceDir := fmt.Sprintf("%s/%s", strings.TrimRight(baseDir, "/"), osUsername)
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

	var command string
	if action == "install" {
		command = installCommand
	} else {
		command = updateCommand
	}

	if command == "" {
		command = buildSteamCmdCommand(instanceDir, steamAppID, action == "install")
	}

	if command == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "no install or update command configured"},
			Completed: time.Now().UTC(),
		}, nil
	}

	command = normalizeSteamCmdInstallDir(command, instanceDir)

	shellCmd := fmt.Sprintf(
	"export HOME=%[1]s; export XDG_DATA_HOME=%[1]s/.local/share; "+
	"mkdir -p %[1]s/.steam %[1]s/.local/share; "+
	"cd %[1]s && %[2]s",
	instanceDir, command,
)

output, err := runCommandOutput(
	"su",
	"-", osUsername,              // Login-Session => korrektes Umfeld
	"-s", "/bin/sh",
	"-c", shellCmd,
)
	if err != nil {
		return failureResult(job.ID, err)
	}

	buildID, version := extractBuildInfo(output)
	resultOutput := map[string]string{
		"message": "sniper " + action + " completed",
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

func buildSteamCmdCommand(instanceDir, steamAppID string, validate bool) string {
	if steamAppID == "" {
		return ""
	}
	parts := []string{
		"steamcmd",
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
