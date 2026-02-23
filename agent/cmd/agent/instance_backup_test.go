package main

import "testing"

func TestResolveLocalBackupRootUsesConfiguredBasePath(t *testing.T) {
	root, err := resolveLocalBackupRoot(map[string]any{
		"backup_target_config": map[string]any{
			"base_path": "/srv/backups",
		},
	}, "/var/lib/easywi/backups/instances")
	if err != nil {
		t.Fatalf("resolveLocalBackupRoot returned error: %v", err)
	}
	if root != "/srv/backups" {
		t.Fatalf("root=%q, want %q", root, "/srv/backups")
	}
}

func TestResolveLocalBackupRootSupportsLegacyPathAliases(t *testing.T) {
	root, err := resolveLocalBackupRoot(map[string]any{
		"backup_target_config": map[string]any{
			"path": "/data/backups",
		},
	}, "/var/lib/easywi/backups/instances")
	if err != nil {
		t.Fatalf("resolveLocalBackupRoot returned error: %v", err)
	}
	if root != "/data/backups" {
		t.Fatalf("root=%q, want %q", root, "/data/backups")
	}
}

func TestResolveLocalBackupRootFallsBackToDefault(t *testing.T) {
	fallback := "/var/lib/easywi/backups/instances"
	root, err := resolveLocalBackupRoot(map[string]any{}, fallback)
	if err != nil {
		t.Fatalf("resolveLocalBackupRoot returned error: %v", err)
	}
	if root != fallback {
		t.Fatalf("root=%q, want %q", root, fallback)
	}
}

func TestResolveLocalBackupRootRejectsRelativePath(t *testing.T) {
	_, err := resolveLocalBackupRoot(map[string]any{
		"backup_target_config": map[string]any{
			"base_path": "relative/backups",
		},
	}, "/var/lib/easywi/backups/instances")
	if err == nil {
		t.Fatal("expected error for relative path")
	}
}
