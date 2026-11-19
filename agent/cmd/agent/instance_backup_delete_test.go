package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"

	"easywi/agent/internal/jobs"
)

func rentalBackupFixture(t *testing.T) (string, map[string]any) {
	t.Helper()
	root := t.TempDir()
	dir := filepath.Join(root, "42")
	if err := os.Mkdir(dir, 0700); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, "instance-42-123.tar.gz")
	data := []byte("recorded customer backup")
	if err := os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
	checksum := sha256.Sum256(data)
	return path, map[string]any{"instance_id": "42", "backup_id": "5", "backup_path": path, "backup_sha256": hex.EncodeToString(checksum[:]), "size_bytes": fmt.Sprint(len(data)), "backup_target_type": "local", "backup_target_config": map[string]any{"base_path": root}}
}
func TestRentalBackupDeletionChecksProofAndIsIdempotent(t *testing.T) {
	path, p := rentalBackupFixture(t)
	result, _ := handleInstanceBackupDelete(jobs.Job{ID: "delete-test", Payload: p})
	if result.Status != "success" {
		t.Fatalf("deletion failed: %v", result.Output)
	}
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatal("expired backup still exists")
	}
	result, _ = handleInstanceBackupDelete(jobs.Job{ID: "delete-test", Payload: p})
	if result.Status != "success" {
		t.Fatal("repeated deletion failed")
	}
}
func TestRentalBackupDeletionRetainsChangedOrForeignArchives(t *testing.T) {
	path, p := rentalBackupFixture(t)
	p["instance_id"] = "43"
	result, _ := handleInstanceBackupDelete(jobs.Job{ID: "delete-test", Payload: p})
	if result.Status == "success" {
		t.Fatal("another instance archive was deleted")
	}
	p["instance_id"] = "42"
	if err := os.WriteFile(path, []byte("changed backup"), 0600); err != nil {
		t.Fatal(err)
	}
	result, _ = handleInstanceBackupDelete(jobs.Job{ID: "delete-test", Payload: p})
	if result.Status == "success" {
		t.Fatal("changed archive was deleted")
	}
	if _, err := os.Stat(path); err != nil {
		t.Fatal("blocked deletion changed the file")
	}
}
func TestRentalBackupDeletionRejectsSymlinks(t *testing.T) {
	path, p := rentalBackupFixture(t)
	outside := filepath.Join(t.TempDir(), "foreign.tar.gz")
	if err := os.Rename(path, outside); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, path); err != nil {
		t.Fatal(err)
	}
	result, _ := handleInstanceBackupDelete(jobs.Job{ID: "delete-test", Payload: p})
	if result.Status == "success" {
		t.Fatal("symlink archive was accepted")
	}
	if _, err := os.Stat(outside); err != nil {
		t.Fatal("foreign file changed")
	}
}
func TestRentalRemoteBackupDeletionUsesStrongEtagAndRejectsConcurrentChanges(t *testing.T) {
	for _, changed := range []bool{false, true} {
		t.Run(fmt.Sprint(changed), func(t *testing.T) {
			data := []byte("recorded remote backup")
			hash := sha256.Sum256(data)
			deleted := false
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path != "/rentals/instance-42-123.tar.gz" {
					t.Errorf("unexpected path %s", r.URL.Path)
					w.WriteHeader(404)
					return
				}
				if r.Method == http.MethodGet {
					w.Header().Set("ETag", `"version-one"`)
					_, _ = w.Write(data)
					return
				}
				if r.Method != http.MethodDelete || r.Header.Get("If-Match") != `"version-one"` {
					t.Error("unconditional or unexpected remote deletion")
					w.WriteHeader(400)
					return
				}
				if changed {
					w.WriteHeader(http.StatusPreconditionFailed)
					return
				}
				deleted = true
				w.WriteHeader(http.StatusNoContent)
			}))
			defer server.Close()
			p := map[string]any{"instance_id": "42", "backup_id": "5", "backup_path": server.URL + "/rentals/instance-42-123.tar.gz", "backup_sha256": hex.EncodeToString(hash[:]), "size_bytes": fmt.Sprint(len(data)), "backup_target_type": "webdav", "backup_target_config": map[string]any{"url": server.URL, "remote_path": "rentals", "username": "fixture"}, "backup_target_secret": map[string]any{"password": "fixture"}}
			result, _ := handleInstanceBackupDelete(jobs.Job{ID: "delete-test", Payload: p})
			if changed && (result.Status == "success" || deleted) {
				t.Fatal("changed remote archive was removed")
			}
			if !changed && (result.Status != "success" || !deleted) {
				t.Fatalf("recorded archive not removed: %v", result.Output)
			}
		})
	}
}
