package main

import (
	"archive/tar"
	"compress/gzip"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestInstanceBackupTimeoutUsesEnvVar(t *testing.T) {
	t.Setenv("EASYWI_INSTANCE_BACKUP_TIMEOUT", "90")
	got := instanceBackupTimeout()
	if got != 90*60*1000000000 {
		t.Fatalf("timeout=%v, want 90m", got)
	}
}

func TestInstanceBackupTimeoutFallsBackToDefault(t *testing.T) {
	t.Setenv("EASYWI_INSTANCE_BACKUP_TIMEOUT", "")
	got := instanceBackupTimeout()
	if got != defaultInstanceBackupTimeout {
		t.Fatalf("timeout=%v, want %v", got, defaultInstanceBackupTimeout)
	}
}

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

func TestHandleInstanceBackupCreateUploadsToWebDAVAndRemovesLocalArchive(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("instance backups are not supported on windows agents")
	}

	instanceDir := t.TempDir()
	if err := os.WriteFile(filepath.Join(instanceDir, "server.cfg"), []byte("hostname test"), 0o644); err != nil {
		t.Fatalf("write instance file: %v", err)
	}

	backupRoot := t.TempDir()
	t.Setenv("EASYWI_INSTANCE_BACKUP_DIR", backupRoot)

	var uploadedPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPut {
			t.Fatalf("method=%s, want PUT", r.Method)
		}
		uploadedPath = r.URL.Path
		if _, _, ok := r.BasicAuth(); !ok {
			t.Fatalf("missing basic auth")
		}
		if _, err := io.Copy(io.Discard, r.Body); err != nil {
			t.Fatalf("read request body: %v", err)
		}
		w.WriteHeader(http.StatusCreated)
	}))
	defer server.Close()

	result, _ := handleInstanceBackupCreate(jobs.Job{
		ID: "job-1",
		Payload: map[string]any{
			"instance_id":        "42",
			"install_path":       instanceDir,
			"backup_target_type": "webdav",
			"backup_target_config": map[string]any{
				"url":         server.URL,
				"remote_path": "/remote",
				"username":    "user",
			},
			"backup_target_secret": map[string]any{
				"password": "pass",
			},
		},
	})

	if result.Status != "success" {
		t.Fatalf("status=%s output=%v", result.Status, result.Output)
	}
	backupPath := result.Output["backup_path"]
	if !strings.HasPrefix(backupPath, server.URL+"/remote/instance-42-") {
		t.Fatalf("backup_path=%q, want remote WebDAV URL", backupPath)
	}
	if uploadedPath == "" {
		t.Fatalf("expected upload request")
	}

	matches, err := filepath.Glob(filepath.Join(backupRoot, "42", "*.tar.gz"))
	if err != nil {
		t.Fatalf("glob local backups: %v", err)
	}
	if len(matches) != 0 {
		t.Fatalf("expected local staging archive to be removed, found %v", matches)
	}
}
