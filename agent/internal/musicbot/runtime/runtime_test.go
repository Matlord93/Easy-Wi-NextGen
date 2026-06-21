package musicbotruntime

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestLoadConfigAppliesDefaults(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.json")
	writeConfig(t, configPath, map[string]any{
		"instance_id":  "42",
		"customer_id":  "7",
		"service_name": "easywi-musicbot-42",
	})

	config, err := LoadConfig(configPath)
	if err != nil {
		t.Fatalf("LoadConfig() error = %v", err)
	}
	if config.DataDir != filepath.Join(dir, "data") {
		t.Fatalf("DataDir = %q", config.DataDir)
	}
	if config.LogDir != filepath.Join(dir, "logs") {
		t.Fatalf("LogDir = %q", config.LogDir)
	}
	if config.PluginDir != filepath.Join(dir, "plugins") {
		t.Fatalf("PluginDir = %q", config.PluginDir)
	}
}

func TestRuntimeStatusAndDummyPlayback(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	runtime, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "easywi-musicbot-42",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = runtime.Close() }()

	status := runtime.HandleCommand("status")
	if !status.OK || status.Payload["running"] != true {
		t.Fatalf("status response = %#v", status)
	}

	play := runtime.HandleCommand(`{"command":"play"}`)
	if !play.OK {
		t.Fatalf("play response = %#v", play)
	}
	playback, ok := play.Payload["playback"].(PlaybackState)
	if !ok || playback.State != "playing" {
		t.Fatalf("playback = %#v", play.Payload["playback"])
	}
}

func writeConfig(t *testing.T, path string, payload map[string]any) {
	t.Helper()
	encoded, err := json.Marshal(payload)
	if err != nil {
		t.Fatalf("Marshal() error = %v", err)
	}
	if err := os.WriteFile(path, encoded, 0o600); err != nil {
		t.Fatalf("WriteFile() error = %v", err)
	}
}

func TestRuntimeStatusIncludesPluginDirectoryAndManifestSummaries(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	pluginDir := filepath.Join(dir, "plugins")
	manifestDir := filepath.Join(pluginDir, "demo.plugin")
	if err := os.MkdirAll(manifestDir, 0o750); err != nil {
		t.Fatalf("MkdirAll() error = %v", err)
	}
	if err := os.WriteFile(filepath.Join(manifestDir, "manifest.json"), []byte(`{"identifier":"demo.plugin","name":"Demo Plugin","version":"1.0.0"}`), 0o640); err != nil {
		t.Fatalf("WriteFile() error = %v", err)
	}
	runtime, err := New(Config{InstanceID: "42", CustomerID: "7", ServiceName: "easywi-musicbot-42", DataDir: filepath.Join(dir, "data"), LogDir: filepath.Join(dir, "logs"), PluginDir: pluginDir}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = runtime.Close() }()

	status := runtime.HandleCommand("status")
	plugins, ok := status.Payload["plugins"].(map[string]any)
	if !ok {
		t.Fatalf("plugins payload = %#v", status.Payload["plugins"])
	}
	if plugins["directory"] != pluginDir || plugins["execution_enabled"] != false {
		t.Fatalf("plugins payload = %#v", plugins)
	}
	manifests, ok := plugins["manifests"].([]map[string]any)
	if !ok || len(manifests) != 1 || manifests[0]["identifier"] != "demo.plugin" {
		t.Fatalf("manifests = %#v", plugins["manifests"])
	}
}
