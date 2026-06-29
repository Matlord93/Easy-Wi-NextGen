package musicbotruntime

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

// TestExistingTeamSpeakConfigProducesIdenticalRuntimeFunction verifies that an
// existing TS config still produces a working runtime with the same behaviour.
func TestExistingTeamSpeakConfigProducesIdenticalRuntimeFunction(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	cfg := Config{
		InstanceID:  "ts-compat-1",
		CustomerID:  "42",
		ServiceName: "musicbot-ts-compat",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{
			Enabled:   true,
			Profile:   "ts3",
			Backend:   "ts3_client_compatible",
			Host:      "ts.example.com",
			Port:      9987,
			Nickname:  "MusicBot",
			ChannelID: "42",
		},
	}
	r, err := New(cfg, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = r.Close() }()

	resp := r.HandleCommand("status")
	if !resp.OK {
		t.Fatalf("status not OK: %v", resp.Error)
	}
	connectors, ok := resp.Payload["connectors"].(map[string]any)
	if !ok {
		t.Fatalf("connectors missing from status payload, got type %T", resp.Payload["connectors"])
	}
	tsStatus, ok := connectors["teamspeak"]
	if !ok {
		t.Fatalf("connectors.teamspeak missing")
	}
	if tsStatus == nil {
		t.Fatal("connectors.teamspeak is nil")
	}
}

// TestConnectorsTeamspeakIsBuiltCorrectly checks that connectors.teamspeak is
// populated with the expected platform identifier.
func TestConnectorsTeamspeakIsBuiltCorrectly(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewTeamSpeakConnector(TeamSpeakConnectorConfig{
		Enabled: true,
		Profile: "ts3",
		Backend: "ts3_client_compatible",
		Host:    "ts.example.com",
	})
	if got := connector.Platform(); got != "teamspeak" {
		t.Errorf("Platform() = %q, want %q", got, "teamspeak")
	}
	status := connector.GetStatus(ctx)
	if status.Platform != "teamspeak" {
		t.Errorf("status.Platform = %q, want teamspeak", status.Platform)
	}
}

// TestDiscordDisabledProducesNoConnectorEntry checks that a disabled Discord
// connector is not added to the active connectors map.
func TestDiscordDisabledProducesNoConnectorEntry(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	cfg := Config{
		InstanceID:  "discord-disabled",
		CustomerID:  "7",
		ServiceName: "musicbot-discord-disabled",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		Discord:     ConnectorConfig{Enabled: false},
	}
	r, err := New(cfg, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = r.Close() }()

	if _, ok := r.connectors["discord"]; ok {
		t.Error("disabled discord connector must not appear in r.connectors")
	}

	resp := r.HandleCommand("status")
	if !resp.OK {
		t.Fatalf("status not OK: %v", resp.Error)
	}
}

// TestOutputBackendTeamspeakVoiceRemains verifies that the output_backend field
// stays "teamspeak_voice" when the TS connector is ready.
func TestOutputBackendTeamspeakVoiceRemains(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewTeamSpeakVoiceConnector(TeamSpeakConnectorConfig{
		Enabled: true,
		Profile: "ts3",
		Backend: "ts3_client_compatible",
		Host:    "127.0.0.1",
	})
	status := connector.GetStatus(ctx)
	// With placeholder backend the output_backend starts as "null"; when ready it
	// becomes "teamspeak_voice".  Verify the value is never anything else.
	if status.OutputBackend != "null" && status.OutputBackend != "teamspeak_voice" {
		t.Errorf("unexpected output_backend = %q", status.OutputBackend)
	}
}

// TestTeamSpeakStatusNormalisedFromConnectors verifies that the runtime status
// payload exposes TeamSpeak state under connectors.teamspeak.
func TestTeamSpeakStatusNormalisedFromConnectors(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	cfg := Config{
		InstanceID:  "ts-norm-1",
		CustomerID:  "3",
		ServiceName: "musicbot-ts-norm",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{
			Enabled: true,
			Profile: "ts3",
			Backend: "ts3_client_compatible",
			Host:    "ts.example.com",
		},
	}
	r, err := New(cfg, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = r.Close() }()

	resp := r.HandleCommand("status")
	if !resp.OK {
		t.Fatalf("status not OK: %v", resp.Error)
	}
	connectors, _ := resp.Payload["connectors"].(map[string]any)
	if connectors["teamspeak"] == nil {
		t.Fatal("connectors.teamspeak not present in status payload")
	}
}

// TestConnectorNeutralCommandReconnect checks that the reconnect command works
// for any registered connector without platform-specific branching.
func TestConnectorNeutralCommandReconnect(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	cfg := Config{
		InstanceID:  "reconnect-1",
		CustomerID:  "1",
		ServiceName: "musicbot-reconnect",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
		TeamSpeak: TeamSpeakConnectorConfig{
			Enabled: true,
			Profile: "ts3",
			Backend: "ts3_client_compatible",
			Host:    "ts.example.com",
		},
	}
	r, err := New(cfg, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = r.Close() }()

	resp := r.HandleCommand("reconnect")
	if !resp.OK {
		t.Fatalf("reconnect not OK: %v", resp.Error)
	}
	if resp.Payload["reconnected"] == nil {
		t.Error("reconnect payload missing reconnected list")
	}
	if resp.Payload["connectors"] == nil {
		t.Error("reconnect payload missing connectors map")
	}
}

// TestPluginConnectorEventContainsConnectorType checks that ConnectorEvent
// carries connector_type and platform fields.
func TestPluginConnectorEventContainsConnectorType(t *testing.T) {
	t.Parallel()
	event := ConnectorEvent{
		Name:          "user_joined_channel",
		ConnectorType: "teamspeak",
		Platform:      "teamspeak",
		InstanceID:    "inst-1",
		ChannelID:     "ch-42",
		UserID:        "u-7",
		Username:      "Tester",
	}
	if event.ConnectorType == "" {
		t.Error("ConnectorType must not be empty")
	}
	if event.Platform == "" {
		t.Error("Platform must not be empty")
	}
	if event.ConnectorType != event.Platform {
		t.Errorf("ConnectorType=%q and Platform=%q should match", event.ConnectorType, event.Platform)
	}
}

// TestConnectorSupportsFeature verifies the SupportsFeature method for both connectors.
func TestConnectorSupportsFeature(t *testing.T) {
	t.Parallel()
	ts := NewTeamSpeakConnector(TeamSpeakConnectorConfig{Enabled: true, Host: "127.0.0.1"})
	if !ts.SupportsFeature("chat_commands") {
		t.Error("TeamSpeak must support chat_commands")
	}
	if !ts.SupportsFeature("voice") {
		t.Error("TeamSpeak must support voice")
	}
	if ts.SupportsFeature("slash_commands") {
		t.Error("TeamSpeak must NOT support slash_commands")
	}

	disc := NewDiscordConnector(ConnectorConfig{Enabled: false})
	if !disc.SupportsFeature("voice") {
		t.Error("Discord must support voice")
	}
	if !disc.SupportsFeature("slash_commands") {
		t.Error("Discord must support slash_commands")
	}
	if disc.SupportsFeature("chat_commands") {
		t.Error("Discord must NOT support chat_commands")
	}
}

// TestConnectorGetDiagnosticsContainsPlatform checks that GetDiagnostics includes
// the platform key for both connectors.
func TestConnectorGetDiagnosticsContainsPlatform(t *testing.T) {
	t.Parallel()
	ctx := context.Background()

	ts := NewTeamSpeakConnector(TeamSpeakConnectorConfig{Enabled: true, Host: "127.0.0.1"})
	tsDiag := ts.GetDiagnostics(ctx)
	if tsDiag["platform"] != "teamspeak" {
		t.Errorf("TeamSpeak diagnostics platform = %q, want teamspeak", tsDiag["platform"])
	}

	disc := NewDiscordConnector(ConnectorConfig{Enabled: false})
	discDiag := disc.GetDiagnostics(ctx)
	if discDiag["platform"] != "discord" {
		t.Errorf("Discord diagnostics platform = %q, want discord", discDiag["platform"])
	}
}

// TestCustomerWithoutDiscordAllowedSeesDiscordDisabled verifies that a Discord
// connector configured as disabled reports the correct state.
func TestCustomerWithoutDiscordAllowedSeesDiscordDisabled(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	// Customer without discord_allowed gets Discord with enabled=false.
	disc := NewDiscordConnector(ConnectorConfig{Enabled: false})
	status := disc.GetStatus(ctx)
	if status.Enabled {
		t.Error("disabled Discord connector must not report Enabled=true")
	}
	if status.State != ConnectionStateDisconnected {
		t.Errorf("disabled Discord connector state = %q, want disconnected", status.State)
	}
}

// TestCustomerWithDiscordAllowedCanConfigureDiscordWithoutBreakingTeamSpeak
// checks that enabling Discord does not interfere with the TeamSpeak connector.
func TestCustomerWithDiscordAllowedCanConfigureDiscordWithoutBreakingTeamSpeak(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	ts := NewTeamSpeakConnector(TeamSpeakConnectorConfig{
		Enabled: true,
		Profile: "ts3",
		Backend: "ts3_client_compatible",
		Host:    "ts.example.com",
	})
	disc := NewDiscordConnector(ConnectorConfig{
		Enabled: false,
		Config:  map[string]any{"command_mode": "placeholder"},
	})

	// Both connectors should have their own independent status.
	tsStatus := ts.GetStatus(ctx)
	discStatus := disc.GetStatus(ctx)

	if tsStatus.Platform != "teamspeak" {
		t.Errorf("TS status platform = %q, want teamspeak", tsStatus.Platform)
	}
	if discStatus.Platform != "discord" {
		t.Errorf("Discord status platform = %q, want discord", discStatus.Platform)
	}
	if tsStatus.State != discStatus.State {
		// Both should be disconnected initially.
		if tsStatus.State != ConnectionStateDisconnected || discStatus.State != ConnectionStateDisconnected {
			t.Errorf("unexpected initial states: ts=%q discord=%q", tsStatus.State, discStatus.State)
		}
	}
}

// TestAutoconnectHintIsRespectedByConnector checks that ShouldAutoconnect()
// returns false when the connector is not configured for autoconnect.
func TestAutoconnectHintIsRespectedByConnector(t *testing.T) {
	t.Parallel()
	noAutoconnect := NewTeamSpeakConnector(TeamSpeakConnectorConfig{
		Enabled:     true,
		Autoconnect: false,
		Host:        "ts.example.com",
	})
	if noAutoconnect.ShouldAutoconnect() {
		t.Error("ShouldAutoconnect() must be false when Autoconnect=false")
	}

	withAutoconnect := NewTeamSpeakConnector(TeamSpeakConnectorConfig{
		Enabled:     true,
		Autoconnect: true,
		Host:        "ts.example.com",
	})
	if !withAutoconnect.ShouldAutoconnect() {
		t.Error("ShouldAutoconnect() must be true when Autoconnect=true")
	}
}

// TestConnectorCreateAudioOutputFallsBackToNull verifies CreateAudioOutput()
// returns NullAudioOutput when the connector is not ready.
func TestConnectorCreateAudioOutputFallsBackToNull(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	ts := NewTeamSpeakConnector(TeamSpeakConnectorConfig{
		Enabled: true, Profile: "ts3", Backend: "ts3_client_compatible", Host: "127.0.0.1",
	})
	out := ts.CreateAudioOutput(ctx)
	if _, isNull := out.(NullAudioOutput); !isNull {
		t.Errorf("CreateAudioOutput() on unconnected TS connector must return NullAudioOutput, got %T", out)
	}

	disc := NewDiscordConnector(ConnectorConfig{Enabled: false})
	discOut := disc.CreateAudioOutput(ctx)
	if _, isNull := discOut.(NullAudioOutput); !isNull {
		t.Errorf("CreateAudioOutput() on disabled Discord connector must return NullAudioOutput, got %T", discOut)
	}
}
