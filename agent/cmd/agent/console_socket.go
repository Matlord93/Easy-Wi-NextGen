package main

import (
	"context"
	"easywi/agent/internal/liveconsole"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
	"unicode"
	"unicode/utf8"
)

var consoleAllowedChars = regexp.MustCompile(`^[\p{L}\p{N}\p{P}\p{S}\p{Zs}]+$`)

var instanceRuntimeDir = liveconsole.RuntimeDir()

type tokenBucketLimiter struct {
	mu      sync.Mutex
	rate    int
	window  time.Duration
	history map[string][]time.Time
}

func newTokenBucketLimiter(rate int, window time.Duration) *tokenBucketLimiter {
	if rate < 1 {
		rate = 1
	}
	if window <= 0 {
		window = time.Second
	}
	return &tokenBucketLimiter{rate: rate, window: window, history: map[string][]time.Time{}}
}

func (l *tokenBucketLimiter) Allow(key string) bool {
	if key == "" {
		return false
	}
	now := time.Now()
	cutoff := now.Add(-l.window)

	l.mu.Lock()
	defer l.mu.Unlock()

	if len(l.history) >= 4096 {
		for k, times := range l.history {
			if len(times) == 0 || !times[len(times)-1].After(cutoff) {
				delete(l.history, k)
			}
		}
		if _, exists := l.history[key]; !exists && len(l.history) >= 4096 {
			return false
		}
	}
	entries := l.history[key][:0]
	for _, ts := range l.history[key] {
		if ts.After(cutoff) {
			entries = append(entries, ts)
		}
	}
	if len(entries) >= l.rate {
		l.history[key] = entries
		return false
	}
	l.history[key] = append(entries, now)
	return true
}

func sanitizeConsoleCommand(input string) (string, error) {
	clean := strings.TrimSpace(strings.ReplaceAll(strings.ReplaceAll(input, "\r", ""), "\n", ""))
	if clean == "" {
		return "", errors.New("command is required")
	}
	if len(clean) > maxConsoleCommandLength {
		return "", fmt.Errorf("command exceeds %d characters", maxConsoleCommandLength)
	}
	if !utf8.ValidString(clean) {
		return "", errors.New("command must be valid utf-8")
	}
	for _, r := range clean {
		if !unicode.IsPrint(r) {
			return "", errors.New("command contains non-printable characters")
		}
	}
	if !consoleAllowedChars.MatchString(clean) {
		return "", errors.New("command contains unsupported characters")
	}
	return clean, nil
}

func systemdConsoleSocketPath(instanceID string) string {
	instanceID = strings.TrimSpace(instanceID)
	if instanceID == "" {
		return ""
	}
	return filepath.Join(instanceRuntimeDir, instanceID, "console.sock")
}

func writeConsoleCommandToSocket(socketPath, command string) error {
	socketPath = strings.TrimSpace(socketPath)
	if socketPath == "" {
		return errors.New("empty socket path")
	}
	var conn net.Conn
	var err error
	deadline := time.Now().Add(2 * time.Second)
	for {
		dialCtx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
		conn, err = liveconsole.DialLocal(dialCtx, socketPath)
		cancel()
		if err == nil {
			break
		}
		if !shouldRetryConsoleConnect(err) || time.Now().After(deadline) {
			if errors.Is(err, os.ErrNotExist) {
				return fmt.Errorf("console socket unavailable: %w", err)
			}
			return err
		}
		time.Sleep(150 * time.Millisecond)
	}
	defer func() {
		_ = conn.Close()
	}()
	if err := conn.SetReadDeadline(time.Now().Add(time.Second)); err != nil {
		return err
	}
	var hello struct {
		Version  int    `json:"v"`
		Instance string `json:"instance_id"`
	}
	expected := filepath.Base(filepath.Dir(socketPath))
	if err := json.NewDecoder(io.LimitReader(conn, 512)).Decode(&hello); err != nil || hello.Version != 1 || hello.Instance != expected {
		return errors.New("console endpoint identity mismatch; restart this game with the updated wrapper")
	}
	if err := conn.SetWriteDeadline(time.Now().Add(500 * time.Millisecond)); err != nil {
		return err
	}
	if err := json.NewEncoder(conn).Encode(map[string]string{"instance_id": expected, "command": command}); err != nil {
		return err
	}
	var ack struct {
		Accepted bool `json:"accepted"`
	}
	if err := json.NewDecoder(io.LimitReader(conn, 512)).Decode(&ack); err != nil {
		return err
	}
	if !ack.Accepted {
		return errors.New("console command queue is full or unavailable")
	}
	return nil
}

func shouldRetryConsoleConnect(err error) bool {
	if errors.Is(err, os.ErrNotExist) {
		return true
	}
	lowerErr := strings.ToLower(err.Error())
	if strings.Contains(lowerErr, "connection refused") || strings.Contains(lowerErr, "no such file") {
		return true
	}
	return false
}
