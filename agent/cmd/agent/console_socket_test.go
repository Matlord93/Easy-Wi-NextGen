package main

import (
	"bufio"
	"errors"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"
)

func TestWriteConsoleCommandToSocket(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("unix sockets are not supported on windows")
	}
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

func TestWriteConsoleCommandToSocketRetriesUntilSocketReady(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("unix sockets are not supported on windows")
	}
	socketPath := filepath.Join(t.TempDir(), "console.sock")
	received := make(chan string, 1)

	go func() {
		time.Sleep(300 * time.Millisecond)
		listener, err := net.Listen("unix", socketPath)
		if err != nil {
			return
		}
		defer func() {
			_ = listener.Close()
		}()

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

	if err := writeConsoleCommandToSocket(socketPath, "status"); err != nil {
		t.Fatalf("expected retry to succeed, got %v", err)
	}

	select {
	case line := <-received:
		if line != "status\n" {
			t.Fatalf("expected status command, got %q", line)
		}
	case <-time.After(2 * time.Second):
		t.Fatalf("timed out waiting for delayed socket command")
	}
}

func TestShouldRetryConsoleConnect(t *testing.T) {
	if !shouldRetryConsoleConnect(os.ErrNotExist) {
		t.Fatalf("expected ENOENT to be retryable")
	}
	if !shouldRetryConsoleConnect(errors.New("dial unix /tmp/console.sock: connect: connection refused")) {
		t.Fatalf("expected connection refused to be retryable")
	}
	if shouldRetryConsoleConnect(errors.New("permission denied")) {
		t.Fatalf("expected permission denied to be non-retryable")
	}
}
