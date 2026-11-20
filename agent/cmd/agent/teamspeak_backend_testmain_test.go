package main

import (
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

var teamspeakClientFixtureDir string

// Prepare executable fixtures before m.Run can fork any parallel test helpers.
// Per-test hard links keep the validated path private without reopening the
// executable inode for writing or relaxing production symlink validation.
func TestMain(m *testing.M) {
	os.Exit(runAgentTests(m))
}

func runAgentTests(m *testing.M) (code int) {
	dir, err := os.MkdirTemp("", "easywi-agent-teamspeak-fixtures-")
	if err != nil {
		_, _ = fmt.Fprintln(os.Stderr, "create TeamSpeak test fixtures:", err)
		return 1
	}
	defer func() {
		if err := os.RemoveAll(dir); err != nil {
			_, _ = fmt.Fprintln(os.Stderr, "remove TeamSpeak test fixtures:", err)
			code = 1
		}
	}()
	for _, mode := range []string{"mock", "stub", "fail-secret"} {
		if err := os.WriteFile(filepath.Join(dir, mode), []byte(mockTeamspeakClientScript(mode)), 0o755); err != nil {
			_, _ = fmt.Fprintln(os.Stderr, "write TeamSpeak test fixture:", err)
			return 1
		}
	}
	teamspeakClientFixtureDir = dir
	return m.Run()
}
