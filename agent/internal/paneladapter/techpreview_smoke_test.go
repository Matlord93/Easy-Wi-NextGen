package paneladapter

import (
	"context"
	"reflect"
	"testing"
)

func TestTechPreviewDiscoverCapabilities(t *testing.T) {
	adapter := TechPreviewAdapter{}
	caps, err := adapter.DiscoverCapabilities(context.Background(), Context{Panel: "tech-preview", Version: "0.x"})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	expected := []string{"ping", "account.describe"}
	if !reflect.DeepEqual(caps, expected) {
		t.Fatalf("unexpected capabilities: got=%v want=%v", caps, expected)
	}
}

func TestTechPreviewExecuteActionSmoke(t *testing.T) {
	adapter := TechPreviewAdapter{}
	out, err := adapter.ExecuteAction(context.Background(), "ping", map[string]any{"probe": "smoke"}, Context{Panel: "tech-preview", Version: "0.x"})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if got := out["status"]; got != "ok" {
		t.Fatalf("unexpected status: %v", got)
	}
}

func TestTechPreviewExecuteUnsupportedActionReturnsStandardizedError(t *testing.T) {
	adapter := TechPreviewAdapter{}
	_, err := adapter.ExecuteAction(context.Background(), "site.provision", nil, Context{Panel: "tech-preview", Version: "0.x"})
	if err == nil {
		t.Fatal("expected error")
	}
	if err.Code != ErrActionUnsupported {
		t.Fatalf("unexpected error code: %s", err.Code)
	}
	if err.Retryable {
		t.Fatal("unsupported action must not be retryable")
	}
}
