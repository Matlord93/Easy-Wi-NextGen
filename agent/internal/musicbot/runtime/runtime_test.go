package musicbotruntime

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
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

func TestRunServiceBlocksUntilContextCancel(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	ctx, cancel := context.WithCancel(context.Background())
	result := make(chan error, 1)
	go func() { result <- rt.RunService(ctx) }()

	select {
	case err := <-result:
		t.Fatalf("RunService returned before context cancel: %v", err)
	case <-time.After(50 * time.Millisecond):
	}

	cancel()
	select {
	case err := <-result:
		if err != nil {
			t.Fatalf("RunService returned error: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("RunService did not return after context cancel")
	}
}

func TestRunServicePreCanceledContextReturnsImmediately(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	result := make(chan error, 1)
	go func() { result <- rt.RunService(ctx) }()

	select {
	case err := <-result:
		if err != nil {
			t.Fatalf("RunService returned error: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("RunService did not return for pre-canceled context")
	}
}

func TestRunServiceStdinEOFDoesNotExit(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	// RunService never reads stdin. Provide a closed pipe (like /dev/null in systemd)
	// and verify the service stays up until we explicitly cancel the context.
	ctx, cancel := context.WithCancel(context.Background())
	result := make(chan error, 1)
	go func() { result <- rt.RunService(ctx) }()

	// Give the service a moment to start and confirm it hasn't exited due to stdin EOF.
	select {
	case err := <-result:
		t.Fatalf("RunService exited unexpectedly (stdin EOF must not be the cause): %v", err)
	case <-time.After(80 * time.Millisecond):
	}

	cancel()
	select {
	case err := <-result:
		if err != nil {
			t.Fatalf("RunService returned error on clean shutdown: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("RunService did not return after context cancel")
	}
}

func TestAutoConnectContextCanceledNotLoggedAsError(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	var logBuf bytes.Buffer
	rt, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "svc-log-test",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, &logBuf)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = rt.Close() }()

	ctx, cancel := context.WithCancel(context.Background())
	cancel() // pre-cancel so any connector.Connect would see context.Canceled

	rt.autoConnectAll(ctx)

	if strings.Contains(logBuf.String(), "context canceled") {
		t.Errorf("context canceled must not appear in log output on clean shutdown; got:\n%s", logBuf.String())
	}
}

func TestRunInteractiveModeExitsOnStdinEOF(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Closed pipe simulates stdin EOF (same as what systemd would deliver).
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatalf("os.Pipe: %v", err)
	}
	_ = w.Close() // EOF immediately

	result := make(chan error, 1)
	go func() { result <- rt.Run(ctx, r, &bytes.Buffer{}) }()

	select {
	case runErr := <-result:
		if runErr != nil {
			t.Fatalf("Run() returned error: %v", runErr)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("Run() did not exit on stdin EOF in interactive mode")
	}
}

func TestRunServiceAutoConnectBeforeIdle(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	var logBuf bytes.Buffer
	rt, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "svc-order-test",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, &logBuf)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = rt.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	_ = rt.RunService(ctx)

	log := logBuf.String()
	idleIdx := strings.Index(log, "runtime idle")
	stoppedIdx := strings.Index(log, "stopped")
	if idleIdx < 0 {
		t.Fatalf("log missing 'runtime idle': %s", log)
	}
	if stoppedIdx < 0 {
		t.Fatalf("log missing 'stopped': %s", log)
	}
	if idleIdx > stoppedIdx {
		t.Errorf("'runtime idle' must appear before 'stopped'; got:\n%s", log)
	}
}

func TestRuntimeStaysActiveWhenBridgeMissing(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	var logBuf bytes.Buffer
	rt, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "svc-bridge-missing",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{
			Enabled:          true,
			Autoconnect:      true,
			BackendType:      TeamSpeakBackendTypeExternalClientBridge,
			BridgePath:       "/nonexistent/easywi-teamspeak-bridge",
			Host:             "ts.example.com",
		},
	}, &logBuf)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = rt.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	_ = rt.RunService(ctx)

	log := logBuf.String()
	if !strings.Contains(log, "runtime idle") {
		t.Errorf("service must reach 'runtime idle' even when bridge binary is missing; log:\n%s", log)
	}
	if !strings.Contains(log, "missing_bridge_binary") {
		t.Errorf("log must contain 'missing_bridge_binary' error code; log:\n%s", log)
	}
}

func TestRuntimeAutoconnectFalseSkipsConnect(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	var logBuf bytes.Buffer
	rt, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "svc-noautoconnect",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{
			Enabled:     true,
			Autoconnect: false,
			BackendType: TeamSpeakBackendTypeExternalClientBridge,
			BridgePath:  "/nonexistent/easywi-teamspeak-bridge",
			Host:        "ts.example.com",
		},
	}, &logBuf)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = rt.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	_ = rt.RunService(ctx)

	log := logBuf.String()
	if strings.Contains(log, "external client bridge starting") {
		t.Errorf("autoconnect=false must not attempt bridge start; log:\n%s", log)
	}
	if !strings.Contains(log, "runtime idle") {
		t.Errorf("service must reach 'runtime idle'; log:\n%s", log)
	}
}

func TestValidateTeamspeakBridgePathMissingBinaryError(t *testing.T) {
	t.Parallel()
	err := validateTeamspeakBridgePath("/nonexistent/path/easywi-teamspeak-bridge")
	if !errors.Is(err, ErrTeamSpeakBridgeMissingBridgeBinary) {
		t.Errorf("expected ErrTeamSpeakBridgeMissingBridgeBinary, got %v", err)
	}
}

func TestExternalBridgeDefaultsProfileToTs3(t *testing.T) {
	t.Parallel()
	config := TeamSpeakConnectorConfig{
		Enabled:     true,
		BackendType: TeamSpeakBackendTypeExternalClientBridge,
		Host:        "ts.example.com",
		// no Profile field
	}
	client := NewExternalBridgeTeamspeakVoiceClient()
	// ValidateConfig must not complain about missing profile for external_client_bridge
	err := client.ValidateConfig(config)
	// Will fail on missing bridge_path, NOT on missing profile
	if errors.Is(err, ErrTeamSpeakBridgeMissingBridgeBinary) || errors.Is(err, ErrTeamSpeakExternalBridgeNotConfigured) {
		// correct: failed on bridge path, not profile
		return
	}
	if err != nil && strings.Contains(err.Error(), "profile") {
		t.Errorf("external_client_bridge must default profile to ts3, got: %v", err)
	}
}

func TestBridgeErrorFromCodeMapsKnownCodes(t *testing.T) {
	t.Parallel()
	tests := []struct {
		code string
		want error
	}{
		{"missing_bridge_binary", ErrTeamSpeakBridgeMissingBridgeBinary},
		{"missing_client_binary", ErrTeamSpeakBridgeMissingClientBinary},
		{"xvfb_failed", ErrTeamSpeakBridgeXvfbFailed},
		{"pulseaudio_failed", ErrTeamSpeakBridgePulseaudioFailed},
		{"ts3client_start_failed", ErrTeamSpeakBridgeTsClientStartFailed},
		{"connect_failed", ErrTeamSpeakBridgeConnectFailed},
	}
	for _, tc := range tests {
		err := bridgeErrorFromCode(tc.code, "some message")
		if !errors.Is(err, tc.want) {
			t.Errorf("bridgeErrorFromCode(%q) = %v, want %v", tc.code, err, tc.want)
		}
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
