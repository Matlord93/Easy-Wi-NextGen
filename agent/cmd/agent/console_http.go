package main

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"sync"
	"time"
)

type consoleLine struct {
	ID     int64  `json:"id"`
	TS     string `json:"ts"`
	Stream string `json:"stream"`
	Text   string `json:"text"`
	Level  string `json:"level,omitempty"`
}

type consoleSnapshot struct {
	cursor    string
	lines     []map[string]any
	running   bool
	restarted bool
	restarts  int
	sessionID string
}

type consoleSession struct {
	instanceID string
	unitName   string
	ctx        context.Context
	cancel     context.CancelFunc

	mu         sync.Mutex
	buffer     []consoleLine
	nextID     int64
	lastAccess time.Time
	restarts   int
	running    bool
	sessionID  string
}

type consoleSessionManager struct {
	mu       sync.Mutex
	sessions map[string]*consoleSession
	ttl      time.Duration
}

type startedJournalStream struct {
	stdout io.ReadCloser
	stderr io.ReadCloser
	wait   func() error
}

var globalConsoleSessions = newConsoleSessionManager(2 * time.Minute)
var httpConsoleRateLimiter = newTokenBucketLimiter(5, 3*time.Second)
var lookupCommand = exec.LookPath
var writeConsoleCommand = writeConsoleCommandToSocket
var startJournalStream = func(ctx context.Context, unitName string) (*startedJournalStream, error) {
	cmd := exec.CommandContext(ctx, "journalctl", "-u", unitName, "-f", "-n", "200", "-o", "short-iso", "--no-pager")
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		return nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return &startedJournalStream{stdout: stdout, stderr: stderr, wait: cmd.Wait}, nil
}

func newConsoleSessionManager(ttl time.Duration) *consoleSessionManager {
	return &consoleSessionManager{sessions: map[string]*consoleSession{}, ttl: ttl}
}

func newConsoleSessionID() string {
	raw := make([]byte, 8)
	if _, err := rand.Read(raw); err == nil {
		return hex.EncodeToString(raw)
	}
	return strconv.FormatInt(time.Now().UnixNano(), 16)
}

func resolveInstanceUnitName(instanceID string) string {
	instanceID = strings.TrimSpace(instanceID)
	if instanceID == "" {
		return ""
	}
	return fmt.Sprintf("gs-%s", instanceID)
}

func (m *consoleSessionManager) getOrCreate(instanceID, unitName string) *consoleSession {
	key := instanceID + ":" + unitName
	m.mu.Lock()
	defer m.mu.Unlock()
	if s, ok := m.sessions[key]; ok {
		s.mu.Lock()
		s.lastAccess = time.Now()
		s.mu.Unlock()
		return s
	}
	ctx, cancel := context.WithCancel(context.Background())
	s := &consoleSession{
		instanceID: instanceID,
		unitName:   unitName,
		ctx:        ctx,
		cancel:     cancel,
		buffer:     make([]consoleLine, 0, 400),
		lastAccess: time.Now(),
		sessionID:  newConsoleSessionID(),
	}
	m.sessions[key] = s
	go s.run()
	go m.cleanupLoop(key, s)
	return s
}

func (m *consoleSessionManager) cleanupLoop(key string, s *consoleSession) {
	t := time.NewTicker(5 * time.Second)
	defer t.Stop()
	for range t.C {
		s.mu.Lock()
		expired := time.Since(s.lastAccess) > m.ttl
		s.mu.Unlock()
		if expired {
			s.cancel()
			m.mu.Lock()
			delete(m.sessions, key)
			m.mu.Unlock()
			return
		}
	}
}

func (s *consoleSession) appendLine(stream, text, level string) {
	text = strings.TrimSpace(text)
	if text == "" {
		return
	}
	// Never emit legacy header spam as normal output.
	if stream == "journal" && strings.HasPrefix(text, "--- journalctl ") {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.nextID++
	s.lastAccess = time.Now()
	s.buffer = append(s.buffer, consoleLine{ID: s.nextID, TS: time.Now().UTC().Format(time.RFC3339), Stream: stream, Text: text, Level: level})
	if len(s.buffer) > 1000 {
		s.buffer = s.buffer[len(s.buffer)-1000:]
	}
}

func (s *consoleSession) snapshotAfterCursor(cursorRaw string) consoleSnapshot {
	s.mu.Lock()
	defer s.mu.Unlock()
	cursorID := parseCursor(cursorRaw, s.sessionID)
	lines := make([]map[string]any, 0, 200)
	lastID := cursorID
	for _, line := range s.buffer {
		if line.ID <= cursorID {
			continue
		}
		lastID = line.ID
		lines = append(lines, map[string]any{"id": line.ID, "ts": line.TS, "stream": line.Stream, "text": line.Text, "level": line.Level})
	}
	if cursorID == 0 && len(lines) > 200 {
		lines = lines[len(lines)-200:]
		if len(lines) > 0 {
			if value, ok := lines[len(lines)-1]["id"].(int64); ok {
				lastID = value
			}
		}
	}
	return consoleSnapshot{
		cursor:    makeCursor(s.sessionID, lastID),
		lines:     lines,
		running:   s.running,
		restarted: s.restarts > 0,
		restarts:  s.restarts,
		sessionID: s.sessionID,
	}
}

func (s *consoleSession) run() {
	backoff := time.Second
	for {
		select {
		case <-s.ctx.Done():
			return
		default:
		}

		s.mu.Lock()
		s.running = true
		s.mu.Unlock()

		stream, err := startJournalStream(s.ctx, s.unitName)
		if err != nil {
			s.appendLine("meta", "journal start failed", "error")
			s.mu.Lock()
			s.running = false
			s.mu.Unlock()
			select {
			case <-time.After(backoff):
				if backoff < 10*time.Second {
					backoff *= 2
				}
				continue
			case <-s.ctx.Done():
				return
			}
		}

		s.mu.Lock()
		restarts := s.restarts
		s.mu.Unlock()
		if restarts == 0 {
			s.appendLine("meta", fmt.Sprintf("connected (%s)", s.unitName), "info")
		} else {
			s.appendLine("meta", "reconnected", "info")
		}
		backoff = time.Second

		done := make(chan struct{})
		go scanConsoleReader(stream.stdout, s)
		go scanConsoleReader(stream.stderr, s)
		go func() {
			_ = stream.wait()
			close(done)
		}()

		select {
		case <-done:
			s.mu.Lock()
			s.restarts++
			s.running = false
			s.mu.Unlock()
			select {
			case <-time.After(backoff):
				if backoff < 10*time.Second {
					backoff *= 2
				}
				continue
			case <-s.ctx.Done():
				return
			}
		case <-s.ctx.Done():
			_ = stream.wait()
			return
		}
	}
}

func scanConsoleReader(r io.Reader, s *consoleSession) {
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
	sc.Split(splitLogLines)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		s.appendLine("journal", line, "")
	}
}

func parseCursor(raw, expectedSessionID string) int64 {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return 0
	}
	parts := strings.Split(raw, ":")
	if len(parts) != 2 {
		return 0
	}
	if expectedSessionID != "" && parts[0] != expectedSessionID {
		return 0
	}
	id, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil || id < 0 {
		return 0
	}
	return id
}

func makeCursor(sessionID string, id int64) string {
	return sessionID + ":" + strconv.FormatInt(id, 10)
}

func handleInstanceConsoleHTTP(w http.ResponseWriter, r *http.Request, instanceID string) bool {
	base := "/v1/instances/" + strings.TrimSpace(instanceID) + "/console/"
	if !strings.HasPrefix(r.URL.Path, base) {
		return false
	}
	requestID := strings.TrimSpace(r.Header.Get("X-Request-ID"))
	action := strings.Trim(strings.TrimPrefix(r.URL.Path, base), "/ ")
	unit := resolveInstanceUnitName(instanceID)
	if unit == "" {
		writeAccessEnvelope(w, 400, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "invalid instance id", RequestID: requestID})
		return true
	}

	switch action {
	case "logs":
		if r.Method != http.MethodGet {
			writeAccessEnvelope(w, 405, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
			return true
		}
		journalAvailable := true
		if _, err := lookupCommand("journalctl"); err != nil {
			journalAvailable = false
		}
		if !journalAvailable {
			writeAccessEnvelope(w, 200, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
				"cursor": "",
				"lines":  []any{},
				"meta": map[string]any{
					"unit":              unit,
					"state":             "unavailable",
					"journal_available": false,
				},
			}})
			return true
		}
		s := globalConsoleSessions.getOrCreate(instanceID, unit)
		snapshot := s.snapshotAfterCursor(strings.TrimSpace(r.URL.Query().Get("cursor")))
		writeAccessEnvelope(w, 200, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
			"cursor": snapshot.cursor,
			"lines":  snapshot.lines,
			"meta": map[string]any{
				"unit":      unit,
				"state":     map[bool]string{true: "connected", false: "restarting"}[snapshot.running],
				"restarted": snapshot.restarted,
			},
		}})
		return true
	case "health":
		if r.Method != http.MethodGet {
			writeAccessEnvelope(w, 405, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
			return true
		}
		journalAvailable := true
		if _, err := lookupCommand("journalctl"); err != nil {
			journalAvailable = false
		}
		if !journalAvailable {
			socketPath := systemdConsoleSocketPath(instanceID)
			sockExists := false
			if socketPath != "" {
				if st, err := os.Stat(socketPath); err == nil {
					sockExists = st.Mode()&os.ModeSocket != 0
				}
			}
			writeAccessEnvelope(w, 200, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
				"unit_name":         unit,
				"unit_active_state": "inactive",
				"socket_path":       socketPath,
				"socket_exists":     sockExists,
				"journal_available": false,
				"journal_session":   map[string]any{"connected": false, "restarts": 0, "session_id": ""},
			}})
			return true
		}
		s := globalConsoleSessions.getOrCreate(instanceID, unit)
		snapshot := s.snapshotAfterCursor("")
		socketPath := systemdConsoleSocketPath(instanceID)
		sockExists := false
		if socketPath != "" {
			if st, err := os.Stat(socketPath); err == nil {
				sockExists = st.Mode()&os.ModeSocket != 0
			}
		}
		writeAccessEnvelope(w, 200, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
			"unit_name":         unit,
			"unit_active_state": map[bool]string{true: "active", false: "inactive"}[snapshot.running],
			"socket_path":       socketPath,
			"socket_exists":     sockExists,
			"journal_session":   map[string]any{"connected": snapshot.running, "restarts": snapshot.restarts, "session_id": snapshot.sessionID},
		}})
		return true
	case "command":
		if r.Method != http.MethodPost {
			writeAccessEnvelope(w, 405, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
			return true
		}
		payload := parseQueryHTTPPayload(r)
		command, err := sanitizeConsoleCommand(payload["command"])
		if err != nil {
			writeAccessEnvelope(w, 422, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: err.Error(), RequestID: requestID})
			return true
		}
		caller := strings.TrimSpace(r.Header.Get("x-customer-id"))
		if caller == "" {
			caller = "anon"
		}
		if !httpConsoleRateLimiter.Allow(instanceID + ":" + caller) {
			writeAccessEnvelope(w, 429, accessEnvelope{OK: false, ErrorCode: "RATE_LIMITED", Message: "too many commands", RequestID: requestID, Data: map[string]any{"retry_after_ms": 3000}})
			return true
		}
		socketPath := systemdConsoleSocketPath(instanceID)
		if strings.TrimSpace(socketPath) == "" {
			writeAccessEnvelope(w, 409, accessEnvelope{OK: false, ErrorCode: "CONSOLE_UNAVAILABLE", Message: "console socket path unavailable", RequestID: requestID})
			return true
		}
		if err := writeConsoleCommand(socketPath, command); err != nil {
			code := "CONSOLE_UNAVAILABLE"
			if strings.Contains(strings.ToLower(err.Error()), "permission") {
				code = "PERMISSION_DENIED"
			}
			writeAccessEnvelope(w, 409, accessEnvelope{OK: false, ErrorCode: code, Message: err.Error(), RequestID: requestID})
			return true
		}
		s := globalConsoleSessions.getOrCreate(instanceID, unit)
		s.appendLine("meta", "> "+redactCommandForMeta(command), "info")
		writeAccessEnvelope(w, 200, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{"accepted": true, "sent_at": time.Now().UTC().Format(time.RFC3339)}})
		return true
	default:
		writeAccessEnvelope(w, 404, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "unknown console action", RequestID: requestID})
		return true
	}
}

func redactCommandForMeta(cmd string) string {
	lower := strings.ToLower(cmd)
	if strings.Contains(lower, "password") || strings.Contains(lower, "passwd") || strings.Contains(lower, "token") || strings.Contains(lower, "secret") || strings.Contains(lower, "apikey") || strings.Contains(lower, "api_key") {
		return "[redacted-command]"
	}
	return cmd
}
