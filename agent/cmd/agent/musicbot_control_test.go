package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRuntimeControlClientRequiresInstanceSocket(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	_, err := NewRuntimeControlClient(dir).Command("pause", map[string]any{"action": "pause"})
	if err == nil {
		t.Fatal("expected missing instance control socket error")
	}
	if !strings.Contains(err.Error(), filepath.Join(dir, "control.sock")) {
		t.Fatalf("error = %v, want instance control.sock path", err)
	}
}

func TestRuntimeControlClientIgnoresForeignConfiguredSocket(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	foreign := filepath.Join(t.TempDir(), "musicbot", "other", "control.sock")
	config := []byte(`{"control":{"unix_socket":"` + filepath.ToSlash(foreign) + `","tcp_addr":"127.0.0.1:9"}}`)
	if err := os.WriteFile(filepath.Join(dir, "config.json"), config, 0o600); err != nil {
		t.Fatal(err)
	}
	client := NewRuntimeControlClient(dir)
	if client.UnixSocket != filepath.Join(dir, "control.sock") {
		t.Fatalf("unix socket = %q, want instance control.sock", client.UnixSocket)
	}
	if client.TCPAddr != "" {
		t.Fatalf("tcp fallback = %q, want disabled on unix", client.TCPAddr)
	}
}
