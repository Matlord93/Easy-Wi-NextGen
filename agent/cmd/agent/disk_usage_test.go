//go:build linux

package main

import (
	"os"
	"path/filepath"
	"strconv"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestInstanceDiskScanMeasuresStoredGameDirectory(t *testing.T) {
	base := t.TempDir()
	gameDir := filepath.Join(base, "game files")
	if err := os.Mkdir(gameDir, 0750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(gameDir, "save data"), make([]byte, 4096), 0600); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(base, "linked game")
	if err := os.Symlink(gameDir, link); err != nil {
		t.Fatal(err)
	}
	job := jobs.Job{ID: "disk-scan", Payload: map[string]any{"base_dir": base, "instance_dir": gameDir}}
	result, _ := handleInstanceDiskScan(job)
	used, err := strconv.ParseInt(result.Output["used_bytes"], 10, 64)
	if result.Status != "success" || result.Output["path"] != gameDir || err != nil || used < 4096 {
		t.Fatalf("unexpected disk scan result: %+v", result)
	}

	job.Payload["instance_dir"] = link
	result, _ = handleInstanceDiskScan(job)
	if result.Status != "failed" || result.Output["used_bytes"] != "" {
		t.Fatalf("expected failure for a symlink instead of a directory: %+v", result)
	}
}
