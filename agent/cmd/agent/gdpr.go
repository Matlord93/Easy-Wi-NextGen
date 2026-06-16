package main

import (
	"time"

	"easywi/agent/internal/jobs"
)

func handleGdprAnonymizeUser(job jobs.Job) (jobs.Result, func() error) {
	return jobs.Result{
		JobID:     job.ID,
		Status:    "failed",
		Output:    map[string]string{"error": "gdpr.anonymize_user is not implemented on this agent; handle deletion in the control plane"},
		Completed: time.Now().UTC(),
	}, nil
}
