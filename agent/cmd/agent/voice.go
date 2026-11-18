package main

import (
	"context"
	"log"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var defaultVoiceGovernor = newVoiceQueryGovernor()

func handleVoiceProbe(job jobs.Job) (jobs.Result, func() error) {
	provider := strings.ToLower(payloadValue(job.Payload, "provider_type"))
	if provider == "" {
		provider = "unknown"
	}

	if runtime.GOOS == "windows" {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message":       "voice probes are not supported on windows",
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

	correlationID := payloadValue(job.Payload, "correlation_id", "request_id", "trace_id")
	if correlationID == "" {
		correlationID = "voice-" + job.ID
	}
	target := voiceTarget{
		provider: provider,
		host:     payloadValue(job.Payload, "query_host", "host"),
		port:     payloadValue(job.Payload, "query_port", "port"),
		user:     payloadValue(job.Payload, "query_user", "user"),
		endpoint: payloadValue(job.Payload, "query_endpoint"),
	}
	if target.host == "" {
		target.host = "unknown"
	}
	if target.port == "" {
		target.port = "0"
	}
	result, err := defaultVoiceGovernor.queryStatus(context.Background(), target, correlationID)
	if err != nil {
		queryErr, _ := err.(*voiceQueryError)
		retryAfter := 0
		errorCode := "voice_query_failed"
		if queryErr != nil {
			retryAfter = queryErr.RetryAfter
			if queryErr.Code != "" {
				errorCode = queryErr.Code
			}
		}
		out := map[string]string{
			"status":         "unknown",
			"provider_type":  provider,
			"message":        err.Error(),
			"error_code":     errorCode,
			"correlation_id": correlationID,
		}
		if retryAfter > 0 {
			out["retry_after"] = strconv.Itoa(retryAfter)
		}
		for k, v := range defaultVoiceGovernor.snapshot() {
			out["metric_"+k] = v
		}
		log.Printf("voice.query.failed job_id=%s correlation_id=%s error_code=%s message=%q", job.ID, correlationID, errorCode, err.Error())
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}
	result["correlation_id"] = correlationID
	for k, v := range defaultVoiceGovernor.snapshot() {
		result["metric_"+k] = v
	}
	log.Printf("voice.query.ok job_id=%s correlation_id=%s target=%s:%s provider=%s", job.ID, correlationID, target.host, target.port, provider)
	return jobs.Result{JobID: job.ID, Status: "success", Output: result, Completed: time.Now().UTC()}, nil
}

func handleVoiceAction(job jobs.Job, action string) (jobs.Result, func() error) {
	provider := strings.ToLower(payloadValue(job.Payload, "provider_type"))
	if provider == "" {
		provider = "unknown"
	}

	if provider != "ts3" && provider != "ts6" {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message":       "voice action provider is not supported",
			"error_code":    "voice_query_failed",
			"provider_type": provider,
			"action":        action,
		}, Completed: time.Now().UTC()}, nil
	}

	// Legacy voice.action jobs must execute the same implementation as the panel's TS jobs.
	// Incomplete old payloads fail rather than reporting a successful no-op.
	sid, err := strconv.Atoi(payloadValue(job.Payload, "sid"))
	if err != nil || sid <= 0 || (action != "start" && action != "stop" && action != "restart") {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
			"message": "missing server binding or invalid action", "error_code": "voice_action_invalid",
		}, Completed: time.Now().UTC()}, nil
	}
	payload := make(map[string]any, len(job.Payload)+1)
	for key, value := range job.Payload {
		payload[key] = value
	}
	payload["action"] = action
	job.Payload = payload
	var result orchestratorResult
	if provider == "ts3" {
		result = handleTs3VirtualAction(job)
	} else {
		result = handleTs6VirtualAction(job)
	}
	output := map[string]string{"action": action, "provider_type": provider}
	if result.status == "success" {
		output["status"] = "ok"
	} else {
		output["message"] = result.errorText
		output["error_code"] = "voice_action_failed"
	}
	return jobs.Result{JobID: job.ID, Status: result.status, Output: output, Completed: time.Now().UTC()}, nil
}
