package api

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"easywi/agent/internal/trace"
)

func TestSendHeartbeatPropagatesTraceHeaders(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get(trace.RequestHeader) != "77a96e16-ab58-4f39-a8b0-df57f12983ea" {
			t.Fatalf("request header missing")
		}
		if r.Header.Get(trace.CorrelationHeader) != "0fed9f91-d67f-4f85-a640-4b44fd4ad6ae" {
			t.Fatalf("correlation header missing")
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"ok": true})
	}))
	defer server.Close()

	client, err := NewClient(server.URL, "agent-1", "secret", "test")
	if err != nil {
		t.Fatalf("new client: %v", err)
	}

	ctx := trace.WithIDs(context.Background(), "77a96e16-ab58-4f39-a8b0-df57f12983ea", "0fed9f91-d67f-4f85-a640-4b44fd4ad6ae")
	err = client.SendHeartbeat(ctx, map[string]any{"cpu": 1}, nil, nil, "online")
	if err != nil {
		t.Fatalf("send heartbeat: %v", err)
	}
}

func TestMutatingCallsSetIdempotencyKey(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodPost && r.Header.Get("Idempotency-Key") == "" {
			t.Fatalf("idempotency key missing")
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	}))
	defer server.Close()

	client, err := NewClient(server.URL, "agent-1", "secret", "test")
	if err != nil {
		t.Fatalf("new client: %v", err)
	}
	if err := client.SendHeartbeat(context.Background(), map[string]any{"cpu": 1}, nil, nil, "online"); err != nil {
		t.Fatalf("send heartbeat: %v", err)
	}
}
