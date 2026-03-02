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
	type smokeState struct {
		sync.Mutex
		result  jobs.Result
		polled  bool
		started bool
	}
	state := &smokeState{}

	jobStarted := make(chan struct{}, 1)
	jobResult := make(chan jobs.Result, 1)
	signal := func(ch chan struct{}) {
		select {
		case ch <- struct{}{}:
		default:
		}
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/agent/heartbeat", func(w http.ResponseWriter, _ *http.Request) {
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
		if !state.started {
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
		signal(jobStarted)
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	mux.HandleFunc("/agent/jobs/smoke-job-1/result", func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if err := r.Body.Close(); err != nil {
				t.Errorf("close request body: %v", err)
			}
		}()
		var result jobs.Result
		if err := json.NewDecoder(r.Body).Decode(&result); err != nil {
			t.Fatalf("decode result payload: %v", err)
		}
		state.Lock()
		state.result = result
		state.Unlock()
		select {
		case jobResult <- result:
		default:
		}
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

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	go run(ctx, client, cfg, "", logging.NewJSONLogger(io.Discard, "agent", cfg.AgentID))

	waitFor := func(ch <-chan struct{}, description string) {
		t.Helper()
		select {
		case <-ch:
		case <-ctx.Done():
			t.Fatalf("timeout waiting for %s", description)
		}
	}

	waitFor(jobStarted, "dummy job to be started")

	var result jobs.Result
	select {
	case result = <-jobResult:
	case <-ctx.Done():
		t.Fatal("timeout waiting for dummy job result")
	}
	if result.JobID != "smoke-job-1" {
		t.Fatalf("expected result for smoke-job-1, got %q", result.JobID)
	}
	if strings.ToLower(result.Status) != "success" {
		t.Fatalf("expected successful dummy job result, got %q", result.Status)
	}
}
