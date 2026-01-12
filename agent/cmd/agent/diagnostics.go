package main

import (
	"runtime"
	"time"

	"easywi/agent/internal/jobs"
)

func handleAgentDiagnostics(job jobs.Job) (jobs.Result, func() error) {
	output := map[string]string{
		"os":         runtime.GOOS,
		"arch":       runtime.GOARCH,
		"go_version": runtime.Version(),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}
