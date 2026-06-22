// easywi-ts-e2e-helper is a TeamSpeak bridge NDJSON protocol conformance helper
// for the Musicbot Live E2E test suite.
//
// It is spawned by the easywi-teamspeak-bridge binary as a client_library or
// native_sdk subprocess. It implements the full bridge NDJSON protocol
// (docs/architecture/musicbot-teamspeak-external-bridge-protocol.md) and
// responds correctly to all defined actions.
//
// This binary exists solely as an E2E test fixture. It is NOT a TeamSpeak
// client — it does not open UDP sockets to TeamSpeak servers. It validates
// that the processBackedAdapter → bridge → runtime stack works end-to-end
// including AudioPipeline frame counting (frames_sent > 0).
//
// Constraints respected:
//   - No SinusBot, no TS3AudioBot, no ServerQuery audio.
//   - No reverse engineering of TeamSpeak network protocols.
//   - Passwords are never written to stderr or stdout.
//   - stdout is JSON-only; all diagnostics go to stderr.
//   - Requires EASYWI_TS_CLIENT_LIB=1 or EASYWI_TS_NATIVE_SDK=1 to run.
package main

import (
	"bufio"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"strings"
)

type request struct {
	Action          string `json:"action"`
	Host            string `json:"host,omitempty"`
	Port            int    `json:"port,omitempty"`
	Profile         string `json:"profile,omitempty"`
	Nickname        string `json:"nickname,omitempty"`
	IdentityPath    string `json:"identity_path,omitempty"`
	ServerPassword  string `json:"server_password,omitempty"`  // secret — never log
	ChannelID       string `json:"channel_id,omitempty"`
	ChannelPassword string `json:"channel_password,omitempty"` // secret — never log
	Format          string `json:"format,omitempty"`
	Payload         string `json:"payload,omitempty"`
	DurationMs      int    `json:"duration_ms,omitempty"`
}

type response struct {
	OK        bool   `json:"ok"`
	Error     string `json:"error,omitempty"`
	Ready     bool   `json:"ready,omitempty"`
	State     string `json:"state,omitempty"`
	ClientID  string `json:"client_id,omitempty"`
	ChannelID string `json:"channel_id,omitempty"`
}

const (
	stateConnected    = "connected"
	stateDisconnected = "disconnected"
)

// helper holds runtime state for the protocol session.
type helper struct {
	state     string
	clientID  string
	channelID string
	nickname  string

	enc    *json.Encoder
	logger *log.Logger

	// saved for sanitize — values themselves are never written anywhere
	serverPassword  string
	channelPassword string
}

func newHelper() *helper {
	enc := json.NewEncoder(os.Stdout)
	enc.SetEscapeHTML(false)
	return &helper{
		state:  stateDisconnected,
		enc:    enc,
		logger: log.New(os.Stderr, "[easywi-ts-e2e-helper] ", log.LstdFlags),
	}
}

func (h *helper) respond(r response) {
	if err := h.enc.Encode(r); err != nil {
		h.logger.Printf("encode response: %v", err)
	}
}

// sanitize removes known secret values from s before any diagnostic logging.
func (h *helper) sanitize(s string) string {
	out := s
	if h.serverPassword != "" {
		out = strings.ReplaceAll(out, h.serverPassword, "[redacted]")
	}
	if h.channelPassword != "" {
		out = strings.ReplaceAll(out, h.channelPassword, "[redacted]")
	}
	return out
}

func (h *helper) handleConnect(req request) response {
	port := req.Port
	if port <= 0 {
		port = 9987
	}
	// Save secrets for masking — never log them.
	h.serverPassword = req.ServerPassword
	h.channelPassword = ""

	profile := req.Profile
	if profile == "" {
		profile = "ts3"
	}

	// Log connection target without secret values.
	h.logger.Printf("connect host=%s port=%d profile=%s nickname=%s",
		req.Host, port, profile, req.Nickname)

	h.state = stateConnected
	h.clientID = "e2e-ts-client-1"
	h.nickname = req.Nickname
	h.channelID = ""
	return response{OK: true, Ready: true, State: stateConnected, ClientID: h.clientID}
}

func (h *helper) handleDisconnect() response {
	h.logger.Printf("disconnect (state was %s)", h.state)
	h.state = stateDisconnected
	h.clientID = ""
	h.channelID = ""
	h.serverPassword = ""
	h.channelPassword = ""
	return response{OK: true, State: stateDisconnected}
}

func (h *helper) handleReconnect() response {
	if h.state != stateConnected {
		h.logger.Printf("reconnect: was not connected")
		return response{OK: false, Error: "reconnect: not previously connected"}
	}
	h.logger.Printf("reconnect client_id=%s", h.clientID)
	return response{OK: true, Ready: true, State: stateConnected, ClientID: h.clientID}
}

func (h *helper) handleJoinChannel(req request) response {
	if h.state != stateConnected {
		return response{OK: false, Error: "join_channel: not connected"}
	}
	if strings.TrimSpace(req.ChannelID) == "" {
		return response{OK: false, Error: "join_channel: channel_id is required"}
	}
	h.channelPassword = req.ChannelPassword
	h.channelID = req.ChannelID
	h.logger.Printf("joined channel %s", req.ChannelID)
	return response{OK: true, ChannelID: req.ChannelID}
}

func (h *helper) handleLeaveChannel() response {
	h.logger.Printf("leave channel (was %s)", h.channelID)
	h.channelID = ""
	h.channelPassword = ""
	return response{OK: true}
}

func (h *helper) handleSetNickname(req request) response {
	if strings.TrimSpace(req.Nickname) == "" {
		return response{OK: false, Error: "set_nickname: nickname is required"}
	}
	h.logger.Printf("nickname: %s → %s", h.nickname, req.Nickname)
	h.nickname = req.Nickname
	return response{OK: true}
}

func (h *helper) handleSendOpusFrame(req request) response {
	if h.state != stateConnected {
		return response{OK: false, Error: "send_opus_frame: not connected"}
	}
	if h.channelID == "" {
		return response{OK: false, Error: "send_opus_frame: not in a voice channel"}
	}
	if !strings.EqualFold(req.Format, "opus") {
		return response{OK: false, Error: fmt.Sprintf("send_opus_frame: unsupported format %q", req.Format)}
	}
	if req.Payload == "" {
		return response{OK: false, Error: "send_opus_frame: payload is required"}
	}
	// Decode to validate the base64; the bytes are not forwarded anywhere in this fixture.
	if _, err := base64.StdEncoding.DecodeString(req.Payload); err != nil {
		return response{OK: false, Error: "send_opus_frame: payload is not valid base64"}
	}
	return response{OK: true}
}

func (h *helper) handleStatus() response {
	if h.state != stateConnected {
		return response{OK: true, State: stateDisconnected}
	}
	return response{
		OK:        true,
		Ready:     true,
		State:     stateConnected,
		ClientID:  h.clientID,
		ChannelID: h.channelID,
	}
}

func (h *helper) dispatch(req request) response {
	switch req.Action {
	case "connect":
		return h.handleConnect(req)
	case "disconnect", "shutdown":
		return h.handleDisconnect()
	case "reconnect":
		return h.handleReconnect()
	case "join_channel":
		return h.handleJoinChannel(req)
	case "leave_channel":
		return h.handleLeaveChannel()
	case "set_nickname":
		return h.handleSetNickname(req)
	case "send_opus_frame":
		return h.handleSendOpusFrame(req)
	case "status":
		return h.handleStatus()
	default:
		h.logger.Printf("unknown action: %s", h.sanitize(req.Action))
		return response{OK: false, Error: "unknown action: " + req.Action}
	}
}

func main() {
	// Verify expected invocation environment.
	clientLib := os.Getenv("EASYWI_TS_CLIENT_LIB")
	nativeSDK := os.Getenv("EASYWI_TS_NATIVE_SDK")
	if clientLib != "1" && nativeSDK != "1" {
		fmt.Fprintln(os.Stderr,
			"[easywi-ts-e2e-helper] fatal: must be invoked by easywi-teamspeak-bridge "+
				"(EASYWI_TS_CLIENT_LIB=1 or EASYWI_TS_NATIVE_SDK=1 not set)")
		os.Exit(1)
	}

	h := newHelper()
	h.logger.Printf("started (client_lib=%s native_sdk=%s)", clientLib, nativeSDK)

	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		line := scanner.Bytes()
		var req request
		if err := json.Unmarshal(line, &req); err != nil {
			h.respond(response{OK: false, Error: "invalid JSON request"})
			continue
		}
		h.respond(h.dispatch(req))
	}

	if err := scanner.Err(); err != nil {
		h.logger.Printf("stdin read error: %v", err)
		os.Exit(1)
	}
	h.logger.Printf("exiting (stdin closed)")
}
