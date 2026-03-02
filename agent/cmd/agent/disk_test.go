package main

import (
	"path/filepath"
	"testing"
)

func TestResolveInstanceDirSupportsRootPath(t *testing.T) {
	base := t.TempDir()
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	rootPath := filepath.Join(base, "gs21")

	resolved, err := resolveInstanceDir(map[string]any{
		"root_path": rootPath,
	})
	if err != nil {
		t.Fatalf("resolveInstanceDir returned error: %v", err)
	}
	if resolved != rootPath {
		t.Fatalf("resolveInstanceDir=%q, want %q", resolved, rootPath)
	}
}

func TestResolveInstanceDirPrefersInstallPathOverRootPath(t *testing.T) {
	base := t.TempDir()
	installPath := filepath.Join(base, "gs22")
	rootPath := filepath.Join(base, "gs21")
	resolved, err := resolveInstanceDir(map[string]any{
		"install_path": installPath,
		"root_path":    rootPath,
	})
	if err != nil {
		t.Fatalf("resolveInstanceDir returned error: %v", err)
	}
	if resolved != installPath {
		t.Fatalf("resolveInstanceDir=%q, want %q", resolved, installPath)
	}
}
