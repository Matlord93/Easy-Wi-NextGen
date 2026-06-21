package musicbotruntime

import (
	"context"
	"errors"
	"sync"
	"time"
)

var ErrDiscordVoiceNotReady = errors.New("discord voice backend is not ready")

// DiscordAudioOutput adapts a ready DiscordVoiceClient to the AudioOutput
// contract used by AudioPipeline. It deliberately checks the live voice state
// before every frame so status never reports fake readiness.
type DiscordAudioOutput struct {
	voiceClient DiscordVoiceClient
	mu          sync.Mutex
	lastError   string
	config      map[string]any
}

func NewDiscordAudioOutput(voiceClient DiscordVoiceClient) *DiscordAudioOutput {
	return NewDiscordAudioOutputWithConfig(voiceClient, nil)
}

func NewDiscordAudioOutputWithConfig(voiceClient DiscordVoiceClient, config map[string]any) *DiscordAudioOutput {
	return &DiscordAudioOutput{voiceClient: voiceClient, config: config}
}

func (o *DiscordAudioOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		o.setLastError(err)
		return err
	}
	if frame.Format != "opus" {
		err := errors.New("discord audio output requires opus frames")
		o.setLastError(err)
		return err
	}
	select {
	case <-ctx.Done():
		o.setLastError(ctx.Err())
		return ctx.Err()
	default:
	}
	if o == nil || o.voiceClient == nil {
		err := ErrDiscordVoiceBackendNotConfigured
		o.setLastError(err)
		return err
	}
	state := o.voiceClient.GetVoiceState(ctx)
	if !state.GatewayConnected || !state.VoiceJoined || state.CapabilityStatus != CapabilityStatusReady {
		err := ErrDiscordVoiceNotReady
		if state.LastError != "" {
			err = errors.New(state.LastError)
		}
		o.setLastError(err)
		return err
	}
	if err := o.voiceClient.SendOpusFrame(ctx, frame); err != nil {
		maskedErr := o.maskError(err)
		o.setLastError(maskedErr)
		return maskedErr
	}
	o.setLastError(nil)
	return nil
}

func (o *DiscordAudioOutput) OutputName() string { return "discord_voice" }

func (o *DiscordAudioOutput) LastError() string {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.lastError
}

func (o *DiscordAudioOutput) maskError(err error) error {
	if o == nil || err == nil {
		return err
	}
	masked := maskSensitiveError(err.Error(), o.config)
	if masked == err.Error() {
		return err
	}
	return errors.New(masked)
}

func (o *DiscordAudioOutput) setLastError(err error) {
	if o == nil {
		return
	}
	o.mu.Lock()
	defer o.mu.Unlock()
	if err == nil {
		o.lastError = ""
	} else {
		o.lastError = err.Error()
	}
}

func audioFrameDuration(frame AudioFrame) time.Duration {
	if frame.Duration > 0 {
		return frame.Duration
	}
	if frame.DurationMs > 0 {
		return time.Duration(frame.DurationMs) * time.Millisecond
	}
	return 20 * time.Millisecond
}
