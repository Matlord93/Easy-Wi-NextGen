package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"easywi/agent/internal/api"
	"easywi/agent/internal/config"
	"easywi/agent/internal/jobs"
	"easywi/agent/internal/logging"
)

func TestAgentCoreConnectivitySmoke(t *testing.T) {
	t.Parallel()

	type smokeState struct {
		sync.Mutex
		heartbeats int
		started    bool
		result     jobs.Result
		polled     bool
	}
	state := &smokeState{}

	mux := http.NewServeMux()
	mux.HandleFunc("/agent/heartbeat", func(w http.ResponseWriter, _ *http.Request) {
		state.Lock()
		state.heartbeats++
		state.Unlock()
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	mux.HandleFunc("/agent/metrics-batch", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	mux.HandleFunc("/agent/jobs", func(w http.ResponseWriter, _ *http.Request) {
		state.Lock()
		defer state.Unlock()
		w.Header().Set("Content-Type", "application/json")
		if !state.polled {
			state.polled = true
			_, _ = w.Write([]byte(`{"jobs":[{"id":"smoke-job-1","type":"agent.diagnostics","payload":{}}],"max_concurrency":1}`))
			return
		}
		_, _ = w.Write([]byte(`{"jobs":[],"max_concurrency":1}`))
	})
	mux.HandleFunc("/agent/jobs/smoke-job-1/start", func(w http.ResponseWriter, _ *http.Request) {
		state.Lock()
		state.started = true
		state.Unlock()
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	mux.HandleFunc("/agent/jobs/smoke-job-1/result", func(w http.ResponseWriter, r *http.Request) {
		defer r.Body.Close()
		var result jobs.Result
		if err := json.NewDecoder(r.Body).Decode(&result); err != nil {
			t.Fatalf("decode result payload: %v", err)
		}
		state.Lock()
		state.result = result
		state.Unlock()
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	mux.HandleFunc("/agent/smoke-agent/jobs", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"jobs":[],"max_concurrency":1}`))
	})

	server := httptest.NewServer(mux)
	defer server.Close()

	client, err := api.NewClient(server.URL, "smoke-agent", "smoke-secret", "test")
	if err != nil {
		t.Fatalf("create api client: %v", err)
	}

	cfg := config.Config{
		AgentID:           "smoke-agent",
		Secret:            "smoke-secret",
		APIURL:            server.URL,
		Version:           "test",
		PollInterval:      25 * time.Millisecond,
		HeartbeatInterval: 45 * time.Millisecond,
		MaxConcurrency:    1,
		ServiceListen:     "disabled",
	}

	ctx, cancel := context.WithTimeout(context.Background(), 250*time.Millisecond)
	defer cancel()

	run(ctx, client, cfg, "", logging.NewJSONLogger(io.Discard, "agent", cfg.AgentID))

	state.Lock()
	defer state.Unlock()
	if state.heartbeats == 0 {
		t.Fatal("expected at least one heartbeat to be sent")
	}
	if !state.started {
		t.Fatal("expected dummy job to be started")
	}
	if state.result.JobID != "smoke-job-1" {
		t.Fatalf("expected result for smoke-job-1, got %q", state.result.JobID)
	}
	if strings.ToLower(state.result.Status) != "success" {
		t.Fatalf("expected successful dummy job result, got %q", state.result.Status)
	}
}
