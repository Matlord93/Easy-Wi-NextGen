package musicbotruntime

import (
	"context"
	"errors"
)

var ErrDiscordVoiceBackendNotConfigured = errors.New("discord voice backend is not configured; install a Discord voice backend before enabling audio output")

type DiscordVoiceState struct {
	GatewayConnected bool             `json:"gateway_connected"`
	VoiceJoined      bool             `json:"voice_joined"`
	GuildID          string           `json:"guild_id,omitempty"`
	ChannelID        string           `json:"channel_id,omitempty"`
	CapabilityStatus CapabilityStatus `json:"capability_status"`
	LastError        string           `json:"last_error,omitempty"`
}

type DiscordVoiceClient interface {
	ConnectGateway(ctx context.Context, config ConnectorConfig) error
	DisconnectGateway(ctx context.Context) error
	JoinVoiceChannel(ctx context.Context, guildID string, channelID string) error
	LeaveVoiceChannel(ctx context.Context) error
	SendOpusFrame(ctx context.Context, frame AudioFrame) error
	GetVoiceState(ctx context.Context) DiscordVoiceState
	Reconnect(ctx context.Context, config ConnectorConfig) error
	Close(ctx context.Context) error
	GetLastError() string
}

type PlaceholderDiscordVoiceClient struct {
	lastError string
}

func NewPlaceholderDiscordVoiceClient() *PlaceholderDiscordVoiceClient {
	return &PlaceholderDiscordVoiceClient{}
}

func (c *PlaceholderDiscordVoiceClient) ConnectGateway(ctx context.Context, config ConnectorConfig) error {
	select {
	case <-ctx.Done():
		c.lastError = ctx.Err().Error()
		return ctx.Err()
	default:
	}
	c.lastError = ErrDiscordVoiceBackendNotConfigured.Error()
	return ErrDiscordVoiceBackendNotConfigured
}

func (c *PlaceholderDiscordVoiceClient) DisconnectGateway(ctx context.Context) error {
	select {
	case <-ctx.Done():
		c.lastError = ctx.Err().Error()
		return ctx.Err()
	default:
	}
	return nil
}

func (c *PlaceholderDiscordVoiceClient) JoinVoiceChannel(ctx context.Context, guildID string, channelID string) error {
	select {
	case <-ctx.Done():
		c.lastError = ctx.Err().Error()
		return ctx.Err()
	default:
	}
	c.lastError = ErrDiscordVoiceBackendNotConfigured.Error()
	return ErrDiscordVoiceBackendNotConfigured
}

func (c *PlaceholderDiscordVoiceClient) LeaveVoiceChannel(ctx context.Context) error {
	select {
	case <-ctx.Done():
		c.lastError = ctx.Err().Error()
		return ctx.Err()
	default:
	}
	return nil
}

func (c *PlaceholderDiscordVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	select {
	case <-ctx.Done():
		c.lastError = ctx.Err().Error()
		return ctx.Err()
	default:
	}
	c.lastError = ErrDiscordVoiceBackendNotConfigured.Error()
	return ErrDiscordVoiceBackendNotConfigured
}

func (c *PlaceholderDiscordVoiceClient) GetVoiceState(ctx context.Context) DiscordVoiceState {
	select {
	case <-ctx.Done():
		c.lastError = ctx.Err().Error()
	default:
	}
	return DiscordVoiceState{CapabilityStatus: CapabilityStatusVoiceBackendRequired, LastError: c.lastError}
}

func (c *PlaceholderDiscordVoiceClient) Reconnect(ctx context.Context, config ConnectorConfig) error {
	if err := c.DisconnectGateway(ctx); err != nil {
		return err
	}
	return c.ConnectGateway(ctx, config)
}

func (c *PlaceholderDiscordVoiceClient) Close(ctx context.Context) error {
	return c.DisconnectGateway(ctx)
}

func (c *PlaceholderDiscordVoiceClient) GetLastError() string {
	return c.lastError
}
