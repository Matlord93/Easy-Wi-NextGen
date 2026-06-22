package main

import (
	"context"
	"encoding/base64"
	"fmt"
	"log"
	"strings"
)

// handler drives the synchronous NDJSON request/response loop and enforces
// state machine invariants. It holds secrets only for masking; it never logs them.
type handler struct {
	backend ClientBackend
	logger  *log.Logger

	// Secrets are saved for masking error messages — they are never written anywhere.
	serverPassword  string
	channelPassword string
}

func newHandler(backend ClientBackend, logger *log.Logger) *handler {
	return &handler{backend: backend, logger: logger}
}

// dispatch routes one request to the appropriate action handler and returns the response.
func (h *handler) dispatch(req request) response {
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
		h.logger.Printf("unknown action: %s", h.mask(req.Action))
		return response{OK: false, Error: "unknown action: " + req.Action}
	}
}

func (h *handler) handleConnect(req request) response {
	port := req.Port
	if port <= 0 {
		port = 9987
	}
	profile := strings.ToLower(strings.TrimSpace(req.Profile))
	if profile != "ts3" && profile != "ts6" {
		profile = "ts3"
	}

	// Save secrets for masking — must not appear in logs or errors.
	h.serverPassword = req.ServerPassword
	h.channelPassword = ""

	if strings.TrimSpace(req.Host) == "" {
		return response{OK: false, Error: "connect: host is required"}
	}

	cfg := connectConfig{
		Host:           req.Host,
		Port:           port,
		Profile:        profile,
		Nickname:       req.Nickname,
		IdentityPath:   req.IdentityPath,
		SDKLibraryPath: req.BackendPath,
		ServerPassword: req.ServerPassword,
	}

	h.logger.Printf("connect host=%s port=%d profile=%s nickname=%s sdk_path=%s",
		req.Host, port, profile, req.Nickname, req.BackendPath)

	clientID, err := h.backend.Connect(context.Background(), cfg)
	if err != nil {
		h.logger.Printf("connect failed (host=%s port=%d)", req.Host, port)
		return response{OK: false, Error: h.mask(err.Error())}
	}

	h.logger.Printf("connected host=%s port=%d client_id=%s", req.Host, port, clientID)
	return response{OK: true, Ready: true, State: stateConnected, ClientID: clientID}
}

func (h *handler) handleDisconnect() response {
	wasConnected := h.backend.Connected()
	if err := h.backend.Disconnect(context.Background()); err != nil {
		h.logger.Printf("disconnect error: %v", h.mask(err.Error()))
	}
	h.serverPassword = ""
	h.channelPassword = ""
	if wasConnected {
		h.logger.Printf("disconnected")
	}
	return response{OK: true, State: stateDisconnected}
}

func (h *handler) handleReconnect() response {
	if !h.backend.Connected() {
		return response{OK: false, Error: "reconnect: not previously connected"}
	}
	clientID, err := h.backend.Reconnect(context.Background())
	if err != nil {
		h.logger.Printf("reconnect failed")
		return response{OK: false, Error: h.mask(err.Error())}
	}
	h.logger.Printf("reconnected client_id=%s", clientID)
	return response{OK: true, Ready: true, State: stateConnected, ClientID: clientID}
}

func (h *handler) handleJoinChannel(req request) response {
	if strings.TrimSpace(req.ChannelID) == "" {
		return response{OK: false, Error: "join_channel: channel_id is required"}
	}
	if !h.backend.Connected() {
		return response{OK: false, Error: "join_channel: not connected"}
	}

	// Save channel password for masking — never log it.
	h.channelPassword = req.ChannelPassword

	actualID, err := h.backend.JoinChannel(context.Background(), req.ChannelID, req.ChannelPassword)
	if err != nil {
		h.logger.Printf("join_channel %s failed", req.ChannelID)
		return response{OK: false, Error: h.mask(err.Error())}
	}
	h.logger.Printf("joined channel %s", actualID)
	return response{OK: true, ChannelID: actualID}
}

func (h *handler) handleLeaveChannel() response {
	if err := h.backend.LeaveChannel(context.Background()); err != nil {
		h.logger.Printf("leave_channel failed: %v", err)
		return response{OK: false, Error: err.Error()}
	}
	h.channelPassword = ""
	h.logger.Printf("left channel")
	return response{OK: true}
}

func (h *handler) handleSetNickname(req request) response {
	if strings.TrimSpace(req.Nickname) == "" {
		return response{OK: false, Error: "set_nickname: nickname is required"}
	}
	if err := h.backend.SetNickname(context.Background(), req.Nickname); err != nil {
		h.logger.Printf("set_nickname failed: %v", err)
		return response{OK: false, Error: err.Error()}
	}
	h.logger.Printf("nickname updated")
	return response{OK: true}
}

func (h *handler) handleSendOpusFrame(req request) response {
	if !h.backend.Connected() {
		return response{OK: false, Error: "send_opus_frame: not connected"}
	}
	if h.backend.CurrentChannelID() == "" {
		return response{OK: false, Error: "send_opus_frame: not in a voice channel"}
	}
	if !strings.EqualFold(req.Format, "opus") {
		return response{OK: false, Error: fmt.Sprintf("send_opus_frame: unsupported format %q, expected opus", req.Format)}
	}
	if req.Payload == "" {
		return response{OK: false, Error: "send_opus_frame: payload is required"}
	}
	frame, err := base64.StdEncoding.DecodeString(req.Payload)
	if err != nil {
		return response{OK: false, Error: "send_opus_frame: payload is not valid base64"}
	}
	durationMs := req.DurationMs
	if durationMs <= 0 {
		durationMs = 20
	}
	if err := h.backend.SendOpusFrame(context.Background(), frame, durationMs); err != nil {
		return response{OK: false, Error: h.mask(err.Error())}
	}
	return response{OK: true}
}

func (h *handler) handleStatus() response {
	if !h.backend.Connected() {
		return response{OK: true, State: stateDisconnected, BuildMode: h.backend.Name()}
	}
	return response{
		OK:        true,
		Ready:     true,
		State:     stateConnected,
		ClientID:  h.backend.ClientID(),
		ChannelID: h.backend.CurrentChannelID(),
		BuildMode: h.backend.Name(),
	}
}

// mask replaces known secret values in s before any log or error output.
func (h *handler) mask(s string) string {
	out := s
	if h.serverPassword != "" {
		out = strings.ReplaceAll(out, h.serverPassword, "[redacted]")
	}
	if h.channelPassword != "" {
		out = strings.ReplaceAll(out, h.channelPassword, "[redacted]")
	}
	return out
}
