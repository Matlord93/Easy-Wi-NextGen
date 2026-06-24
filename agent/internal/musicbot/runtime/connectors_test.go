package musicbotruntime

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestPlaceholderTeamspeakVoiceClientValidatesConfig(t *testing.T) {
	t.Parallel()
	client := NewPlaceholderTeamspeakVoiceClient()

	if err := client.ValidateConfig(TeamSpeakConnectorConfig{Enabled: true, Config: map[string]any{}}); err == nil {
		t.Fatal("ValidateConfig() error = nil, want error for missing host")
	}
	if err := client.ValidateConfig(TeamSpeakConnectorConfig{Enabled: true, Profile: "mumble", Host: "127.0.0.1"}); err == nil {
		t.Fatal("ValidateConfig() error = nil, want error for invalid profile")
	}
	if err := client.ValidateConfig(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", Host: "127.0.0.1"}); err != nil {
		t.Fatalf("ValidateConfig() top-level config error = %v", err)
	}
	if err := client.ValidateConfig(TeamSpeakConnectorConfig{Enabled: true, Config: map[string]any{"profile": "ts6", "backend": "ts3_client_compatible", "host": "127.0.0.1"}}); err != nil {
		t.Fatalf("ValidateConfig() map config error = %v", err)
	}
}

func TestTeamSpeakConnectorStatusContainsProfileBackendAndCapability(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewTeamSpeakConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts6", Backend: "ts3_client_compatible", Host: "127.0.0.1"})

	if err := connector.Connect(ctx); err != nil {
		t.Fatalf("Connect() error = %v", err)
	}
	status := connector.GetStatus(ctx)
	if status.Profile != "ts6" || status.Backend != "ts3_client_compatible" || status.CapabilityStatus != CapabilityStatusClientBackendRequired || status.VoiceClientAvailable {
		t.Fatalf("status = %#v", status)
	}
}

func TestTeamSpeakConnectorJoinChannelRequiresNativeBackend(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewTeamSpeakConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", Host: "127.0.0.1"})
	if err := connector.Connect(ctx); err != nil {
		t.Fatalf("Connect() error = %v", err)
	}

	err := connector.JoinChannel(ctx, "123")
	if !errors.Is(err, ErrTeamSpeakVoiceBackendNotConfigured) {
		t.Fatalf("JoinChannel() error = %v, want %v", err, ErrTeamSpeakVoiceBackendNotConfigured)
	}
	if status := connector.GetStatus(ctx); status.LastError != teamSpeakVoiceBackendNotConfiguredMessage || status.CapabilityStatus != CapabilityStatusClientBackendRequired {
		t.Fatalf("status after JoinChannel() = %#v", status)
	}
}

func TestTeamSpeakConnectorSendOpusFrameRequiresNativeBackend(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewTeamSpeakConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", Host: "127.0.0.1"})
	if err := connector.Connect(ctx); err != nil {
		t.Fatalf("Connect() error = %v", err)
	}

	frame := AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, PCM: []byte{0, 1}}
	err := connector.SendAudioFrame(ctx, frame)
	if !errors.Is(err, ErrTeamSpeakVoiceBackendNotConfigured) {
		t.Fatalf("SendAudioFrame() error = %v, want %v", err, ErrTeamSpeakVoiceBackendNotConfigured)
	}
	if status := connector.GetStatus(ctx); status.LastError != teamSpeakVoiceBackendNotConfiguredMessage || status.CapabilityStatus != CapabilityStatusClientBackendRequired {
		t.Fatalf("status after SendAudioFrame() = %#v", status)
	}
}

func TestRuntimeRejectsInvalidEnabledTeamSpeakConnector(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	_, err := New(Config{
		InstanceID:  "42",
		CustomerID:  "7",
		ServiceName: "easywi-musicbot-42",
		DataDir:     dir + "/data",
		LogDir:      dir + "/logs",
		PluginDir:   dir + "/plugins",
		TeamSpeak:   TeamSpeakConnectorConfig{Enabled: true, Config: map[string]any{}},
	}, discardWriter{})
	if err == nil {
		t.Fatal("New() error = nil, want invalid TeamSpeak config error")
	}
}

type discardWriter struct{}

func (discardWriter) Write(p []byte) (int, error) { return len(p), nil }

func TestTeamSpeakConnectorSupportsTs3AndTs6Profiles(t *testing.T) {
	t.Parallel()
	for _, profile := range []string{"ts3", "ts6"} {
		connector := NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: profile, Backend: "ts3_client_compatible", Host: "127.0.0.1"})
		if err := connector.ValidateConfig(); err != nil {
			t.Fatalf("ValidateConfig(%s) error = %v", profile, err)
		}
		if status := connector.GetStatus(context.Background()); status.Profile != profile || status.Backend != "ts3_client_compatible" || status.CapabilityStatus != CapabilityStatusClientBackendRequired {
			t.Fatalf("status(%s) = %#v", profile, status)
		}
	}
}

func TestNativeSDKTeamspeakBackendWithoutSDKFailsCleanly(t *testing.T) {
	t.Parallel()
	client := NewNativeSdkTeamspeakVoiceClient()
	cfg := TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", BackendType: TeamSpeakBackendTypeNativeSDK, Host: "127.0.0.1"}
	if err := client.ValidateConfig(cfg); !errors.Is(err, ErrTeamSpeakNativeSDKNotInstalled) {
		t.Fatalf("ValidateConfig() = %v, want %v", err, ErrTeamSpeakNativeSDKNotInstalled)
	}
	if err := client.SendOpusFrame(context.Background(), AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1}}); !errors.Is(err, ErrTeamSpeakNativeSDKNotInstalled) {
		t.Fatalf("SendOpusFrame() = %v, want %v", err, ErrTeamSpeakNativeSDKNotInstalled)
	}
}

func TestExternalBridgeTeamspeakBackendWithoutPathFailsCleanly(t *testing.T) {
	t.Parallel()
	client := NewExternalBridgeTeamspeakVoiceClient()
	cfg := TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", BackendType: TeamSpeakBackendTypeExternalClientBridge, Host: "127.0.0.1"}
	if err := client.ValidateConfig(cfg); !errors.Is(err, ErrTeamSpeakExternalBridgeNotConfigured) {
		t.Fatalf("ValidateConfig() = %v, want %v", err, ErrTeamSpeakExternalBridgeNotConfigured)
	}
}

func TestTeamSpeakConnectorSelectsBackendTypeAndReportsRequired(t *testing.T) {
	t.Parallel()
	connector := NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", BackendType: TeamSpeakBackendTypeNativeSDK, Host: "127.0.0.1"})
	status := connector.GetStatus(context.Background())
	if status.BackendType != TeamSpeakBackendTypeNativeSDK || status.CapabilityStatus != CapabilityStatusClientBackendRequired || status.Connected || status.VoiceClientAvailable || status.OutputBackend != "null" {
		t.Fatalf("status = %#v", status)
	}
}

func TestTeamspeakAudioOutputRequiresReadyBackend(t *testing.T) {
	t.Parallel()
	output := NewTeamspeakAudioOutput(NewPlaceholderTeamspeakVoiceClient())
	err := output.SendAudioFrame(context.Background(), AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1}})
	if !errors.Is(err, ErrTeamSpeakVoiceNotReady) {
		t.Fatalf("SendAudioFrame() = %v, want %v", err, ErrTeamSpeakVoiceNotReady)
	}
}

func TestTeamSpeakStatusDoesNotLeakSecrets(t *testing.T) {
	t.Parallel()
	secret := "secret-channel-password"
	connector := NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", Host: "127.0.0.1", ChannelPassword: secret})
	_ = connector.Connect(context.Background())
	_ = connector.JoinChannel(context.Background(), "123")
	status := connector.GetStatus(context.Background())
	if strings.Contains(status.LastError, secret) {
		t.Fatalf("LastError leaked secret: %q", status.LastError)
	}
}

func TestTeamspeakAudioOutputSendsOnlyWhenVoiceReady(t *testing.T) {
	t.Parallel()
	client := &mockReadyTeamspeakVoiceClient{state: ConnectionStateConnected}
	notReady := NewTeamspeakAudioOutputWithReadiness(client, func(context.Context) bool { return false }, TeamSpeakConnectorConfig{})
	frame := AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1}}
	if err := notReady.SendAudioFrame(context.Background(), frame); !errors.Is(err, ErrTeamSpeakVoiceNotReady) {
		t.Fatalf("SendAudioFrame(not ready) = %v, want %v", err, ErrTeamSpeakVoiceNotReady)
	}
	if client.sent != 0 {
		t.Fatalf("sent = %d, want 0", client.sent)
	}
	ready := NewTeamspeakAudioOutputWithReadiness(client, func(context.Context) bool { return true }, TeamSpeakConnectorConfig{})
	if err := ready.SendAudioFrame(context.Background(), frame); err != nil {
		t.Fatalf("SendAudioFrame(ready) = %v", err)
	}
	if client.sent != 1 {
		t.Fatalf("sent = %d, want 1", client.sent)
	}
}

func TestTeamspeakAudioOutputErrorLandsInPipelineLastOutputErrorAndMasksSecrets(t *testing.T) {
	t.Parallel()
	secret := "secret-server-password"
	client := &mockReadyTeamspeakVoiceClient{state: ConnectionStateConnected, sendErr: errors.New("send failed with " + secret)}
	output := NewTeamspeakAudioOutputWithReadiness(client, func(context.Context) bool { return true }, TeamSpeakConnectorConfig{ServerPassword: secret})
	pipeline := NewAudioPipeline(nil, nil, nil, nil, output)
	err := pipeline.Output(context.Background(), AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1}})
	if err == nil {
		t.Fatal("Output() error = nil, want send error")
	}
	status := pipeline.Snapshot()
	if strings.Contains(status.LastError, secret) || strings.Contains(status.LastOutputError, secret) {
		t.Fatalf("pipeline status leaked secret: %#v", status)
	}
	if status.LastOutputError == "" || status.FramesSent != 0 {
		t.Fatalf("status = %#v", status)
	}
}

func TestRuntimeSelectAudioOutputFallsBackToNullWhenTeamSpeakNotReady(t *testing.T) {
	t.Parallel()
	r := &Runtime{connectors: map[string]Connector{"teamspeak": NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", Host: "127.0.0.1"})}, pipeline: NewAudioPipeline(nil, nil, nil, nil, nil)}
	r.selectAudioOutput(context.Background())
	if got := r.pipeline.OutputBackendName(); got != "null" {
		t.Fatalf("OutputBackendName() = %q, want null", got)
	}
}

func TestTeamSpeakProfilesRemainInPlaybackStatus(t *testing.T) {
	t.Parallel()
	for _, profile := range []string{"ts3", "ts6"} {
		r := &Runtime{config: Config{TeamSpeak: TeamSpeakConnectorConfig{Profile: profile}}, connectors: map[string]Connector{"teamspeak": NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: profile, Backend: "ts3_client_compatible", Host: "127.0.0.1"})}, pipeline: NewAudioPipeline(nil, nil, nil, nil, nil)}
		status := r.buildPlaybackStatusLocked(r.pipeline.Snapshot())
		if status["teamspeak_profile"] != profile {
			t.Fatalf("teamspeak_profile(%s) = %#v", profile, status["teamspeak_profile"])
		}
	}
}

type mockReadyTeamspeakVoiceClient struct {
	state   ConnectionState
	sendErr error
	sent    int
}

func (m *mockReadyTeamspeakVoiceClient) Connect(ctx context.Context, config TeamSpeakConnectorConfig) error {
	return nil
}
func (m *mockReadyTeamspeakVoiceClient) Disconnect(ctx context.Context) error {
	m.state = ConnectionStateDisconnected
	return nil
}
func (m *mockReadyTeamspeakVoiceClient) Reconnect(ctx context.Context) error    { return nil }
func (m *mockReadyTeamspeakVoiceClient) Authenticate(ctx context.Context) error { return nil }
func (m *mockReadyTeamspeakVoiceClient) SetNickname(ctx context.Context, nickname string) error {
	return nil
}
func (m *mockReadyTeamspeakVoiceClient) JoinChannel(ctx context.Context, channelID string, password string) error {
	return nil
}
func (m *mockReadyTeamspeakVoiceClient) LeaveChannel(ctx context.Context) error { return nil }
func (m *mockReadyTeamspeakVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	if m.sendErr != nil {
		return m.sendErr
	}
	m.sent++
	return nil
}
func (m *mockReadyTeamspeakVoiceClient) GetClientID(ctx context.Context) (string, error) {
	return "client-1", nil
}
func (m *mockReadyTeamspeakVoiceClient) GetConnectionState(ctx context.Context) ConnectionState {
	return m.state
}
func (m *mockReadyTeamspeakVoiceClient) GetLastError() string { return "" }

func TestExternalBridgeTeamspeakBackendConnectJoinSendReconnect(t *testing.T) {
	t.Parallel()
	bridge := writeMockTeamspeakBridge(t, false)
	client := NewExternalBridgeTeamspeakVoiceClient()
	cfg := TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", BackendType: TeamSpeakBackendTypeExternalClientBridge, BackendPath: bridge, Host: "127.0.0.1", ChannelID: "123"}
	if err := client.Connect(context.Background(), cfg); err != nil {
		t.Fatalf("Connect() = %v", err)
	}
	if state := client.GetConnectionState(context.Background()); state != ConnectionStateConnected {
		t.Fatalf("state = %q, want connected", state)
	}
	if id, _ := client.GetClientID(context.Background()); id != "mock-client" {
		t.Fatalf("client id = %q", id)
	}
	if err := client.JoinChannel(context.Background(), "123", ""); err != nil {
		t.Fatalf("JoinChannel() = %v", err)
	}
	if err := client.SendOpusFrame(context.Background(), AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1, 2}}); err != nil {
		t.Fatalf("SendOpusFrame() = %v", err)
	}
	if err := client.Reconnect(context.Background()); err != nil {
		t.Fatalf("Reconnect() = %v", err)
	}
	_ = client.Disconnect(context.Background())
}

func TestTeamSpeakConnectorExternalBridgeReportsReadyStatus(t *testing.T) {
	t.Parallel()
	bridge := writeMockTeamspeakBridge(t, false)
	connector := NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{Enabled: true, Profile: "ts6", Backend: "ts3_client_compatible", BackendType: TeamSpeakBackendTypeExternalClientBridge, BackendPath: bridge, Host: "127.0.0.1", ChannelID: "123"})
	if err := connector.Connect(context.Background()); err != nil {
		t.Fatalf("Connect() = %v", err)
	}
	if err := connector.JoinChannel(context.Background(), "123"); err != nil {
		t.Fatalf("JoinChannel() = %v", err)
	}
	status := connector.GetStatus(context.Background())
	if !status.Connected || !status.VoiceClientAvailable || status.CapabilityStatus != CapabilityStatusReady || status.OutputBackend != "teamspeak_voice" || status.Profile != "ts6" || status.ClientID != "mock-client" || status.ChannelID != "123" {
		t.Fatalf("status = %#v", status)
	}
	_ = connector.Disconnect(context.Background())
}

func TestExternalBridgeTeamspeakBackendJoinAndSendErrors(t *testing.T) {
	t.Parallel()
	bridge := writeMockTeamspeakBridge(t, true)
	client := NewExternalBridgeTeamspeakVoiceClient()
	cfg := TeamSpeakConnectorConfig{Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", BackendType: TeamSpeakBackendTypeExternalClientBridge, BackendPath: bridge, Host: "127.0.0.1", ChannelPassword: "super-secret"}
	if err := client.Connect(context.Background(), cfg); err != nil {
		t.Fatalf("Connect() = %v", err)
	}
	if err := client.JoinChannel(context.Background(), "bad", "super-secret"); err == nil || strings.Contains(err.Error(), "super-secret") {
		t.Fatalf("JoinChannel() err = %v, want redacted failure", err)
	}
	if err := client.SendOpusFrame(context.Background(), AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1}}); err == nil {
		t.Fatal("SendOpusFrame() error = nil, want bridge failure")
	}
	_ = client.Disconnect(context.Background())
}

// TestTeamspeakConfigStringInstancePathAndRuntimeDir verifies that teamspeakConfigString
// returns instance_path and runtime_dir from the direct struct fields.
func TestTeamspeakConfigStringInstancePathAndRuntimeDir(t *testing.T) {
	t.Parallel()
	cfg := TeamSpeakConnectorConfig{
		InstancePath: "/var/lib/easywi/musicbot/musicbot-test-33a166",
		RuntimeDir:   "/var/lib/easywi/musicbot/musicbot-test-33a166/runtime/teamspeak-bridge",
	}
	if got := teamspeakConfigString(cfg, "instance_path"); got != cfg.InstancePath {
		t.Errorf("teamspeakConfigString(instance_path) = %q, want %q", got, cfg.InstancePath)
	}
	if got := teamspeakConfigString(cfg, "runtime_dir"); got != cfg.RuntimeDir {
		t.Errorf("teamspeakConfigString(runtime_dir) = %q, want %q", got, cfg.RuntimeDir)
	}
}

// TestTeamspeakConfigStringInstancePathFallsBackToConfigMap verifies that when
// InstancePath is not set in the struct, it is read from Config map.
func TestTeamspeakConfigStringInstancePathFallsBackToConfigMap(t *testing.T) {
	t.Parallel()
	cfg := TeamSpeakConnectorConfig{
		Config: map[string]any{
			"instance_path": "/from/map",
			"runtime_dir":   "/from/map/runtime",
		},
	}
	if got := teamspeakConfigString(cfg, "instance_path"); got != "/from/map" {
		t.Errorf("teamspeakConfigString(instance_path) = %q, want /from/map", got)
	}
	if got := teamspeakConfigString(cfg, "runtime_dir"); got != "/from/map/runtime" {
		t.Errorf("teamspeakConfigString(runtime_dir) = %q, want /from/map/runtime", got)
	}
}

// TestTeamspeakConfigClientQueryPortFromStructField verifies that
// teamspeakConfigClientQueryPort returns ClientQueryPort from the struct field.
func TestTeamspeakConfigClientQueryPortFromStructField(t *testing.T) {
	t.Parallel()
	cfg := TeamSpeakConnectorConfig{ClientQueryPort: 25641}
	if got := teamspeakConfigClientQueryPort(cfg); got != 25641 {
		t.Errorf("teamspeakConfigClientQueryPort() = %d, want 25641", got)
	}
}

// TestTeamspeakConfigClientQueryPortFallsBackToConfigMap verifies that when
// ClientQueryPort is not set in the struct, it is read from the Config map.
func TestTeamspeakConfigClientQueryPortFallsBackToConfigMap(t *testing.T) {
	t.Parallel()
	cfg := TeamSpeakConnectorConfig{
		Config: map[string]any{"client_query_port": float64(25642)},
	}
	if got := teamspeakConfigClientQueryPort(cfg); got != 25642 {
		t.Errorf("teamspeakConfigClientQueryPort() = %d, want 25642", got)
	}
}

// TestTeamspeakConfigClientQueryPortZeroWhenUnset verifies that
// teamspeakConfigClientQueryPort returns 0 when not configured (no default).
func TestTeamspeakConfigClientQueryPortZeroWhenUnset(t *testing.T) {
	t.Parallel()
	if got := teamspeakConfigClientQueryPort(TeamSpeakConnectorConfig{}); got != 0 {
		t.Errorf("teamspeakConfigClientQueryPort() = %d, want 0", got)
	}
}

// TestTeamspeakConfigStringClientQueryHostFromStructField verifies that
// teamspeakConfigString returns ClientQueryHost from the struct field.
func TestTeamspeakConfigStringClientQueryHostFromStructField(t *testing.T) {
	t.Parallel()
	cfg := TeamSpeakConnectorConfig{ClientQueryHost: "127.0.0.1"}
	if got := teamspeakConfigString(cfg, "client_query_host"); got != "127.0.0.1" {
		t.Errorf("teamspeakConfigString(client_query_host) = %q, want 127.0.0.1", got)
	}
}

// TestTeamspeakConfigStringClientQueryHostFallsBackToConfigMap verifies that
// when ClientQueryHost is not set in the struct, it is read from Config map.
func TestTeamspeakConfigStringClientQueryHostFallsBackToConfigMap(t *testing.T) {
	t.Parallel()
	cfg := TeamSpeakConnectorConfig{
		Config: map[string]any{"client_query_host": "192.168.1.5"},
	}
	if got := teamspeakConfigString(cfg, "client_query_host"); got != "192.168.1.5" {
		t.Errorf("teamspeakConfigString(client_query_host) = %q, want 192.168.1.5", got)
	}
}

// TestExternalBridgeConnectPassesInstancePathAndRuntimeDir verifies that
// ExternalBridgeTeamspeakVoiceClient.Connect sends instance_path and runtime_dir
// to the bridge subprocess in the connect request.
func TestExternalBridgeConnectPassesInstancePathAndRuntimeDir(t *testing.T) {
	t.Parallel()
	captureFile := filepath.Join(t.TempDir(), "captured.json")
	path := filepath.Join(t.TempDir(), "mock-bridge-capture")
	script := "#!/bin/sh\nwhile IFS= read -r line; do\n  case \"$line\" in\n    *disconnect*) echo '{\"ok\":true}' ; exit 0 ;;\n    *connect*) echo \"$line\" > " + captureFile + " ; echo '{\"ok\":true,\"state\":\"connected\",\"client_id\":\"c1\"}' ;;\n    *) echo '{\"ok\":true}' ;;\n  esac\ndone\n"
	if err := os.WriteFile(path, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}

	client := NewExternalBridgeTeamspeakVoiceClient()
	cfg := TeamSpeakConnectorConfig{
		Enabled:         true,
		Profile:         "ts3",
		Backend:         "ts3_client_compatible",
		BackendType:     TeamSpeakBackendTypeExternalClientBridge,
		BackendPath:     path,
		Host:            "127.0.0.1",
		InstancePath:    "/var/lib/easywi/musicbot/musicbot-test-33a166",
		RuntimeDir:      "/var/lib/easywi/musicbot/musicbot-test-33a166/runtime/teamspeak-bridge",
		ClientQueryHost: "127.0.0.1",
		ClientQueryPort: 25641,
	}
	if err := client.Connect(context.Background(), cfg); err != nil {
		t.Fatalf("Connect() = %v", err)
	}
	_ = client.Disconnect(context.Background())

	captured, err := os.ReadFile(captureFile)
	if err != nil {
		t.Fatalf("captured request not written: %v", err)
	}
	for _, want := range []string{`"instance_path":`, `"runtime_dir":`, `"client_query_host":`, `"client_query_port":`} {
		if !strings.Contains(string(captured), want) {
			t.Errorf("connect request missing %s; got: %s", want, captured)
		}
	}
}

// writeMockTeamspeakBridge returns a path to a mock bridge executable by
// symlinking the test binary itself. TestMain detects the "mock-ts-bridge"
// base name and runs runMockBridgeProtocol instead of the test suite.
// A "mock-ts-bridge.fail" marker file in the same directory enables fail mode.
// Using a symlink to a compiled binary avoids the ETXTBSY race that occurs on
// Linux when parallel goroutines fork while another OS thread holds an
// O_WRONLY fd to a freshly-written shell script at the same path.
func writeMockTeamspeakBridge(t *testing.T, fail bool) string {
	t.Helper()
	exe, err := os.Executable()
	if err != nil {
		t.Fatalf("os.Executable: %v", err)
	}
	dir := t.TempDir()
	path := filepath.Join(dir, "mock-ts-bridge")
	if err := os.Symlink(exe, path); err != nil {
		t.Fatalf("symlink mock bridge: %v", err)
	}
	if fail {
		if err := os.WriteFile(filepath.Join(dir, "mock-ts-bridge.fail"), nil, 0o600); err != nil {
			t.Fatalf("write fail marker: %v", err)
		}
	}
	return path
}
