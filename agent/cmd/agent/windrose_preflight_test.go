package main

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestWindrosePreflightDetectsMissingToolsAndExe(t *testing.T) {
	oldLookPath := windrosePreflightLookPath
	oldCommand := windrosePreflightCommand
	t.Cleanup(func() { windrosePreflightLookPath = oldLookPath; windrosePreflightCommand = oldCommand })
	windrosePreflightCommand = func(name string, args ...string) ([]byte, error) { return []byte("wine-9.0"), nil }

	for _, tool := range []string{"wine", "xvfb-run", "taskset"} {
		t.Run("missing_"+tool, func(t *testing.T) {
			windrosePreflightLookPath = func(name string) (string, error) {
				if name == tool {
					return "", errors.New("missing")
				}
				return "/usr/bin/" + name, nil
			}
			err := runWindrosePreflight(t.TempDir(), map[string]any{"template_name": "Windrose Dedicated Server (Linux via Wine)"}, "wine R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe")
			if err == nil || !strings.Contains(err.Error(), tool) {
				t.Fatalf("expected missing %s error, got %v", tool, err)
			}
		})
	}

	windrosePreflightLookPath = func(name string) (string, error) { return "/usr/bin/" + name, nil }
	err := runWindrosePreflight(t.TempDir(), map[string]any{"template_name": "Windrose Dedicated Server (Linux via Wine)"}, "wine R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe")
	if err == nil || !strings.Contains(err.Error(), "missing executable") {
		t.Fatalf("expected missing executable error, got %v", err)
	}
}

func TestWindrosePreflightSucceedsWithToolsAndExe(t *testing.T) {
	oldLookPath := windrosePreflightLookPath
	oldCommand := windrosePreflightCommand
	t.Cleanup(func() { windrosePreflightLookPath = oldLookPath; windrosePreflightCommand = oldCommand })
	windrosePreflightLookPath = func(name string) (string, error) { return "/usr/bin/" + name, nil }
	windrosePreflightCommand = func(name string, args ...string) ([]byte, error) { return []byte("wine-9.0"), nil }
	dir := t.TempDir()
	exe := filepath.Join(dir, filepath.FromSlash(windroseExeRelativePath))
	if err := os.MkdirAll(filepath.Dir(exe), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(exe, []byte("exe"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := runWindrosePreflight(dir, map[string]any{"template_name": "Windrose Dedicated Server (Linux via Wine)"}, "wine R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe"); err != nil {
		t.Fatalf("expected success, got %v", err)
	}
}
