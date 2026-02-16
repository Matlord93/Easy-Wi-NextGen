package main

import (
	"errors"
	"fmt"
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
	return filepath.Join("/run/easywi/instances", instanceID, "console.sock")
}

func writeConsoleCommandToSocket(socketPath, command string) error {
	socketPath = strings.TrimSpace(socketPath)
	if socketPath == "" {
		return errors.New("empty socket path")
	}
	conn, err := net.DialTimeout("unix", socketPath, 500*time.Millisecond)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return fmt.Errorf("console socket unavailable: %w", err)
		}
		return err
	}
	defer func() {
		_ = conn.Close()
	}()
	if err := conn.SetWriteDeadline(time.Now().Add(500 * time.Millisecond)); err != nil {
		return err
	}
	_, err = conn.Write([]byte(command + "\n"))
	return err
}
