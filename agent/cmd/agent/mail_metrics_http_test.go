package main

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"easywi/agent/internal/mail/telemetry"
)

type fakeCollector struct {
	snapshot telemetry.Snapshot
	err      error
}

func (f fakeCollector) Collect(_ context.Context) (telemetry.Snapshot, error) {
	if f.err != nil {
		return telemetry.Snapshot{}, f.err
	}
	return f.snapshot, nil
}

func TestHandleMailMetricsHTTPSuccess(t *testing.T) {
	old := mailMetricsCollector
	defer func() { mailMetricsCollector = old }()
	mailMetricsCollector = fakeCollector{snapshot: telemetry.Snapshot{GeneratedAt: time.Now().UTC(), NodeID: "n1"}}

	r := httptest.NewRequest(http.MethodGet, "/v1/agent/mail/metrics", nil)
	w := httptest.NewRecorder()
	handleMailMetricsHTTP(w, r)
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200 got %d", w.Code)
	}
}

func TestHandleMailMetricsHTTPFailure(t *testing.T) {
	old := mailMetricsCollector
	defer func() { mailMetricsCollector = old }()
	mailMetricsCollector = fakeCollector{err: errors.New("collector down")}

	r := httptest.NewRequest(http.MethodGet, "/v1/agent/mail/metrics", nil)
	w := httptest.NewRecorder()
	handleMailMetricsHTTP(w, r)
	if w.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503 got %d", w.Code)
	}
}
