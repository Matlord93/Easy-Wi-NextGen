package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestEnsureServiceActiveSucceedsWhenRunningDespiteFailedState(t *testing.T) {
	bin := t.TempDir()
	script := `#!/bin/sh
if [ "$1" = "reset-failed" ]; then exit 0; fi
if [ "$1" = "is-active" ]; then echo active; exit 0; fi
if [ "$1" = "is-failed" ]; then echo failed; exit 0; fi
if [ "$1" = "show" ]; then echo 1234; exit 0; fi
exit 0
`
	if err := os.WriteFile(filepath.Join(bin, "systemctl"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(bin, "ps"), []byte("#!/bin/sh\necho '/home/gs21/game/bin/linuxsteamrt64/cs2 -dedicated'\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(bin, "journalctl"), []byte("#!/bin/sh\necho 'all good'\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	oldPath := os.Getenv("PATH")
	t.Setenv("PATH", bin+":"+oldPath)

	if err := ensureServiceActive("gs-1", time.Now().Add(-5*time.Second)); err != nil {
		t.Fatalf("expected healthy, got %v", err)
	}
}

func TestEnsureServiceActiveFailsOnNewCrashMarker(t *testing.T) {
	bin := t.TempDir()
	script := `#!/bin/sh
if [ "$1" = "reset-failed" ]; then exit 0; fi
if [ "$1" = "is-active" ]; then echo active; exit 0; fi
if [ "$1" = "is-failed" ]; then echo no; exit 0; fi
if [ "$1" = "show" ]; then echo 1234; exit 0; fi
exit 0
`
	_ = os.WriteFile(filepath.Join(bin, "systemctl"), []byte(script), 0o755)
	_ = os.WriteFile(filepath.Join(bin, "ps"), []byte("#!/bin/sh\necho '/home/gs21/game/bin/linuxsteamrt64/cs2 -dedicated'\n"), 0o755)
	_ = os.WriteFile(filepath.Join(bin, "journalctl"), []byte("#!/bin/sh\necho 'failed with result'\n"), 0o755)
	oldPath := os.Getenv("PATH")
	t.Setenv("PATH", bin+":"+oldPath)

	if err := ensureServiceActive("gs-1", time.Now().Add(-5*time.Second)); err == nil {
		t.Fatalf("expected unhealthy error")
	}
}
