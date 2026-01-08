package main

import (
	"time"

	"easywi/agent/internal/jobs"
)

func handleGdprAnonymizeUser(job jobs.Job) (jobs.Result, func() error) {
	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"status": "queued"},
		Completed: time.Now().UTC(),
	}, nil
}
