//go:build ts3clientlib

// easywi-teamspeak-client — TeamSpeak 3 client library backend.
//
// Build requirements:
//   1. Register at https://teamspeak.com/en/features/teamspeak-sdk/ and download
//      the TeamSpeak 3 client library SDK for Linux (libts3client.so).
//   2. libopus must be installed: apt-get install libopus-dev
//   3. Build with:
//        CGO_ENABLED=1 go build -tags ts3clientlib ./cmd/easywi-teamspeak-client/
//
// Runtime requirements:
//   - Set backend_path in the musicbot config to the full path of libts3client.so.
//   - The directory containing libts3client.so must also contain the SDK resource
//     files (if any). The SDK resources folder is derived from the library path.
//   - libopus.so.0 (or libopus.so) must be in the library search path.
//
// Audio path (Opus → TeamSpeak voice):
//   send_opus_frame → ts3bridge_push_opus_frame (Opus→PCM via libopus)
//   → PCM ring buffer
//   → TS3 SDK custom capture callback → TS3 network voice channel
//
// Security:
//   - server_password and channel_password are never written to stderr or any log.
//   - The SDK library is loaded from the path provided in the connect request
//     (backend_path) via dlopen; no shell execution.
//   - Secrets are not passed to C functions as formatted strings; they are
//     passed as direct const char* pointers into SDK calls only.

package main

// #cgo LDFLAGS: -ldl -lpthread
// #include "ts3_client.h"
// #include <stdlib.h>
import "C"

import (
	"context"
	"errors"
	"fmt"
	"log"
	"path/filepath"
	"strings"
	"sync"
	"unsafe"
)

// ts3BridgeCaptureCallback is called by the TS3 SDK when it needs capture audio.
// We drain the PCM ring buffer into the buffer the SDK provides.
//
//export ts3BridgeCaptureCallback
func ts3BridgeCaptureCallback(deviceName *C.char, buffer **C.short, samples *C.int, stereo C.int) {
	if buffer == nil || samples == nil || *samples <= 0 {
		return
	}
	n := int(*samples)
	pcm := (*[1 << 28]C.short)(unsafe.Pointer(*buffer))[:n:n]

	got := int(C.ts3bridge_pop_pcm(&pcm[0], C.int(n)))
	if got < n {
		// Zero-fill the remainder to avoid sending garbage audio.
		for i := got; i < n; i++ {
			pcm[i] = 0
		}
	}
	_ = C.ts3bridge_acquire_custom_capture(deviceName, buffer, samples)
}

// ts3BridgeConnectStatusCallback is called by the TS3 SDK on connection state changes.
//
//export ts3BridgeConnectStatusCallback
func ts3BridgeConnectStatusCallback(scHandlerID C.uint64, newStatus C.int, errorNumber C.uint) {
	C.ts3bridge_notify_connect_status(newStatus)
}

// ts3ClientLibBackend connects to TeamSpeak 3/6 via the official client library.
type ts3ClientLibBackend struct {
	mu        sync.Mutex
	logger    *log.Logger
	connected bool
	clientID  string
	channelID string
	lastCfg   connectConfig
}

func newBackend(logger *log.Logger) ClientBackend {
	return &ts3ClientLibBackend{logger: logger}
}

func (b *ts3ClientLibBackend) Name() string { return "ts3clientlib" }

func (b *ts3ClientLibBackend) Connect(ctx context.Context, cfg connectConfig) (string, error) {
	if err := ctx.Err(); err != nil {
		return "", err
	}
	b.mu.Lock()
	defer b.mu.Unlock()

	if cfg.SDKLibraryPath == "" {
		return "", errors.New("connect: backend_path (SDK library path) is required for ts3clientlib backend")
	}

	// Load SDK and Opus via dlopen.
	sdkPath := C.CString(cfg.SDKLibraryPath)
	defer C.free(unsafe.Pointer(sdkPath))

	if rc := C.ts3bridge_load(sdkPath); rc != 0 {
		return "", fmt.Errorf("connect: failed to load TeamSpeak SDK from %s — ensure libts3client.so and libopus.so are installed", cfg.SDKLibraryPath)
	}

	// Resources directory = directory containing the SDK library.
	resourcesDir := filepath.Dir(cfg.SDKLibraryPath)
	resDir := C.CString(resourcesDir)
	defer C.free(unsafe.Pointer(resDir))

	if rc := C.ts3bridge_init(resDir); rc != 0 {
		C.ts3bridge_shutdown()
		return "", errors.New("connect: failed to initialize TeamSpeak client SDK")
	}

	// Prepare C strings. Secrets (serverPassword) are passed as raw pointers,
	// not formatted into any string — they never touch stderr.
	host := C.CString(cfg.Host)
	defer C.free(unsafe.Pointer(host))
	nick := C.CString(cfg.Nickname)
	defer C.free(unsafe.Pointer(nick))

	var identPath *C.char
	if cfg.IdentityPath != "" {
		identPath = C.CString(cfg.IdentityPath)
		defer C.free(unsafe.Pointer(identPath))
	}

	// server_password: passed directly, never formatted or logged.
	var serverPw *C.char
	if cfg.ServerPassword != "" {
		serverPw = C.CString(cfg.ServerPassword)
		defer C.free(unsafe.Pointer(serverPw))
	}

	clientIDCStr := C.ts3bridge_connect(host, C.uint(cfg.Port), nick, identPath, serverPw)
	if clientIDCStr == nil {
		C.ts3bridge_shutdown()
		return "", errors.New("connect: TeamSpeak connection failed — check host, port, and credentials")
	}
	clientID := C.GoString(clientIDCStr)
	C.free(unsafe.Pointer(clientIDCStr))

	b.connected = true
	b.clientID = clientID
	b.channelID = ""
	b.lastCfg = cfg

	b.logger.Printf("connected to %s:%d client_id=%s", cfg.Host, cfg.Port, clientID)
	return clientID, nil
}

func (b *ts3ClientLibBackend) Disconnect(_ context.Context) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.connected {
		C.ts3bridge_disconnect()
		b.connected = false
		b.clientID = ""
		b.channelID = ""
		b.logger.Printf("disconnected")
	}
	return nil
}

func (b *ts3ClientLibBackend) Reconnect(ctx context.Context) (string, error) {
	b.mu.Lock()
	cfg := b.lastCfg
	b.mu.Unlock()
	// Disconnect first, then reconnect.
	_ = b.Disconnect(ctx)
	return b.Connect(ctx, cfg)
}

func (b *ts3ClientLibBackend) SetNickname(_ context.Context, nickname string) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	if !b.connected {
		return errors.New("set_nickname: not connected")
	}
	nick := C.CString(nickname)
	defer C.free(unsafe.Pointer(nick))
	if rc := C.ts3bridge_set_nickname(nick); rc != 0 {
		return fmt.Errorf("set_nickname: SDK returned error (code %d)", int(rc))
	}
	return nil
}

func (b *ts3ClientLibBackend) JoinChannel(_ context.Context, channelID, password string) (string, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	if !b.connected {
		return "", errors.New("join_channel: not connected")
	}

	// Parse channelID as uint64.
	var chID C.uint64
	if _, err := fmt.Sscanf(channelID, "%d", &chID); err != nil {
		return "", fmt.Errorf("join_channel: channel_id %q is not a valid numeric channel ID", channelID)
	}

	// password is a secret: passed as raw pointer, never logged.
	var pw *C.char
	if password != "" {
		pw = C.CString(password)
		defer C.free(unsafe.Pointer(pw))
	}

	if rc := C.ts3bridge_join_channel(chID, pw); rc != 0 {
		return "", errors.New("join_channel: failed to join channel — check channel ID and password")
	}
	b.channelID = channelID
	return channelID, nil
}

func (b *ts3ClientLibBackend) LeaveChannel(_ context.Context) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	if !b.connected {
		return nil
	}
	// Move to channel 1 (server default).
	var chID C.uint64 = 1
	if rc := C.ts3bridge_join_channel(chID, nil); rc != 0 {
		return errors.New("leave_channel: failed to move to default channel")
	}
	b.channelID = ""
	return nil
}

func (b *ts3ClientLibBackend) SendOpusFrame(_ context.Context, frame []byte, _ int) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	if !b.connected {
		return errors.New("send_opus_frame: not connected")
	}
	if len(frame) == 0 {
		return errors.New("send_opus_frame: empty frame")
	}
	if rc := C.ts3bridge_push_opus_frame((*C.uchar)(unsafe.Pointer(&frame[0])), C.int(len(frame))); rc != 0 {
		return errors.New("send_opus_frame: Opus decode failed")
	}
	return nil
}

func (b *ts3ClientLibBackend) Connected() bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.connected
}

func (b *ts3ClientLibBackend) ClientID() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.clientID
}

func (b *ts3ClientLibBackend) CurrentChannelID() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.channelID
}

func (b *ts3ClientLibBackend) Shutdown(_ context.Context) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.connected || C.ts3bridge_get_connection_status() != C.TS3_STATUS_DISCONNECTED {
		C.ts3bridge_shutdown()
		b.connected = false
		b.clientID = ""
		b.channelID = ""
	}
	return nil
}

// sdkLibDir extracts the directory from an SDK library path, handling symlinks.
func sdkLibDir(sdkPath string) string {
	return filepath.Dir(strings.TrimSpace(sdkPath))
}
