package main

import (
	"testing"

	"easywi/agent/internal/jobs"
)

func TestMailBackendGuardBlocksDisabledBackend(t *testing.T) {
	out, ok := mailBackendGuard(jobs.Job{Payload: map[string]any{"mail_enabled": "false", "mail_backend": "none"}})
	if ok {
		t.Fatal("expected guard to block")
	}
	if out["error_code"] != "MAIL_BACKEND_DISABLED" {
		t.Fatalf("expected MAIL_BACKEND_DISABLED, got %q", out["error_code"])
	}
}

func TestMailBackendGuardAllowsLocalBackend(t *testing.T) {
	_, ok := mailBackendGuard(jobs.Job{Payload: map[string]any{"mail_enabled": "true", "mail_backend": "local"}})
	if !ok {
		t.Fatal("expected local backend to pass")
	}
}

func TestMailBackendGuardDefaultsToEnabledLocal(t *testing.T) {
	_, ok := mailBackendGuard(jobs.Job{Payload: map[string]any{}})
	if !ok {
		t.Fatal("expected defaults to pass")
	}
}

func TestMailBackendGuardRejectsInvalidBackend(t *testing.T) {
	out, ok := mailBackendGuard(jobs.Job{Payload: map[string]any{"mail_backend": "invalid"}})
	if ok {
		t.Fatal("expected invalid backend to fail")
	}
	if out["error_code"] != "MAIL_BACKEND_INVALID" {
		t.Fatalf("expected MAIL_BACKEND_INVALID, got %q", out["error_code"])
	}
}
