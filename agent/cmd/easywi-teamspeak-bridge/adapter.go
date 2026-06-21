package main

import (
	"context"
	"errors"
)

// ErrClientBackendNotAvailable is returned by PlaceholderAdapter for all operations
// that require a real TeamSpeak client layer.
var ErrClientBackendNotAvailable = errors.New("TeamSpeak client backend not available: client_backend_required")

// connectParams holds the configuration supplied by the runtime in a connect request.
type connectParams struct {
	Host           string
	Port           int
	Profile        string // "ts3" or "ts6"
	Nickname       string
	IdentityPath   string
	ServerPassword string // secret – must not appear in logs or error strings
}

// adapterStatus is a point-in-time snapshot returned by TeamspeakClientAdapter.Status.
type adapterStatus struct {
	State     string // "connected", "disconnected", "connecting", "error"
	ClientID  string
	ChannelID string
}

// TeamspeakClientAdapter is the interface a real TeamSpeak client layer must implement
// to be plugged into the bridge. PlaceholderAdapter satisfies the interface but
// does not connect to any server.
//
// All methods receive a context.Context; implementations must respect cancellation.
// Secret values (ServerPassword, channel passwords) must never appear in error strings.
type TeamspeakClientAdapter interface {
	// Connect dials the TeamSpeak server and returns the assigned client ID.
	Connect(ctx context.Context, params connectParams) (clientID string, err error)

	// Disconnect disconnects from the server. Must be idempotent.
	Disconnect(ctx context.Context) error

	// Reconnect re-establishes the connection using saved state from the previous
	// Connect call. Returns the (possibly new) client ID.
	Reconnect(ctx context.Context) (clientID string, err error)

	// JoinChannel moves the client into the given channel. Returns the channel ID
	// that was actually joined (may differ from channelID if the server redirected).
	JoinChannel(ctx context.Context, channelID, password string) (actualChannelID string, err error)

	// LeaveChannel moves the client to the server default/lobby channel.
	LeaveChannel(ctx context.Context) error

	// SetNickname changes the client display name on the connected server.
	SetNickname(ctx context.Context, nickname string) error

	// SendOpusFrame delivers a raw Opus packet to the current voice channel.
	// frame is the decoded (non-base64) Opus packet. durationMs is the declared
	// frame duration and must be positive.
	SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error

	// Status returns the current adapter state without performing network operations.
	// Must not block.
	Status(ctx context.Context) (adapterStatus, error)

	// Close releases all resources held by the adapter.
	Close() error
}

// PlaceholderAdapter satisfies TeamspeakClientAdapter without connecting to any
// TeamSpeak server. Connect and all voice operations return ErrClientBackendNotAvailable.
// Replace this with a real implementation once a supported TeamSpeak client library
// is available.
//
// No reverse engineering, no SinusBot, no TS3AudioBot, no ServerQuery audio.
type PlaceholderAdapter struct{}

// NewPlaceholderAdapter returns a PlaceholderAdapter ready for use.
func NewPlaceholderAdapter() *PlaceholderAdapter { return &PlaceholderAdapter{} }

func (*PlaceholderAdapter) Connect(_ context.Context, _ connectParams) (string, error) {
	return "", ErrClientBackendNotAvailable
}

func (*PlaceholderAdapter) Disconnect(_ context.Context) error { return nil }

func (*PlaceholderAdapter) Reconnect(_ context.Context) (string, error) {
	return "", ErrClientBackendNotAvailable
}

func (*PlaceholderAdapter) JoinChannel(_ context.Context, _ string, _ string) (string, error) {
	return "", ErrClientBackendNotAvailable
}

func (*PlaceholderAdapter) LeaveChannel(_ context.Context) error { return nil }

func (*PlaceholderAdapter) SetNickname(_ context.Context, _ string) error { return nil }

func (*PlaceholderAdapter) SendOpusFrame(_ context.Context, _ []byte, _ int) error {
	return ErrClientBackendNotAvailable
}

func (*PlaceholderAdapter) Status(_ context.Context) (adapterStatus, error) {
	return adapterStatus{State: stateDisconnected}, nil
}

func (*PlaceholderAdapter) Close() error { return nil }
