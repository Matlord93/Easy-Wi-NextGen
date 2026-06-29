package musicbotruntime

import (
	"context"
	"errors"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestDiscordAudioOutputSendsOnlyWhenVoiceReady(t *testing.T) {
	client := &mockDiscordVoiceClient{state: DiscordVoiceState{GatewayConnected: true, VoiceJoined: false, CapabilityStatus: CapabilityStatusPlaceholder}}
	output := NewDiscordAudioOutput(client)
	frame := testOpusFrame()
	if err := output.SendAudioFrame(context.Background(), frame); !errors.Is(err, ErrDiscordVoiceNotReady) {
		t.Fatalf("SendAudioFrame() = %v, want ErrDiscordVoiceNotReady", err)
	}
	if client.sent != 0 {
		t.Fatalf("sent = %d, want 0", client.sent)
	}

	client.state = DiscordVoiceState{GatewayConnected: true, VoiceJoined: true, CapabilityStatus: CapabilityStatusReady}
	if err := output.SendAudioFrame(context.Background(), frame); err != nil {
		t.Fatalf("SendAudioFrame() ready = %v", err)
	}
	if client.sent != 1 {
		t.Fatalf("sent = %d, want 1", client.sent)
	}
}

func TestDiscordAudioOutputVoiceClientErrorLandsInPipelineLastOutputError(t *testing.T) {
	wantErr := errors.New("udp send failed")
	client := &mockDiscordVoiceClient{state: DiscordVoiceState{GatewayConnected: true, VoiceJoined: true, CapabilityStatus: CapabilityStatusReady}, sendErr: wantErr}
	pipeline := NewAudioPipeline(NewFileAudioSourceResolver(""), DummyDecoder{}, DummyResampler{}, DummyOpusEncoder{}, NewDiscordAudioOutput(client))
	err := pipeline.Output(context.Background(), testOpusFrame())
	if !errors.Is(err, wantErr) {
		t.Fatalf("Output() = %v, want %v", err, wantErr)
	}
	status := pipeline.Snapshot()
	// Output errors set LastOutputError and OutputStatus; LastError and
	// DecoderStatus must remain unset/unchanged so the connector stays ready.
	if status.LastOutputError != wantErr.Error() {
		t.Fatalf("LastOutputError = %q, want %q; status = %#v", status.LastOutputError, wantErr.Error(), status)
	}
	if status.LastError != "" {
		t.Fatalf("LastError = %q, want empty for output-only error; status = %#v", status.LastError, status)
	}
	if status.FramesSent != 0 {
		t.Fatalf("FramesSent = %d, want 0; status = %#v", status.FramesSent, status)
	}
}

func TestDiscordAudioOutputDoesNotLeakTokenInErrors(t *testing.T) {
	secret := "super-secret-token"
	client := &mockDiscordVoiceClient{state: DiscordVoiceState{GatewayConnected: true, VoiceJoined: true, CapabilityStatus: CapabilityStatusReady}, sendErr: errors.New("failed with " + secret)}
	output := NewDiscordAudioOutputWithConfig(client, map[string]any{"bot_token": secret})
	pipeline := NewAudioPipeline(nil, nil, nil, nil, output)
	_ = pipeline.Output(context.Background(), testOpusFrame())
	status := pipeline.Snapshot()
	if strings.Contains(status.LastError, secret) || strings.Contains(status.LastOutputError, secret) {
		t.Fatalf("pipeline status leaked token: %#v", status)
	}
	if !strings.Contains(status.LastOutputError, "[redacted]") {
		t.Fatalf("LastOutputError = %q, want redacted marker", status.LastOutputError)
	}
}

func TestRuntimeNullAudioOutputFallbackWhenDiscordNotReady(t *testing.T) {
	r := &Runtime{connectors: map[string]Connector{}, pipeline: NewAudioPipeline(nil, nil, nil, nil, nil)}
	r.selectAudioOutput(context.Background())
	if got := r.pipeline.OutputBackendName(); got != "null" {
		t.Fatalf("OutputBackendName() = %q, want null", got)
	}
}

func TestAudioPipelineStopContextStopsFurtherSends(t *testing.T) {
	dir := t.TempDir()
	path := dir + "/track.wav"
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}
	output := &cancelAfterFirstOutput{}
	ctx, cancel := context.WithCancel(context.Background())
	output.cancel = cancel
	pipeline := NewAudioPipeline(NewFileAudioSourceResolver(dir), DummyDecoder{FrameCount: 3}, DummyResampler{}, DummyOpusEncoder{}, output)
	err := pipeline.Process(ctx, AudioSource{Type: TrackSourceUpload, URI: "track.wav", MimeType: "audio/wav"})
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("Process() = %v, want context.Canceled", err)
	}
	if output.count != 1 {
		t.Fatalf("sent = %d, want 1", output.count)
	}
}

func testOpusFrame() AudioFrame {
	return AudioFrame{Format: "opus", SampleRateHz: 48000, SampleRate: 48000, Channels: 2, Payload: []byte{1, 2, 3}, DurationMs: 20, Duration: 20 * time.Millisecond}
}

type mockDiscordVoiceClient struct {
	mu      sync.Mutex
	state   DiscordVoiceState
	sendErr error
	sent    int
}

func (m *mockDiscordVoiceClient) ConnectGateway(ctx context.Context, config ConnectorConfig) error {
	return nil
}
func (m *mockDiscordVoiceClient) DisconnectGateway(ctx context.Context) error { return nil }
func (m *mockDiscordVoiceClient) JoinVoiceChannel(ctx context.Context, guildID string, channelID string) error {
	return nil
}
func (m *mockDiscordVoiceClient) LeaveVoiceChannel(ctx context.Context) error { return nil }
func (m *mockDiscordVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.sendErr != nil {
		return m.sendErr
	}
	m.sent++
	return nil
}
func (m *mockDiscordVoiceClient) GetVoiceState(ctx context.Context) DiscordVoiceState { return m.state }
func (m *mockDiscordVoiceClient) Reconnect(ctx context.Context, config ConnectorConfig) error {
	return nil
}
func (m *mockDiscordVoiceClient) Close(ctx context.Context) error { return nil }
func (m *mockDiscordVoiceClient) GetLastError() string            { return "" }

type cancelAfterFirstOutput struct {
	count  int
	cancel context.CancelFunc
}

func (o *cancelAfterFirstOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	o.count++
	if o.count == 1 {
		o.cancel()
	}
	return nil
}
func (o *cancelAfterFirstOutput) OutputName() string { return "test" }
