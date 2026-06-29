package musicbotruntime

import (
	"context"
	"errors"
	"strings"
)

var ErrTeamSpeakVoiceNotReady = errors.New("teamspeak voice backend is not ready")

type teamspeakReadyFunc func(context.Context) bool

type TeamspeakAudioOutput struct {
	voiceClient NativeTeamspeakVoiceClient
	ready       teamspeakReadyFunc
	config      TeamSpeakConnectorConfig
}

func NewTeamspeakAudioOutput(voiceClient NativeTeamspeakVoiceClient) *TeamspeakAudioOutput {
	return NewTeamspeakAudioOutputWithReadiness(voiceClient, nil, TeamSpeakConnectorConfig{})
}

func NewTeamspeakAudioOutputWithReadiness(voiceClient NativeTeamspeakVoiceClient, ready teamspeakReadyFunc, config TeamSpeakConnectorConfig) *TeamspeakAudioOutput {
	return &TeamspeakAudioOutput{voiceClient: voiceClient, ready: ready, config: config}
}

func NewTeamspeakAudioOutputFromConnector(connector *TeamSpeakVoiceConnector) *TeamspeakAudioOutput {
	if connector == nil {
		return NewTeamspeakAudioOutput(nil)
	}
	ready := func(ctx context.Context) bool {
		status := connector.GetStatus(ctx)
		return status.VoiceClientAvailable && status.CapabilityStatus == CapabilityStatusReady && status.Connected
	}
	return NewTeamspeakAudioOutputWithReadiness(connector.voiceClient, ready, connector.config)
}

func (o *TeamspeakAudioOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		return err
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	if o == nil || o.voiceClient == nil || !o.isReady(ctx) {
		return ErrTeamSpeakVoiceNotReady
	}
	frame = normalizeTeamspeakAudioFrame(frame)
	if err := o.voiceClient.SendAudioFrame(ctx, frame); err != nil {
		masked := maskTeamspeakSecretError(err.Error(), o.config)
		if masked == err.Error() {
			return err
		}
		return errors.New(masked)
	}
	return nil
}

func (o *TeamspeakAudioOutput) isReady(ctx context.Context) bool {
	if o.ready != nil {
		return o.ready(ctx)
	}
	return o.voiceClient.GetConnectionState(ctx) == ConnectionStateConnected
}

func (o *TeamspeakAudioOutput) OutputName() string { return "teamspeak_voice" }

func normalizeTeamspeakAudioFrame(frame AudioFrame) AudioFrame {
	format := strings.ToLower(strings.TrimSpace(frame.Format))
	if isPCMFrame(frame) || (format == "opus" && opusPayloadLooksLikePCM(frame)) {
		frame.Format = "pcm_s16le"
		if len(frame.PCM) == 0 {
			frame.PCM = append([]byte(nil), frame.Payload...)
		}
		if len(frame.Payload) == 0 {
			frame.Payload = append([]byte(nil), frame.PCM...)
		}
	}
	return frame
}

func isPCMFrame(frame AudioFrame) bool {
	format := strings.ToLower(strings.TrimSpace(frame.Format))
	return format == "pcm" || format == "pcm_s16le" || len(frame.PCM) > 0
}

func opusPayloadLooksLikePCM(frame AudioFrame) bool {
	sampleRate := frame.SampleRateHz
	if sampleRate == 0 {
		sampleRate = frame.SampleRate
	}
	durationMs := frame.DurationMs
	if durationMs == 0 && frame.Duration > 0 {
		durationMs = int(frame.Duration / 1000000)
	}
	if sampleRate <= 0 || frame.Channels <= 0 || durationMs <= 0 {
		return false
	}
	want := sampleRate * durationMs * frame.Channels * 2 / 1000
	return want > 0 && len(frame.Payload) == want
}
