package main

import (
	"bufio"
	"net"
	"path/filepath"
	"testing"
	"time"
)

func TestWriteConsoleCommandToSocket(t *testing.T) {
	socketPath := filepath.Join(t.TempDir(), "console.sock")
	listener, err := net.Listen("unix", socketPath)
	if err != nil {
		t.Fatalf("listen socket: %v", err)
	}
	defer func() {
		_ = listener.Close()
	}()

	received := make(chan string, 1)
	go func() {
		conn, acceptErr := listener.Accept()
		if acceptErr != nil {
			return
		}
		defer func() {
			_ = conn.Close()
		}()
		line, _ := bufio.NewReader(conn).ReadString('\n')
		received <- line
	}()

	if err := writeConsoleCommandToSocket(socketPath, "help"); err != nil {
		t.Fatalf("write command: %v", err)
	}

	select {
	case line := <-received:
		if line != "help\n" {
			t.Fatalf("expected help command, got %q", line)
		}
	case <-time.After(2 * time.Second):
		t.Fatalf("timed out waiting for command")
	}
}

func TestConsoleLimiterRateLimit(t *testing.T) {
	limiter := newTokenBucketLimiter(2, time.Second)
	if !limiter.Allow("1") {
		t.Fatalf("first call should pass")
	}
	if !limiter.Allow("1") {
		t.Fatalf("second call should pass")
	}
	if limiter.Allow("1") {
		t.Fatalf("third call should be rate-limited")
	}
}

func TestSanitizeConsoleCommandRejectsNewlines(t *testing.T) {
	_, err := sanitizeConsoleCommand("say hi\nstatus")
	if err != nil {
		t.Fatalf("expected newline to be sanitized, got error: %v", err)
	}

	_, err = sanitizeConsoleCommand("\x01")
	if err == nil {
		t.Fatalf("expected control character to fail validation")
	}
}
