package api

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func TestDoWithRetryRetriesOn429AndRespectsRetryAfter(t *testing.T) {
	var hits int32
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if atomic.AddInt32(&hits, 1) == 1 {
			w.Header().Set("Retry-After", "0")
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer ts.Close()

	policy := DefaultRetryPolicy()
	policy.BaseBackoff = 1 * time.Millisecond
	policy.MaxBackoff = 2 * time.Millisecond
	resp, err := doWithRetry(context.Background(), ts.Client(), policy, &circuitBreaker{}, RetryClassSafe, func() (*http.Request, error) {
		return http.NewRequest(http.MethodGet, ts.URL, nil)
	})
	if err != nil {
		t.Fatalf("doWithRetry returned error: %v", err)
	}
	_ = resp.Body.Close()
	if got := atomic.LoadInt32(&hits); got != 2 {
		t.Fatalf("expected 2 attempts, got %d", got)
	}
}

func TestDoWithRetryRetriesOn5xx(t *testing.T) {
	var hits int32
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if atomic.AddInt32(&hits, 1) < 3 {
			w.WriteHeader(http.StatusBadGateway)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer ts.Close()

	policy := DefaultRetryPolicy()
	policy.BaseBackoff = time.Millisecond
	policy.MaxBackoff = 2 * time.Millisecond
	policy.MaxAttempts = 4
	resp, err := doWithRetry(context.Background(), ts.Client(), policy, &circuitBreaker{}, RetryClassSafe, func() (*http.Request, error) {
		return http.NewRequest(http.MethodGet, ts.URL, nil)
	})
	if err != nil {
		t.Fatalf("doWithRetry returned error: %v", err)
	}
	_ = resp.Body.Close()
	if got := atomic.LoadInt32(&hits); got != 3 {
		t.Fatalf("expected 3 attempts, got %d", got)
	}
}

func TestDoWithRetryRetriesOnTimeout(t *testing.T) {
	var hits int32
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if atomic.AddInt32(&hits, 1) == 1 {
			time.Sleep(120 * time.Millisecond)
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer ts.Close()

	policy := DefaultRetryPolicy()
	policy.RequestTimeout = 50 * time.Millisecond
	policy.BaseBackoff = 1 * time.Millisecond
	policy.MaxBackoff = 2 * time.Millisecond
	client := NewRetryHTTPClient(policy)
	resp, err := doWithRetry(context.Background(), client, policy, &circuitBreaker{}, RetryClassSafe, func() (*http.Request, error) {
		return http.NewRequest(http.MethodGet, ts.URL, nil)
	})
	if err != nil {
		t.Fatalf("doWithRetry returned error: %v", err)
	}
	_ = resp.Body.Close()
	if got := atomic.LoadInt32(&hits); got != 2 {
		t.Fatalf("expected 2 attempts, got %d", got)
	}
}
