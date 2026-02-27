package main

import (
	"runtime"
	"testing"
)

func TestEnsureJobSupportedByPlatform(t *testing.T) {
	if runtime.GOOS == "windows" {
		if err := ensureJobSupportedByPlatform("webspace.create"); err == nil {
			t.Fatalf("expected webspace.create to be blocked on windows")
		}
		if err := ensureJobSupportedByPlatform("windows.service.start"); err != nil {
			t.Fatalf("expected windows.service.start to be allowed on windows: %v", err)
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
