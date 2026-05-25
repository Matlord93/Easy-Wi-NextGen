package main

import (
	"bufio"
	"errors"
	"io"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestHandleTsQueryListTreatsEmptyBanListAsSuccess(t *testing.T) {
	job := jobs.Job{ID: "job-banlist", Payload: map[string]any{"sid": "1"}}
	client := &ts3QueryClient{
		conn:           newScriptedQueryConn("error id=0 msg=ok\nerror id=1281 msg=database\\sempty\\sresult\\sset\n"),
		commandTimeout: time.Second,
	}
	client.reader = newScriptedReader(client.conn)
	client.writer = newScriptedWriter(client.conn)

	result := handleTsQueryList(job, func(_ map[string]any, fn func(*ts3QueryClient) error) error {
		return fn(client)
	}, "banlist", "bans")

	if result.status != "success" {
		t.Fatalf("expected empty banlist to succeed, got status=%s error=%s", result.status, result.errorText)
	}
	bans, ok := result.resultPayload["bans"].([]map[string]string)
	if !ok {
		t.Fatalf("expected bans payload, got %#v", result.resultPayload["bans"])
	}
	if len(bans) != 0 {
		t.Fatalf("expected empty bans, got %#v", bans)
	}
}

func TestIsTsQueryEmptyResultError(t *testing.T) {
	err := parseTsQueryErrorLine(`error id=1281 msg=database\sempty\sresult\sset`)
	if !isTsQueryEmptyResultError(err) {
		t.Fatalf("expected TS database empty result set to be treated as empty list")
	}

	other := parseTsQueryErrorLine(`error id=2568 msg=insufficient\sclient\spermissions`)
	if isTsQueryEmptyResultError(other) {
		t.Fatalf("expected permission errors to stay fatal")
	}
}

func TestNormalizeTsQueryListPayloadMapsBanID(t *testing.T) {
	rows := []map[string]string{
		{"banid": "42", "name": "foo"},
		{"ban_id": "77", "name": "bar"},
		{"id": "88", "name": "baz"},
	}
	normalized := normalizeTsQueryListPayload("banlist", rows)
	if normalized[0]["banId"] != "42" || normalized[1]["banId"] != "77" || normalized[2]["banId"] != "88" {
		t.Fatalf("expected banId fallback normalization, got %#v", normalized)
	}
}

func TestTsPoolRetriesConnectionErrorsOnly(t *testing.T) {
	entry := &tsPoolEntry{factory: func() (*ts3QueryClient, error) {
		return &ts3QueryClient{}, nil
	}}
	attempts := 0
	err := entry.use(func(_ *ts3QueryClient) error {
		attempts++
		if attempts == 1 {
			return errors.New("connection reset by peer")
		}
		return nil
	})
	if err != nil {
		t.Fatalf("expected retry to recover connection error: %v", err)
	}
	if attempts != 2 {
		t.Fatalf("expected 2 attempts for connection error, got %d", attempts)
	}

	semanticErr := &tsQueryError{ID: "1281", Message: "database empty result set"}
	entry = &tsPoolEntry{factory: func() (*ts3QueryClient, error) { return &ts3QueryClient{}, nil }}
	attempts = 0
	err = entry.use(func(_ *ts3QueryClient) error {
		attempts++
		return semanticErr
	})
	if !errors.Is(err, semanticErr) {
		t.Fatalf("expected semantic error to be returned, got %v", err)
	}
	if attempts != 1 {
		t.Fatalf("expected no reconnect retry for semantic query errors, got %d attempts", attempts)
	}
}

func TestTsQueryClientHasCommandDeadline(t *testing.T) {
	client := &ts3QueryClient{commandTimeout: time.Second, conn: &deadlineRecorderConn{}}
	client.setDeadline()
	recorder := client.conn.(*deadlineRecorderConn)
	if recorder.deadline.IsZero() {
		t.Fatalf("expected command deadline to be set")
	}
	client.clearDeadline()
	if !recorder.deadline.IsZero() {
		t.Fatalf("expected command deadline to be cleared")
	}
}

type deadlineRecorderConn struct {
	deadline time.Time
}

func (conn *deadlineRecorderConn) Read(_ []byte) (int, error) {
	return 0, errors.New("not implemented")
}
func (conn *deadlineRecorderConn) Write(p []byte) (int, error) { return len(p), nil }
func (conn *deadlineRecorderConn) Close() error                { return nil }
func (conn *deadlineRecorderConn) SetDeadline(t time.Time) error {
	conn.deadline = t
	return nil
}

type scriptedQueryConn struct {
	reader *strings.Reader
}

func newScriptedQueryConn(script string) *scriptedQueryConn {
	return &scriptedQueryConn{reader: strings.NewReader(script)}
}

func (conn *scriptedQueryConn) Read(p []byte) (int, error)  { return conn.reader.Read(p) }
func (conn *scriptedQueryConn) Write(p []byte) (int, error) { return len(p), nil }
func (conn *scriptedQueryConn) Close() error                { return nil }

func newScriptedReader(conn io.Reader) *bufio.Reader { return bufio.NewReader(conn) }
func newScriptedWriter(conn io.Writer) *bufio.Writer { return bufio.NewWriter(conn) }
