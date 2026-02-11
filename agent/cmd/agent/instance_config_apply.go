package main

import (
	"runtime"
	"time"

	"easywi/agent/internal/jobs"
)

func handleInstanceConfigApply(job jobs.Job) (jobs.Result, func() error) {
	applyMode := payloadValue(job.Payload, "apply_mode")
	if applyMode == "" {
		applyMode = "restart"
	}

	output := map[string]string{
		"message":    "Config apply acknowledged.",
		"apply_mode": applyMode,
		"supported":  "true",
	}

	if runtime.GOOS == "windows" {
		output["supported"] = "false"
		output["message"] = "Config apply is not supported on windows agent yet (no-op)."
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}
