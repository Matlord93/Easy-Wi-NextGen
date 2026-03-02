package main

import (
	"path/filepath"
	"testing"
)

func TestBuildSystemdExecStartUsesWrapperForGameserverUnits(t *testing.T) {
	execStart, runtimeDir := buildSystemdExecStart("gs-42", "/srv/instance/start.sh")
	if runtimeDir != filepath.FromSlash("easywi/instances/42") {
		t.Fatalf("unexpected runtime directory %q", runtimeDir)
	}
	if execStart == "/srv/instance/start.sh" {
		t.Fatalf("expected wrapper exec start, got %q", execStart)
	}
}

func TestBuildSystemdExecStartLeavesOtherUnitsUntouched(t *testing.T) {
	command := "/usr/bin/sinusbot"
	execStart, runtimeDir := buildSystemdExecStart("sinusbot-1", command)
	if runtimeDir != "" {
		t.Fatalf("unexpected runtime dir for non-gameserver unit: %q", runtimeDir)
	}
	if execStart != command {
		t.Fatalf("command changed for non-gameserver unit: %q", execStart)
	}
}
