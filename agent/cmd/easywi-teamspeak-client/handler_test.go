package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"strings"
	"testing"
)

// ──────────────────────────────────────────────────────────────────────────
// Mock backend
// ──────────────────────────────────────────────────────────────────────────

type mockBackend struct {
	name_        string
	connected_   bool
	clientID_    string
	channelID_   string
	connectErr   error
	reconnectErr error
	joinErr      error
	leaveErr     error
	setNickErr   error
	sendFrameErr error
	shutdownErr  error
}

func newMock() *mockBackend { return &mockBackend{name_: "mock", clientID_: "mock-42"} }

func (m *mockBackend) Name() string { return m.name_ }

func (m *mockBackend) Connect(_ context.Context, _ connectConfig) (string, error) {
	if m.connectErr != nil {
		return "", m.connectErr
	}
	m.connected_ = true
	return m.clientID_, nil
}

func (m *mockBackend) Disconnect(_ context.Context) error {
	m.connected_ = false
	m.channelID_ = ""
	return nil
}

func (m *mockBackend) Reconnect(_ context.Context) (string, error) {
	if m.reconnectErr != nil {
		return "", m.reconnectErr
	}
	m.connected_ = true
	return m.clientID_, nil
}

func (m *mockBackend) SetNickname(_ context.Context, _ string) error { return m.setNickErr }

func (m *mockBackend) JoinChannel(_ context.Context, channelID, _ string) (string, error) {
	if m.joinErr != nil {
		return "", m.joinErr
	}
	m.channelID_ = channelID
	return channelID, nil
}

func (m *mockBackend) LeaveChannel(_ context.Context) error {
	if m.leaveErr != nil {
		return m.leaveErr
	}
	m.channelID_ = ""
	return nil
}

func (m *mockBackend) SendOpusFrame(_ context.Context, _ []byte, _ int) error {
	return m.sendFrameErr
}

func (m *mockBackend) Connected() bool          { return m.connected_ }
func (m *mockBackend) ClientID() string         { return m.clientID_ }
func (m *mockBackend) CurrentChannelID() string { return m.channelID_ }

func (m *mockBackend) Shutdown(_ context.Context) error {
	m.connected_ = false
	return m.shutdownErr
}

// ──────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────

func nullLogger() *log.Logger { return log.New(io.Discard, "", 0) }

func newTestHandler(m *mockBackend) *handler {
	return newHandler(m, nullLogger())
}

func validBase64Opus() string {
	return base64.StdEncoding.EncodeToString([]byte("opus-frame-data"))
}

// ──────────────────────────────────────────────────────────────────────────
// connect
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerConnectSuccess(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "connect", Host: "ts.example.com", Port: 9987, Profile: "ts3"})
	if !resp.OK {
		t.Fatalf("connect: expected ok=true, got error=%q", resp.Error)
	}
	if resp.State != stateConnected {
		t.Fatalf("connect: expected state=%q, got %q", stateConnected, resp.State)
	}
	if !resp.Ready {
		t.Fatal("connect: expected ready=true")
	}
	if resp.ClientID == "" {
		t.Fatal("connect: expected non-empty client_id")
	}
}

func TestHandlerConnectMissingHost(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "connect", Port: 9987})
	if resp.OK {
		t.Fatal("connect without host: expected ok=false")
	}
	if !strings.Contains(resp.Error, "host") {
		t.Fatalf("connect without host: error should mention host, got %q", resp.Error)
	}
}

func TestHandlerConnectBackendError(t *testing.T) {
	m := newMock()
	m.connectErr = errors.New("connection refused")
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	if resp.OK {
		t.Fatal("connect with backend error: expected ok=false")
	}
	if !strings.Contains(resp.Error, "connection refused") {
		t.Fatalf("connect error should propagate, got %q", resp.Error)
	}
}

func TestHandlerConnectDefaultPortAndProfile(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	// Port=0 → should default to 9987; Profile="" → should default to ts3
	resp := h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	if !resp.OK {
		t.Fatalf("connect with zero port: expected ok=true, error=%q", resp.Error)
	}
}

// ──────────────────────────────────────────────────────────────────────────
// disconnect / shutdown
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerDisconnect(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "disconnect"})
	if !resp.OK {
		t.Fatalf("disconnect: expected ok=true, error=%q", resp.Error)
	}
	if resp.State != stateDisconnected {
		t.Fatalf("disconnect: expected state=%q, got %q", stateDisconnected, resp.State)
	}
	if m.connected_ {
		t.Fatal("disconnect: backend should be disconnected")
	}
}

func TestHandlerShutdownAlias(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "shutdown"})
	if !resp.OK {
		t.Fatalf("shutdown: expected ok=true, error=%q", resp.Error)
	}
	if resp.State != stateDisconnected {
		t.Fatalf("shutdown: expected state=%q, got %q", stateDisconnected, resp.State)
	}
}

// ──────────────────────────────────────────────────────────────────────────
// reconnect
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerReconnectSuccess(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "reconnect"})
	if !resp.OK {
		t.Fatalf("reconnect: expected ok=true, error=%q", resp.Error)
	}
	if resp.State != stateConnected {
		t.Fatalf("reconnect: expected state=%q, got %q", stateConnected, resp.State)
	}
	if resp.ClientID == "" {
		t.Fatal("reconnect: expected non-empty client_id")
	}
}

func TestHandlerReconnectWhenNotConnected(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "reconnect"})
	if resp.OK {
		t.Fatal("reconnect when not connected: expected ok=false")
	}
	if !strings.Contains(resp.Error, "not previously connected") {
		t.Fatalf("reconnect error should mention not connected, got %q", resp.Error)
	}
}

func TestHandlerReconnectBackendError(t *testing.T) {
	m := newMock()
	m.reconnectErr = errors.New("server unreachable")
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "reconnect"})
	if resp.OK {
		t.Fatal("reconnect with backend error: expected ok=false")
	}
}

// ──────────────────────────────────────────────────────────────────────────
// join_channel
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerJoinChannelSuccess(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "join_channel", ChannelID: "5"})
	if !resp.OK {
		t.Fatalf("join_channel: expected ok=true, error=%q", resp.Error)
	}
	if resp.ChannelID != "5" {
		t.Fatalf("join_channel: expected channel_id=5, got %q", resp.ChannelID)
	}
}

func TestHandlerJoinChannelMissingID(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "join_channel"})
	if resp.OK {
		t.Fatal("join_channel without channel_id: expected ok=false")
	}
	if !strings.Contains(resp.Error, "channel_id") {
		t.Fatalf("join_channel error should mention channel_id, got %q", resp.Error)
	}
}

func TestHandlerJoinChannelNotConnected(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "join_channel", ChannelID: "5"})
	if resp.OK {
		t.Fatal("join_channel when not connected: expected ok=false")
	}
	if !strings.Contains(resp.Error, "not connected") {
		t.Fatalf("join_channel error should mention not connected, got %q", resp.Error)
	}
}

func TestHandlerJoinChannelBackendError(t *testing.T) {
	m := newMock()
	m.joinErr = errors.New("channel not found")
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "join_channel", ChannelID: "99"})
	if resp.OK {
		t.Fatal("join_channel with backend error: expected ok=false")
	}
	if !strings.Contains(resp.Error, "channel not found") {
		t.Fatalf("join_channel error should propagate, got %q", resp.Error)
	}
}

// ──────────────────────────────────────────────────────────────────────────
// leave_channel
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerLeaveChannel(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{Action: "leave_channel"})
	if !resp.OK {
		t.Fatalf("leave_channel: expected ok=true, error=%q", resp.Error)
	}
	if m.channelID_ != "" {
		t.Fatal("leave_channel: backend channel should be cleared")
	}
}

// ──────────────────────────────────────────────────────────────────────────
// set_nickname
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerSetNickname(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "set_nickname", Nickname: "Musicbot"})
	if !resp.OK {
		t.Fatalf("set_nickname: expected ok=true, error=%q", resp.Error)
	}
}

func TestHandlerSetNicknameEmptyRejected(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{Action: "set_nickname", Nickname: "   "})
	if resp.OK {
		t.Fatal("set_nickname with empty nickname: expected ok=false")
	}
	if !strings.Contains(resp.Error, "nickname") {
		t.Fatalf("set_nickname error should mention nickname, got %q", resp.Error)
	}
}

// ──────────────────────────────────────────────────────────────────────────
// send_opus_frame
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerSendOpusFrameSuccess(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{
		Action:     "send_opus_frame",
		Format:     "opus",
		Payload:    validBase64Opus(),
		DurationMs: 20,
	})
	if !resp.OK {
		t.Fatalf("send_opus_frame: expected ok=true, error=%q", resp.Error)
	}
}

func TestHandlerSendOpusFrameNotConnected(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{
		Action:  "send_opus_frame",
		Format:  "opus",
		Payload: validBase64Opus(),
	})
	if resp.OK {
		t.Fatal("send_opus_frame when not connected: expected ok=false")
	}
	if !strings.Contains(resp.Error, "not connected") {
		t.Fatalf("error should mention not connected, got %q", resp.Error)
	}
}

func TestHandlerSendOpusFrameNotInChannel(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	// Note: no join_channel

	resp := h.dispatch(request{
		Action:  "send_opus_frame",
		Format:  "opus",
		Payload: validBase64Opus(),
	})
	if resp.OK {
		t.Fatal("send_opus_frame when not in channel: expected ok=false")
	}
	if !strings.Contains(resp.Error, "voice channel") {
		t.Fatalf("error should mention voice channel, got %q", resp.Error)
	}
}

func TestHandlerSendOpusFrameWrongFormat(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{
		Action:  "send_opus_frame",
		Format:  "pcm",
		Payload: validBase64Opus(),
	})
	if resp.OK {
		t.Fatal("send_opus_frame with wrong format: expected ok=false")
	}
	if !strings.Contains(resp.Error, "opus") {
		t.Fatalf("error should mention opus, got %q", resp.Error)
	}
}

func TestHandlerSendOpusFrameEmptyPayload(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{Action: "send_opus_frame", Format: "opus"})
	if resp.OK {
		t.Fatal("send_opus_frame with empty payload: expected ok=false")
	}
	if !strings.Contains(resp.Error, "payload") {
		t.Fatalf("error should mention payload, got %q", resp.Error)
	}
}

func TestHandlerSendOpusFrameInvalidBase64(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{
		Action:  "send_opus_frame",
		Format:  "opus",
		Payload: "!!!not-base64!!!",
	})
	if resp.OK {
		t.Fatal("send_opus_frame with invalid base64: expected ok=false")
	}
	if !strings.Contains(resp.Error, "base64") {
		t.Fatalf("error should mention base64, got %q", resp.Error)
	}
}

func TestHandlerSendOpusFrameBackendError(t *testing.T) {
	m := newMock()
	m.sendFrameErr = errors.New("audio pipeline broken")
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{
		Action:  "send_opus_frame",
		Format:  "opus",
		Payload: validBase64Opus(),
	})
	if resp.OK {
		t.Fatal("send_opus_frame with backend error: expected ok=false")
	}
}

// ──────────────────────────────────────────────────────────────────────────
// status
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerStatusConnected(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})
	h.dispatch(request{Action: "join_channel", ChannelID: "5"})

	resp := h.dispatch(request{Action: "status"})
	if !resp.OK {
		t.Fatalf("status: expected ok=true, error=%q", resp.Error)
	}
	if resp.State != stateConnected {
		t.Fatalf("status: expected state=%q, got %q", stateConnected, resp.State)
	}
	if !resp.Ready {
		t.Fatal("status: expected ready=true when connected")
	}
	if resp.ClientID == "" {
		t.Fatal("status: expected non-empty client_id when connected")
	}
	if resp.ChannelID != "5" {
		t.Fatalf("status: expected channel_id=5, got %q", resp.ChannelID)
	}
}

func TestHandlerStatusDisconnected(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "status"})
	if !resp.OK {
		t.Fatalf("status when disconnected: expected ok=true (status must not return ok=false for state reporting)")
	}
	if resp.State != stateDisconnected {
		t.Fatalf("status when disconnected: expected state=%q, got %q", stateDisconnected, resp.State)
	}
	if resp.Ready {
		t.Fatal("status when disconnected: expected ready=false")
	}
	if resp.ClientID != "" {
		t.Fatalf("status when disconnected: client_id must be empty, got %q", resp.ClientID)
	}
}

// ──────────────────────────────────────────────────────────────────────────
// Unknown action
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerUnknownAction(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	resp := h.dispatch(request{Action: "foobar"})
	if resp.OK {
		t.Fatal("unknown action: expected ok=false")
	}
	if !strings.Contains(resp.Error, "unknown action") {
		t.Fatalf("unknown action error should mention 'unknown action', got %q", resp.Error)
	}
}

// ──────────────────────────────────────────────────────────────────────────
// Secret masking — server_password must NOT appear in any response error
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerSecretMaskingServerPassword(t *testing.T) {
	secret := "s3cr3t-server-pw"
	m := newMock()
	m.connectErr = fmt.Errorf("auth failed with password %s", secret)
	h := newTestHandler(m)

	resp := h.dispatch(request{
		Action:         "connect",
		Host:           "ts.example.com",
		ServerPassword: secret,
	})
	if resp.OK {
		t.Fatal("expected connect to fail")
	}
	if strings.Contains(resp.Error, secret) {
		t.Fatalf("server_password must not appear in error response: got %q", resp.Error)
	}
	if !strings.Contains(resp.Error, "[redacted]") {
		t.Fatalf("error should contain [redacted] instead of secret, got %q", resp.Error)
	}
}

func TestHandlerSecretMaskingChannelPassword(t *testing.T) {
	secret := "ch4nnel-s3cr3t"
	m := newMock()
	m.joinErr = fmt.Errorf("access denied, password %s rejected", secret)
	h := newTestHandler(m)
	h.dispatch(request{Action: "connect", Host: "ts.example.com"})

	resp := h.dispatch(request{
		Action:          "join_channel",
		ChannelID:       "7",
		ChannelPassword: secret,
	})
	if resp.OK {
		t.Fatal("expected join_channel to fail")
	}
	if strings.Contains(resp.Error, secret) {
		t.Fatalf("channel_password must not appear in error response: got %q", resp.Error)
	}
	if !strings.Contains(resp.Error, "[redacted]") {
		t.Fatalf("error should contain [redacted] instead of channel password, got %q", resp.Error)
	}
}

func TestHandlerSecretsNotInDisconnectResponse(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)
	h.serverPassword = "my-secret"
	h.channelPassword = "ch-secret"

	resp := h.dispatch(request{Action: "disconnect"})
	raw, _ := json.Marshal(resp)
	if strings.Contains(string(raw), "my-secret") || strings.Contains(string(raw), "ch-secret") {
		t.Fatalf("secrets found in disconnect response JSON: %s", string(raw))
	}
}

// ──────────────────────────────────────────────────────────────────────────
// Protocol JSON conformance — stdout must be valid NDJSON
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerAllResponsesAreValidJSON(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	actions := []request{
		{Action: "connect", Host: "ts.example.com"},
		{Action: "status"},
		{Action: "join_channel", ChannelID: "1"},
		{Action: "set_nickname", Nickname: "Bot"},
		{Action: "send_opus_frame", Format: "opus", Payload: validBase64Opus(), DurationMs: 20},
		{Action: "leave_channel"},
		{Action: "reconnect"},
		{Action: "unknown_action_xyz"},
		{Action: "disconnect"},
	}

	for _, req := range actions {
		resp := h.dispatch(req)
		b, err := json.Marshal(resp)
		if err != nil {
			t.Fatalf("action %q: response is not JSON-serialisable: %v", req.Action, err)
		}
		var check map[string]interface{}
		if err := json.Unmarshal(b, &check); err != nil {
			t.Fatalf("action %q: response JSON round-trip failed: %v", req.Action, err)
		}
		if _, hasOK := check["ok"]; !hasOK {
			t.Fatalf("action %q: response JSON missing 'ok' field", req.Action)
		}
	}
}

// ──────────────────────────────────────────────────────────────────────────
// Stub backend — default build must fail clearly on connect
// ──────────────────────────────────────────────────────────────────────────

func TestStubBackendFailsClearlyOnConnect(t *testing.T) {
	// Use the real newBackend() from whichever build tag is active.
	logger := nullLogger()
	b := newBackend(logger)
	_, err := b.Connect(context.Background(), connectConfig{Host: "ts.example.com", Port: 9987})
	if err == nil {
		// The ts3clientlib backend may succeed if the library is installed at test time.
		// For the stub, err must be non-nil.
		if b.Name() == "stub" {
			t.Fatal("stub backend: expected Connect to return an error")
		}
		t.Logf("ts3clientlib backend: Connect succeeded (SDK present)")
		return
	}
	if b.Name() == "stub" {
		// Error must NOT contain "panic" or "nil pointer" — must be a clear user message.
		if strings.Contains(err.Error(), "panic") || strings.Contains(err.Error(), "nil pointer") {
			t.Fatalf("stub backend error looks like a crash: %q", err.Error())
		}
		if len(err.Error()) < 20 {
			t.Fatalf("stub backend error is too short to be useful: %q", err.Error())
		}
		t.Logf("stub backend returns clear error: %s", err.Error())
	}
}

func TestStubBackendStatusAlwaysReturnsDisconnected(t *testing.T) {
	b := newBackend(nullLogger())
	if b.Connected() {
		t.Fatal("backend: Connected() must return false before any Connect()")
	}
	if b.ClientID() != "" {
		t.Fatalf("backend: ClientID() must be empty before connect, got %q", b.ClientID())
	}
	if b.CurrentChannelID() != "" {
		t.Fatalf("backend: CurrentChannelID() must be empty before connect, got %q", b.CurrentChannelID())
	}
}

// ──────────────────────────────────────────────────────────────────────────
// Full protocol sequence: connect → join → frames → leave → disconnect
// ──────────────────────────────────────────────────────────────────────────

func TestHandlerFullProtocolSequence(t *testing.T) {
	m := newMock()
	h := newTestHandler(m)

	steps := []struct {
		req     request
		wantOK  bool
		wantKey string // field that must be non-empty in the response
	}{
		{request{Action: "status"}, true, ""}, // disconnected status is ok=true
		{request{Action: "connect", Host: "ts.example.com", Port: 9987, Profile: "ts3", Nickname: "Bot"}, true, "client_id"},
		{request{Action: "set_nickname", Nickname: "Bot [DJ]"}, true, ""},
		{request{Action: "status"}, true, "client_id"},
		{request{Action: "join_channel", ChannelID: "5"}, true, "channel_id"},
		{request{Action: "send_opus_frame", Format: "opus", Payload: validBase64Opus(), DurationMs: 20}, true, ""},
		{request{Action: "send_opus_frame", Format: "opus", Payload: validBase64Opus(), DurationMs: 20}, true, ""},
		{request{Action: "status"}, true, "channel_id"},
		{request{Action: "leave_channel"}, true, ""},
		{request{Action: "reconnect"}, true, "client_id"},
		{request{Action: "join_channel", ChannelID: "3"}, true, "channel_id"},
		{request{Action: "leave_channel"}, true, ""},
		{request{Action: "disconnect"}, true, ""},
		{request{Action: "status"}, true, ""},
	}

	for _, step := range steps {
		resp := h.dispatch(step.req)
		if resp.OK != step.wantOK {
			t.Fatalf("action=%q: expected ok=%v, got ok=%v error=%q",
				step.req.Action, step.wantOK, resp.OK, resp.Error)
		}
		if step.wantKey == "client_id" && resp.ClientID == "" {
			t.Fatalf("action=%q: expected non-empty client_id", step.req.Action)
		}
		if step.wantKey == "channel_id" && resp.ChannelID == "" {
			t.Fatalf("action=%q: expected non-empty channel_id", step.req.Action)
		}
	}
}
