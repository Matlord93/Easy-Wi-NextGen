package musicbotruntime

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"golang.org/x/crypto/nacl/secretbox"
)

// Discord Gateway URL and API version.
const discordGatewayURL = "wss://gateway.discord.gg/?v=10&encoding=json"

const (
	discordReconnectMaxRetries     = 5
	discordReconnectInitialBackoff = 250 * time.Millisecond
	discordReconnectMaxBackoff     = 5 * time.Second
	discordIdentifyMinInterval     = 100 * time.Millisecond
	discordVoiceStateMinInterval   = 100 * time.Millisecond
)

// Gateway opcodes.
const (
	gwOpcodeDispatch         = 0
	gwOpcodeHeartbeat        = 1
	gwOpcodeIdentify         = 2
	gwOpcodeVoiceStateUpdate = 4
	gwOpcodeReconnect        = 7
	gwOpcodeInvalidSession   = 9
	gwOpcodeHello            = 10
	gwOpcodeHeartbeatACK     = 11
)

// Voice WebSocket opcodes.
const (
	vcOpcodeIdentify           = 0
	vcOpcodeSelectProtocol     = 1
	vcOpcodeReady              = 2
	vcOpcodeHeartbeat          = 3
	vcOpcodeSessionDescription = 4
	vcOpcodeSpeaking           = 5
	vcOpcodeHeartbeatACK       = 6
	vcOpcodeHello              = 8
)

// Samples per millisecond at 48 kHz (Discord standard).
const opusSamplesPerMs = 48

// rtpPayloadTypeOpus is the RTP payload type Discord uses for Opus.
const rtpPayloadTypeOpus = 0x78

type gwMessage struct {
	Op   int             `json:"op"`
	Data json.RawMessage `json:"d"`
	Seq  *int64          `json:"s,omitempty"`
	Type string          `json:"t,omitempty"`
}

type vcMessage struct {
	Op   int             `json:"op"`
	Data json.RawMessage `json:"d"`
}

type voiceServerUpdateData struct {
	Token    string `json:"token"`
	GuildID  string `json:"guild_id"`
	Endpoint string `json:"endpoint"`
}

type voiceStateUpdateData struct {
	GuildID   string `json:"guild_id"`
	ChannelID string `json:"channel_id"`
	UserID    string `json:"user_id"`
	SessionID string `json:"session_id"`
}

// wsConn is the minimal WebSocket interface used by the client.
// *websocket.Conn satisfies this interface; tests inject fakes.
type wsConn interface {
	ReadJSON(v any) error
	WriteJSON(v any) error
	Close() error
}

// wsDialFunc establishes a WebSocket connection. The default uses gorilla/websocket.
// Tests inject a fake to avoid real network calls.
type wsDialFunc func(ctx context.Context, rawURL string, header http.Header) (wsConn, error)

func defaultWSDialFunc(ctx context.Context, rawURL string, header http.Header) (wsConn, error) {
	d := websocket.Dialer{HandshakeTimeout: 15 * time.Second}
	conn, _, err := d.DialContext(ctx, rawURL, header)
	return conn, err
}

// udpDialFunc opens a connected UDP socket to the voice server.
type udpDialFunc func(serverAddr *net.UDPAddr) (*net.UDPConn, error)

func defaultUDPDialFunc(serverAddr *net.UDPAddr) (*net.UDPConn, error) {
	return net.DialUDP("udp", nil, serverAddr)
}

// ipDiscoveryFunc discovers the external IP/port via Discord's UDP IP-discovery packet.
type ipDiscoveryFunc func(conn *net.UDPConn, ssrc uint32) (ip string, port int, err error)

func defaultIPDiscovery(conn *net.UDPConn, ssrc uint32) (string, int, error) {
	// 74-byte request: type(2) + length(2) + ssrc(4) + address(64) + port(2)
	pkt := make([]byte, 74)
	binary.BigEndian.PutUint16(pkt[0:2], 1)  // type: request
	binary.BigEndian.PutUint16(pkt[2:4], 70) // length
	binary.BigEndian.PutUint32(pkt[4:8], ssrc)

	_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
	if _, err := conn.Write(pkt); err != nil {
		_ = conn.SetDeadline(time.Time{})
		return "", 0, fmt.Errorf("ip discovery write: %w", err)
	}
	resp := make([]byte, 74)
	n, err := conn.Read(resp)
	_ = conn.SetDeadline(time.Time{})
	if err != nil {
		return "", 0, fmt.Errorf("ip discovery read: %w", err)
	}
	if n < 74 {
		return "", 0, fmt.Errorf("ip discovery short response: %d bytes", n)
	}
	// Response: type(2)=2 + length(2) + ssrc(4) + address(64, null-terminated) + port(2)
	ip := strings.TrimRight(string(resp[8:72]), "\x00")
	port := int(binary.BigEndian.Uint16(resp[72:74]))
	return ip, port, nil
}

// RealDiscordVoiceClient implements DiscordVoiceClient using the Discord Gateway
// and Voice WebSocket protocols with UDP XSalsa20-Poly1305 voice transport.
//
// The bot token is never logged or included in any exported state.
// All errors that propagate through network calls are masked via maskSensitiveError.
type RealDiscordVoiceClient struct {
	mu        sync.Mutex
	state     DiscordVoiceState
	lastError string // always token-masked

	// Gateway WS state
	gwConn      wsConn
	gwSessionID string
	gwSeq       int64
	gwUserID    string

	// Voice WS + UDP state
	vcConn      wsConn
	vcSSRC      uint32
	vcSecretKey [32]byte
	vcUDPConn   *net.UDPConn
	vcSequence  uint16
	vcTimestamp uint32

	// Pending voice-join handshake signals; non-nil only during JoinVoiceChannel.
	voiceServerCh chan voiceServerUpdateData
	voiceStateCh  chan voiceStateUpdateData

	// Lifecycle context — cancelled only by Close.
	ctx    context.Context
	cancel context.CancelFunc

	lastIdentifyAt         time.Time
	lastVoiceStateUpdateAt time.Time
	reconnectInFlight      bool
	lastConfig             ConnectorConfig
	lastVoiceSessionID     string
	lastVoiceServer        voiceServerUpdateData
	gwHeartbeatAwaitingACK bool
	vcHeartbeatAwaitingACK bool

	// Swappable for testing.
	dial        wsDialFunc
	udpDial     udpDialFunc
	ipDiscovery ipDiscoveryFunc
}

// NewRealDiscordVoiceClient creates a Discord voice client backed by real
// Discord APIs. Provide ConnectorConfig with bot_token, application_id,
// guild_id, and voice_channel_id.
func NewRealDiscordVoiceClient() *RealDiscordVoiceClient {
	return newRealDiscordVoiceClientWith(defaultWSDialFunc, defaultUDPDialFunc, defaultIPDiscovery)
}

func newRealDiscordVoiceClientWith(dial wsDialFunc, udpDial udpDialFunc, ipDisc ipDiscoveryFunc) *RealDiscordVoiceClient {
	ctx, cancel := context.WithCancel(context.Background())
	return &RealDiscordVoiceClient{
		dial:        dial,
		udpDial:     udpDial,
		ipDiscovery: ipDisc,
		ctx:         ctx,
		cancel:      cancel,
		state:       DiscordVoiceState{CapabilityStatus: CapabilityStatusVoiceBackendRequired, VoiceGatewayState: string(ConnectionStateDisconnected), VoiceUDPState: string(ConnectionStateDisconnected)},
	}
}

// OutputName satisfies AudioOutputName; the connector surfaces this as "discord".
func (c *RealDiscordVoiceClient) OutputName() string { return "discord" }

// ConnectGateway authenticates the bot against the Discord Gateway and starts
// the background heartbeat and event-dispatch loops.
func (c *RealDiscordVoiceClient) ConnectGateway(ctx context.Context, config ConnectorConfig) error {
	token := discordConfigString(config, "bot_token")
	c.rememberConfig(config)
	if token == "" {
		return errors.New("discord bot_token is required")
	}

	if err := c.waitIdentifyRateLimit(ctx); err != nil {
		return err
	}

	conn, err := c.dial(ctx, discordGatewayURL, http.Header{})
	if err != nil {
		c.setMaskedError(fmt.Sprintf("gateway dial: %v", err), config.Config)
		return errors.New(c.GetLastError())
	}

	// Receive Hello (op 10).
	hbInterval, err := c.readGatewayHello(conn)
	if err != nil {
		_ = conn.Close()
		c.setMaskedError(fmt.Sprintf("gateway hello: %v", err), config.Config)
		return errors.New(c.GetLastError())
	}

	// Send Identify (op 2).
	identify := map[string]any{
		"op": gwOpcodeIdentify,
		"d": map[string]any{
			"token":   token,
			"intents": 0,
			"properties": map[string]any{
				"os": "linux", "browser": "easywi-musicbot", "device": "easywi-musicbot",
			},
		},
	}
	if err := conn.WriteJSON(identify); err != nil {
		_ = conn.Close()
		c.setMaskedError("gateway identify", config.Config)
		return errors.New(c.GetLastError())
	}

	// Wait for READY dispatch, skipping heartbeat ACKs.
	sessionID, resumeURL, userID, err := c.readGatewayReady(conn)
	if err != nil {
		_ = conn.Close()
		c.setMaskedError(fmt.Sprintf("gateway ready: %v", err), config.Config)
		return errors.New(c.GetLastError())
	}

	c.mu.Lock()
	c.gwConn = conn
	c.gwSessionID = sessionID
	c.gwUserID = userID
	_ = resumeURL // reserved for future resume support
	c.state.GatewayConnected = true
	c.state.VoiceGatewayState = string(ConnectionStateConnected)
	c.state.CapabilityStatus = CapabilityStatusPlaceholder
	c.state.LastError = ""
	c.state.LastVoiceError = ""
	c.mu.Unlock()

	go c.gatewayHeartbeatLoop(conn, hbInterval)
	go c.gatewayReadLoop(conn, config.Config)

	return nil
}

func (c *RealDiscordVoiceClient) readGatewayHello(conn wsConn) (time.Duration, error) {
	var msg gwMessage
	if err := conn.ReadJSON(&msg); err != nil {
		return 0, err
	}
	if msg.Op != gwOpcodeHello {
		return 0, fmt.Errorf("expected hello (op 10), got op %d", msg.Op)
	}
	var d struct {
		HeartbeatInterval int `json:"heartbeat_interval"`
	}
	if err := json.Unmarshal(msg.Data, &d); err != nil {
		return 0, fmt.Errorf("parse hello: %w", err)
	}
	if d.HeartbeatInterval <= 0 {
		d.HeartbeatInterval = 5000
	}
	return time.Duration(d.HeartbeatInterval) * time.Millisecond, nil
}

func (c *RealDiscordVoiceClient) readGatewayReady(conn wsConn) (sessionID, resumeURL, userID string, err error) {
	for {
		var msg gwMessage
		if readErr := conn.ReadJSON(&msg); readErr != nil {
			return "", "", "", readErr
		}
		if msg.Op == gwOpcodeHeartbeatACK {
			continue
		}
		if msg.Op != gwOpcodeDispatch || msg.Type != "READY" {
			return "", "", "", fmt.Errorf("expected READY dispatch, got op=%d type=%q", msg.Op, msg.Type)
		}
		var d struct {
			SessionID     string `json:"session_id"`
			ResumeGateway string `json:"resume_gateway_url"`
			User          struct {
				ID string `json:"id"`
			} `json:"user"`
		}
		if parseErr := json.Unmarshal(msg.Data, &d); parseErr != nil {
			return "", "", "", fmt.Errorf("parse ready: %w", parseErr)
		}
		return d.SessionID, d.ResumeGateway, d.User.ID, nil
	}
}

// DisconnectGateway leaves any joined voice channel, closes the gateway
// connection, and resets state to disconnected.
func (c *RealDiscordVoiceClient) DisconnectGateway(ctx context.Context) error {
	_ = c.LeaveVoiceChannel(ctx)

	c.mu.Lock()
	conn := c.gwConn
	c.gwConn = nil
	c.state.GatewayConnected = false
	c.state.VoiceGatewayState = string(ConnectionStateDisconnected)
	if c.state.CapabilityStatus != CapabilityStatusError {
		c.state.CapabilityStatus = CapabilityStatusVoiceBackendRequired
	}
	c.mu.Unlock()

	if conn != nil {
		_ = conn.Close()
	}
	return nil
}

// JoinVoiceChannel sends a Voice State Update to the gateway and completes the
// full voice WebSocket + UDP handshake before returning.
func (c *RealDiscordVoiceClient) JoinVoiceChannel(ctx context.Context, guildID, channelID string) error {
	c.mu.Lock()
	if !c.state.GatewayConnected || c.gwConn == nil {
		c.mu.Unlock()
		return errors.New("gateway not connected; call ConnectGateway first")
	}
	gwConn := c.gwConn
	userID := c.gwUserID

	serverCh := make(chan voiceServerUpdateData, 1)
	stateCh := make(chan voiceStateUpdateData, 1)
	c.voiceServerCh = serverCh
	c.voiceStateCh = stateCh
	c.mu.Unlock()

	defer func() {
		c.mu.Lock()
		c.voiceServerCh = nil
		c.voiceStateCh = nil
		c.mu.Unlock()
	}()

	vsu := map[string]any{
		"op": gwOpcodeVoiceStateUpdate,
		"d": map[string]any{
			"guild_id":   guildID,
			"channel_id": channelID,
			"self_mute":  false,
			"self_deaf":  false,
		},
	}
	if err := c.throttleVoiceStateUpdate(ctx); err != nil {
		return err
	}
	if err := gwConn.WriteJSON(vsu); err != nil {
		return fmt.Errorf("voice state update: %w", err)
	}

	// Collect both VOICE_STATE_UPDATE (for our user) and VOICE_SERVER_UPDATE.
	var serverInfo voiceServerUpdateData
	var voiceSessionID string
	gotServer, gotState := false, false
	timeout := time.After(15 * time.Second)
	for !gotServer || !gotState {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-timeout:
			return errors.New("timeout waiting for voice handshake from Discord gateway")
		case s := <-serverCh:
			serverInfo = s
			gotServer = true
		case s := <-stateCh:
			if s.UserID == userID {
				voiceSessionID = s.SessionID
				gotState = true
			}
		}
	}

	if err := c.connectVoiceWS(ctx, serverInfo, voiceSessionID); err != nil {
		return err
	}

	c.mu.Lock()
	c.lastVoiceSessionID = voiceSessionID
	c.lastVoiceServer = serverInfo
	c.state.VoiceJoined = true
	c.state.GuildID = guildID
	c.state.ChannelID = channelID
	c.state.CapabilityStatus = CapabilityStatusReady
	c.state.LastError = ""
	c.mu.Unlock()

	return nil
}

func (c *RealDiscordVoiceClient) connectVoiceWS(ctx context.Context, server voiceServerUpdateData, voiceSessionID string) error {
	endpoint := server.Endpoint
	// Strip trailing port Discord sometimes includes (e.g. "eu-central.discord.media:443").
	if i := strings.LastIndex(endpoint, ":"); i > strings.Index(endpoint, ".") {
		endpoint = endpoint[:i]
	}
	if !strings.HasPrefix(endpoint, "wss://") {
		endpoint = "wss://" + endpoint
	}
	endpoint += "?v=4"

	conn, err := c.dial(ctx, endpoint, nil)
	if err != nil {
		return fmt.Errorf("voice ws dial: %w", err)
	}

	// Receive Hello (op 8).
	var helloMsg vcMessage
	if err := conn.ReadJSON(&helloMsg); err != nil {
		_ = conn.Close()
		return fmt.Errorf("voice ws hello: %w", err)
	}
	if helloMsg.Op != vcOpcodeHello {
		_ = conn.Close()
		return fmt.Errorf("expected voice hello (op 8), got op %d", helloMsg.Op)
	}
	var helloData struct {
		HeartbeatInterval float64 `json:"heartbeat_interval"`
	}
	_ = json.Unmarshal(helloMsg.Data, &helloData)
	hbInterval := time.Duration(helloData.HeartbeatInterval) * time.Millisecond
	if hbInterval <= 0 {
		hbInterval = 5 * time.Second
	}

	c.mu.Lock()
	gwUserID := c.gwUserID
	c.mu.Unlock()

	// Send Identify (op 0).
	identify := map[string]any{
		"op": vcOpcodeIdentify,
		"d": map[string]any{
			"server_id":  server.GuildID,
			"user_id":    gwUserID,
			"session_id": voiceSessionID,
			"token":      server.Token, // voice token — short-lived, not the bot token
		},
	}
	if err := conn.WriteJSON(identify); err != nil {
		_ = conn.Close()
		return fmt.Errorf("voice ws identify: %w", err)
	}

	// Receive Ready (op 2).
	var readyMsg vcMessage
	if err := conn.ReadJSON(&readyMsg); err != nil {
		_ = conn.Close()
		return fmt.Errorf("voice ws ready: %w", err)
	}
	if readyMsg.Op != vcOpcodeReady {
		_ = conn.Close()
		return fmt.Errorf("expected voice ready (op 2), got op %d", readyMsg.Op)
	}
	var readyData struct {
		SSRC uint32 `json:"ssrc"`
		IP   string `json:"ip"`
		Port int    `json:"port"`
	}
	if err := json.Unmarshal(readyMsg.Data, &readyData); err != nil {
		_ = conn.Close()
		return fmt.Errorf("parse voice ready: %w", err)
	}

	// Open UDP socket and perform IP discovery.
	serverAddr := &net.UDPAddr{IP: net.ParseIP(readyData.IP), Port: readyData.Port}
	udpConn, err := c.udpDial(serverAddr)
	if err != nil {
		_ = conn.Close()
		return fmt.Errorf("voice udp dial: %w", err)
	}
	myIP, myPort, err := c.ipDiscovery(udpConn, readyData.SSRC)
	if err != nil {
		_ = udpConn.Close()
		_ = conn.Close()
		return fmt.Errorf("voice ip discovery: %w", err)
	}

	// Send Select Protocol (op 1).
	selectProto := map[string]any{
		"op": vcOpcodeSelectProtocol,
		"d": map[string]any{
			"protocol": "udp",
			"data": map[string]any{
				"address": myIP,
				"port":    myPort,
				"mode":    "xsalsa20_poly1305",
			},
		},
	}
	if err := conn.WriteJSON(selectProto); err != nil {
		_ = udpConn.Close()
		_ = conn.Close()
		return fmt.Errorf("voice select protocol: %w", err)
	}

	// Receive Session Description (op 4).
	var sdMsg vcMessage
	if err := conn.ReadJSON(&sdMsg); err != nil {
		_ = udpConn.Close()
		_ = conn.Close()
		return fmt.Errorf("voice session description: %w", err)
	}
	if sdMsg.Op != vcOpcodeSessionDescription {
		_ = udpConn.Close()
		_ = conn.Close()
		return fmt.Errorf("expected session description (op 4), got op %d", sdMsg.Op)
	}
	var sdData struct {
		SecretKey []byte `json:"secret_key"`
	}
	if err := json.Unmarshal(sdMsg.Data, &sdData); err != nil {
		_ = udpConn.Close()
		_ = conn.Close()
		return fmt.Errorf("parse session description: %w", err)
	}
	if len(sdData.SecretKey) != 32 {
		_ = udpConn.Close()
		_ = conn.Close()
		return fmt.Errorf("invalid secret key length: %d (want 32)", len(sdData.SecretKey))
	}

	c.mu.Lock()
	if c.vcConn != nil {
		_ = c.vcConn.Close()
	}
	if c.vcUDPConn != nil {
		_ = c.vcUDPConn.Close()
	}
	c.vcConn = conn
	c.vcSSRC = readyData.SSRC
	copy(c.vcSecretKey[:], sdData.SecretKey)
	c.vcUDPConn = udpConn
	c.state.VoiceGatewayState = string(ConnectionStateConnected)
	c.state.VoiceUDPState = string(ConnectionStateConnected)
	c.state.LastVoiceError = ""
	c.vcSequence = 0
	c.vcTimestamp = 0
	c.mu.Unlock()

	go c.voiceHeartbeatLoop(conn, hbInterval)
	go c.voiceReadLoop(conn)

	return nil
}

// LeaveVoiceChannel sends a leave signal to Discord and tears down the voice
// WebSocket and UDP connection.
func (c *RealDiscordVoiceClient) LeaveVoiceChannel(ctx context.Context) error {
	c.mu.Lock()
	gwConn := c.gwConn
	guildID := c.state.GuildID
	vcConn := c.vcConn
	udpConn := c.vcUDPConn
	c.vcConn = nil
	c.vcUDPConn = nil
	c.lastVoiceSessionID = ""
	c.lastVoiceServer = voiceServerUpdateData{}
	c.state.VoiceJoined = false
	c.state.ChannelID = ""
	c.state.VoiceGatewayState = string(ConnectionStateDisconnected)
	c.state.VoiceUDPState = string(ConnectionStateDisconnected)
	if c.state.CapabilityStatus == CapabilityStatusReady {
		c.state.CapabilityStatus = CapabilityStatusPlaceholder
	}
	c.mu.Unlock()

	if udpConn != nil {
		_ = udpConn.Close()
	}
	if vcConn != nil {
		_ = vcConn.Close()
	}

	// Notify Discord gateway that we left.
	if gwConn != nil && guildID != "" {
		_ = c.throttleVoiceStateUpdate(ctx)
		_ = gwConn.WriteJSON(map[string]any{
			"op": gwOpcodeVoiceStateUpdate,
			"d": map[string]any{
				"guild_id":   guildID,
				"channel_id": nil,
				"self_mute":  false,
				"self_deaf":  false,
			},
		})
	}
	return nil
}

// SendOpusFrame encrypts an Opus frame with XSalsa20-Poly1305 and delivers it
// via UDP to the Discord voice server. Returns an error if the voice channel is
// not joined or the context is cancelled.
func (c *RealDiscordVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	select {
	case <-ctx.Done():
		return ctx.Err()
	default:
	}

	c.mu.Lock()
	if !c.state.VoiceJoined || c.vcUDPConn == nil {
		c.mu.Unlock()
		return errors.New("voice channel not joined; call JoinVoiceChannel first")
	}
	ssrc := c.vcSSRC
	seq := c.vcSequence
	ts := c.vcTimestamp
	secretKey := c.vcSecretKey
	udpConn := c.vcUDPConn

	c.vcSequence++
	c.vcTimestamp += uint32(frame.DurationMs * opusSamplesPerMs)
	c.mu.Unlock()

	// Build 12-byte RTP header.
	var header [12]byte
	header[0] = 0x80                               // V=2, P=0, X=0, CC=0
	header[1] = rtpPayloadTypeOpus                 // M=0, PT=120
	binary.BigEndian.PutUint16(header[2:4], seq)   // Sequence
	binary.BigEndian.PutUint32(header[4:8], ts)    // Timestamp
	binary.BigEndian.PutUint32(header[8:12], ssrc) // SSRC

	// 24-byte nonce: first 12 bytes = RTP header, remaining 12 = zero.
	var nonce [24]byte
	copy(nonce[:], header[:])

	payload := frame.Payload
	if len(payload) == 0 {
		payload = frame.PCM
	}

	// Encrypt: secretbox.Seal appends (MAC + ciphertext) to header bytes.
	packet := secretbox.Seal(header[:], payload, &nonce, &secretKey)

	_ = udpConn.SetWriteDeadline(time.Now().Add(50 * time.Millisecond))
	_, err := udpConn.Write(packet)
	_ = udpConn.SetWriteDeadline(time.Time{})
	if err != nil {
		c.setError(fmt.Errorf("send voice frame: %w", err))
		c.markVoiceTransportLost("udp send failed")
		return err
	}
	return nil
}

// GetVoiceState returns a copy of the current voice state (never contains the bot token).
func (c *RealDiscordVoiceClient) GetVoiceState(_ context.Context) DiscordVoiceState {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.state
}

// GetLastError returns the last masked error string (token is never included).
func (c *RealDiscordVoiceClient) GetLastError() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.lastError
}

// Reconnect disconnects and reconnects, rejoining the voice channel if
// guild_id and voice_channel_id are present in config.
func (c *RealDiscordVoiceClient) Reconnect(ctx context.Context, config ConnectorConfig) error {
	return c.ReconnectWithBackoff(ctx, config, discordReconnectMaxRetries)
}

func (c *RealDiscordVoiceClient) ReconnectWithBackoff(ctx context.Context, config ConnectorConfig, maxRetries int) error {
	guildID := discordConfigString(config, "guild_id")
	channelID := discordConfigString(config, "voice_channel_id")

	var lastErr error
	backoff := discordReconnectInitialBackoff
	if maxRetries <= 0 {
		maxRetries = 1
	}
	for attempt := 0; attempt < maxRetries; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(backoff):
			}
			backoff *= 2
			if backoff > discordReconnectMaxBackoff {
				backoff = discordReconnectMaxBackoff
			}
		}

		c.noteReconnectAttempt()
		if err := c.DisconnectGateway(ctx); err != nil {
			lastErr = err
			continue
		}

		newCtx, newCancel := context.WithCancel(context.Background())
		c.mu.Lock()
		oldCancel := c.cancel
		c.ctx = newCtx
		c.cancel = newCancel
		c.mu.Unlock()
		oldCancel()

		if err := c.ConnectGateway(ctx, config); err != nil {
			lastErr = err
			continue
		}
		if guildID != "" && channelID != "" {
			if err := c.JoinVoiceChannel(ctx, guildID, channelID); err != nil {
				lastErr = err
				continue
			}
		}
		c.clearReconnectInFlight()
		return nil
	}
	if lastErr == nil {
		lastErr = errors.New("discord reconnect failed")
	}
	c.clearStateForFatalError(lastErr)
	c.clearReconnectInFlight()
	return lastErr
}

// Close cancels all background goroutines and disconnects gateway + voice.
func (c *RealDiscordVoiceClient) Close(ctx context.Context) error {
	c.mu.Lock()
	cancel := c.cancel
	c.mu.Unlock()
	cancel()
	return c.DisconnectGateway(ctx)
}

// gatewayHeartbeatLoop sends periodic heartbeats until the connection fails or
// the client context is cancelled.
func (c *RealDiscordVoiceClient) gatewayHeartbeatLoop(conn wsConn, interval time.Duration) {
	if interval <= 0 {
		interval = 5 * time.Second
	}
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-c.ctx.Done():
			return
		case <-ticker.C:
		}
		c.mu.Lock()
		seq := c.gwSeq
		awaiting := c.gwHeartbeatAwaitingACK
		lastHB := parseRFC3339Time(c.state.LastHeartbeatAt)
		if awaiting && time.Since(lastHB) > interval+interval/2 {
			c.mu.Unlock()
			c.setError(errors.New("gateway heartbeat ack timeout"))
			_ = conn.Close()
			c.scheduleReconnect("gateway heartbeat ack timeout")
			return
		}
		c.gwHeartbeatAwaitingACK = true
		now := time.Now().UTC().Format(time.RFC3339)
		c.state.LastHeartbeatAt = now
		c.mu.Unlock()
		if err := conn.WriteJSON(map[string]any{"op": gwOpcodeHeartbeat, "d": seq}); err != nil {
			c.setError(fmt.Errorf("gateway heartbeat write: %w", err))
			c.scheduleReconnect("gateway heartbeat write failed")
			return
		}
	}
}

// voiceHeartbeatLoop sends periodic heartbeats on the voice WebSocket.
func (c *RealDiscordVoiceClient) voiceHeartbeatLoop(conn wsConn, interval time.Duration) {
	if interval <= 0 {
		interval = 5 * time.Second
	}
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	nonce := uint64(0)
	for {
		select {
		case <-c.ctx.Done():
			return
		case <-ticker.C:
		}
		c.mu.Lock()
		awaiting := c.vcHeartbeatAwaitingACK
		lastHB := parseRFC3339Time(c.state.LastHeartbeatAt)
		if awaiting && time.Since(lastHB) > interval+interval/2 {
			c.mu.Unlock()
			c.setError(errors.New("voice heartbeat ack timeout"))
			c.markVoiceTransportLost("voice heartbeat ack timeout")
			_ = conn.Close()
			c.scheduleReconnect("voice heartbeat ack timeout")
			return
		}
		c.vcHeartbeatAwaitingACK = true
		now := time.Now().UTC().Format(time.RFC3339)
		c.state.LastHeartbeatAt = now
		c.mu.Unlock()
		nonce++
		if err := conn.WriteJSON(map[string]any{"op": vcOpcodeHeartbeat, "d": nonce}); err != nil {
			c.setError(fmt.Errorf("voice heartbeat write: %w", err))
			c.markVoiceTransportLost("voice heartbeat write failed")
			c.scheduleReconnect("voice heartbeat write failed")
			return
		}
	}
}

// gatewayReadLoop dispatches incoming gateway events; it runs for the lifetime
// of the gateway connection.
func (c *RealDiscordVoiceClient) gatewayReadLoop(conn wsConn, cfgMap map[string]any) {
	for {
		var msg gwMessage
		if err := conn.ReadJSON(&msg); err != nil {
			select {
			case <-c.ctx.Done():
				return
			default:
			}
			c.mu.Lock()
			current := c.gwConn == conn
			c.mu.Unlock()
			if !current {
				return
			}
			c.setMaskedError(fmt.Sprintf("gateway read: %v", err), cfgMap)
			c.scheduleReconnect("gateway read failed")
			return
		}

		if msg.Seq != nil {
			c.mu.Lock()
			c.gwSeq = *msg.Seq
			c.mu.Unlock()
		}

		switch msg.Op {
		case gwOpcodeDispatch:
			c.handleGatewayDispatch(msg)
		case gwOpcodeHeartbeatACK:
			c.noteHeartbeatACK(true)
		case gwOpcodeReconnect:
			c.setError(errors.New("gateway requested reconnect"))
			c.scheduleReconnect("gateway requested reconnect")
			return
		case gwOpcodeInvalidSession:
			c.setError(errors.New("gateway: invalid session"))
			c.scheduleReconnect("gateway invalid session")
			return
		}
	}
}

func (c *RealDiscordVoiceClient) voiceReadLoop(conn wsConn) {
	for {
		var msg vcMessage
		if err := conn.ReadJSON(&msg); err != nil {
			select {
			case <-c.ctx.Done():
				return
			default:
			}
			c.mu.Lock()
			current := c.vcConn == conn
			c.mu.Unlock()
			if !current {
				return
			}
			c.setError(fmt.Errorf("voice ws read: %w", err))
			c.markVoiceTransportLost("voice websocket closed")
			c.scheduleReconnect("voice websocket closed")
			return
		}
		if msg.Op == vcOpcodeHeartbeatACK {
			c.noteHeartbeatACK(false)
		}
	}
}

func (c *RealDiscordVoiceClient) noteHeartbeatACK(gateway bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	now := time.Now().UTC().Format(time.RFC3339)
	c.state.LastHeartbeatAckAt = now
	if gateway {
		c.gwHeartbeatAwaitingACK = false
	} else {
		c.vcHeartbeatAwaitingACK = false
	}
}

func (c *RealDiscordVoiceClient) scheduleReconnect(reason string) {
	c.mu.Lock()
	if c.reconnectInFlight {
		c.mu.Unlock()
		return
	}
	cfg := c.lastConfig
	if discordConfigString(cfg, "bot_token") == "" {
		c.mu.Unlock()
		return
	}
	c.reconnectInFlight = true
	c.mu.Unlock()
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		_ = c.ReconnectWithBackoff(ctx, cfg, discordReconnectMaxRetries)
	}()
}

func (c *RealDiscordVoiceClient) clearReconnectInFlight() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.reconnectInFlight = false
}

func (c *RealDiscordVoiceClient) noteReconnectAttempt() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.state.ReconnectCount++
	c.state.LastReconnectAt = time.Now().UTC().Format(time.RFC3339)
}

func (c *RealDiscordVoiceClient) rememberConfig(config ConnectorConfig) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.lastConfig = config
}

func (c *RealDiscordVoiceClient) waitIdentifyRateLimit(ctx context.Context) error {
	c.mu.Lock()
	wait := time.Duration(0)
	if !c.lastIdentifyAt.IsZero() {
		elapsed := time.Since(c.lastIdentifyAt)
		if elapsed < discordIdentifyMinInterval {
			wait = discordIdentifyMinInterval - elapsed
		}
	}
	c.mu.Unlock()
	if wait > 0 {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		}
	}
	c.mu.Lock()
	c.lastIdentifyAt = time.Now()
	c.mu.Unlock()
	return nil
}

func (c *RealDiscordVoiceClient) throttleVoiceStateUpdate(ctx context.Context) error {
	c.mu.Lock()
	wait := time.Duration(0)
	if !c.lastVoiceStateUpdateAt.IsZero() {
		elapsed := time.Since(c.lastVoiceStateUpdateAt)
		if elapsed < discordVoiceStateMinInterval {
			wait = discordVoiceStateMinInterval - elapsed
		}
	}
	c.mu.Unlock()
	if wait > 0 {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		}
	}
	c.mu.Lock()
	c.lastVoiceStateUpdateAt = time.Now()
	c.mu.Unlock()
	return nil
}

func (c *RealDiscordVoiceClient) markVoiceTransportLost(message string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.state.VoiceGatewayState = string(ConnectionStateError)
	c.state.VoiceUDPState = string(ConnectionStateError)
	c.state.VoiceJoined = false
	c.state.CapabilityStatus = CapabilityStatusError
	c.state.LastVoiceError = message
}

func (c *RealDiscordVoiceClient) clearStateForFatalError(err error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	msg := maskSensitiveError(err.Error(), c.lastConfig.Config)
	c.lastError = msg
	c.state.GatewayConnected = false
	c.state.VoiceJoined = false
	c.state.VoiceGatewayState = string(ConnectionStateError)
	c.state.VoiceUDPState = string(ConnectionStateError)
	c.state.CapabilityStatus = CapabilityStatusError
	c.state.LastError = msg
	c.state.LastVoiceError = msg
	c.state.ChannelID = ""
	c.gwConn = nil
	c.vcConn = nil
	c.vcUDPConn = nil
}

func parseRFC3339Time(value string) time.Time {
	if value == "" {
		return time.Time{}
	}
	parsed, err := time.Parse(time.RFC3339, value)
	if err != nil {
		return time.Time{}
	}
	return parsed
}

func (c *RealDiscordVoiceClient) handleGatewayDispatch(msg gwMessage) {
	c.mu.Lock()
	serverCh := c.voiceServerCh
	stateCh := c.voiceStateCh
	myUserID := c.gwUserID
	c.mu.Unlock()

	switch msg.Type {
	case "VOICE_SERVER_UPDATE":
		if serverCh == nil {
			return
		}
		var d voiceServerUpdateData
		if err := json.Unmarshal(msg.Data, &d); err == nil {
			select {
			case serverCh <- d:
			default:
			}
		}
	case "VOICE_STATE_UPDATE":
		if stateCh == nil {
			return
		}
		var d voiceStateUpdateData
		if err := json.Unmarshal(msg.Data, &d); err == nil && d.UserID == myUserID {
			select {
			case stateCh <- d:
			default:
			}
		}
	}
}

func (c *RealDiscordVoiceClient) setError(err error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	msg := maskSensitiveError(err.Error(), c.lastConfig.Config)
	c.lastError = msg
	c.state.CapabilityStatus = CapabilityStatusError
	c.state.LastError = msg
	c.state.LastVoiceError = msg
}

// setMaskedError records an error after redacting the bot token from the message.
func (c *RealDiscordVoiceClient) setMaskedError(msg string, cfgMap map[string]any) {
	masked := maskSensitiveError(msg, cfgMap)
	c.mu.Lock()
	defer c.mu.Unlock()
	c.lastError = masked
	c.state.CapabilityStatus = CapabilityStatusError
	c.state.LastError = masked
}
