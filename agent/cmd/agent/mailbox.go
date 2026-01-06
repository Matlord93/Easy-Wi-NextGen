package main

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleMailboxCreate(job jobs.Job) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "email")
	passwordHash := payloadValue(job.Payload, "password_hash", "password")
	quotaValue := payloadValue(job.Payload, "quota_mb", "quota")
	enabledValue := payloadValue(job.Payload, "enabled", "active")

	missing := missingValues([]requiredValue{
		{key: "address", value: address},
		{key: "password_hash", value: passwordHash},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	quotaMB := normalizeMailboxQuota(quotaValue)
	enabled := normalizeMailboxEnabled(enabledValue, true)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":      address,
			"quota_mb":     strconv.Itoa(quotaMB),
			"enabled":      fmt.Sprintf("%t", enabled),
			"password_set": "hash",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxPasswordReset(job jobs.Job) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "email")
	passwordHash := payloadValue(job.Payload, "password_hash", "password")
	if address == "" || passwordHash == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address or password_hash"},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":      address,
			"password_set": "hash",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxQuotaUpdate(job jobs.Job) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "email")
	quotaValue := payloadValue(job.Payload, "quota_mb", "quota")
	if address == "" || quotaValue == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address or quota"},
			Completed: time.Now().UTC(),
		}, nil
	}

	quotaMB := normalizeMailboxQuota(quotaValue)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":  address,
			"quota_mb": strconv.Itoa(quotaMB),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxEnable(job jobs.Job) (jobs.Result, func() error) {
	return handleMailboxStatus(job, true)
}

func handleMailboxDisable(job jobs.Job) (jobs.Result, func() error) {
	return handleMailboxStatus(job, false)
}

func handleMailboxStatus(job jobs.Job, enabled bool) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "email")
	if address == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address"},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address": address,
			"enabled": fmt.Sprintf("%t", enabled),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func normalizeMailboxQuota(value string) int {
	if value == "" {
		return 0
	}
	parsed, err := strconv.Atoi(value)
	if err != nil || parsed < 0 {
		return 0
	}
	return parsed
}

func normalizeMailboxEnabled(value string, defaultValue bool) bool {
	if value == "" {
		return defaultValue
	}
	switch value {
	case "true", "1", "yes", "on":
		return true
	case "false", "0", "no", "off":
		return false
	default:
		return defaultValue
	}
}
