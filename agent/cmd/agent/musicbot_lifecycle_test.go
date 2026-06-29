package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestMusicbotInstallCreatesExpectedFiles(t *testing.T) {
	t.Parallel()
	job, installPath, _ := musicbotLifecycleJob(t)

	result := handleMusicbotInstall(job)
	if result.status != "success" {
		t.Fatalf("install status=%s error=%s", result.status, result.errorText)
	}
	assertFileExists(t, filepath.Join(installPath, "bin", "easywi-musicbot"))
	assertFileExists(t, filepath.Join(installPath, "config.json"))
	assertFileExists(t, filepath.Join(installPath, "systemd", "musicbot-test.service"))
	if runtime.GOOS != "windows" {
		mode := fileMode(t, filepath.Join(installPath, "config.json"))
		if mode.Perm() != 0o600 {
			t.Fatalf("config mode = %v, want 0600", mode.Perm())
		}
	}
}

func TestMusicbotRepairIsIdempotent(t *testing.T) {
	t.Parallel()
	job, installPath, _ := musicbotLifecycleJob(t)
	if result := handleMusicbotInstall(job); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}
	first := handleMusicbotRepair(job)
	second := handleMusicbotRepair(job)
	if first.status != "success" || second.status != "success" {
		t.Fatalf("repair statuses = %s/%s errors=%s/%s", first.status, second.status, first.errorText, second.errorText)
	}
	assertFileExists(t, filepath.Join(installPath, "config.json"))
	assertFileExists(t, filepath.Join(installPath, "systemd", "musicbot-test.service"))
}

func TestMusicbotUninstallRespectsKeepData(t *testing.T) {
	t.Parallel()
	job, installPath, _ := musicbotLifecycleJob(t)
	if result := handleMusicbotInstall(job); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}
	job.Payload["keep_data"] = "true"
	result := handleMusicbotUninstall(job)
	if result.status != "success" {
		t.Fatalf("uninstall failed: %s", result.errorText)
	}
	assertFileExists(t, filepath.Join(installPath, "config.json"))
	if _, err := os.Stat(filepath.Join(installPath, "systemd", "musicbot-test.service")); !os.IsNotExist(err) {
		t.Fatalf("unit file still exists or unexpected error: %v", err)
	}

	job2, installPath2, _ := musicbotLifecycleJob(t)
	if result := handleMusicbotInstall(job2); result.status != "success" {
		t.Fatalf("second install failed: %s", result.errorText)
	}
	job2.Payload["keep_data"] = "false"
	job2.Payload["delete_data"] = "true"
	result = handleMusicbotUninstall(job2)
	if result.status != "success" {
		t.Fatalf("second uninstall failed: %s", result.errorText)
	}
	if _, err := os.Stat(installPath2); !os.IsNotExist(err) {
		t.Fatalf("install path still exists or unexpected error: %v", err)
	}
}

func TestMusicbotUpdateFailsWhenRuntimeBinaryMissing(t *testing.T) {
	t.Parallel()
	job, _, runtimeBinary := musicbotLifecycleJob(t)
	if result := handleMusicbotInstall(job); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}
	if err := os.Remove(runtimeBinary); err != nil {
		t.Fatal(err)
	}
	result := handleMusicbotUpdate(job)
	if result.status != "failed" {
		t.Fatalf("update status=%s, want failed", result.status)
	}
	if !strings.Contains(result.errorText, "install easywi-musicbot to /usr/local/bin/easywi-musicbot") {
		t.Fatalf("update error=%q, want actionable missing-binary message", result.errorText)
	}
}

func TestMusicbotRepairInstallsMissingRuntimeBinary(t *testing.T) {
	t.Parallel()
	job, installPath, _ := musicbotLifecycleJob(t)
	if result := handleMusicbotRepair(job); result.status != "success" {
		t.Fatalf("repair failed: %s", result.errorText)
	}
	assertFileExists(t, filepath.Join(installPath, "bin", "easywi-musicbot"))
	if mode := fileMode(t, filepath.Join(installPath, "bin", "easywi-musicbot")); mode.Perm() != 0o755 {
		t.Fatalf("runtime binary mode = %v, want 0755", mode.Perm())
	}
}

func musicbotLifecycleJob(t *testing.T) (jobs.Job, string, string) {
	t.Helper()
	dir := t.TempDir()
	installPath := filepath.Join(dir, "musicbot", "instance-1")
	runtimeBinary := filepath.Join(dir, "easywi-musicbot")
	if err := os.WriteFile(runtimeBinary, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	return jobs.Job{ID: "job-1", Payload: map[string]any{
		"instance_id":      "1",
		"customer_id":      "2",
		"node_id":          "node-1",
		"service_name":     "musicbot-test",
		"install_path":     installPath,
		"runtime_binary":   runtimeBinary,
		"systemd_unit_dir": filepath.Join(installPath, "systemd"),
		"skip_systemd":     "true",
		"enable":           "false",
	}}, installPath, runtimeBinary
}

func assertFileExists(t *testing.T, path string) {
	t.Helper()
	if _, err := os.Stat(path); err != nil {
		t.Fatalf("expected %s to exist: %v", path, err)
	}
}

func fileMode(t *testing.T, path string) os.FileMode {
	t.Helper()
	stat, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	return stat.Mode()
}

func TestMusicbotInstallCreatesPerInstanceIsolationLayout(t *testing.T) {
	t.Parallel()
	jobA, installPathA, _ := musicbotLifecycleJob(t)
	jobB, installPathB, _ := musicbotLifecycleJob(t)
	jobA.Payload["instance_id"] = "bot-a"
	jobA.Payload["service_name"] = "musicbot-alpha"
	jobB.Payload["instance_id"] = "bot-b"
	jobB.Payload["service_name"] = "musicbot-beta"

	if result := handleMusicbotInstall(jobA); result.status != "success" {
		t.Fatalf("install A failed: %s", result.errorText)
	}
	if result := handleMusicbotInstall(jobB); result.status != "success" {
		t.Fatalf("install B failed: %s", result.errorText)
	}
	if installPathA == installPathB {
		t.Fatal("install paths must differ")
	}
	configA := readJSONFile(t, filepath.Join(installPathA, "config.json"))
	configB := readJSONFile(t, filepath.Join(installPathB, "config.json"))
	controlA := configA["control"].(map[string]any)["unix_socket"]
	controlB := configB["control"].(map[string]any)["unix_socket"]
	if controlA == controlB || controlA != filepath.Join(installPathA, "control.sock") || controlB != filepath.Join(installPathB, "control.sock") {
		t.Fatalf("control sockets not isolated: %#v %#v", controlA, controlB)
	}
	pulseA := configA["pulse"].(map[string]any)
	pulseB := configB["pulse"].(map[string]any)
	if pulseA["sink"] == pulseB["sink"] || pulseA["source"] == pulseB["source"] || pulseA["socket"] == pulseB["socket"] {
		t.Fatalf("pulse resources not isolated: %#v %#v", pulseA, pulseB)
	}
	tsA := configA["teamspeak"].(map[string]any)
	tsB := configB["teamspeak"].(map[string]any)
	if tsA["identity_path"] == tsB["identity_path"] || tsA["runtime_dir"] == tsB["runtime_dir"] {
		t.Fatalf("teamspeak resources not isolated: %#v %#v", tsA, tsB)
	}
	for _, path := range []string{"data/tracks", "data/queue", "data/playlists", "runtime/tmp", "runtime/pulse", "data/teamspeak-client/ts3home"} {
		assertFileExists(t, filepath.Join(installPathA, filepath.FromSlash(path)))
		assertFileExists(t, filepath.Join(installPathB, filepath.FromSlash(path)))
	}
}

func readJSONFile(t *testing.T, path string) map[string]any {
	t.Helper()
	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var decoded map[string]any
	if err := json.Unmarshal(content, &decoded); err != nil {
		t.Fatal(err)
	}
	return decoded
}
