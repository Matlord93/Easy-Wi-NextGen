package musicbotruntime

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
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
	if play.OK {
		t.Fatalf("play response = %#v", play)
	}
	playback, ok := play.Payload["playback"].(PlaybackState)
	if !ok || playback.State != "stopped" || playback.LastError == "" {
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
			Enabled:     true,
			Autoconnect: true,
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

func TestExternalBridgeRuntimeStartupPublishesReadyStatus(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	bridgePath := filepath.Join(dir, "fake-easywi-teamspeak-bridge")
	script := `#!/bin/sh
while IFS= read -r line; do
  case "$line" in
    *'"action":"connect"'*)
      echo 'protocol_ready=true' >&2
      echo 'connect_step=start' >&2
      echo 'connect_step=wait_clientquery_done clientquery_listening=true' >&2
      echo 'external_client_bridge connect_sent=true' >&2
      echo 'external_client_bridge ts_server_connected=true connected_clid=28 connected_cid=1' >&2
      echo 'external_client_bridge state_connected=true capability_status=ready voice_client_available=true' >&2
      echo 'external_client_bridge audio_injection_ready=true pulseaudio_pid=123 pulse_socket=/tmp/pulse.sock sink=easywi_sink source=easywi_source' >&2
      printf '%s\n' '{"ok":true,"backend_type":"external_client_bridge","ready":true,"state":"connected","client_id":"28"}'
      ;;
    *'"action":"join_channel"'*)
      printf '%s\n' '{"ok":true,"channel_id":"1"}'
      ;;
    *'"action":"disconnect"'*)
      printf '%s\n' '{"ok":true,"state":"disconnected"}'
      ;;
    *)
      printf '%s\n' '{"ok":true,"state":"connected","client_id":"28","channel_id":"1"}'
      ;;
  esac
done
`
	if err := os.WriteFile(bridgePath, []byte(script), 0o755); err != nil {
		t.Fatalf("WriteFile() error = %v", err)
	}

	var logBuf bytes.Buffer
	rt, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "svc-bridge-ready",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{
			Enabled:          true,
			Autoconnect:      true,
			BackendType:      TeamSpeakBackendTypeExternalClientBridge,
			BridgePath:       bridgePath,
			ClientBinaryPath: filepath.Join(dir, "ts3client_linux_amd64"),
			Host:             "ts.example.com",
			ChannelID:        "1",
		},
	}, &logBuf)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = rt.Close() }()

	rt.autoConnectAll(context.Background())

	statusResp := rt.HandleCommand("status")
	if !statusResp.OK {
		t.Fatalf("status response not ok: %#v", statusResp)
	}
	connectors := statusResp.Payload["connectors"].(map[string]any)
	teamspeak := connectors["teamspeak"].(ConnectionStatus)
	if !teamspeak.Connected || !teamspeak.VoiceClientAvailable || teamspeak.CapabilityStatus != CapabilityStatusReady {
		t.Fatalf("teamspeak status = %#v, want connected ready", teamspeak)
	}
	playbackStatus := statusResp.Payload["playback_status"].(map[string]any)
	if playbackStatus["audio_injection_ready"] != true || playbackStatus["audio_backend_ready"] != true {
		t.Fatalf("playback_status audio readiness = %#v, want ready", playbackStatus)
	}

	log := logBuf.String()
	for _, want := range []string{"bridge_event=BridgeStarted", "bridge_event=ClientQueryReady", "bridge_event=ClientConnected", "bridge_event=ServerConnected", "bridge_event=AudioInjectionReady", "event=RuntimeStatusPublished", "capability_status=ready"} {
		if !strings.Contains(log, want) {
			t.Fatalf("log missing %q:\n%s", want, log)
		}
	}
}

func TestExternalBridgeConnectTimesOutInsteadOfWaitingForever(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	bridgePath := filepath.Join(dir, "fake-hanging-easywi-teamspeak-bridge")
	script := "#!/bin/sh\nread line\ncat >/dev/null\n"
	if err := os.WriteFile(bridgePath, []byte(script), 0o755); err != nil {
		t.Fatalf("WriteFile() error = %v", err)
	}
	client := NewExternalBridgeTeamspeakVoiceClient()
	ctx, cancel := context.WithTimeout(context.Background(), 100*time.Millisecond)
	defer cancel()
	err := client.Connect(ctx, TeamSpeakConnectorConfig{Enabled: true, BackendType: TeamSpeakBackendTypeExternalClientBridge, BridgePath: bridgePath, Host: "ts.example.com"})
	if !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("Connect() error = %v, want context deadline exceeded", err)
	}
}

func TestRuntimeAcceptsRadioURLPlaybackSource(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	resp := rt.HandleCommand(`{"command":"play","args":{"source_type":"radio","radio_url":"https://stream.example.com/live.mp3","source":{"type":"radio","uri":"https://stream.example.com/live.mp3","mime_type":"audio/mpeg"}}}`)
	if !resp.OK {
		t.Fatalf("radio play response = %#v", resp)
	}
	playback, ok := resp.Payload["playback"].(PlaybackState)
	if !ok {
		t.Fatalf("playback payload = %#v", resp.Payload["playback"])
	}
	if playback.State != "playing" || playback.Current != "https://stream.example.com/live.mp3" || playback.LastError != "" {
		t.Fatalf("playback = %#v", playback)
	}
}

func TestMapPanelVolumeToPulseVolume(t *testing.T) {
	cases := []struct {
		name  string
		panel int
		want  int
	}{
		{name: "zero mutes", panel: 0, want: 0},
		{name: "one audible", panel: 1, want: 15},
		{name: "seven mapped", panel: 7, want: 21},
		{name: "middle linear", panel: 50, want: 64},
		{name: "hundred boosted", panel: 100, want: 115},
		{name: "negative clamps", panel: -10, want: 0},
		{name: "over hundred clamps", panel: 250, want: 115},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := mapPanelVolumeToPulseVolume(tc.panel); got != tc.want {
				t.Fatalf("mapPanelVolumeToPulseVolume(%d) = %d, want %d", tc.panel, got, tc.want)
			}
		})
	}
}

func TestVolumeCommandSetsPanelAndPulseVolume(t *testing.T) {
	dir := t.TempDir()
	callsFile := filepath.Join(dir, "pactl-calls.json")
	fakePactl := filepath.Join(dir, "fake-pactl.sh")
	script := fmt.Sprintf(`#!/bin/sh
printf '%%s\n' "$PULSE_SERVER|$*" >> %q
if [ "$1" = "get-sink-volume" ]; then
  echo 'Volume: front-left: 13763 /  21%% / -40.65 dB,   front-right: 13763 /  21%% / -40.65 dB'
fi
`, callsFile)
	if err := os.WriteFile(fakePactl, []byte(script), 0o700); err != nil {
		t.Fatalf("write fake pactl: %v", err)
	}
	oldExec := execCommandContext
	execCommandContext = func(ctx context.Context, name string, args ...string) *exec.Cmd {
		return exec.CommandContext(ctx, fakePactl, args...)
	}
	defer func() { execCommandContext = oldExec }()

	runtime, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "easywi-musicbot-42",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{Config: map[string]any{
			"pulse_socket":    filepath.Join(dir, "pulse.sock"),
			"playback_device": "easywi_sink_42",
		}},
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = runtime.Close() }()

	resp := runtime.HandleCommand(`{"command":"volume","args":{"value":7}}`)
	if !resp.OK {
		t.Fatalf("volume response = %#v", resp)
	}
	playback := resp.Payload["playback"].(PlaybackState)
	if playback.Volume != 7 {
		t.Fatalf("panel volume = %d, want 7", playback.Volume)
	}
	if playback.EffectivePulseVolume != 21 {
		t.Fatalf("effective pulse volume = %d, want 21", playback.EffectivePulseVolume)
	}
	if playback.ActualPulseVolume != 21 {
		t.Fatalf("actual pulse volume = %d, want 21", playback.ActualPulseVolume)
	}
	content, err := os.ReadFile(callsFile)
	if err != nil {
		t.Fatalf("read pactl calls: %v", err)
	}
	want := strings.Join([]string{
		"unix:" + filepath.Join(dir, "pulse.sock") + "|set-sink-volume easywi_sink_42 21%",
		"unix:" + filepath.Join(dir, "pulse.sock") + "|get-sink-volume easywi_sink_42",
	}, "\n")
	if got := strings.TrimSpace(string(content)); got != want {
		t.Fatalf("pactl call = %q, want %q", got, want)
	}
	if strings.Contains(string(content), "blackhole") {
		t.Fatalf("pactl must not use blackhole sink: %s", content)
	}
}

func TestVolumeCommandSurfacesPactlErrorInPlaybackStatus(t *testing.T) {
	dir := t.TempDir()
	fakePactl := filepath.Join(dir, "fake-pactl.sh")
	script := "#!/bin/sh\necho 'sink not found' >&2\nexit 9\n"
	if err := os.WriteFile(fakePactl, []byte(script), 0o700); err != nil {
		t.Fatalf("write fake pactl: %v", err)
	}
	oldExec := execCommandContext
	execCommandContext = func(ctx context.Context, name string, args ...string) *exec.Cmd {
		return exec.CommandContext(ctx, fakePactl, args...)
	}
	defer func() { execCommandContext = oldExec }()

	runtime, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "easywi-musicbot-42",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{Config: map[string]any{
			"pulse_socket":    filepath.Join(dir, "pulse.sock"),
			"playback_device": "easywi_sink_42",
		}},
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = runtime.Close() }()

	resp := runtime.HandleCommand(`{"command":"volume","args":{"value":7}}`)
	if !resp.OK {
		t.Fatalf("volume response = %#v", resp)
	}
	playback := resp.Payload["playback"].(PlaybackState)
	if playback.LastVolumeError == "" || !strings.Contains(playback.LastVolumeError, "sink not found") {
		t.Fatalf("last volume error = %q, want pactl stderr", playback.LastVolumeError)
	}
	status := runtime.HandleCommand("status")
	playbackStatus := status.Payload["playback_status"].(map[string]any)
	if got := asString(playbackStatus["last_error"]); !strings.Contains(got, "sink not found") {
		t.Fatalf("playback_status.last_error = %q, want pactl stderr", got)
	}
}

func TestResolvePulseServer(t *testing.T) {
	install := filepath.Join(string(filepath.Separator), "srv", "bot")
	bridgeSocket := filepath.Join(install, "runtime", "teamspeak-bridge", "runtime", "teamspeak-bridge", "pulse", "pulse.sock")
	cases := []struct {
		name       string
		install    string
		configured string
		want       string
	}{
		{name: "unix unchanged", install: install, configured: "unix:/tmp/pulse.sock", want: "unix:/tmp/pulse.sock"},
		{name: "absolute prefixed", install: install, configured: "/tmp/pulse.sock", want: "unix:/tmp/pulse.sock"},
		{name: "empty default", install: install, configured: "", want: "unix:" + filepath.Join(install, "runtime", "teamspeak-bridge", "pulse", "pulse.sock")},
		{name: "deduplicates bridge runtime", install: install, configured: bridgeSocket, want: "unix:" + filepath.Join(install, "runtime", "teamspeak-bridge", "pulse", "pulse.sock")},
		{name: "install path already bridge dir", install: filepath.Join(install, "runtime", "teamspeak-bridge"), configured: "", want: "unix:" + filepath.Join(install, "runtime", "teamspeak-bridge", "pulse", "pulse.sock")},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := resolvePulseServer(tc.install, tc.configured); got != tc.want {
				t.Fatalf("resolvePulseServer(%q, %q) = %q, want %q", tc.install, tc.configured, got, tc.want)
			}
		})
	}
}

func TestFindMusicSinkIgnoresBlackhole(t *testing.T) {
	output := "0\teasywi_ts3_playback_blackhole_42\tmodule-null-sink.c\tIDLE\n1\teasywi_sink_djj7oc4x9l0j\tmodule-null-sink.c\tIDLE\n"
	if got := findMusicSink(output); got != "easywi_sink_djj7oc4x9l0j" {
		t.Fatalf("findMusicSink() = %q, want easywi_sink_djj7oc4x9l0j", got)
	}
}

func TestFindMusicSinkPrefersRunning(t *testing.T) {
	output := "0\teasywi_sink_idle\tmodule-null-sink.c\tIDLE\n1\teasywi_sink_running\tmodule-null-sink.c\tRUNNING\n"
	if got := findMusicSink(output); got != "easywi_sink_running" {
		t.Fatalf("findMusicSink() = %q, want easywi_sink_running", got)
	}
}

func TestVolumeCommandDiscoversSinkWhenCacheEmpty(t *testing.T) {
	dir := t.TempDir()
	callsFile := filepath.Join(dir, "pactl-calls.log")
	fakePactl := filepath.Join(dir, "fake-pactl.sh")
	script := fmt.Sprintf(`#!/bin/sh
printf '%%s\n' "$PULSE_SERVER|$*" >> %q
if [ "$1 $2 $3" = "list short sinks" ]; then
  printf '0\teasywi_ts3_playback_blackhole_42\tmodule-null-sink.c\tIDLE\n1\teasywi_sink_djj7oc4x9l0j\tmodule-null-sink.c\tRUNNING\n'
elif [ "$1" = "get-sink-volume" ]; then
  echo 'Volume: front-left: 13763 /  21%% / -40.65 dB,   front-right: 13763 /  21%% / -40.65 dB'
fi
`, callsFile)
	if err := os.WriteFile(fakePactl, []byte(script), 0o700); err != nil {
		t.Fatalf("write fake pactl: %v", err)
	}
	oldExec := execCommandContext
	execCommandContext = func(ctx context.Context, name string, args ...string) *exec.Cmd {
		return exec.CommandContext(ctx, fakePactl, args...)
	}
	defer func() { execCommandContext = oldExec }()

	runtime, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "easywi-musicbot-42",
		InstallPath: dir,
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = runtime.Close() }()

	resp := runtime.HandleCommand(`{"command":"volume","args":{"value":7}}`)
	if !resp.OK {
		t.Fatalf("volume response = %#v", resp)
	}
	playback := resp.Payload["playback"].(PlaybackState)
	wantPulseServer := "unix:" + filepath.Join(dir, "runtime", "teamspeak-bridge", "pulse", "pulse.sock")
	if playback.PulseSink != "easywi_sink_djj7oc4x9l0j" {
		t.Fatalf("pulse sink = %q, want discovered sink", playback.PulseSink)
	}
	if playback.PulseServer != wantPulseServer {
		t.Fatalf("pulse server = %q, want %q", playback.PulseServer, wantPulseServer)
	}
	content, err := os.ReadFile(callsFile)
	if err != nil {
		t.Fatalf("read pactl calls: %v", err)
	}
	want := strings.Join([]string{
		wantPulseServer + "|list short sinks",
		wantPulseServer + "|set-sink-volume easywi_sink_djj7oc4x9l0j 21%",
		wantPulseServer + "|get-sink-volume easywi_sink_djj7oc4x9l0j",
	}, "\n")
	if got := strings.TrimSpace(string(content)); got != want {
		t.Fatalf("pactl calls = %q, want %q", got, want)
	}
}
