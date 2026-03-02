package logging

import (
	"bytes"
	"context"
	"encoding/json"
	"testing"

	"easywi/agent/internal/trace"
)

func TestJSONLoggerIncludesRequiredSchemaFields(t *testing.T) {
	buf := &bytes.Buffer{}
	logger := NewJSONLogger(buf, "agent", "agent-42")

	ctx := trace.WithIDs(context.Background(), "", "")
	logger.Info(ctx, "heartbeat.sent", "heartbeat submitted", map[string]any{"attempt": 1})

	var payload map[string]any
	if err := json.Unmarshal(buf.Bytes(), &payload); err != nil {
		t.Fatalf("unmarshal payload: %v", err)
	}

	required := []string{"level", "service", "timestamp", "request_id", "correlation_id", "agent_id", "event", "error_code", "msg"}
	for _, key := range required {
		value, ok := payload[key]
		if !ok {
			t.Fatalf("missing required key %s", key)
		}
		if key != "error_code" && value == "" {
			t.Fatalf("required key %s should not be empty", key)
		}
	}

	fields, ok := payload["fields"].(map[string]any)
	if !ok || fields["attempt"] != float64(1) {
		t.Fatalf("expected fields.attempt=1, got %#v", payload["fields"])
	}
}
