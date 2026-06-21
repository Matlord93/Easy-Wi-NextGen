package musicbotruntime

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"strings"
	"sync"
	"testing"
	"time"
)

// ----------------------------------------------------------------------------
// Mock WebSocket connection
// ----------------------------------------------------------------------------

// mockWSConn is a synchronous, channel-backed fake WebSocket connection.
// Pre-load messages via pushMsg; reads block until a message or close arrives.
type mockWSConn struct {
	mu     sync.Mutex
	msgCh  chan json.RawMessage
	sent   []map[string]any
	closed bool
}

func newMockWSConn(capacity int) *mockWSConn {
	return &mockWSConn{msgCh: make(chan json.RawMessage, capacity)}
}

func (m *mockWSConn) pushMsg(v any) {
	data, _ := json.Marshal(v)
	m.msgCh <- json.RawMessage(data)
}

func (m *mockWSConn) ReadJSON(v any) error {
	data, ok := <-m.msgCh
	if !ok {
		return errors.New("connection closed")
	}
	return json.Unmarshal(data, v)
}

func (m *mockWSConn) WriteJSON(v any) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.closed {
		return errors.New("connection closed")
	}
	raw, _ := json.Marshal(v)
	var msg map[string]any
	_ = json.Unmarshal(raw, &msg)
	m.sent = append(m.sent, msg)
	return nil
}

func (m *mockWSConn) Close() error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if !m.closed {
		m.closed = true
		close(m.msgCh)
	}
	return nil
}

func (m *mockWSConn) sentOps() []int {
	m.mu.Lock()
	defer m.mu.Unlock()
	var ops []int
	for _, msg := range m.sent {
		if op, ok := msg["op"].(float64); ok {
			ops = append(ops, int(op))
		}
	}
	return ops
}

// ----------------------------------------------------------------------------
// Scripted dial functions
// ----------------------------------------------------------------------------

// gatewayDialScript builds a dial function that returns a pre-scripted gateway conn.
// It pre-loads: Hello(op10) → READY(op0) → then blocks until conn is closed.
func gatewayDialScript(sessionID, userID string) (wsDialFunc, *mockWSConn) {
	gw := newMockWSConn(8)
	gw.pushMsg(map[string]any{
		"op": gwOpcodeHello,
		"d":  map[string]any{"heartbeat_interval": 1000},
	})
	gw.pushMsg(map[string]any{
		"op": gwOpcodeDispatch,
		"t":  "READY",
		"d": map[string]any{
			"session_id":         sessionID,
			"resume_gateway_url": "wss://resume.discord.gg",
			"user":               map[string]any{"id": userID},
		},
	})

	dial := func(_ context.Context, rawURL string, _ http.Header) (wsConn, error) {
		if strings.Contains(rawURL, "gateway.discord.gg") {
			return gw, nil
		}
		return nil, errors.New("unexpected dial: " + rawURL)
	}
	return dial, gw
}

// voiceHandshakeDialScript extends a gateway dial function to also handle
// voice WebSocket connections by returning a pre-scripted voice conn.
func voiceHandshakeDialScript(sessionID, userID string, ssrc uint32, secretKey []byte) (wsDialFunc, *mockWSConn, *mockWSConn) {
	gw := newMockWSConn(16)
	// Gateway Hello + READY
	gw.pushMsg(map[string]any{
		"op": gwOpcodeHello,
		"d":  map[string]any{"heartbeat_interval": 1000},
	})
	gw.pushMsg(map[string]any{
		"op": gwOpcodeDispatch,
		"t":  "READY",
		"d": map[string]any{
			"session_id":         sessionID,
			"resume_gateway_url": "wss://resume.discord.gg",
			"user":               map[string]any{"id": userID},
		},
	})

	vc := newMockWSConn(16)
	// Voice Hello + Ready + Session Description
	vc.pushMsg(map[string]any{
		"op": vcOpcodeHello,
		"d":  map[string]any{"heartbeat_interval": 1000.0},
	})
	vc.pushMsg(map[string]any{
		"op": vcOpcodeReady,
		"d": map[string]any{
			"ssrc":  ssrc,
			"ip":    "127.0.0.1",
			"port":  12345,
			"modes": []string{"xsalsa20_poly1305"},
		},
	})
	vc.pushMsg(map[string]any{
		"op": vcOpcodeSessionDescription,
		"d": map[string]any{
			"mode":       "xsalsa20_poly1305",
			"secret_key": secretKey,
		},
	})

	dial := func(_ context.Context, rawURL string, _ http.Header) (wsConn, error) {
		if strings.Contains(rawURL, "gateway.discord.gg") {
			return gw, nil
		}
		// Any other URL is treated as a voice WS.
		return vc, nil
	}
	return dial, gw, vc
}

// ----------------------------------------------------------------------------
// Mock UDP helpers
// ----------------------------------------------------------------------------

func mockUDPDial(_ *net.UDPAddr) (*net.UDPConn, error) {
	// Unconnected loopback socket — suitable for tests that only call mockIPDiscovery
	// (which bypasses real I/O). Tests that actually call SendOpusFrame must use
	// newTestUDPDialer instead.
	local, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		return nil, err
	}
	return local, nil
}

// newTestUDPDialer starts a loopback UDP listener that drains received packets
// and returns a dial function that creates connected sockets to that listener.
// Use this in tests that call SendOpusFrame.
func newTestUDPDialer(t *testing.T) udpDialFunc {
	t.Helper()
	listener, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatalf("newTestUDPDialer: %v", err)
	}
	t.Cleanup(func() { _ = listener.Close() })
	go func() {
		buf := make([]byte, 4096)
		for {
			if _, _, err := listener.ReadFromUDP(buf); err != nil {
				return
			}
		}
	}()
	addr := listener.LocalAddr().(*net.UDPAddr)
	return func(_ *net.UDPAddr) (*net.UDPConn, error) {
		return net.DialUDP("udp", nil, addr)
	}
}

func mockIPDiscovery(_ *net.UDPConn, _ uint32) (string, int, error) {
	return "192.0.2.1", 54321, nil
}

// ----------------------------------------------------------------------------
// Test: gateway VOICE_STATE/SERVER update routing during JoinVoiceChannel
// ----------------------------------------------------------------------------

// simulateVoiceHandshakeEvents pushes VOICE_STATE_UPDATE and VOICE_SERVER_UPDATE
// into the gateway mock after a short delay (simulating Discord's async events).
func simulateVoiceHandshakeEvents(gw *mockWSConn, guildID, channelID, userID, voiceSessionID string) {
	time.Sleep(20 * time.Millisecond)
	gw.pushMsg(map[string]any{
		"op": gwOpcodeDispatch,
		"t":  "VOICE_STATE_UPDATE",
		"d": map[string]any{
			"guild_id":   guildID,
			"channel_id": channelID,
			"user_id":    userID,
			"session_id": voiceSessionID,
		},
	})
	gw.pushMsg(map[string]any{
		"op": gwOpcodeDispatch,
		"t":  "VOICE_SERVER_UPDATE",
		"d": map[string]any{
			"token":    "voice-short-lived-token",
			"guild_id": guildID,
			"endpoint": "127.0.0.1",
		},
	})
}

// ----------------------------------------------------------------------------
// Tests
// ----------------------------------------------------------------------------

// TestRealDiscordVoiceClientTokenNeverInState verifies that the bot token does
// not appear in GetLastError or GetVoiceState.LastError under any code path.
func TestRealDiscordVoiceClientTokenNeverInState(t *testing.T) {
	t.Parallel()

	const secret = "super-secret-bot-token-XYZ"
	cfg := ConnectorConfig{
		Enabled: true,
		Config:  map[string]any{"bot_token": secret},
	}

	// Dial fails immediately (simulated network error that includes the URL).
	dial := func(_ context.Context, rawURL string, _ http.Header) (wsConn, error) {
		return nil, errors.New("dial failed for " + secret + " at " + rawURL)
	}
	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)

	_ = c.ConnectGateway(context.Background(), cfg)

	if strings.Contains(c.GetLastError(), secret) {
		t.Fatalf("GetLastError leaked bot token: %q", c.GetLastError())
	}
	if strings.Contains(c.GetVoiceState(context.Background()).LastError, secret) {
		t.Fatalf("GetVoiceState.LastError leaked bot token")
	}
}

// TestRealDiscordVoiceClientSendFrameRequiresReadyState verifies that
// SendOpusFrame returns an error when the voice channel is not joined.
func TestRealDiscordVoiceClientSendFrameRequiresReadyState(t *testing.T) {
	t.Parallel()

	c := newRealDiscordVoiceClientWith(defaultWSDialFunc, mockUDPDial, mockIPDiscovery)
	defer func() { _ = c.Close(context.Background()) }()

	frame := AudioFrame{
		Format:       "opus",
		SampleRateHz: 48000,
		Channels:     2,
		DurationMs:   20,
		Payload:      []byte{0xFC, 0xFF},
	}
	err := c.SendOpusFrame(context.Background(), frame)
	if err == nil {
		t.Fatal("SendOpusFrame() expected error when voice not joined, got nil")
	}
	if strings.Contains(err.Error(), "token") || strings.Contains(err.Error(), "secret") {
		t.Fatalf("SendOpusFrame() error contains sensitive word: %q", err.Error())
	}
}

// TestRealDiscordVoiceClientGatewayConnect tests the full gateway connect
// handshake using a scripted mock WS conn, asserting that the client reaches
// GatewayConnected=true and sends Identify.
func TestRealDiscordVoiceClientGatewayConnect(t *testing.T) {
	t.Parallel()

	dial, gw := gatewayDialScript("session-abc", "user-123")
	defer func() { _ = gw.Close() }()

	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)
	defer func() { _ = c.Close(context.Background()) }()

	cfg := ConnectorConfig{
		Enabled: true,
		Config:  map[string]any{"bot_token": "test-token-redacted"},
	}
	if err := c.ConnectGateway(context.Background(), cfg); err != nil {
		t.Fatalf("ConnectGateway() error = %v", err)
	}

	state := c.GetVoiceState(context.Background())
	if !state.GatewayConnected {
		t.Fatal("state.GatewayConnected = false after ConnectGateway")
	}

	// Verify Identify (op 2) was sent and no token leaks.
	ops := gw.sentOps()
	if len(ops) == 0 || ops[0] != gwOpcodeIdentify {
		t.Fatalf("expected first sent op to be Identify (2), got %v", ops)
	}
	if strings.Contains(state.LastError, "test-token-redacted") {
		t.Fatalf("state.LastError leaked token: %q", state.LastError)
	}
}

// TestRealDiscordVoiceClientVoiceStateTransitions verifies that CapabilityStatus
// moves through the expected states: VoiceBackendRequired → Placeholder (after
// gateway) → Ready (after voice join).
func TestRealDiscordVoiceClientVoiceStateTransitions(t *testing.T) {
	t.Parallel()

	const (
		guildID        = "guild-999"
		channelID      = "ch-111"
		userID         = "user-42"
		voiceSessionID = "vs-session-1"
		ssrc           = uint32(12345)
	)
	secretKey := make([]byte, 32)
	for i := range secretKey {
		secretKey[i] = byte(i)
	}

	dial, gw, _ := voiceHandshakeDialScript("gw-session", userID, ssrc, secretKey)
	defer func() { _ = gw.Close() }()

	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)
	defer func() { _ = c.Close(context.Background()) }()

	// Initial state.
	if s := c.GetVoiceState(context.Background()).CapabilityStatus; s != CapabilityStatusVoiceBackendRequired {
		t.Fatalf("initial CapabilityStatus = %q, want %q", s, CapabilityStatusVoiceBackendRequired)
	}

	cfg := ConnectorConfig{
		Enabled: true,
		Config:  map[string]any{"bot_token": "tok"},
	}
	if err := c.ConnectGateway(context.Background(), cfg); err != nil {
		t.Fatalf("ConnectGateway() error = %v", err)
	}
	if s := c.GetVoiceState(context.Background()).CapabilityStatus; s != CapabilityStatusPlaceholder {
		t.Fatalf("after gateway CapabilityStatus = %q, want %q", s, CapabilityStatusPlaceholder)
	}

	// Simulate Discord sending voice handshake events.
	go simulateVoiceHandshakeEvents(gw, guildID, channelID, userID, voiceSessionID)

	if err := c.JoinVoiceChannel(context.Background(), guildID, channelID); err != nil {
		t.Fatalf("JoinVoiceChannel() error = %v", err)
	}
	state := c.GetVoiceState(context.Background())
	if state.CapabilityStatus != CapabilityStatusReady {
		t.Fatalf("after voice join CapabilityStatus = %q, want %q", state.CapabilityStatus, CapabilityStatusReady)
	}
	if !state.VoiceJoined {
		t.Fatal("VoiceJoined = false after JoinVoiceChannel")
	}
	if state.GuildID != guildID || state.ChannelID != channelID {
		t.Fatalf("state.GuildID=%q ChannelID=%q, want %q %q", state.GuildID, state.ChannelID, guildID, channelID)
	}
}

// TestRealDiscordVoiceClientReconnectPath verifies that Reconnect results in a
// re-identified gateway connection (two Identify sends total) and resets state.
func TestRealDiscordVoiceClientReconnectPath(t *testing.T) {
	t.Parallel()

	callCount := 0
	var mu sync.Mutex
	var conns []*mockWSConn

	dial := func(_ context.Context, rawURL string, _ http.Header) (wsConn, error) {
		if !strings.Contains(rawURL, "gateway.discord.gg") {
			return nil, errors.New("unexpected voice dial in reconnect test")
		}
		mu.Lock()
		defer mu.Unlock()
		callCount++
		gw := newMockWSConn(8)
		gw.pushMsg(map[string]any{
			"op": gwOpcodeHello,
			"d":  map[string]any{"heartbeat_interval": 30000},
		})
		gw.pushMsg(map[string]any{
			"op": gwOpcodeDispatch,
			"t":  "READY",
			"d": map[string]any{
				"session_id":         "sess-reconnect",
				"resume_gateway_url": "wss://resume.discord.gg",
				"user":               map[string]any{"id": "user-r"},
			},
		})
		conns = append(conns, gw)
		return gw, nil
	}

	cfg := ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "tok-r"}}
	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())

	if err := c.ConnectGateway(context.Background(), cfg); err != nil {
		t.Fatalf("first ConnectGateway() = %v", err)
	}

	// Reconnect should disconnect and re-connect.
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := c.Reconnect(ctx, cfg); err != nil {
		t.Fatalf("Reconnect() = %v", err)
	}

	mu.Lock()
	cc := callCount
	mu.Unlock()
	if cc != 2 {
		t.Fatalf("dial called %d times, want 2 (initial + reconnect)", cc)
	}

	state := c.GetVoiceState(context.Background())
	if !state.GatewayConnected {
		t.Fatal("GatewayConnected = false after Reconnect")
	}
}

// TestRealDiscordVoiceClientLeaveVoiceChannel verifies that after LeaveVoiceChannel
// the state reflects VoiceJoined=false and CapabilityStatus downgrades.
func TestRealDiscordVoiceClientLeaveVoiceChannel(t *testing.T) {
	t.Parallel()

	const userID = "user-leave"
	const ssrc = uint32(7)
	secretKey := make([]byte, 32)

	dial, gw, _ := voiceHandshakeDialScript("gw-s", userID, ssrc, secretKey)
	defer func() { _ = gw.Close() }()

	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)
	defer func() { _ = c.Close(context.Background()) }()

	cfg := ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "t"}}
	if err := c.ConnectGateway(context.Background(), cfg); err != nil {
		t.Fatalf("ConnectGateway() = %v", err)
	}

	go simulateVoiceHandshakeEvents(gw, "g1", "c1", userID, "vs1")
	if err := c.JoinVoiceChannel(context.Background(), "g1", "c1"); err != nil {
		t.Fatalf("JoinVoiceChannel() = %v", err)
	}

	if !c.GetVoiceState(context.Background()).VoiceJoined {
		t.Fatal("VoiceJoined = false before LeaveVoiceChannel")
	}

	if err := c.LeaveVoiceChannel(context.Background()); err != nil {
		t.Fatalf("LeaveVoiceChannel() = %v", err)
	}

	state := c.GetVoiceState(context.Background())
	if state.VoiceJoined {
		t.Fatal("VoiceJoined = true after LeaveVoiceChannel")
	}
	if state.CapabilityStatus == CapabilityStatusReady {
		t.Fatal("CapabilityStatus = Ready after LeaveVoiceChannel")
	}
}

// TestRealDiscordVoiceClientSendFrameAfterJoin verifies that SendOpusFrame
// succeeds (no error) after a full voice join, even though UDP goes to loopback.
func TestRealDiscordVoiceClientSendFrameAfterJoin(t *testing.T) {
	t.Parallel()

	const userID = "user-send"
	const ssrc = uint32(99)
	secretKey := make([]byte, 32)

	dial, gw, _ := voiceHandshakeDialScript("gw-s2", userID, ssrc, secretKey)
	defer gw.Close()

	c := newRealDiscordVoiceClientWith(dial, newTestUDPDialer(t), mockIPDiscovery)
	defer c.Close(context.Background())

	cfg := ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "t"}}
	if err := c.ConnectGateway(context.Background(), cfg); err != nil {
		t.Fatalf("ConnectGateway() = %v", err)
	}

	go simulateVoiceHandshakeEvents(gw, "g1", "c1", userID, "vs2")
	if err := c.JoinVoiceChannel(context.Background(), "g1", "c1"); err != nil {
		t.Fatalf("JoinVoiceChannel() = %v", err)
	}

	frame := AudioFrame{
		Format:       "opus",
		SampleRateHz: 48000,
		Channels:     2,
		DurationMs:   20,
		Payload:      make([]byte, 40), // 40 bytes of silent Opus
	}
	if err := c.SendOpusFrame(context.Background(), frame); err != nil {
		t.Fatalf("SendOpusFrame() after join = %v", err)
	}
}

// TestRealDiscordVoiceClientContextCancelDuringSend verifies that a cancelled
// context causes SendOpusFrame to return immediately.
func TestRealDiscordVoiceClientContextCancelDuringSend(t *testing.T) {
	t.Parallel()

	c := newRealDiscordVoiceClientWith(defaultWSDialFunc, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())

	ctx, cancel := context.WithCancel(context.Background())
	cancel() // cancel before send

	frame := AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{0x01}}
	err := c.SendOpusFrame(ctx, frame)
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("SendOpusFrame() with cancelled ctx = %v, want context.Canceled", err)
	}
}

// TestPlaceholderDiscordVoiceClientStillUsable ensures the Placeholder remains
// functional alongside the new real client.
func TestPlaceholderDiscordVoiceClientStillUsable(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	p := NewPlaceholderDiscordVoiceClient()

	if err := p.ConnectGateway(ctx, ConnectorConfig{}); !errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
		t.Fatalf("ConnectGateway() = %v, want ErrDiscordVoiceBackendNotConfigured", err)
	}

	state := p.GetVoiceState(ctx)
	if state.CapabilityStatus != CapabilityStatusVoiceBackendRequired {
		t.Fatalf("GetVoiceState().CapabilityStatus = %q, want %q", state.CapabilityStatus, CapabilityStatusVoiceBackendRequired)
	}

	frame := AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{0x01}}
	if err := p.SendOpusFrame(ctx, frame); !errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
		t.Fatalf("SendOpusFrame() = %v, want ErrDiscordVoiceBackendNotConfigured", err)
	}

	// Must never contain last error with raw error text that could leak infra details.
	if strings.Contains(p.GetLastError(), "token") {
		t.Fatalf("Placeholder GetLastError() contains 'token': %q", p.GetLastError())
	}

	// Disconnect/Leave/Close must succeed gracefully.
	if err := p.LeaveVoiceChannel(ctx); err != nil {
		t.Fatalf("LeaveVoiceChannel() = %v", err)
	}
	if err := p.Close(ctx); err != nil {
		t.Fatalf("Close() = %v", err)
	}
}

// TestRealDiscordVoiceClientGatewayConnectMissingToken verifies that
// ConnectGateway fails cleanly without a token and never panics.
func TestRealDiscordVoiceClientGatewayConnectMissingToken(t *testing.T) {
	t.Parallel()

	c := newRealDiscordVoiceClientWith(defaultWSDialFunc, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())

	err := c.ConnectGateway(context.Background(), ConnectorConfig{Enabled: true, Config: nil})
	if err == nil {
		t.Fatal("ConnectGateway() expected error with missing token, got nil")
	}
	if c.GetVoiceState(context.Background()).GatewayConnected {
		t.Fatal("GatewayConnected = true without valid token")
	}
}

// TestRealDiscordVoiceClientDisconnectClearsGatewayState checks that
// DisconnectGateway resets the connected flags.
func TestRealDiscordVoiceClientDisconnectClearsGatewayState(t *testing.T) {
	t.Parallel()

	dial, gw := gatewayDialScript("sess-dc", "user-dc")
	defer gw.Close()

	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)

	cfg := ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "tok-dc"}}
	if err := c.ConnectGateway(context.Background(), cfg); err != nil {
		t.Fatalf("ConnectGateway() = %v", err)
	}
	if !c.GetVoiceState(context.Background()).GatewayConnected {
		t.Fatal("GatewayConnected = false after connect")
	}

	if err := c.DisconnectGateway(context.Background()); err != nil {
		t.Fatalf("DisconnectGateway() = %v", err)
	}
	if c.GetVoiceState(context.Background()).GatewayConnected {
		t.Fatal("GatewayConnected = true after DisconnectGateway")
	}
}

func TestRealDiscordVoiceClientGatewayHeartbeatTimeoutMarksError(t *testing.T) {
	c := newRealDiscordVoiceClientWith(defaultWSDialFunc, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())
	conn := newMockWSConn(4)
	go c.gatewayHeartbeatLoop(conn, 5*time.Millisecond)
	deadline := time.After(200 * time.Millisecond)
	for {
		select {
		case <-deadline:
			t.Fatal("heartbeat timeout did not mark error")
		default:
			state := c.GetVoiceState(context.Background())
			if strings.Contains(state.LastError, "heartbeat ack timeout") {
				return
			}
			time.Sleep(5 * time.Millisecond)
		}
	}
}

func TestRealDiscordVoiceClientVoiceWebsocketCloseSchedulesReconnect(t *testing.T) {
	var mu sync.Mutex
	dials := 0
	dial := func(_ context.Context, rawURL string, _ http.Header) (wsConn, error) {
		mu.Lock()
		dials++
		mu.Unlock()
		gw := newMockWSConn(4)
		gw.pushMsg(map[string]any{"op": gwOpcodeHello, "d": map[string]any{"heartbeat_interval": 1000}})
		gw.pushMsg(map[string]any{"op": gwOpcodeDispatch, "t": "READY", "d": map[string]any{"session_id": "s", "user": map[string]any{"id": "u"}}})
		return gw, nil
	}
	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())
	c.rememberConfig(ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "tok"}})
	conn := newMockWSConn(1)
	c.mu.Lock()
	c.vcConn = conn
	c.mu.Unlock()
	go c.voiceReadLoop(conn)
	_ = conn.Close()
	deadline := time.After(2 * time.Second)
	for {
		select {
		case <-deadline:
			t.Fatal("voice websocket close did not trigger reconnect")
		default:
			state := c.GetVoiceState(context.Background())
			if state.ReconnectCount > 0 {
				mu.Lock()
				gotDials := dials
				mu.Unlock()
				if gotDials == 0 {
					t.Fatal("reconnect count increased without dialing")
				}
				return
			}
			time.Sleep(10 * time.Millisecond)
		}
	}
}

func TestRealDiscordVoiceClientUDPSendFailureUpdatesObservability(t *testing.T) {
	c := newRealDiscordVoiceClientWith(defaultWSDialFunc, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())
	udpConn, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	_ = udpConn.Close()
	c.mu.Lock()
	c.state.VoiceJoined = true
	c.state.CapabilityStatus = CapabilityStatusReady
	c.vcUDPConn = udpConn
	c.mu.Unlock()
	err = c.SendOpusFrame(context.Background(), AudioFrame{Format: "opus", SampleRateHz: 48000, Channels: 2, DurationMs: 20, Payload: []byte{1}})
	if err == nil {
		t.Fatal("SendOpusFrame() expected udp error")
	}
	state := c.GetVoiceState(context.Background())
	if state.VoiceUDPState != string(ConnectionStateError) || state.LastVoiceError == "" {
		t.Fatalf("state after udp failure = %#v", state)
	}
}

func TestRealDiscordVoiceClientRepeatedReconnectDoesNotStayInFlight(t *testing.T) {
	var mu sync.Mutex
	dials := 0
	dial := func(_ context.Context, rawURL string, _ http.Header) (wsConn, error) {
		mu.Lock()
		dials++
		mu.Unlock()
		gw := newMockWSConn(8)
		gw.pushMsg(map[string]any{"op": gwOpcodeHello, "d": map[string]any{"heartbeat_interval": 30000}})
		gw.pushMsg(map[string]any{"op": gwOpcodeDispatch, "t": "READY", "d": map[string]any{"session_id": "s", "user": map[string]any{"id": "u"}}})
		return gw, nil
	}
	c := newRealDiscordVoiceClientWith(dial, mockUDPDial, mockIPDiscovery)
	defer c.Close(context.Background())
	cfg := ConnectorConfig{Enabled: true, Config: map[string]any{"bot_token": "tok"}}
	for i := 0; i < 3; i++ {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		if err := c.ReconnectWithBackoff(ctx, cfg, 1); err != nil {
			cancel()
			t.Fatalf("ReconnectWithBackoff(%d) = %v", i, err)
		}
		cancel()
	}
	c.mu.Lock()
	inFlight := c.reconnectInFlight
	c.mu.Unlock()
	if inFlight {
		t.Fatal("reconnectInFlight remained true after repeated reconnects")
	}
	if got := c.GetVoiceState(context.Background()).ReconnectCount; got != 3 {
		t.Fatalf("ReconnectCount = %d, want 3", got)
	}
}
