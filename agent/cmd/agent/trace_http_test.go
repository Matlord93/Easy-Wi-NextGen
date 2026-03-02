package main

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"easywi/agent/internal/trace"
)

func TestWithTraceContextSetsHeaders(t *testing.T) {
	h := withTraceContext(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get(trace.RequestHeader) == "" {
			t.Fatalf("missing request id")
		}
		if r.Header.Get(trace.CorrelationHeader) == "" {
			t.Fatalf("missing correlation id")
		}
		w.WriteHeader(http.StatusNoContent)
	}))

	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, req)

	if rr.Header().Get(trace.RequestHeader) == "" {
		t.Fatalf("response missing request id")
	}
	if rr.Header().Get(trace.CorrelationHeader) == "" {
		t.Fatalf("response missing correlation id")
	}
}
