package main

import (
	"runtime"
	"testing"
)

func TestEnsureJobSupportedByPlatform(t *testing.T) {
	commonJobs := []string{
		"instance.create",
		"game.ensure_base",
		"webspace.create",
		"mail.ensure_base",
		"server.update.check",
	}
	for _, jobType := range commonJobs {
		if err := ensureJobSupportedByPlatform(jobType); err != nil {
			t.Fatalf("expected %s to be allowed on %s: %v", jobType, runtime.GOOS, err)
		}
	}

	if runtime.GOOS == "windows" {
		if err := ensureJobSupportedByPlatform("windows.service.start"); err != nil {
			t.Fatalf("expected windows.service.start to be allowed on windows: %v", err)
		}
		return
	}

	if err := ensureJobSupportedByPlatform("windows.service.start"); err == nil {
		t.Fatalf("expected windows.service.start to be blocked on non-windows")
	}
}
