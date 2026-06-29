package main

import (
	"bufio"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"strings"
	"sync"
)

const (
	stateDisconnected = "disconnected"
	stateConnected    = "connected"
	stateError        = "error"
)

// bridgeRequest is the JSON object the runtime sends over stdin.
// Fields not relevant to the current action are ignored.
type bridgeRequest struct {
	Action              string `json:"action"`
	BackendType         string `json:"backend_type,omitempty"`
	BackendPath         string `json:"backend_path,omitempty"`
	Host                string `json:"host,omitempty"`
	Port                int    `json:"port,omitempty"`
	Profile             string `json:"profile,omitempty"`
	Nickname            string `json:"nickname,omitempty"`
	IdentityPath        string `json:"identity_path,omitempty"`
	ServerPassword      string `json:"server_password,omitempty"`
	ChannelID           string `json:"channel_id,omitempty"`
	ChannelPassword     string `json:"channel_password,omitempty"`
	Format              string `json:"format,omitempty"`
	Payload             string `json:"payload,omitempty"`
	DurationMs          int    `json:"duration_ms,omitempty"`
	ClientBinaryPath    string `json:"client_binary_path,omitempty"`
	ClientRunscriptPath string `json:"client_runscript_path,omitempty"`
	AudioBackend        string `json:"audio_backend,omitempty"`
	InstancePath        string `json:"instance_path,omitempty"`
	RuntimeDir          string `json:"runtime_dir,omitempty"`
	ClientQueryHost     string `json:"client_query_host,omitempty"`
	ClientQueryPort     int    `json:"client_query_port,omitempty"`
}

// bridgeResponse is the JSON object the bridge writes to stdout after each request.
type bridgeResponse struct {
	OK                    bool   `json:"ok"`
	Error                 string `json:"error,omitempty"`
	BackendType           string `json:"backend_type,omitempty"`
	Ready                 bool   `json:"ready,omitempty"`
	State                 string `json:"state,omitempty"`
	ClientID              string `json:"client_id,omitempty"`
	ChannelID             string `json:"channel_id,omitempty"`
	Ts3LogPath            string `json:"ts3_log_path,omitempty"`
	CrashdumpPath         string `json:"crashdump_path,omitempty"`
	ClientQueryPort       int    `json:"client_query_port,omitempty"`
	LicenseAcceptRequired bool   `json:"license_accept_required,omitempty"`
}

// bridge is the protocol engine. It owns the adapter, tracks connection state,
// and drives the synchronous stdin→stdout request/response loop.
type bridge struct {
	adapter TeamspeakClientAdapter

	mu              sync.Mutex
	state           string
	clientID        string
	channelID       string
	serverPassword  string // saved for secret masking
	channelPassword string // saved for secret masking

	logger *log.Logger
	out    *json.Encoder
}

func newBridge(adapter TeamspeakClientAdapter, w io.Writer, logger *log.Logger) *bridge {
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	return &bridge{
		adapter: adapter,
		state:   stateDisconnected,
		logger:  logger,
		out:     enc,
	}
}

// run reads newline-delimited JSON from r, dispatches each request to the adapter,
// and writes one JSON response per request to the encoder supplied to newBridge.
// It returns nil on clean EOF (stdin closed by the runtime) and a non-nil error
// only on scanner I/O failure.
func (b *bridge) run(ctx context.Context, r io.Reader) error {
	b.logger.Printf("protocol_ready=true")
	scanner := bufio.NewScanner(r)
	for scanner.Scan() {
		if ctx.Err() != nil {
			return nil
		}
		line := scanner.Bytes()
		var req bridgeRequest
		if err := json.Unmarshal(line, &req); err != nil {
			b.write(bridgeResponse{OK: false, Error: "invalid JSON request"})
			continue
		}
		b.write(b.dispatch(ctx, req))
	}
	return scanner.Err()
}

func (b *bridge) dispatch(ctx context.Context, req bridgeRequest) bridgeResponse {
	switch req.Action {
	case "connect":
		return b.handleConnect(ctx, req)
	case "disconnect", "shutdown":
		return b.handleDisconnect(ctx, req)
	case "reconnect":
		return b.handleReconnect(ctx)
	case "join_channel":
		return b.handleJoinChannel(ctx, req)
	case "leave_channel":
		return b.handleLeaveChannel(ctx)
	case "set_nickname":
		return b.handleSetNickname(ctx, req)
	case "send_opus_frame", "send_audio_frame":
		return b.handleSendAudioFrame(ctx, req)
	case "status":
		return b.handleStatus()
	default:
		return bridgeResponse{OK: false, Error: "unknown action: " + req.Action}
	}
}

func (b *bridge) handleConnect(ctx context.Context, req bridgeRequest) bridgeResponse {
	port := req.Port
	if port <= 0 {
		port = 9987
	}
	params := connectParams{
		BackendType:         normalizeBackendType(req.BackendType),
		BackendPath:         req.BackendPath,
		Host:                req.Host,
		Port:                port,
		Profile:             normalizeProfile(req.Profile),
		Nickname:            req.Nickname,
		IdentityPath:        req.IdentityPath,
		ChannelID:           req.ChannelID,
		ServerPassword:      req.ServerPassword,
		ChannelPassword:     req.ChannelPassword,
		ClientBinaryPath:    req.ClientBinaryPath,
		ClientRunscriptPath: req.ClientRunscriptPath,
		AudioBackend:        req.AudioBackend,
		InstancePath:        req.InstancePath,
		RuntimeDir:          req.RuntimeDir,
		ClientQueryHost:     req.ClientQueryHost,
		ClientQueryPort:     req.ClientQueryPort,
	}

	b.mu.Lock()
	b.serverPassword = req.ServerPassword
	b.channelPassword = ""
	b.mu.Unlock()

	clientID, err := b.adapter.Connect(ctx, params)
	if err != nil {
		b.mu.Lock()
		b.state = stateDisconnected
		b.clientID = ""
		b.mu.Unlock()
		b.logger.Printf("connect to %s:%d failed", params.Host, port)
		return bridgeResponse{OK: false, Error: b.mask(err.Error())}
	}

	b.mu.Lock()
	b.state = stateConnected
	b.clientID = clientID
	b.channelID = ""
	b.mu.Unlock()

	b.logger.Printf("connected to %s:%d profile=%s backend=%s", params.Host, port, params.Profile, params.BackendType)
	return bridgeResponse{OK: true, BackendType: params.BackendType, Ready: true, State: stateConnected, ClientID: clientID}
}

func (b *bridge) handleDisconnect(ctx context.Context, req bridgeRequest) bridgeResponse {
	if req.Action == "shutdown" {
		_ = b.adapter.Shutdown(ctx)
	} else {
		_ = b.adapter.Disconnect(ctx)
	}
	b.mu.Lock()
	b.state = stateDisconnected
	b.clientID = ""
	b.channelID = ""
	b.mu.Unlock()
	b.logger.Printf("disconnected (action=%s)", req.Action)
	return bridgeResponse{OK: true, State: stateDisconnected}
}

func (b *bridge) handleReconnect(ctx context.Context) bridgeResponse {
	clientID, err := b.adapter.Reconnect(ctx)
	if err != nil {
		b.mu.Lock()
		b.state = stateDisconnected
		b.clientID = ""
		b.mu.Unlock()
		b.logger.Printf("reconnect failed")
		return bridgeResponse{OK: false, Error: b.mask(err.Error())}
	}
	b.mu.Lock()
	b.state = stateConnected
	b.clientID = clientID
	b.mu.Unlock()
	b.logger.Printf("reconnected client_id=%s", clientID)
	return bridgeResponse{OK: true, State: stateConnected, ClientID: clientID}
}

func (b *bridge) handleJoinChannel(ctx context.Context, req bridgeRequest) bridgeResponse {
	if strings.TrimSpace(req.ChannelID) == "" {
		return bridgeResponse{OK: false, Error: "channel_id is required"}
	}

	b.mu.Lock()
	b.channelPassword = req.ChannelPassword
	b.mu.Unlock()

	actualID, err := b.adapter.JoinChannel(ctx, req.ChannelID, req.ChannelPassword)
	if err != nil {
		b.logger.Printf("join_channel %s failed", req.ChannelID)
		return bridgeResponse{OK: false, Error: b.mask(err.Error())}
	}
	if actualID == "" {
		actualID = req.ChannelID
	}
	b.mu.Lock()
	b.channelID = actualID
	b.mu.Unlock()
	b.logger.Printf("joined channel %s", actualID)
	return bridgeResponse{OK: true, ChannelID: actualID}
}

func (b *bridge) handleLeaveChannel(ctx context.Context) bridgeResponse {
	if err := b.adapter.LeaveChannel(ctx); err != nil {
		b.logger.Printf("leave_channel failed: %v", err)
		return bridgeResponse{OK: false, Error: err.Error()}
	}
	b.mu.Lock()
	b.channelID = ""
	b.mu.Unlock()
	b.logger.Printf("left channel")
	return bridgeResponse{OK: true}
}

func (b *bridge) handleSetNickname(ctx context.Context, req bridgeRequest) bridgeResponse {
	if strings.TrimSpace(req.Nickname) == "" {
		return bridgeResponse{OK: false, Error: "nickname is required"}
	}
	if err := b.adapter.SetNickname(ctx, req.Nickname); err != nil {
		b.logger.Printf("set_nickname failed: %v", err)
		return bridgeResponse{OK: false, Error: err.Error()}
	}
	b.logger.Printf("nickname updated")
	return bridgeResponse{OK: true}
}

func (b *bridge) handleSendAudioFrame(ctx context.Context, req bridgeRequest) bridgeResponse {
	b.mu.Lock()
	connected := b.state == stateConnected
	b.mu.Unlock()

	if !connected {
		return bridgeResponse{OK: false, Error: "not connected to TeamSpeak server"}
	}
	format := strings.ToLower(strings.TrimSpace(req.Format))
	if req.Action == "send_opus_frame" && format != "opus" {
		return bridgeResponse{OK: false, Error: fmt.Sprintf("unsupported frame format %q, expected opus", req.Format)}
	}
	if format != "opus" && format != "pcm" && format != "pcm_s16le" {
		return bridgeResponse{OK: false, Error: fmt.Sprintf("unsupported frame format %q, expected opus or pcm_s16le", req.Format)}
	}
	if req.Payload == "" {
		return bridgeResponse{OK: false, Error: "payload is required"}
	}
	frame, err := base64.StdEncoding.DecodeString(req.Payload)
	if err != nil {
		return bridgeResponse{OK: false, Error: "payload is not valid base64"}
	}
	durationMs := req.DurationMs
	if durationMs <= 0 {
		durationMs = 20
	}
	if format == "opus" && payloadLooksLikePCM(frame, 48000, 2, durationMs) {
		format = "pcm_s16le"
	}
	if err := b.adapter.SendAudioFrame(ctx, format, frame, durationMs); err != nil {
		return bridgeResponse{OK: false, Error: err.Error()}
	}
	return bridgeResponse{OK: true}
}

func (b *bridge) handleStatus() bridgeResponse {
	status, err := b.adapter.Status(context.Background())
	b.mu.Lock()
	defer b.mu.Unlock()
	if err != nil {
		return bridgeResponse{OK: false, Error: b.mask(err.Error())}
	}
	state := b.state
	if status.State != "" && b.clientID == "" {
		state = status.State
	}
	clientID := b.clientID
	if clientID == "" {
		clientID = status.ClientID
	}
	channelID := b.channelID
	if channelID == "" {
		channelID = status.ChannelID
	}
	return bridgeResponse{
		OK:                    true,
		BackendType:           status.BackendType,
		Ready:                 status.Ready && state == stateConnected,
		State:                 state,
		ClientID:              clientID,
		ChannelID:             channelID,
		Ts3LogPath:            status.Ts3LogPath,
		CrashdumpPath:         status.CrashdumpPath,
		ClientQueryPort:       status.ClientQueryPort,
		LicenseAcceptRequired: status.LicenseAcceptRequired,
	}
}

func (b *bridge) write(resp bridgeResponse) {
	if err := b.out.Encode(resp); err != nil {
		b.logger.Printf("write response error: %v", err)
	}
}

// mask replaces known secret values in msg with [redacted].
func (b *bridge) mask(msg string) string {
	b.mu.Lock()
	sp := b.serverPassword
	cp := b.channelPassword
	b.mu.Unlock()
	out := msg
	if sp != "" {
		out = strings.ReplaceAll(out, sp, "[redacted]")
	}
	if cp != "" {
		out = strings.ReplaceAll(out, cp, "[redacted]")
	}
	return out
}

// normalizeProfile returns "ts6" for ts6 input and "ts3" for everything else.
func normalizeProfile(profile string) string {
	if strings.EqualFold(strings.TrimSpace(profile), "ts6") {
		return "ts6"
	}
	return "ts3"
}

func payloadLooksLikePCM(payload []byte, sampleRate, channels, durationMs int) bool {
	if sampleRate <= 0 || channels <= 0 || durationMs <= 0 {
		return false
	}
	want := sampleRate * durationMs * channels * 2 / 1000
	return want > 0 && len(payload) == want
}
