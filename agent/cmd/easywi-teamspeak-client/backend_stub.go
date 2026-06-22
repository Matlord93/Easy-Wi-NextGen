//go:build !ts3clientlib && !ts3nativesdk

package main

// stubBackend is the default backend when no TeamSpeak SDK is compiled in.
// It implements the full protocol but returns a clear error on Connect.
//
// To build with a real TeamSpeak client SDK, use:
//
//	-tags ts3clientlib  (TeamSpeak 3 client library, see backend_ts3clientlib.go)
//	-tags ts3nativesdk  (TeamSpeak 5 native SDK — planned; see docs/architecture/musicbot-teamspeak-client-backend.md)
//
// The admin must:
//   1. Register at https://teamspeak.com/en/features/teamspeak-sdk/
//   2. Download the TeamSpeak 3 client library (libts3client.so for Linux)
//   3. Place the library at the path configured as client_library_path in the musicbot config
//   4. Build this binary:  CGO_CFLAGS=-I/path/to/ts3sdk/include go build -tags ts3clientlib ./cmd/easywi-teamspeak-client/
//   5. Point client_backend_path in the musicbot config to the resulting binary

import (
	"context"
	"errors"
	"log"
)

var errSDKNotInstalled = errors.New(
	"TeamSpeak client SDK not installed — " +
		"rebuild with -tags ts3clientlib (TS3 client library) or -tags ts3nativesdk (TS5 native SDK); " +
		"see docs/architecture/musicbot-teamspeak-client-backend.md")

type stubBackend struct {
	logger *log.Logger
}

func newBackend(logger *log.Logger) ClientBackend {
	return &stubBackend{logger: logger}
}

func (b *stubBackend) Name() string { return "stub" }

func (b *stubBackend) Connect(_ context.Context, cfg connectConfig) (string, error) {
	b.logger.Printf("connect host=%s port=%d: TeamSpeak client SDK not installed", cfg.Host, cfg.Port)
	return "", errSDKNotInstalled
}

func (b *stubBackend) Disconnect(_ context.Context) error            { return nil }
func (b *stubBackend) Reconnect(_ context.Context) (string, error)   { return "", errSDKNotInstalled }
func (b *stubBackend) SetNickname(_ context.Context, _ string) error { return nil }
func (b *stubBackend) LeaveChannel(_ context.Context) error          { return nil }
func (b *stubBackend) Shutdown(_ context.Context) error              { return nil }
func (b *stubBackend) Connected() bool                               { return false }
func (b *stubBackend) ClientID() string                              { return "" }
func (b *stubBackend) CurrentChannelID() string                      { return "" }

func (b *stubBackend) JoinChannel(_ context.Context, _, _ string) (string, error) {
	return "", errSDKNotInstalled
}

func (b *stubBackend) SendOpusFrame(_ context.Context, _ []byte, _ int) error {
	return errSDKNotInstalled
}
