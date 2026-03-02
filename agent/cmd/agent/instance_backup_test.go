package main

import (
	"archive/tar"
	"compress/gzip"
	"os"
	"path/filepath"
	"testing"
)

func TestResolveLocalBackupRootUsesConfiguredBasePath(t *testing.T) {
	basePath := filepath.Join(t.TempDir(), "backups")
	fallback := filepath.Join(t.TempDir(), "instances")
	root, err := resolveLocalBackupRoot(map[string]any{
		"backup_target_config": map[string]any{
			"base_path": basePath,
		},
	}, fallback)
	if err != nil {
		t.Fatalf("resolveLocalBackupRoot returned error: %v", err)
	}
	if root != basePath {
		t.Fatalf("root=%q, want %q", root, basePath)
	}
}

func TestResolveLocalBackupRootSupportsLegacyPathAliases(t *testing.T) {
	legacyPath := filepath.Join(t.TempDir(), "legacy-backups")
	fallback := filepath.Join(t.TempDir(), "instances")
	root, err := resolveLocalBackupRoot(map[string]any{
		"backup_target_config": map[string]any{
			"path": legacyPath,
		},
	}, fallback)
	if err != nil {
		t.Fatalf("resolveLocalBackupRoot returned error: %v", err)
	}
	if root != legacyPath {
		t.Fatalf("root=%q, want %q", root, legacyPath)
	}
}

func TestResolveLocalBackupRootFallsBackToDefault(t *testing.T) {
	fallback := filepath.Join(t.TempDir(), "instances")
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

func TestValidateRemoteBackupURLRejectsHostMismatch(t *testing.T) {
	err := validateRemoteBackupURL(map[string]any{
		"backup_target_config": map[string]any{"url": "https://dav.example.test/backups"},
	}, "https://internal.local/instance.tar.gz")
	if err == nil {
		t.Fatal("expected host mismatch validation error")
	}
}

func TestValidateBackupArchivePathsRejectsTraversal(t *testing.T) {
	archivePath := filepath.Join(t.TempDir(), "bad.tar.gz")
	archive, err := os.Create(archivePath)
	if err != nil {
		t.Fatalf("create archive: %v", err)
	}
	gz := gzip.NewWriter(archive)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "../../etc/passwd", Mode: 0o644, Size: int64(len("x"))}); err != nil {
		t.Fatalf("write header: %v", err)
	}
	if _, err := tw.Write([]byte("x")); err != nil {
		t.Fatalf("write body: %v", err)
	}
	_ = tw.Close()
	_ = gz.Close()
	_ = archive.Close()

	err = validateBackupArchivePaths(archivePath, t.TempDir())
	if err == nil {
		t.Fatal("expected traversal validation error")
	}
}
