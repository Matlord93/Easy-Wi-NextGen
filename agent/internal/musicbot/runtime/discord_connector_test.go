package musicbotruntime

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func TestDiscordConnectorValidatesConfigWithoutLeakingToken(t *testing.T) {
	t.Parallel()
	connector := NewDiscordConnector(ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "super-secret-token"}})
	err := connector.ValidateConfig()
	if err == nil {
		t.Fatal("ValidateConfig() error = nil, want missing guild_id error")
	}
	if strings.Contains(err.Error(), "super-secret-token") {
		t.Fatalf("ValidateConfig() leaked token in error: %q", err.Error())
	}

	connector = NewDiscordConnector(ConnectorConfig{Enabled: true, Config: map[string]any{"command_mode": "placeholder"}})
	if err := connector.ValidateConfig(); err != nil {
		t.Fatalf("ValidateConfig() placeholder error = %v", err)
	}
}

func TestDiscordConnectorPlaceholderRequiresVoiceBackend(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewDiscordConnector(ConnectorConfig{Enabled: true, Config: map[string]any{
		"command_mode":     "placeholder",
		"voice_channel_id": "voice-1",
	}})

	err := connector.Connect(ctx)
	if !errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
		t.Fatalf("Connect() error = %v, want %v", err, ErrDiscordVoiceBackendNotConfigured)
	}
	status := connector.GetStatus(ctx)
	if status.CapabilityStatus != CapabilityStatusVoiceBackendRequired || status.VoiceClientAvailable {
		t.Fatalf("status after placeholder connect = %#v", status)
	}
	frame := AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, PCM: []byte{0, 1}}
	err = connector.SendAudioFrame(ctx, frame)
	if !errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
		t.Fatalf("SendAudioFrame() error = %v, want %v", err, ErrDiscordVoiceBackendNotConfigured)
	}
}

func TestDiscordConnectorMasksSensitiveRuntimeErrors(t *testing.T) {
	t.Parallel()
	ctx := context.Background()
	connector := NewDiscordConnector(ConnectorConfig{Enabled: true, Config: map[string]any{
		"bot_token": "super-secret-token",
		"guild_id":  "guild-1",
	}})

	connector.setError(assertionError("failed with super-secret-token"))
	status := connector.GetStatus(ctx)
	if strings.Contains(status.LastError, "super-secret-token") {
		t.Fatalf("LastError leaked token: %q", status.LastError)
	}
	if !strings.Contains(status.LastError, "[redacted]") {
		t.Fatalf("LastError was not redacted: %q", status.LastError)
	}
}

type assertionError string

func (e assertionError) Error() string { return string(e) }
