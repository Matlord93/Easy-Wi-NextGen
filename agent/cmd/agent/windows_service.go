package main

import (
	"fmt"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleWindowsServiceStart(job jobs.Job) (jobs.Result, func() error) {
	return handleWindowsServiceAction(job, "start")
}

func handleWindowsServiceStop(job jobs.Job) (jobs.Result, func() error) {
	return handleWindowsServiceAction(job, "stop")
}

func handleWindowsServiceRestart(job jobs.Job) (jobs.Result, func() error) {
	return handleWindowsServiceAction(job, "restart")
}

func handleWindowsServiceAction(job jobs.Job, action string) (jobs.Result, func() error) {
	if runtime.GOOS != "windows" {
		return failureResult(job.ID, fmt.Errorf("windows service control is only supported on Windows agents"))
	}

	serviceName := payloadValue(job.Payload, "service_name", "name")
	if serviceName == "" {
		return failureResult(job.ID, fmt.Errorf("missing service_name"))
	}

	var outputLines []string
	switch action {
	case "start", "stop":
		output, err := runCommandOutput("sc", action, serviceName)
		outputLines = append(outputLines, output)
		if err != nil {
			return failureResult(job.ID, err)
		}
	case "restart":
		stopOutput, err := runCommandOutput("sc", "stop", serviceName)
		outputLines = append(outputLines, stopOutput)
		if err != nil {
			return failureResult(job.ID, err)
		}
		startOutput, err := runCommandOutput("sc", "start", serviceName)
		outputLines = append(outputLines, startOutput)
		if err != nil {
			return failureResult(job.ID, err)
		}
	default:
		return failureResult(job.ID, fmt.Errorf("unsupported action %q", action))
	}

	statusOutput, err := runCommandOutput("sc", "query", serviceName)
	if err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := map[string]string{
		"service_name":   serviceName,
		"service_status": parseWindowsServiceState(statusOutput),
		"service_query":  trimOutput(statusOutput, 4000),
	}
	if len(outputLines) > 0 {
		diagnostics["service_action_output"] = trimOutput(strings.Join(outputLines, "\n"), 4000)
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func parseWindowsServiceState(output string) string {
	for _, line := range strings.Split(output, "\n") {
		if !strings.Contains(line, "STATE") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) == 0 {
			continue
		}
		return fields[len(fields)-1]
	}
	return "unknown"
}
