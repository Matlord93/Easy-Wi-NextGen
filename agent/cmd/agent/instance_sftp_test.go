package main

import (
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleInstanceSftpCredentialsResetMissingValues(t *testing.T) {
	job := jobs.Job{
		ID:      "job-1",
		Payload: map[string]any{},
	}

	result, _ := handleInstanceSftpCredentialsReset(job)
	if result.Status != "failed" {
		t.Fatalf("expected failed status, got %s", result.Status)
	}
	if result.Output["error_code"] != "INVALID_INPUT" {
		t.Fatalf("expected error_code INVALID_INPUT, got %v", result.Output["error_code"])
	}
}
