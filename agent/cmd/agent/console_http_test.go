package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

type nopReadCloser struct{ io.Reader }

func (n nopReadCloser) Close() error { return nil }

func TestConsoleCursorNoDuplicatesAcrossReconnect(t *testing.T) {
	s := &consoleSession{sessionID: "sess1", buffer: []consoleLine{{ID: 1, Stream: "journal", Text: "one"}, {ID: 2, Stream: "journal", Text: "two"}}, running: true}
	first := s.snapshotAfterCursor("")
	if len(first.lines) != 2 {
		t.Fatalf("expected 2 lines, got %d", len(first.lines))
	}
	second := s.snapshotAfterCursor(first.cursor)
	if len(second.lines) != 0 {
		t.Fatalf("expected no duplicate lines, got %d", len(second.lines))
	}

	// Reconnect keeps session cursor semantics: no duplicates across polls.
	s.mu.Lock()
	s.restarts = 1
	s.nextID = 2
	s.buffer = append(s.buffer, consoleLine{ID: 3, Stream: "journal", Text: "three"})
	s.mu.Unlock()
	third := s.snapshotAfterCursor(first.cursor)
	if len(third.lines) != 1 {
		t.Fatalf("expected one new line after reconnect, got %d", len(third.lines))
	}
	if text, _ := third.lines[0]["text"].(string); text != "three" {
		t.Fatalf("expected newest line, got %q", text)
	}
}

func TestJournalReconnectEmitsSingleMetaEvent(t *testing.T) {
	origStart := startJournalStream
	defer func() { startJournalStream = origStart }()

	runs := 0
	startJournalStream = func(ctx context.Context, unitName string) (*startedJournalStream, error) {
		runs++
		if runs > 2 {
			<-ctx.Done()
			return nil, ctx.Err()
		}
		content := "line-a\n"
		if runs == 2 {
			content = "line-b\n"
		}
		return &startedJournalStream{
			stdout: nopReadCloser{strings.NewReader(content)},
			stderr: nopReadCloser{strings.NewReader("")},
			wait:   func() error { return fmt.Errorf("exit") },
		}, nil
	}

	s := &consoleSession{instanceID: "1", unitName: "gs-1", buffer: make([]consoleLine, 0, 10), sessionID: "sess1"}
	s.ctx, s.cancel = context.WithCancel(context.Background())
	go s.run()
	time.Sleep(2300 * time.Millisecond)
	s.cancel()
	time.Sleep(100 * time.Millisecond)

	metaCount := 0
	reconnectCount := 0
	for _, line := range s.buffer {
		if line.Stream != "meta" {
			continue
		}
		metaCount++
		if line.Text == "reconnected" {
			reconnectCount++
		}
	}
	if metaCount < 2 {
		t.Fatalf("expected at least connected+reconnected meta, got %d", metaCount)
	}
	if reconnectCount != 1 {
		t.Fatalf("expected exactly one reconnect meta event, got %d", reconnectCount)
	}
}

func TestConsoleCommandRedaction(t *testing.T) {
	cases := []string{
		"set password hunter2",
		"auth token abc123",
		"set api_key zzz",
		"my SECRET value",
	}
	for _, c := range cases {
		if got := redactCommandForMeta(c); got != "[redacted-command]" {
			t.Fatalf("expected redaction for %q, got %q", c, got)
		}
	}
	if got := redactCommandForMeta("say hello"); got != "say hello" {
		t.Fatalf("did not expect redaction, got %q", got)
	}
}

func TestConsoleCommandRateLimit(t *testing.T) {
	origWrite := writeConsoleCommand
	defer func() { writeConsoleCommand = origWrite }()
	writeConsoleCommand = func(socketPath, command string) error { return fmt.Errorf("permission denied") }

	httpConsoleRateLimiter = newTokenBucketLimiter(1, 3*time.Second)
	req1 := httptest.NewRequest(http.MethodPost, "/v1/instances/7/console/command", strings.NewReader(`{"command":"status"}`))
	req1.Header.Set("Content-Type", "application/json")
	req1.Header.Set("x-customer-id", "1")
	w1 := httptest.NewRecorder()
	handled := handleInstanceConsoleHTTP(w1, req1, "7")
	if !handled {
		t.Fatal("expected route to be handled")
	}

	req2 := httptest.NewRequest(http.MethodPost, "/v1/instances/7/console/command", strings.NewReader(`{"command":"status"}`))
	req2.Header.Set("Content-Type", "application/json")
	req2.Header.Set("x-customer-id", "1")
	w2 := httptest.NewRecorder()
	_ = handleInstanceConsoleHTTP(w2, req2, "7")
	if w2.Code != 429 {
		t.Fatalf("expected 429, got %d", w2.Code)
	}
	var payload map[string]any
	if err := json.Unmarshal(w2.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if payload["error_code"] != "RATE_LIMITED" {
		t.Fatalf("expected RATE_LIMITED, got %v", payload["error_code"])
	}
}

func TestConsoleHealthWithoutJournalctlReturnsOkEnvelopeWithRequestID(t *testing.T) {
	origLookup := lookupCommand
	defer func() { lookupCommand = origLookup }()
	lookupCommand = func(file string) (string, error) { return "", fmt.Errorf("missing") }

	req := httptest.NewRequest(http.MethodGet, "/v1/instances/5/console/health", nil)
	req.Header.Set("X-Request-ID", "req-123")
	w := httptest.NewRecorder()
	_ = handleInstanceConsoleHTTP(w, req, "5")

	var payload map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if ok, _ := payload["ok"].(bool); !ok {
		t.Fatalf("expected ok=true, got %v", payload["ok"])
	}
	data, _ := payload["data"].(map[string]any)
	if journalAvailable, _ := data["journal_available"].(bool); journalAvailable {
		t.Fatalf("expected journal_available=false, got %v", data["journal_available"])
	}
	if payload["request_id"] != "req-123" {
		t.Fatalf("expected request id req-123, got %v", payload["request_id"])
	}
}
