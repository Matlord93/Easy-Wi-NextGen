// Package main implements the easywi-teamspeak-client binary:
// a TeamSpeak client helper that speaks the bridge NDJSON sub-protocol
// (docs/architecture/musicbot-teamspeak-external-bridge-protocol.md §2).
//
// The binary is spawned by easywi-teamspeak-bridge via processBackedAdapter
// when the bridge's backend_type is "client_library" or "native_sdk".
// It receives EASYWI_TS_CLIENT_LIB=1 or EASYWI_TS_NATIVE_SDK=1 from the bridge.
//
// Build modes
//
//	Default (no tags): stub backend — fails clearly on connect with SDK install
//	                   instructions; all protocol logic is compiled and tested.
//	-tags ts3clientlib: links against the official TeamSpeak 3 client library
//	                    (libts3client.so); see backend_ts3clientlib.go for build
//	                    and SDK install instructions.
//
// Security constraints honoured by this binary:
//   - No reverse engineering of the TeamSpeak network protocol.
//   - No SinusBot, no TS3AudioBot, no ServerQuery audio.
//   - server_password and channel_password are never written to stdout or stderr.
//   - stdout is JSON-only (the NDJSON protocol).
//   - All secrets are masked before any diagnostic output.
//   - No shell execution; all external calls go through the SDK API directly.
package main

const (
	stateConnected    = "connected"
	stateDisconnected = "disconnected"
	stateConnecting   = "connecting"
	stateError        = "error"
)

// request is a JSON command received from the bridge over stdin.
// Fields not relevant to the current action are ignored.
type request struct {
	Action          string `json:"action"`
	Host            string `json:"host,omitempty"`
	Port            int    `json:"port,omitempty"`
	Profile         string `json:"profile,omitempty"`
	Nickname        string `json:"nickname,omitempty"`
	IdentityPath    string `json:"identity_path,omitempty"`
	ServerPassword  string `json:"server_password,omitempty"` // secret — never log
	ChannelID       string `json:"channel_id,omitempty"`
	ChannelPassword string `json:"channel_password,omitempty"` // secret — never log
	BackendPath     string `json:"backend_path,omitempty"`     // path to SDK library (.so)
	BackendType     string `json:"backend_type,omitempty"`     // "native_sdk" | "client_library"
	Format          string `json:"format,omitempty"`
	Payload         string `json:"payload,omitempty"`
	DurationMs      int    `json:"duration_ms,omitempty"`
}

// response is a JSON reply written to stdout after each request.
type response struct {
	OK        bool   `json:"ok"`
	Error     string `json:"error,omitempty"`
	Ready     bool   `json:"ready,omitempty"`
	State     string `json:"state,omitempty"`
	ClientID  string `json:"client_id,omitempty"`
	ChannelID string `json:"channel_id,omitempty"`
}
