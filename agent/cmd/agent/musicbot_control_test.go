package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestRuntimeControlClientStateFileFallback(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	response, err := NewRuntimeControlClient(dir).Command("pause", map[string]any{"action": "pause"})
	if err != nil {
		t.Fatalf("Command() error = %v", err)
	}
	if !response.OK || response.Payload["transport"] != "state_file" {
		t.Fatalf("response = %#v", response)
	}
	stateFile, ok := response.Payload["state_file"].(string)
	if !ok || stateFile == "" {
		t.Fatalf("missing state_file in %#v", response.Payload)
	}
	if _, err := os.Stat(filepath.Clean(stateFile)); err != nil {
		t.Fatalf("state file stat: %v", err)
	}
}
