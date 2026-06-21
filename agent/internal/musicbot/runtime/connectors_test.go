package musicbotruntime

import (
	"context"
	"errors"
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
	if status := connector.GetStatus(ctx); status.LastError != teamSpeakVoiceBackendNotConfiguredMessage || status.CapabilityStatus != CapabilityStatusError {
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
	if status := connector.GetStatus(ctx); status.LastError != teamSpeakVoiceBackendNotConfiguredMessage || status.CapabilityStatus != CapabilityStatusError {
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
