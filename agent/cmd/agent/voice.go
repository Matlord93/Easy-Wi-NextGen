package main

import (
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleVoiceProbe(job jobs.Job) (jobs.Result, func() error) {
	provider := strings.ToLower(payloadValue(job.Payload, "provider_type"))
	if provider == "" {
		provider = "unknown"
	}

	if runtime.GOOS == "windows" {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message":       "voice query is not supported on windows agents",
			"error_code":    "voice_unsupported_os",
			"provider_type": provider,
		}, Completed: time.Now().UTC()}, nil
	}

	if provider != "ts3" && provider != "ts6" {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message":       "voice query provider is not supported",
			"error_code":    "voice_query_failed",
			"provider_type": provider,
		}, Completed: time.Now().UTC()}, nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{
		"status":         "online",
		"players_online": "0",
		"players_max":    "0",
		"provider_type":  provider,
	}, Completed: time.Now().UTC()}, nil
}

func handleVoiceAction(job jobs.Job, action string) (jobs.Result, func() error) {
	provider := strings.ToLower(payloadValue(job.Payload, "provider_type"))
	if provider == "" {
		provider = "unknown"
	}

	if runtime.GOOS == "windows" {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message":       "voice actions are not supported on windows agents",
			"error_code":    "voice_unsupported_os",
			"provider_type": provider,
			"action":        action,
		}, Completed: time.Now().UTC()}, nil
	}

	if provider != "ts3" && provider != "ts6" {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message":       "voice action provider is not supported",
			"error_code":    "voice_query_failed",
			"provider_type": provider,
			"action":        action,
		}, Completed: time.Now().UTC()}, nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{
		"status":        "ok",
		"action":        action,
		"provider_type": provider,
	}, Completed: time.Now().UTC()}, nil
}
