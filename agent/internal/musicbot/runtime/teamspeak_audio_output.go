package musicbotruntime

import (
	"context"
	"errors"
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
	if err := validateTeamspeakOpusFrame(frame); err != nil {
		return err
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	if o == nil || o.voiceClient == nil || !o.isReady(ctx) {
		return ErrTeamSpeakVoiceNotReady
	}
	if err := o.voiceClient.SendOpusFrame(ctx, frame); err != nil {
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
