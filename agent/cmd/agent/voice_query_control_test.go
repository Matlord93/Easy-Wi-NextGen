package main

import (
	"testing"
	"time"
)

func TestVoiceGovernorTimeoutPatternEntersCooldown(t *testing.T) {
	gov := newVoiceQueryGovernor()
	gov.cfg.hardCooldown = 5 * time.Minute
	gov.cfg.timeoutCooldownThreshold = 2
	key := "node-a:10011:user"

	gov.finish(key, &voiceQueryError{Code: "voice_query_timeout", Retryable: true}, "corr-1", 100)
	if _, err := gov.acquire(key, gov.nowFn()); err == nil {
		t.Fatalf("expected backoff after first timeout")
	}

	// bypass backoff by moving its timestamp into the past and trigger a second timeout
	gov.mu.Lock()
	gov.targets[key].backoffUntil = gov.nowFn().Add(-time.Second)
	gov.mu.Unlock()
	gov.finish(key, &voiceQueryError{Code: "voice_query_timeout", Retryable: true}, "corr-2", 110)

	retryAfter, err := gov.acquire(key, gov.nowFn())
	if err == nil {
		t.Fatalf("expected cooldown after timeout pattern")
	}
	if retryAfter <= 0 {
		t.Fatalf("expected positive retry_after for cooldown, got %d", retryAfter)
	}

	gov.mu.Lock()
	banEvents := gov.stats.banEvents
	gov.mu.Unlock()
	if banEvents != 1 {
		t.Fatalf("expected one cooldown event, got %d", banEvents)
	}
}
