package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"strings"
	"sync"
	"testing"
)

// mockAdapter is a configurable test double for TeamspeakClientAdapter.
type mockAdapter struct {
	mu sync.Mutex

	connectID  string
	connectErr error

	reconnectID  string
	reconnectErr error

	joinChannelID  string
	joinChannelErr error

	leaveChannelErr error
	setNicknameErr  error
	sendFrameErr    error
	shutdownCalls   int
}

func (m *mockAdapter) Connect(_ context.Context, _ connectParams) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.connectErr != nil {
		return "", m.connectErr
	}
	id := m.connectID
	if id == "" {
		id = "mock-client-id"
	}
	return id, nil
}

func (m *mockAdapter) Disconnect(_ context.Context) error { return nil }

func (m *mockAdapter) Authenticate(_ context.Context) error { return nil }

func (m *mockAdapter) Reconnect(_ context.Context) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.reconnectErr != nil {
		return "", m.reconnectErr
	}
	id := m.reconnectID
	if id == "" {
		id = "mock-reconnect-id"
	}
	return id, nil
}

func (m *mockAdapter) JoinChannel(_ context.Context, channelID, _ string) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.joinChannelErr != nil {
		return "", m.joinChannelErr
	}
	actual := m.joinChannelID
	if actual == "" {
		actual = channelID
	}
	return actual, nil
}

func (m *mockAdapter) LeaveChannel(_ context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.leaveChannelErr
}

func (m *mockAdapter) SetNickname(_ context.Context, _ string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.setNicknameErr
}

func (m *mockAdapter) SendOpusFrame(_ context.Context, _ []byte, _ int) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.sendFrameErr
}

func (m *mockAdapter) Status(_ context.Context) (adapterStatus, error) {
	return adapterStatus{State: stateDisconnected}, nil
}

func (m *mockAdapter) Shutdown(_ context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.shutdownCalls++
	return nil
}

// runRequests feeds reqs to the bridge and returns the parsed responses in order.
func runRequests(t *testing.T, adapter TeamspeakClientAdapter, reqs ...bridgeRequest) []bridgeResponse {
	t.Helper()
	var in strings.Builder
	enc := json.NewEncoder(&in)
	enc.SetEscapeHTML(false)
	for _, req := range reqs {
		if err := enc.Encode(req); err != nil {
			t.Fatalf("encode request: %v", err)
		}
	}
	var out bytes.Buffer
	b := newBridge(adapter, &out, log.New(io.Discard, "", 0))
	if err := b.run(context.Background(), strings.NewReader(in.String())); err != nil {
		t.Fatalf("bridge run error: %v", err)
	}
	var resps []bridgeResponse
	scanner := bufio.NewScanner(&out)
	for scanner.Scan() {
		var resp bridgeResponse
		if err := json.Unmarshal(scanner.Bytes(), &resp); err != nil {
			t.Fatalf("decode response: %v", err)
		}
		resps = append(resps, resp)
	}
	if len(resps) != len(reqs) {
		t.Fatalf("got %d responses for %d requests", len(resps), len(reqs))
	}
	return resps
}

// runRaw feeds a single raw line (possibly invalid JSON) to the bridge.
func runRaw(t *testing.T, adapter TeamspeakClientAdapter, line string) bridgeResponse {
	t.Helper()
	var out bytes.Buffer
	b := newBridge(adapter, &out, log.New(io.Discard, "", 0))
	if err := b.run(context.Background(), strings.NewReader(line+"\n")); err != nil {
		t.Fatalf("bridge run error: %v", err)
	}
	scanner := bufio.NewScanner(&out)
	if !scanner.Scan() {
		t.Fatal("expected one response line")
	}
	var resp bridgeResponse
	if err := json.Unmarshal(scanner.Bytes(), &resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	return resp
}

// --- status ---

func TestBridgeStatusInitial(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{Action: "status"})
	r := resps[0]
	if !r.OK {
		t.Fatalf("status: ok=false error=%q", r.Error)
	}
	if r.State != stateDisconnected {
		t.Errorf("state = %q, want %q", r.State, stateDisconnected)
	}
	if r.ClientID != "" {
		t.Errorf("client_id = %q, want empty", r.ClientID)
	}
	if r.ChannelID != "" {
		t.Errorf("channel_id = %q, want empty", r.ChannelID)
	}
}

// --- unknown / bad input ---

func TestBridgeUnknownAction(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{Action: "teleport"})
	r := resps[0]
	if r.OK {
		t.Fatal("unknown action: expected ok=false")
	}
	if r.Error == "" {
		t.Error("unknown action: expected non-empty error")
	}
}

func TestBridgeInvalidJSON(t *testing.T) {
	r := runRaw(t, NewPlaceholderAdapter(), `{"action":}`)
	if r.OK {
		t.Fatal("invalid JSON: expected ok=false")
	}
	if r.Error == "" {
		t.Error("invalid JSON: expected error message")
	}
}

func TestBridgeContinuesAfterInvalidJSON(t *testing.T) {
	// Bridge must stay alive and process subsequent valid requests after bad input.
	var in strings.Builder
	in.WriteString("not json at all\n")
	data, _ := json.Marshal(bridgeRequest{Action: "status"})
	in.Write(data)
	in.WriteByte('\n')

	var out bytes.Buffer
	b := newBridge(NewPlaceholderAdapter(), &out, log.New(io.Discard, "", 0))
	if err := b.run(context.Background(), strings.NewReader(in.String())); err != nil {
		t.Fatalf("run error: %v", err)
	}
	var resps []bridgeResponse
	scanner := bufio.NewScanner(&out)
	for scanner.Scan() {
		var resp bridgeResponse
		_ = json.Unmarshal(scanner.Bytes(), &resp)
		resps = append(resps, resp)
	}
	if len(resps) != 2 {
		t.Fatalf("expected 2 responses, got %d", len(resps))
	}
	if resps[0].OK {
		t.Error("response to invalid JSON should be ok=false")
	}
	if !resps[1].OK {
		t.Errorf("status after bad input should succeed, got error=%q", resps[1].Error)
	}
}

// --- connect ---

func TestBridgeConnectSelectsNativeSDKStub(t *testing.T) {
	resps := runRequests(t, NewSelectingAdapter(), bridgeRequest{
		Action: "connect", BackendType: "native_sdk", BackendPath: "", Host: "ts.example.com", ServerPassword: "server-secret",
	})
	r := resps[0]
	if r.OK {
		t.Fatal("native_sdk stub without SDK should not connect")
	}
	if !strings.Contains(r.Error, "native_sdk") || !strings.Contains(r.Error, "backend_path") {
		t.Fatalf("native_sdk stub error = %q", r.Error)
	}
	if strings.Contains(r.Error, "server-secret") {
		t.Fatalf("server password leaked in native_sdk error: %q", r.Error)
	}
}

func TestBridgeConnectSelectsClientLibraryStub(t *testing.T) {
	resps := runRequests(t, NewSelectingAdapter(), bridgeRequest{
		Action: "connect", BackendType: "client_library", BackendPath: "", Host: "ts.example.com",
	})
	r := resps[0]
	if r.OK {
		t.Fatal("client_library stub without library should not connect")
	}
	if !strings.Contains(r.Error, "client_library") || !strings.Contains(r.Error, "backend_path") {
		t.Fatalf("client_library stub error = %q", r.Error)
	}
}

func TestBridgeShutdownCallsAdapterShutdown(t *testing.T) {
	adapter := &mockAdapter{}
	resps := runRequests(t, adapter, bridgeRequest{Action: "shutdown"})
	if !resps[0].OK {
		t.Fatalf("shutdown failed: %v", resps[0].Error)
	}
	adapter.mu.Lock()
	defer adapter.mu.Unlock()
	if adapter.shutdownCalls != 1 {
		t.Fatalf("shutdown calls = %d, want 1", adapter.shutdownCalls)
	}
}

func TestBridgeConnectPlaceholderFails(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{
		Action: "connect", Host: "ts.example.com", Port: 9987, Profile: "ts3", Nickname: "Bot",
	})
	r := resps[0]
	if r.OK {
		t.Fatal("placeholder connect: expected ok=false")
	}
	if r.Error == "" {
		t.Error("placeholder connect: expected non-empty error")
	}
}

func TestBridgeConnectMockSuccess(t *testing.T) {
	adapter := &mockAdapter{connectID: "42"}
	resps := runRequests(t, adapter, bridgeRequest{
		Action: "connect", Host: "ts.example.com", Port: 9987, Profile: "ts3", Nickname: "Bot",
	})
	r := resps[0]
	if !r.OK {
		t.Fatalf("connect failed: %v", r.Error)
	}
	if r.State != stateConnected {
		t.Errorf("state = %q, want connected", r.State)
	}
	if r.ClientID != "42" {
		t.Errorf("client_id = %q, want 42", r.ClientID)
	}
}

func TestBridgeConnectDefaultPort(t *testing.T) {
	// Port 0 in request → bridge should default to 9987 without error.
	adapter := &mockAdapter{connectID: "1"}
	resps := runRequests(t, adapter, bridgeRequest{Action: "connect", Host: "ts.example.com"})
	if !resps[0].OK {
		t.Fatalf("connect with default port failed: %v", resps[0].Error)
	}
}

func TestBridgeConnectProfileTS6(t *testing.T) {
	adapter := &mockAdapter{connectID: "3"}
	resps := runRequests(t, adapter, bridgeRequest{
		Action: "connect", Host: "ts6.example.com", Port: 9987, Profile: "ts6",
	})
	if !resps[0].OK {
		t.Fatalf("ts6 connect failed: %v", resps[0].Error)
	}
}

// --- secret masking ---

func TestBridgeServerPasswordMaskedInError(t *testing.T) {
	password := "super-secret-server-pw"
	adapter := &mockAdapter{
		connectErr: fmt.Errorf("authentication failed (password=%s)", password),
	}
	resps := runRequests(t, adapter, bridgeRequest{
		Action: "connect", Host: "ts.example.com", ServerPassword: password,
	})
	r := resps[0]
	if r.OK {
		t.Fatal("expected ok=false")
	}
	if strings.Contains(r.Error, password) {
		t.Errorf("server_password leaked in error response: %q", r.Error)
	}
	if !strings.Contains(r.Error, "[redacted]") {
		t.Errorf("expected [redacted] in masked error, got: %q", r.Error)
	}
}

func TestBridgeChannelPasswordMaskedInError(t *testing.T) {
	password := "channel-pw-secret"
	adapter := &mockAdapter{
		connectID:      "1",
		joinChannelErr: fmt.Errorf("wrong channel password: %s", password),
	}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "join_channel", ChannelID: "5", ChannelPassword: password},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	r := resps[1]
	if r.OK {
		t.Fatal("expected ok=false for join_channel failure")
	}
	if strings.Contains(r.Error, password) {
		t.Errorf("channel_password leaked in error response: %q", r.Error)
	}
}

// --- disconnect / shutdown ---

func TestBridgeDisconnectIdempotent(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(),
		bridgeRequest{Action: "disconnect"},
		bridgeRequest{Action: "disconnect"},
	)
	for i, r := range resps {
		if !r.OK {
			t.Errorf("disconnect[%d]: ok=false error=%q", i, r.Error)
		}
		if r.State != stateDisconnected {
			t.Errorf("disconnect[%d]: state=%q, want disconnected", i, r.State)
		}
	}
}

func TestBridgeShutdownAliasesDisconnect(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{Action: "shutdown"})
	r := resps[0]
	if !r.OK {
		t.Fatalf("shutdown: ok=false error=%q", r.Error)
	}
	if r.State != stateDisconnected {
		t.Errorf("state = %q, want disconnected", r.State)
	}
}

// --- reconnect ---

func TestBridgeReconnectSuccess(t *testing.T) {
	adapter := &mockAdapter{connectID: "1", reconnectID: "2"}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "reconnect"},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if !resps[1].OK {
		t.Fatalf("reconnect failed: %v", resps[1].Error)
	}
	if resps[1].State != stateConnected {
		t.Errorf("reconnect state = %q, want connected", resps[1].State)
	}
	if resps[1].ClientID != "2" {
		t.Errorf("reconnect client_id = %q, want 2", resps[1].ClientID)
	}
}

func TestBridgeReconnectFailureLeavesDisconnected(t *testing.T) {
	adapter := &mockAdapter{
		connectID:    "1",
		reconnectErr: errors.New("server unreachable"),
	}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "reconnect"},
		bridgeRequest{Action: "status"},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if resps[1].OK {
		t.Fatal("reconnect should have failed")
	}
	if !resps[2].OK {
		t.Fatalf("status after failed reconnect: %v", resps[2].Error)
	}
	if resps[2].State != stateDisconnected {
		t.Errorf("state after reconnect failure = %q, want disconnected", resps[2].State)
	}
}

func TestBridgeReconnectPlaceholderFails(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{Action: "reconnect"})
	if resps[0].OK {
		t.Fatal("placeholder reconnect: expected ok=false")
	}
}

// --- join_channel ---

func TestBridgeJoinChannelMissingID(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{Action: "join_channel"})
	if resps[0].OK {
		t.Fatal("join_channel without channel_id: expected ok=false")
	}
}

func TestBridgeJoinChannelSuccess(t *testing.T) {
	adapter := &mockAdapter{connectID: "1", joinChannelID: "7"}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "join_channel", ChannelID: "7"},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if !resps[1].OK {
		t.Fatalf("join_channel failed: %v", resps[1].Error)
	}
	if resps[1].ChannelID != "7" {
		t.Errorf("channel_id = %q, want 7", resps[1].ChannelID)
	}
}

// --- leave_channel ---

func TestBridgeLeaveChannelClearsChannelID(t *testing.T) {
	adapter := &mockAdapter{connectID: "1"}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "join_channel", ChannelID: "3"},
		bridgeRequest{Action: "leave_channel"},
		bridgeRequest{Action: "status"},
	)
	for i, r := range resps {
		if !r.OK {
			t.Fatalf("step %d failed: %v", i, r.Error)
		}
	}
	statusResp := resps[3]
	if statusResp.ChannelID != "" {
		t.Errorf("after leave_channel, channel_id = %q, want empty", statusResp.ChannelID)
	}
	if statusResp.State != stateConnected {
		t.Errorf("after leave_channel, state = %q, want connected", statusResp.State)
	}
}

// --- set_nickname ---

func TestBridgeSetNicknameMissing(t *testing.T) {
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{Action: "set_nickname"})
	if resps[0].OK {
		t.Fatal("set_nickname without nickname: expected ok=false")
	}
}

func TestBridgeSetNicknameSuccess(t *testing.T) {
	adapter := &mockAdapter{connectID: "1"}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "set_nickname", Nickname: "Musicbot [Paused]"},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if !resps[1].OK {
		t.Fatalf("set_nickname failed: %v", resps[1].Error)
	}
}

// --- send_opus_frame ---

func TestBridgeSendOpusFrameNotConnected(t *testing.T) {
	payload := base64.StdEncoding.EncodeToString([]byte{0x01, 0x02, 0x03})
	resps := runRequests(t, NewPlaceholderAdapter(), bridgeRequest{
		Action: "send_opus_frame", Format: "opus", Payload: payload, DurationMs: 20,
	})
	if resps[0].OK {
		t.Fatal("send_opus_frame when disconnected: expected ok=false")
	}
	if resps[0].Error == "" {
		t.Error("send_opus_frame when disconnected: expected error message")
	}
}

func TestBridgeSendOpusFrameUnsupportedFormat(t *testing.T) {
	adapter := &mockAdapter{connectID: "1"}
	payload := base64.StdEncoding.EncodeToString([]byte{0x01, 0x02})
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "pcm", Payload: payload, DurationMs: 20},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if resps[1].OK {
		t.Fatal("send_opus_frame with format=pcm: expected ok=false")
	}
}

func TestBridgeSendOpusFrameFormatCaseInsensitive(t *testing.T) {
	adapter := &mockAdapter{connectID: "1"}
	payload := base64.StdEncoding.EncodeToString([]byte{0x70, 0x80, 0x90})
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "Opus", Payload: payload, DurationMs: 20},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if !resps[1].OK {
		t.Fatalf("send_opus_frame Format=Opus (case): %v", resps[1].Error)
	}
}

func TestBridgeSendOpusFrameInvalidBase64(t *testing.T) {
	adapter := &mockAdapter{connectID: "1"}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: "!!not-base64!!", DurationMs: 20},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if resps[1].OK {
		t.Fatal("send_opus_frame with invalid base64: expected ok=false")
	}
}

func TestBridgeSendOpusFrameEmptyPayload(t *testing.T) {
	adapter := &mockAdapter{connectID: "1"}
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: "", DurationMs: 20},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if resps[1].OK {
		t.Fatal("send_opus_frame with empty payload: expected ok=false")
	}
}

func TestBridgeSendOpusFrameSuccess(t *testing.T) {
	adapter := &mockAdapter{connectID: "42"}
	frame := []byte{0x70, 0x80, 0x90, 0xA0}
	payload := base64.StdEncoding.EncodeToString(frame)
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: payload, DurationMs: 20},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if !resps[1].OK {
		t.Fatalf("send_opus_frame failed: %v", resps[1].Error)
	}
}

func TestBridgeSendOpusFrameAdapterError(t *testing.T) {
	adapter := &mockAdapter{connectID: "1", sendFrameErr: errors.New("voice channel send failed")}
	payload := base64.StdEncoding.EncodeToString([]byte{0x01, 0x02})
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: payload, DurationMs: 20},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if resps[1].OK {
		t.Fatal("send_opus_frame with adapter error: expected ok=false")
	}
	if resps[1].Error == "" {
		t.Error("expected non-empty error from adapter")
	}
}

func TestBridgeSendOpusFrameDefaultDuration(t *testing.T) {
	// DurationMs=0 must not cause a failure; bridge defaults to 20ms.
	adapter := &mockAdapter{connectID: "1"}
	payload := base64.StdEncoding.EncodeToString([]byte{0x01})
	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com"},
		bridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: payload},
	)
	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if !resps[1].OK {
		t.Fatalf("send_opus_frame zero duration_ms failed: %v", resps[1].Error)
	}
}

// --- full flow ---

func TestBridgeFullFlowMockAdapter(t *testing.T) {
	adapter := &mockAdapter{connectID: "99", joinChannelID: "7"}
	frame := []byte{0x01, 0x02, 0x03}
	payload := base64.StdEncoding.EncodeToString(frame)

	resps := runRequests(t, adapter,
		bridgeRequest{Action: "connect", Host: "ts.example.com", Port: 9987, Profile: "ts3", Nickname: "Bot"},
		bridgeRequest{Action: "join_channel", ChannelID: "7"},
		bridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: payload, DurationMs: 20},
		bridgeRequest{Action: "status"},
		bridgeRequest{Action: "leave_channel"},
		bridgeRequest{Action: "status"},
		bridgeRequest{Action: "disconnect"},
	)

	if !resps[0].OK {
		t.Fatalf("connect failed: %v", resps[0].Error)
	}
	if resps[0].ClientID != "99" || resps[0].State != stateConnected {
		t.Errorf("connect: client_id=%q state=%q", resps[0].ClientID, resps[0].State)
	}

	if !resps[1].OK {
		t.Fatalf("join_channel failed: %v", resps[1].Error)
	}
	if resps[1].ChannelID != "7" {
		t.Errorf("join_channel: channel_id=%q, want 7", resps[1].ChannelID)
	}

	if !resps[2].OK {
		t.Fatalf("send_opus_frame failed: %v", resps[2].Error)
	}

	// status while connected and in channel
	if !resps[3].OK {
		t.Fatalf("status failed: %v", resps[3].Error)
	}
	if resps[3].State != stateConnected {
		t.Errorf("status state=%q, want connected", resps[3].State)
	}
	if resps[3].ClientID != "99" {
		t.Errorf("status client_id=%q, want 99", resps[3].ClientID)
	}
	if resps[3].ChannelID != "7" {
		t.Errorf("status channel_id=%q, want 7", resps[3].ChannelID)
	}

	if !resps[4].OK {
		t.Fatalf("leave_channel failed: %v", resps[4].Error)
	}

	// status after leave: still connected, no channel
	if !resps[5].OK {
		t.Fatalf("status after leave failed: %v", resps[5].Error)
	}
	if resps[5].State != stateConnected {
		t.Errorf("state after leave=%q, want connected", resps[5].State)
	}
	if resps[5].ChannelID != "" {
		t.Errorf("channel_id after leave=%q, want empty", resps[5].ChannelID)
	}

	if !resps[6].OK {
		t.Fatalf("disconnect failed: %v", resps[6].Error)
	}
	if resps[6].State != stateDisconnected {
		t.Errorf("disconnect state=%q, want disconnected", resps[6].State)
	}
}

// --- normalizeProfile ---

func TestNormalizeProfile(t *testing.T) {
	cases := []struct{ in, want string }{
		{"ts3", "ts3"},
		{"ts6", "ts6"},
		{"TS6", "ts6"},
		{"TS3", "ts3"},
		{"", "ts3"},
		{"unknown", "ts3"},
	}
	for _, c := range cases {
		if got := normalizeProfile(c.in); got != c.want {
			t.Errorf("normalizeProfile(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}
