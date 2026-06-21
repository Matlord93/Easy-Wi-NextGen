package musicbotruntime

import (
	"context"
	"errors"
	"fmt"
	"strings"
)

const teamSpeakVoiceBackendNotConfiguredMessage = "TeamSpeak voice client backend is not configured."

var ErrTeamSpeakVoiceBackendNotConfigured = errors.New(teamSpeakVoiceBackendNotConfiguredMessage)

type CapabilityStatus string

const (
	CapabilityStatusPlaceholder           CapabilityStatus = "placeholder"
	CapabilityStatusClientBackendRequired CapabilityStatus = "client_backend_required"
	CapabilityStatusVoiceBackendRequired  CapabilityStatus = "voice_backend_required"
	CapabilityStatusReady                 CapabilityStatus = "ready"
	CapabilityStatusError                 CapabilityStatus = "error"
)

type NativeTeamspeakVoiceClient interface {
	Connect(ctx context.Context, config TeamSpeakConnectorConfig) error
	Disconnect(ctx context.Context) error
	Reconnect(ctx context.Context) error
	Authenticate(ctx context.Context) error
	SetNickname(ctx context.Context, nickname string) error
	JoinChannel(ctx context.Context, channelID string, password string) error
	LeaveChannel(ctx context.Context) error
	SendOpusFrame(ctx context.Context, frame AudioFrame) error
	GetClientID(ctx context.Context) (string, error)
	GetConnectionState(ctx context.Context) ConnectionState
	GetLastError() string
}

type PlaceholderTeamspeakVoiceClient struct {
	config    TeamSpeakConnectorConfig
	lastError string
}

func NewPlaceholderTeamspeakVoiceClient() *PlaceholderTeamspeakVoiceClient {
	return &PlaceholderTeamspeakVoiceClient{}
}

func (c *PlaceholderTeamspeakVoiceClient) ValidateConfig(config TeamSpeakConnectorConfig) error {
	return validateTeamspeakCommonConfig(config)
}

func (c *PlaceholderTeamspeakVoiceClient) Connect(ctx context.Context, config TeamSpeakConnectorConfig) error {
	if err := c.ValidateConfig(config); err != nil {
		c.lastError = err.Error()
		return err
	}
	if err := ctx.Err(); err != nil {
		c.lastError = err.Error()
		return err
	}
	c.config = config
	c.lastError = ""
	return nil
}

func (c *PlaceholderTeamspeakVoiceClient) Disconnect(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		c.lastError = err.Error()
		return err
	}
	return nil
}

func (c *PlaceholderTeamspeakVoiceClient) Reconnect(ctx context.Context) error {
	if err := c.Disconnect(ctx); err != nil {
		return err
	}
	return c.Connect(ctx, c.config)
}

func (c *PlaceholderTeamspeakVoiceClient) Authenticate(ctx context.Context) error {
	return c.backendRequired(ctx)
}

func (c *PlaceholderTeamspeakVoiceClient) SetNickname(ctx context.Context, nickname string) error {
	if strings.TrimSpace(nickname) == "" {
		return c.recordError(errors.New("teamspeak nickname is required"))
	}
	return c.backendRequired(ctx)
}

func (c *PlaceholderTeamspeakVoiceClient) JoinChannel(ctx context.Context, channelID string, password string) error {
	if strings.TrimSpace(channelID) == "" {
		return c.recordError(errors.New("channel_id is required"))
	}
	return c.backendRequired(ctx)
}

func (c *PlaceholderTeamspeakVoiceClient) LeaveChannel(ctx context.Context) error {
	return c.backendRequired(ctx)
}

func (c *PlaceholderTeamspeakVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		return c.recordError(err)
	}
	if !strings.EqualFold(frame.Format, "opus") {
		return c.recordError(fmt.Errorf("teamspeak opus frame format is required, got %q", frame.Format))
	}
	return c.backendRequired(ctx)
}

func (c *PlaceholderTeamspeakVoiceClient) GetClientID(ctx context.Context) (string, error) {
	if err := ctx.Err(); err != nil {
		c.lastError = err.Error()
		return "", err
	}
	return "", ErrTeamSpeakVoiceBackendNotConfigured
}

func (c *PlaceholderTeamspeakVoiceClient) GetConnectionState(ctx context.Context) ConnectionState {
	if err := ctx.Err(); err != nil {
		c.lastError = err.Error()
		return ConnectionStateError
	}
	return ConnectionStateDisconnected
}

func (c *PlaceholderTeamspeakVoiceClient) GetLastError() string {
	return c.lastError
}

func (c *PlaceholderTeamspeakVoiceClient) backendRequired(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return c.recordError(err)
	}
	return c.recordError(ErrTeamSpeakVoiceBackendNotConfigured)
}

func (c *PlaceholderTeamspeakVoiceClient) recordError(err error) error {
	c.lastError = err.Error()
	return err
}
