package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func musicbotQueueSyncJob(t *testing.T, queueItems []map[string]any) (jobs.Job, string) {
	t.Helper()
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "instance-1")

	runtimeBinary := filepath.Join(dir, "easywi-musicbot")
	if err := os.WriteFile(runtimeBinary, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	snapshot := map[string]any{
		"instance_id":  "1",
		"items":        queueItems,
		"revision":     0,
		"generated_at": "2024-01-01T00:00:00Z",
	}

	return jobs.Job{
		ID: "job-sync-1",
		Payload: map[string]any{
			"instance_id":      "1",
			"customer_id":      "2",
			"node_id":          "node-1",
			"service_name":     "musicbot-test",
			"install_path":     installPath,
			"runtime_binary":   runtimeBinary,
			"systemd_unit_dir": filepath.Join(installPath, "systemd"),
			"skip_systemd":     "true",
			"queue_length":     len(queueItems),
			"queue":            snapshot,
		},
	}, installPath
}

func TestHandleMusicbotQueueSyncFailsWithoutInstanceControlSocket(t *testing.T) {
	t.Parallel()
	job, installPath := musicbotQueueSyncJob(t, []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "Song A", "artist": "Artist", "duration_seconds": 180,
			"source": map[string]any{"type": "upload", "uri": "tracks/a.mp3", "mime_type": "audio/mpeg"}, "metadata": map[string]any{}},
	})

	// Install first so installPath has the musicbot dir
	installJob := jobs.Job{ID: "install-1", Payload: map[string]any{
		"instance_id":      "1",
		"customer_id":      "2",
		"node_id":          "node-1",
		"service_name":     "musicbot-test",
		"install_path":     installPath,
		"runtime_binary":   job.Payload["runtime_binary"],
		"systemd_unit_dir": filepath.Join(installPath, "systemd"),
		"skip_systemd":     "true",
		"enable":           "false",
	}}
	if result := handleMusicbotInstall(installJob); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}

	result := handleMusicbotQueueSync(job)
	if result.status != "failed" {
		t.Fatalf("queue.sync status=%s error=%s", result.status, result.errorText)
	}
	if !strings.Contains(result.errorText, filepath.Join(installPath, "control.sock")) {
		t.Fatalf("error = %q, want instance control.sock", result.errorText)
	}
}

func TestHandleMusicbotQueueSyncRejectsInvalidInstallPath(t *testing.T) {
	t.Parallel()
	job := jobs.Job{
		ID: "job-bad",
		Payload: map[string]any{
			"instance_id":  "1",
			"service_name": "musicbot-test",
			"install_path": "../invalid",
			"queue_length": 0,
			"queue":        map[string]any{"instance_id": "1", "items": []any{}},
		},
	}
	result := handleMusicbotQueueSync(job)
	if result.status != "failed" {
		t.Fatalf("expected failure for invalid install_path, got %s", result.status)
	}
}

func TestHandleMusicbotQueueSyncRejectsMissingQueue(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "instance-1")
	if err := os.MkdirAll(installPath, 0o750); err != nil {
		t.Fatal(err)
	}
	job := jobs.Job{
		ID: "job-noqueue",
		Payload: map[string]any{
			"instance_id":  "1",
			"service_name": "musicbot-test",
			"install_path": installPath,
			"queue_length": 0,
			// queue key intentionally omitted
		},
	}
	result := handleMusicbotQueueSync(job)
	if result.status != "failed" {
		t.Fatalf("expected failure for missing queue, got %s", result.status)
	}
}

func TestHandleMusicbotQueueSyncEmptyQueueRequiresInstanceControlSocket(t *testing.T) {
	t.Parallel()
	job, installPath := musicbotQueueSyncJob(t, nil)

	installJob := jobs.Job{ID: "install-2", Payload: map[string]any{
		"instance_id":      "1",
		"customer_id":      "2",
		"node_id":          "node-1",
		"service_name":     "musicbot-test",
		"install_path":     installPath,
		"runtime_binary":   job.Payload["runtime_binary"],
		"systemd_unit_dir": filepath.Join(installPath, "systemd"),
		"skip_systemd":     "true",
		"enable":           "false",
	}}
	if result := handleMusicbotInstall(installJob); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}

	result := handleMusicbotQueueSync(job)
	if result.status != "failed" {
		t.Fatalf("empty queue.sync status=%s error=%s", result.status, result.errorText)
	}
}
