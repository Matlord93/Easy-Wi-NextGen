package logstream

import (
	"testing"
	"time"
)

func TestParseLineClassifiesAndExtracts(t *testing.T) {
	e := ParseLine("postfix", "ABC123: to=<user@example.com>, status=bounced (host not found)", time.Unix(0, 0))
	if e.EventType != "bounce" {
		t.Fatalf("expected bounce, got %s", e.EventType)
	}
	if e.Level != "error" {
		t.Fatalf("expected error level, got %s", e.Level)
	}
	if e.Domain != "example.com" {
		t.Fatalf("expected domain example.com, got %s", e.Domain)
	}
}
