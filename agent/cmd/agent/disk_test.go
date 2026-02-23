package main

import "testing"

func TestResolveInstanceDirSupportsRootPath(t *testing.T) {
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", "/")

	resolved, err := resolveInstanceDir(map[string]any{
		"root_path": "/home/gs21",
	})
	if err != nil {
		t.Fatalf("resolveInstanceDir returned error: %v", err)
	}
	if resolved != "/home/gs21" {
		t.Fatalf("resolveInstanceDir=%q, want %q", resolved, "/home/gs21")
	}
}

func TestResolveInstanceDirPrefersInstallPathOverRootPath(t *testing.T) {
	resolved, err := resolveInstanceDir(map[string]any{
		"install_path": "/home/gs22",
		"root_path":   "/home/gs21",
	})
	if err != nil {
		t.Fatalf("resolveInstanceDir returned error: %v", err)
	}
	if resolved != "/home/gs22" {
		t.Fatalf("resolveInstanceDir=%q, want %q", resolved, "/home/gs22")
	}
}
