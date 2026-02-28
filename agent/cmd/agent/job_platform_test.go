package main

import (
	"runtime"
	"testing"
)

func TestEnsureJobSupportedByPlatform(t *testing.T) {
	if runtime.GOOS == "windows" {
		if err := ensureJobSupportedByPlatform("instance.create"); err != nil {
			t.Fatalf("expected instance.create to be allowed on windows: %v", err)
		}
		if err := ensureJobSupportedByPlatform("game.ensure_base"); err != nil {
			t.Fatalf("expected game.ensure_base to be allowed on windows: %v", err)
		}
		if err := ensureJobSupportedByPlatform("windows.service.start"); err != nil {
			t.Fatalf("expected windows.service.start to be allowed on windows: %v", err)
		}
		if err := ensureJobSupportedByPlatform("webspace.create"); err == nil {
			t.Fatalf("expected webspace.create to be blocked on windows")
		}
		return
	}

	if err := ensureJobSupportedByPlatform("windows.service.start"); err == nil {
		t.Fatalf("expected windows.service.start to be blocked on non-windows")
	}
	if err := ensureJobSupportedByPlatform("webspace.create"); err != nil {
		t.Fatalf("expected webspace.create to be allowed on non-windows: %v", err)
	}
}

func TestWindowsAllowListContainsGameserverLifecycleJobs(t *testing.T) {
	required := []string{
		"game.ensure_base",
		"instance.create",
		"instance.start",
		"instance.stop",
		"instance.restart",
		"instance.delete",
	}

	for _, jobType := range required {
		if _, ok := windowsAllowedJobTypes[jobType]; !ok {
			t.Fatalf("expected %q in windows allow list", jobType)
		}
	}
}
