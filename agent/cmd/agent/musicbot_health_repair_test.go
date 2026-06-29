package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"easywi/agent/internal/jobs"
)

// healthRepairJob returns a job configured to run a specific repair_action
// against a fresh temporary install directory.
func healthRepairJob(t *testing.T, action string) (jobs.Job, string) {
	t.Helper()
	job, installPath, runtimeBinary := musicbotLifecycleJob(t)
	// Install first so the layout exists.
	if result := handleMusicbotInstall(job); result.status != "success" {
		t.Fatalf("pre-install failed: %s", result.errorText)
	}
	job.Payload["repair_action"] = action
	job.Payload["runtime_binary"] = runtimeBinary
	return job, installPath
}

func TestMusicbotRepairRewriteQueueCreatesQueueFile(t *testing.T) {
	t.Parallel()
	job, installPath := healthRepairJob(t, "rewrite_queue")

	result := handleMusicbotHealthRepair(job)
	if result.status != "success" {
		t.Fatalf("rewrite_queue status=%s error=%s", result.status, result.errorText)
	}

	queuePath := filepath.Join(installPath, "data", "queue", "queue.json")
	assertFileExists(t, queuePath)

	data, err := os.ReadFile(queuePath)
	if err != nil {
		t.Fatal(err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(data, &decoded); err != nil {
		t.Fatalf("queue.json is not valid JSON: %v", err)
	}
	if _, ok := decoded["items"]; !ok {
		t.Fatalf("queue.json missing 'items' key: %s", string(data))
	}
}

func TestMusicbotRepairRepairPlaylistsEnsuresDir(t *testing.T) {
	t.Parallel()
	job, installPath := healthRepairJob(t, "repair_playlists")

	result := handleMusicbotHealthRepair(job)
	if result.status != "success" {
		t.Fatalf("repair_playlists status=%s error=%s", result.status, result.errorText)
	}

	assertFileExists(t, filepath.Join(installPath, "data", "playlists"))
}

func TestMusicbotRepairPluginRegistryCreatesRegistryFile(t *testing.T) {
	t.Parallel()
	job, installPath := healthRepairJob(t, "repair_plugin_registry")

	result := handleMusicbotHealthRepair(job)
	if result.status != "success" {
		t.Fatalf("repair_plugin_registry status=%s error=%s", result.status, result.errorText)
	}

	registryPath := filepath.Join(installPath, "plugins", "registry.json")
	assertFileExists(t, registryPath)

	data, err := os.ReadFile(registryPath)
	if err != nil {
		t.Fatal(err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(data, &decoded); err != nil {
		t.Fatalf("registry.json is not valid JSON: %v", err)
	}
}

func TestMusicbotRepairPluginRegistryIsIdempotent(t *testing.T) {
	t.Parallel()
	job, _ := healthRepairJob(t, "repair_plugin_registry")

	first := handleMusicbotHealthRepair(job)
	second := handleMusicbotHealthRepair(job)

	if first.status != "success" || second.status != "success" {
		t.Fatalf("idempotency check failed: %s / %s", first.errorText, second.errorText)
	}
}

func TestMusicbotRepairClearCacheRemovesAndRecreatesDir(t *testing.T) {
	t.Parallel()
	job, installPath := healthRepairJob(t, "clear_cache")

	// Pre-populate a cache file so we can verify it's gone.
	cacheDir := filepath.Join(installPath, "data", "cache")
	if err := os.MkdirAll(cacheDir, 0o750); err != nil {
		t.Fatal(err)
	}
	sentinel := filepath.Join(cacheDir, "test_cache_file.tmp")
	if err := os.WriteFile(sentinel, []byte("cached"), 0o640); err != nil {
		t.Fatal(err)
	}

	result := handleMusicbotHealthRepair(job)
	if result.status != "success" {
		t.Fatalf("clear_cache status=%s error=%s", result.status, result.errorText)
	}

	// The sentinel file must be gone.
	if _, err := os.Stat(sentinel); !os.IsNotExist(err) {
		t.Fatalf("cache file still exists after clear_cache")
	}
	// The cache directory itself must still exist.
	assertFileExists(t, cacheDir)
}

func TestMusicbotRepairRepairUploadDirsEnsuresAllDirs(t *testing.T) {
	t.Parallel()
	job, installPath := healthRepairJob(t, "repair_upload_dirs")

	// Remove the data dir to simulate missing upload dirs.
	if err := os.RemoveAll(filepath.Join(installPath, "data")); err != nil {
		t.Fatal(err)
	}

	result := handleMusicbotHealthRepair(job)
	if result.status != "success" {
		t.Fatalf("repair_upload_dirs status=%s error=%s", result.status, result.errorText)
	}

	for _, dir := range []string{"data/tracks", "data/cache", "data/history"} {
		assertFileExists(t, filepath.Join(installPath, filepath.FromSlash(dir)))
	}
}

func TestMusicbotRepairUnknownActionFails(t *testing.T) {
	t.Parallel()
	job, _ := healthRepairJob(t, "does_not_exist")

	result := handleMusicbotHealthRepair(job)
	if result.status != "failed" {
		t.Fatalf("unknown action status=%s, want failed", result.status)
	}
}

func TestMusicbotRepairMissingActionFails(t *testing.T) {
	t.Parallel()
	job, _, _ := musicbotLifecycleJob(t)
	// No repair_action key.
	delete(job.Payload, "repair_action")

	result := handleMusicbotHealthRepair(job)
	if result.status != "failed" {
		t.Fatalf("missing action status=%s, want failed", result.status)
	}
}

func TestMusicbotRepairRewriteQueueIsIdempotent(t *testing.T) {
	t.Parallel()
	job, _ := healthRepairJob(t, "rewrite_queue")

	first := handleMusicbotHealthRepair(job)
	second := handleMusicbotHealthRepair(job)

	if first.status != "success" || second.status != "success" {
		t.Fatalf("idempotency check failed: %s / %s", first.errorText, second.errorText)
	}
}

func TestMusicbotRepairRepairYoutubeChecksYtdlpPresence(t *testing.T) {
	t.Parallel()
	job, _ := healthRepairJob(t, "repair_youtube")

	result := handleMusicbotHealthRepair(job)
	// Either success (yt-dlp found) or failed (not found) – never an unknown panic.
	if result.status != "success" && result.status != "failed" {
		t.Fatalf("unexpected status=%s for repair_youtube", result.status)
	}
}

func TestMusicbotRepairAutoDjWithoutSocketReturnsSuccess(t *testing.T) {
	t.Parallel()
	job, _ := healthRepairJob(t, "repair_autodj")

	result := handleMusicbotHealthRepair(job)
	// Socket is not running in test environment; should return queued, not fail.
	if result.status != "success" {
		t.Fatalf("repair_autodj without socket status=%s error=%s", result.status, result.errorText)
	}
	repaired, _ := result.resultPayload["repaired"].([]string)
	found := false
	for _, r := range repaired {
		if r == "autodj_repair_queued_for_next_start" || r == "autodj_reset" {
			found = true
		}
	}
	if !found {
		t.Fatalf("repair_autodj result payload missing expected repaired entry: %v", result.resultPayload)
	}
}
