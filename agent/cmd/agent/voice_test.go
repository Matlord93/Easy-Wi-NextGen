package main

import (
	"runtime"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleVoiceProbeUnsupportedProvider(t *testing.T) {
	job := jobs.Job{ID: "job-1", Payload: map[string]any{"provider_type": "custom"}}
	result, _ := handleVoiceProbe(job)

	if runtime.GOOS == "windows" {
		if result.Output["error_code"] != "voice_unsupported_os" {
			t.Fatalf("expected voice_unsupported_os on windows, got %q", result.Output["error_code"])
		}
		return
	}

	if result.Status != "failed" {
		t.Fatalf("expected failed status, got %q", result.Status)
	}
	if result.Output["error_code"] != "voice_query_failed" {
		t.Fatalf("expected voice_query_failed, got %q", result.Output["error_code"])
	}
}
