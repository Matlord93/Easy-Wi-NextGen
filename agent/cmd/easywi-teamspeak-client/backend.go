package main

import "context"

// connectConfig holds the parameters from a connect request.
// Secrets (ServerPassword, ChannelPassword) must never appear in error messages.
type connectConfig struct {
	Host           string
	Port           int
	Profile        string // "ts3" or "ts6"
	Nickname       string
	IdentityPath   string
	SDKLibraryPath string // path to libts3client.so (from backend_path in connect request)
	ServerPassword string // secret — never log
}

// ClientBackend is the interface a real TeamSpeak client layer must implement.
// The stub and CGo backends both satisfy this interface.
//
// Secrets must never appear in error return values; callers also apply masking.
type ClientBackend interface {
	// Name returns a short identifier used in log messages (e.g. "stub", "ts3clientlib").
	Name() string
	// Connect establishes a connection to the TeamSpeak server and returns the
	// server-assigned client ID. The SDK library is loaded from cfg.SDKLibraryPath.
	Connect(ctx context.Context, cfg connectConfig) (clientID string, err error)
	// Disconnect closes the server connection and unloads the SDK.
	Disconnect(ctx context.Context) error
	// Reconnect re-establishes the connection using the last connect configuration.
	Reconnect(ctx context.Context) (clientID string, err error)
	// SetNickname changes the bot's displayed name on the connected server.
	SetNickname(ctx context.Context, nickname string) error
	// JoinChannel moves the client into channelID. Returns the actual channel ID
	// (may differ if the server redirected the client).
	JoinChannel(ctx context.Context, channelID, password string) (actualChannelID string, err error)
	// LeaveChannel moves the client to the server default channel.
	LeaveChannel(ctx context.Context) error
	// SendOpusFrame sends one 20 ms Opus frame to the current channel.
	// The frame slice contains the raw Opus packet bytes (not base64).
	SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error
	// Connected reports whether a server connection is currently active.
	Connected() bool
	// ClientID returns the server-assigned client ID, or "" when disconnected.
	ClientID() string
	// CurrentChannelID returns the channel the client is currently in, or "".
	CurrentChannelID() string
	// Shutdown performs a graceful shutdown; called on disconnect or EOF.
	Shutdown(ctx context.Context) error
}
