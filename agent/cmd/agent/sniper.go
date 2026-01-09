package main

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var (
	buildIDRegex  = regexp.MustCompile(`(?i)build(?:[_\s-]?id)?[:\s]+([0-9]+)`)
	versionRegex  = regexp.MustCompile(`(?i)version[:\s]+([0-9a-zA-Z._-]+)`)
	jsonLineRegex = regexp.MustCompile(`\{.*\}`)
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
	targetBuildID := payloadValue(job.Payload, "target_build_id", "locked_build_id")
	targetVersion := payloadValue(job.Payload, "target_version", "locked_version")
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
		baseDir = "/srv/gameservers"
	}

	osUsername := buildInstanceUsername(customerID, instanceID)
	instanceDir := fmt.Sprintf("%s/%s", strings.TrimRight(baseDir, "/"), osUsername)

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

	output, err := runCommandOutput("su", "-s", "/bin/sh", "-c", fmt.Sprintf("cd %s && %s", instanceDir, command), osUsername)
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
		"+login", "anonymous",
		"+force_install_dir", instanceDir,
		"+app_update", steamAppID,
	}
	if validate {
		parts = append(parts, "validate")
	}
	parts = append(parts, "+quit")
	return strings.Join(parts, " ")
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
