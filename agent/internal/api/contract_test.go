package api

import (
	"os"
	"strings"
	"testing"
)

func TestCoreAgentSpecContainsClientEndpoints(t *testing.T) {
	raw, err := os.ReadFile("../../../docs/api/core-agent.v1.openapi.yaml")
	if err != nil {
		t.Fatalf("read spec: %v", err)
	}
	spec := string(raw)

	expectedPaths := []string{
		"/api/v1/agent/bootstrap:",
		"/api/v1/agent/register:",
		"/api/v1/agent/heartbeat:",
		"/api/v1/agent/metrics-batch:",
		"/api/v1/agent/jobs:",
		"/api/v1/agent/jobs/{id}/start:",
		"/api/v1/agent/jobs/{id}/result:",
		"/api/v1/agent/jobs/{id}/logs:",
	}
	for _, path := range expectedPaths {
		if !strings.Contains(spec, path) {
			t.Fatalf("spec does not contain endpoint %s", path)
		}
	}
}

func TestCoreAgentSpecDocumentsErrorResponsesForClientEndpoints(t *testing.T) {
	raw, err := os.ReadFile("../../../docs/api/core-agent.v1.openapi.yaml")
	if err != nil {
		t.Fatalf("read spec: %v", err)
	}
	spec := string(raw)

	for _, code := range []string{"'400':", "'401':", "'403':", "'409':"} {
		if !strings.Contains(spec, code) {
			t.Fatalf("expected at least one %s error response in spec", code)
		}
	}

	if !strings.Contains(spec, "required: [register_url, register_token, agent_id]") {
		t.Fatalf("bootstrap response schema is missing required registration fields")
	}

	if !strings.Contains(spec, "required: [code, message, request_id]") {
		t.Fatalf("error envelope schema must require code, message and request_id")
	}

	if !strings.Contains(spec, "Idempotency-Key") {
		t.Fatalf("spec must document Idempotency-Key header")
	}
	if !strings.Contains(spec, "Retry-After") {
		t.Fatalf("spec must document Retry-After behavior")
	}
	for _, code := range []string{"INVALID_JSON", "VALIDATION_FAILED", "UNAUTHORIZED", "INTERNAL_ERROR"} {
		if !strings.Contains(spec, code) {
			t.Fatalf("expected error code %s in spec", code)
		}
	}
}
