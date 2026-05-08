package main

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
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

func TestSystemdUnitTemplateGameserverIncludesCommandSocketWrapper(t *testing.T) {
	unit := systemdUnitTemplate(
		"gs-42",
		"easywi",
		"/srv/easywi/instances/42",
		"/srv/easywi/instances/42",
		"/srv/easywi/instances/42/start.sh",
		"",
		0,
		0,
	)

	if !strings.Contains(unit, "--command-socket /run/easywi/instances/42/console.sock") {
		t.Fatalf("expected command socket wrapper in ExecStart, got unit:\n%s", unit)
	}
	if strings.Contains(unit, "StandardInput=pipe") {
		t.Fatalf("unit must not include StandardInput=pipe, got unit:\n%s", unit)
	}
	if !strings.Contains(unit, "StandardOutput=journal") || !strings.Contains(unit, "StandardError=journal") {
		t.Fatalf("expected journal output/error directives, got unit:\n%s", unit)
	}
}

func TestEnsureBaseDirRepairsRestrictiveMode(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("Windows does not preserve Unix permission bits for os.Chmod")
	}
	baseDir := filepath.Join(t.TempDir(), "instances")
	if err := os.MkdirAll(baseDir, 0o750); err != nil {
		t.Fatalf("create base dir: %v", err)
	}
	if err := ensureBaseDir(baseDir); err != nil {
		t.Fatalf("ensure base dir: %v", err)
	}
	info, err := os.Stat(baseDir)
	if err != nil {
		t.Fatalf("stat base dir: %v", err)
	}
	if got := info.Mode().Perm(); got != baseDirMode {
		t.Fatalf("expected base dir mode %o, got %o", baseDirMode, got)
	}
}
