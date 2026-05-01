package main

import (
	"fmt"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

// handleInstanceWatchdogCheck checks whether the game server service is active.
// If it is not running it restarts it (up to max_restarts attempts).
// The job is dispatched by the panel on a configurable schedule.
func handleInstanceWatchdogCheck(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return handleInstanceWatchdogCheckWindows(job, logSender)
	}

	instanceID := payloadValue(job.Payload, "instance_id")
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" && instanceID != "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	if instanceID == "" {
		return failureResult(job.ID, fmt.Errorf("missing required value: instance_id"))
	}

	maxRestarts := 3
	if v, ok := job.Payload["max_restarts"]; ok {
		if n, ok2 := v.(float64); ok2 && n >= 0 {
			maxRestarts = int(n)
		}
	}

	statusOutput, err := runCommandOutput("systemctl", "is-active", serviceName)
	status := strings.TrimSpace(statusOutput)

	diagnostics := map[string]string{
		"instance_id":  instanceID,
		"service_name": serviceName,
		"status_pre":   status,
	}

	if err == nil && status == "active" {
		diagnostics["action"] = "none"
		diagnostics["message"] = "service is running"
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    diagnostics,
			Completed: time.Now().UTC(),
		}, nil
	}

	// Service is not active — attempt restart(s).
	var lastRestartErr error
	for attempt := 1; attempt <= maxRestarts; attempt++ {
		sendWatchdogLog(job.ID, logSender, fmt.Sprintf("watchdog: restart attempt %d/%d for %s", attempt, maxRestarts, serviceName))

		if restartErr := runCommand("systemctl", "restart", serviceName); restartErr != nil {
			lastRestartErr = restartErr
			time.Sleep(2 * time.Second)
			continue
		}

		if activeErr := ensureServiceActive(serviceName); activeErr != nil {
			lastRestartErr = activeErr
			time.Sleep(2 * time.Second)
			continue
		}

		// Restart succeeded.
		streamServiceLogs(job.ID, logSender, serviceName, 5*time.Second)

		diagnostics["action"] = "restarted"
		diagnostics["restart_attempts"] = fmt.Sprintf("%d", attempt)
		diagnostics["status_post"] = "active"
		diagnostics["message"] = fmt.Sprintf("service restarted after %d attempt(s)", attempt)

		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    diagnostics,
			Completed: time.Now().UTC(),
		}, nil
	}

	diagnostics["action"] = "restart_failed"
	diagnostics["restart_attempts"] = fmt.Sprintf("%d", maxRestarts)
	if lastRestartErr != nil {
		diagnostics["error"] = lastRestartErr.Error()
	}

	return failureResult(job.ID, fmt.Errorf("watchdog: service %s could not be restarted after %d attempt(s): %w", serviceName, maxRestarts, lastRestartErr))
}

// handleInstanceWatchdogCheckWindows is the Windows equivalent.
func handleInstanceWatchdogCheckWindows(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" && instanceID != "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	if instanceID == "" {
		return failureResult(job.ID, fmt.Errorf("missing required value: instance_id"))
	}

	maxRestarts := 3
	if v, ok := job.Payload["max_restarts"]; ok {
		if n, ok2 := v.(float64); ok2 && n >= 0 {
			maxRestarts = int(n)
		}
	}

	statusOutput, err := runCommandOutput("sc", "query", serviceName)
	running := err == nil && strings.Contains(strings.ToUpper(statusOutput), "RUNNING")

	diagnostics := map[string]string{
		"instance_id":  instanceID,
		"service_name": serviceName,
		"status_pre":   statusOutput,
	}

	if running {
		diagnostics["action"] = "none"
		diagnostics["message"] = "service is running"
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    diagnostics,
			Completed: time.Now().UTC(),
		}, nil
	}

	var lastRestartErr error
	for attempt := 1; attempt <= maxRestarts; attempt++ {
		sendWatchdogLog(job.ID, logSender, fmt.Sprintf("watchdog: restart attempt %d/%d for %s", attempt, maxRestarts, serviceName))

		if _, startErr := runCommandOutput("sc", "start", serviceName); startErr != nil {
			lastRestartErr = startErr
			time.Sleep(2 * time.Second)
			continue
		}

		time.Sleep(3 * time.Second)

		postStatus, _ := runCommandOutput("sc", "query", serviceName)
		if strings.Contains(strings.ToUpper(postStatus), "RUNNING") {
			diagnostics["action"] = "restarted"
			diagnostics["restart_attempts"] = fmt.Sprintf("%d", attempt)
			diagnostics["status_post"] = "running"
			diagnostics["message"] = fmt.Sprintf("service restarted after %d attempt(s)", attempt)
			return jobs.Result{
				JobID:     job.ID,
				Status:    "success",
				Output:    diagnostics,
				Completed: time.Now().UTC(),
			}, nil
		}

		lastRestartErr = fmt.Errorf("service did not reach RUNNING state after start")
		time.Sleep(2 * time.Second)
	}

	diagnostics["action"] = "restart_failed"
	diagnostics["restart_attempts"] = fmt.Sprintf("%d", maxRestarts)
	if lastRestartErr != nil {
		diagnostics["error"] = lastRestartErr.Error()
	}

	return failureResult(job.ID, fmt.Errorf("watchdog: service %s could not be restarted after %d attempt(s): %w", serviceName, maxRestarts, lastRestartErr))
}

func sendWatchdogLog(jobID string, logSender JobLogSender, msg string) {
	if logSender == nil {
		return
	}
	logSender.Send(jobID, []string{msg}, nil)
}
