package main

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"runtime"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestHandleVoiceProbeUnsupportedProvider(t *testing.T) {
	job := jobs.Job{ID: "job-1", Payload: map[string]any{"provider_type": "custom"}}
	result, _ := handleVoiceProbe(job)

	if runtime.GOOS == "windows" {
		if result.Output["error_code"] != "voice_unsupported_os" {
			t.Fatalf("expected voice_unsupported_os on windows, got %q", result.Output["error_code"])
		}
		return
	}

	if result.Status != "failed" {
		t.Fatalf("expected failed status, got %q", result.Status)
	}
	if result.Output["error_code"] != "voice_query_failed" {
		t.Fatalf("expected voice_query_failed, got %q", result.Output["error_code"])
	}
}

func TestVoiceProbeBanTriggersCooldown(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("voice probes disabled on windows")
	}

	var calls int64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt64(&calls, 1)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"error":"banned"}`))
	}))
	defer srv.Close()

	gov := newVoiceQueryGovernor()
	gov.cfg.hardCooldown = 2 * time.Second
	gov.cfg.statusCacheTTL = 0
	defaultVoiceGovernor = gov

	job := jobs.Job{ID: "job-ban", Payload: map[string]any{
		"provider_type":  "ts6",
		"query_host":     "127.0.0.1",
		"query_port":     "10011",
		"query_endpoint": srv.URL,
	}}
	first, _ := handleVoiceProbe(job)
	if first.Status != "failed" || first.Output["error_code"] != "voice_query_banned" {
		t.Fatalf("expected banned failure, got status=%s code=%s", first.Status, first.Output["error_code"])
	}
	second, _ := handleVoiceProbe(job)
	if second.Output["error_code"] != "voice_rate_limited" {
		t.Fatalf("expected cooldown to rate limit, got %s", second.Output["error_code"])
	}
	if atomic.LoadInt64(&calls) != 1 {
		t.Fatalf("expected exactly one upstream call during cooldown, got %d", calls)
	}
}

func TestVoiceProbeBanAfterNRequestsTriggersCooldown(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("voice probes disabled on windows")
	}

	var calls int64
	banAfter := int64(3)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		n := atomic.AddInt64(&calls, 1)
		w.Header().Set("Content-Type", "application/json")
		if n > banAfter {
			_, _ = w.Write([]byte(`{"error":"banned"}`))
			return
		}
		_, _ = w.Write([]byte(`{"status":"online","players_online":2,"players_max":16}`))
	}))
	defer srv.Close()

	gov := newVoiceQueryGovernor()
	gov.cfg.statusCacheTTL = 0
	gov.cfg.hardCooldown = time.Minute
	gov.cfg.burst = 10
	gov.cfg.ratePerSecond = 100
	defaultVoiceGovernor = gov

	job := jobs.Job{ID: "job-ban-n", Payload: map[string]any{
		"provider_type":  "ts6",
		"query_host":     "127.0.0.1",
		"query_port":     "10011",
		"query_endpoint": srv.URL,
	}}

	for i := 0; i < int(banAfter); i++ {
		res, _ := handleVoiceProbe(job)
		if res.Status != "success" {
			t.Fatalf("expected warmup request %d to succeed, got %s", i+1, res.Status)
		}
	}

	banned, _ := handleVoiceProbe(job)
	if banned.Output["error_code"] != "voice_query_banned" {
		t.Fatalf("expected ban error after threshold, got %s", banned.Output["error_code"])
	}

	cooldown, _ := handleVoiceProbe(job)
	if cooldown.Output["error_code"] != "voice_rate_limited" {
		t.Fatalf("expected cooldown request to be blocked, got %s", cooldown.Output["error_code"])
	}
	if atomic.LoadInt64(&calls) != banAfter+1 {
		t.Fatalf("expected no upstream call while cooling down, got %d", calls)
	}
}

func TestVoiceProbeSingleflightAndConcurrencyLimit(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("voice probes disabled on windows")
	}

	var calls int64
	release := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt64(&calls, 1)
		<-release
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"online","players_online":2,"players_max":16}`))
	}))
	defer srv.Close()

	gov := newVoiceQueryGovernor()
	gov.cfg.maxConcurrency = 1
	gov.cfg.statusCacheTTL = 0
	defaultVoiceGovernor = gov

	payload := map[string]any{"provider_type": "ts6", "query_host": "node-a", "query_port": "10011", "query_endpoint": srv.URL}
	wg := sync.WaitGroup{}
	results := make([]jobs.Result, 2)
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			results[idx], _ = handleVoiceProbe(jobs.Job{ID: fmt.Sprintf("job-%d", idx), Payload: payload})
		}(i)
	}
	time.Sleep(150 * time.Millisecond)
	close(release)
	wg.Wait()

	if atomic.LoadInt64(&calls) != 1 {
		t.Fatalf("expected coalesced single upstream call, got %d", calls)
	}
	if results[0].Status != "success" || results[1].Status != "success" {
		t.Fatalf("expected both requests to succeed, got %s and %s", results[0].Status, results[1].Status)
	}
}

func TestVoiceGovernorReadsNewEnvNames(t *testing.T) {
	t.Setenv("VOICE_QUERY_RPS", "7")
	t.Setenv("VOICE_QUERY_BURST", "5")
	t.Setenv("VOICE_QUERY_MAX_CONCURRENCY", "2")
	t.Setenv("VOICE_BAN_COOLDOWN", "11m")

	gov := newVoiceQueryGovernor()
	if gov.cfg.ratePerSecond != 7 {
		t.Fatalf("expected VOICE_QUERY_RPS to be used, got %f", gov.cfg.ratePerSecond)
	}
	if gov.cfg.burst != 5 {
		t.Fatalf("expected VOICE_QUERY_BURST to be used, got %f", gov.cfg.burst)
	}
	if gov.cfg.maxConcurrency != 2 {
		t.Fatalf("expected VOICE_QUERY_MAX_CONCURRENCY to be used, got %d", gov.cfg.maxConcurrency)
	}
	if gov.cfg.hardCooldown != 11*time.Minute {
		t.Fatalf("expected VOICE_BAN_COOLDOWN to be used, got %s", gov.cfg.hardCooldown)
	}
}
