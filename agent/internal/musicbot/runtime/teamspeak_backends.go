package musicbotruntime

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const (
	TeamSpeakBackendTypePlaceholder          = "placeholder"
	TeamSpeakBackendTypeNativeSDK            = "native_sdk"
	TeamSpeakBackendTypeExternalClientBridge = "external_client_bridge"
	TeamSpeakBackendTypeDisabled             = "disabled"

	teamSpeakNativeSDKNotInstalledMessage       = "TeamSpeak native SDK backend is not installed"
	teamSpeakExternalBridgeNotConfiguredMessage = "TeamSpeak external client bridge is not configured"
)

var (
	ErrTeamSpeakNativeSDKNotInstalled       = errors.New(teamSpeakNativeSDKNotInstalledMessage)
	ErrTeamSpeakExternalBridgeNotConfigured = errors.New(teamSpeakExternalBridgeNotConfiguredMessage)

	ErrTeamSpeakBridgeMissingBridgeBinary = errors.New("missing_bridge_binary")
	ErrTeamSpeakBridgeMissingClientBinary = errors.New("missing_client_binary")
	ErrTeamSpeakBridgeXvfbFailed          = errors.New("xvfb_failed")
	ErrTeamSpeakBridgePulseaudioFailed    = errors.New("pulseaudio_failed")
	ErrTeamSpeakBridgeTsClientStartFailed = errors.New("ts3client_start_failed")
	ErrTeamSpeakBridgeConnectFailed       = errors.New("connect_failed")
)

type teamspeakBackendBase struct {
	config    TeamSpeakConnectorConfig
	state     ConnectionState
	lastError string
	clientID  string
}

func (b *teamspeakBackendBase) Disconnect(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return b.recordError(err)
	}
	b.state = ConnectionStateDisconnected
	b.clientID = ""
	return nil
}

func (b *teamspeakBackendBase) Reconnect(ctx context.Context) error {
	if err := b.Disconnect(ctx); err != nil {
		return err
	}
	return nil
}

func (b *teamspeakBackendBase) Authenticate(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return b.recordError(err)
	}
	if b.state != ConnectionStateConnected {
		return b.recordError(ErrTeamSpeakVoiceBackendNotConfigured)
	}
	return nil
}

func (b *teamspeakBackendBase) SetNickname(ctx context.Context, nickname string) error {
	if err := ctx.Err(); err != nil {
		return b.recordError(err)
	}
	if strings.TrimSpace(nickname) == "" {
		return b.recordError(errors.New("teamspeak nickname is required"))
	}
	if b.state != ConnectionStateConnected {
		return b.recordError(ErrTeamSpeakVoiceBackendNotConfigured)
	}
	return nil
}

func (b *teamspeakBackendBase) LeaveChannel(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return b.recordError(err)
	}
	return nil
}

func (b *teamspeakBackendBase) GetClientID(ctx context.Context) (string, error) {
	if err := ctx.Err(); err != nil {
		_ = b.recordError(err)
		return "", err
	}
	return b.clientID, nil
}

func (b *teamspeakBackendBase) GetConnectionState(ctx context.Context) ConnectionState {
	if err := ctx.Err(); err != nil {
		_ = b.recordError(err)
		return ConnectionStateError
	}
	return b.state
}

func (b *teamspeakBackendBase) GetLastError() string { return b.lastError }

func (b *teamspeakBackendBase) recordError(err error) error {
	masked := maskTeamspeakSecretError(err.Error(), b.config)
	b.lastError = masked
	b.state = ConnectionStateError
	if masked == err.Error() {
		return err
	}
	return errors.New(masked)
}

// NativeSdkTeamspeakVoiceClient is a guarded adapter for a future TeamSpeak
// native SDK integration. It does not fake audio support: it only becomes ready
// when configured SDK/library files exist and a real implementation is wired in.
type NativeSdkTeamspeakVoiceClient struct{ teamspeakBackendBase }

func NewNativeSdkTeamspeakVoiceClient() *NativeSdkTeamspeakVoiceClient {
	return &NativeSdkTeamspeakVoiceClient{}
}

func (c *NativeSdkTeamspeakVoiceClient) ValidateConfig(config TeamSpeakConnectorConfig) error {
	if err := validateTeamspeakCommonConfig(config); err != nil {
		return err
	}
	if !config.Enabled || teamspeakBackendType(config) == TeamSpeakBackendTypeDisabled {
		return nil
	}
	if !teamspeakSDKInstalled(config) {
		return ErrTeamSpeakNativeSDKNotInstalled
	}
	return nil
}

func (c *NativeSdkTeamspeakVoiceClient) Connect(ctx context.Context, config TeamSpeakConnectorConfig) error {
	c.config = config
	if err := ctx.Err(); err != nil {
		return c.recordError(err)
	}
	if err := c.ValidateConfig(config); err != nil {
		return c.recordError(err)
	}
	return c.recordError(ErrTeamSpeakNativeSDKNotInstalled)
}

func (c *NativeSdkTeamspeakVoiceClient) Reconnect(ctx context.Context) error {
	_ = c.teamspeakBackendBase.Reconnect(ctx)
	return c.Connect(ctx, c.config)
}

func (c *NativeSdkTeamspeakVoiceClient) JoinChannel(ctx context.Context, channelID string, password string) error {
	if strings.TrimSpace(channelID) == "" {
		return c.recordError(errors.New("channel_id is required"))
	}
	return c.recordError(ErrTeamSpeakNativeSDKNotInstalled)
}

func (c *NativeSdkTeamspeakVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateTeamspeakOpusFrame(frame); err != nil {
		return c.recordError(err)
	}
	if c.state != ConnectionStateConnected {
		return c.recordError(ErrTeamSpeakNativeSDKNotInstalled)
	}
	return nil
}

func (c *NativeSdkTeamspeakVoiceClient) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if strings.EqualFold(frame.Format, "opus") {
		return c.SendOpusFrame(ctx, frame)
	}
	if err := validateAudioFrame(frame); err != nil {
		return c.recordError(err)
	}
	return c.recordError(ErrTeamSpeakNativeSDKNotInstalled)
}

// ExternalBridgeTeamspeakVoiceClient speaks a small newline-delimited JSON
// protocol to an explicitly configured local bridge process. It does not use a
// shell and refuses known third-party musicbot binaries.
type ExternalBridgeTeamspeakVoiceClient struct {
	teamspeakBackendBase
	mu          sync.Mutex // protects cmd, stdin, scanner, stderrBuf, state fields
	roundTripMu sync.Mutex // serializes bridgeRoundTrip calls
	cmd         *exec.Cmd
	stdin       io.WriteCloser
	scanner     *bufio.Scanner
	stderrBuf   *bytes.Buffer // captures bridge diagnostic logs (written to stderr by bridge)
	logger      interface{ Printf(string, ...any) }
	channel     string
}

type teamspeakBridgeRequest struct {
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

type teamspeakBridgeResponse struct {
	OK                    bool   `json:"ok"`
	Error                 string `json:"error,omitempty"`
	ErrorCode             string `json:"error_code,omitempty"`
	BackendType           string `json:"backend_type,omitempty"`
	Ready                 bool   `json:"ready,omitempty"`
	State                 string `json:"state,omitempty"`
	ClientID              string `json:"client_id,omitempty"`
	ChannelID             string `json:"channel_id,omitempty"`
	LicenseAcceptRequired bool   `json:"license_accept_required,omitempty"`
}

func NewExternalBridgeTeamspeakVoiceClient() *ExternalBridgeTeamspeakVoiceClient {
	return &ExternalBridgeTeamspeakVoiceClient{}
}

func (c *ExternalBridgeTeamspeakVoiceClient) SetLogger(logger interface{ Printf(string, ...any) }) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.logger = logger
}

func (c *ExternalBridgeTeamspeakVoiceClient) ValidateConfig(config TeamSpeakConnectorConfig) error {
	if err := validateTeamspeakCommonConfig(config); err != nil {
		return err
	}
	if !config.Enabled || teamspeakBackendType(config) == TeamSpeakBackendTypeDisabled {
		return nil
	}
	path := strings.TrimSpace(teamspeakConfigString(config, "bridge_path"))
	if path == "" {
		path = strings.TrimSpace(teamspeakConfigString(config, "backend_path"))
	}
	if path == "" {
		return ErrTeamSpeakExternalBridgeNotConfigured
	}
	if err := validateTeamspeakBridgePath(path); err != nil {
		return err
	}
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) Connect(ctx context.Context, config TeamSpeakConnectorConfig) error {
	c.config = config
	if err := ctx.Err(); err != nil {
		return c.recordError(err)
	}
	if err := c.ValidateConfig(config); err != nil {
		return c.recordError(err)
	}
	path := teamspeakConfigString(config, "bridge_path")
	if path == "" {
		path = teamspeakConfigString(config, "backend_path")
	}
	var stderrBuf bytes.Buffer
	cmd := exec.Command(path)
	cmd.Env = append(os.Environ(), "EASYWI_TS_BRIDGE=1")
	cmd.Stderr = io.MultiWriter(&stderrBuf, os.Stderr, c.bridgeEventLogWriter())
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return c.recordError(fmt.Errorf("teamspeak bridge stdin: %w", err))
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		_ = stdin.Close()
		return c.recordError(fmt.Errorf("teamspeak bridge stdout: %w", err))
	}
	if err := cmd.Start(); err != nil {
		_ = stdin.Close()
		return c.recordError(fmt.Errorf("teamspeak bridge start: %w", err))
	}

	c.mu.Lock()
	c.cmd = cmd
	c.stdin = stdin
	c.scanner = bufio.NewScanner(stdout)
	c.stderrBuf = &stderrBuf
	c.mu.Unlock()

	connectCtx, cancelConnect := context.WithTimeout(ctx, 90*time.Second)
	defer cancelConnect()

	resp, err := c.bridgeRoundTrip(connectCtx, teamspeakBridgeRequest{
		Action:              "connect",
		BackendType:         teamspeakBridgeAdapterType(config),
		BackendPath:         teamspeakBridgeAdapterPath(config),
		Host:                teamspeakConfigString(config, "host"),
		Port:                teamspeakConfigPort(config),
		Profile:             normalizeTeamspeakProfile(teamspeakConfigString(config, "profile")),
		Nickname:            teamspeakConfigString(config, "nickname"),
		IdentityPath:        teamspeakConfigString(config, "identity_path"),
		ChannelID:           teamspeakConfigString(config, "channel_id"),
		ServerPassword:      teamspeakConfigString(config, "server_password"),
		ChannelPassword:     teamspeakConfigString(config, "channel_password"),
		ClientBinaryPath:    teamspeakConfigString(config, "client_binary_path"),
		ClientRunscriptPath: teamspeakConfigString(config, "client_runscript_path"),
		AudioBackend:        teamspeakConfigString(config, "audio_backend"),
		InstancePath:        teamspeakConfigString(config, "instance_path"),
		RuntimeDir:          teamspeakConfigString(config, "runtime_dir"),
		ClientQueryHost:     teamspeakConfigString(config, "client_query_host"),
		ClientQueryPort:     teamspeakConfigClientQueryPort(config),
	})
	if err != nil {
		c.stopBridgeProcess()
		return c.recordError(err)
	}
	c.state = ConnectionStateConnected
	c.clientID = resp.ClientID
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) Disconnect(ctx context.Context) error {
	_ = ctx.Err()
	disconnectCtx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	_, _ = c.bridgeRoundTrip(disconnectCtx, teamspeakBridgeRequest{Action: "disconnect"})
	cancel()
	c.stopBridgeProcess()
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) stopBridgeProcess() {
	c.mu.Lock()
	cmd := c.cmd
	stdin := c.stdin
	c.cmd = nil
	c.stdin = nil
	c.scanner = nil
	c.stderrBuf = nil
	c.mu.Unlock()
	if stdin != nil {
		_ = stdin.Close()
	}
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	c.state = ConnectionStateDisconnected
	c.clientID = ""
	c.channel = ""
}

func (c *ExternalBridgeTeamspeakVoiceClient) Reconnect(ctx context.Context) error {
	cfg := c.config
	c.mu.Lock()
	hasBridge := c.stdin != nil && c.scanner != nil
	c.mu.Unlock()
	if hasBridge {
		if _, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "reconnect"}); err == nil {
			c.state = ConnectionStateConnected
			return nil
		}
	}
	_ = c.Disconnect(ctx)
	return c.Connect(ctx, cfg)
}

func (c *ExternalBridgeTeamspeakVoiceClient) Authenticate(ctx context.Context) error {
	if c.state != ConnectionStateConnected {
		return c.recordError(ErrTeamSpeakExternalBridgeNotConfigured)
	}
	_, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "status"})
	if err != nil {
		return c.recordError(err)
	}
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) SetNickname(ctx context.Context, nickname string) error {
	if strings.TrimSpace(nickname) == "" {
		return c.recordError(errors.New("teamspeak nickname is required"))
	}
	_, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "set_nickname", Nickname: nickname})
	if err != nil {
		return c.recordError(err)
	}
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) JoinChannel(ctx context.Context, channelID string, password string) error {
	if strings.TrimSpace(channelID) == "" {
		return c.recordError(errors.New("channel_id is required"))
	}
	connectCtx, cancelConnect := context.WithTimeout(ctx, 90*time.Second)
	defer cancelConnect()

	resp, err := c.bridgeRoundTrip(connectCtx, teamspeakBridgeRequest{Action: "join_channel", ChannelID: channelID, ChannelPassword: password})
	if err != nil {
		return c.recordError(err)
	}
	c.channel = firstNonEmpty(resp.ChannelID, channelID)
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) LeaveChannel(ctx context.Context) error {
	_, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "leave_channel"})
	if err != nil {
		return c.recordError(err)
	}
	c.channel = ""
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) SendOpusFrame(ctx context.Context, frame AudioFrame) error {
	return c.SendAudioFrame(ctx, frame)
}

func (c *ExternalBridgeTeamspeakVoiceClient) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		return c.recordError(err)
	}
	if c.state != ConnectionStateConnected {
		return c.recordError(ErrTeamSpeakExternalBridgeNotConfigured)
	}
	frame = normalizeTeamspeakAudioFrame(frame)
	format := strings.ToLower(strings.TrimSpace(frame.Format))
	payload := frame.Payload
	if format == "pcm" || format == "pcm_s16le" {
		payload = frame.PCM
	}
	if len(payload) == 0 {
		payload = frame.PCM
	}
	if len(payload) == 0 {
		return c.recordError(errors.New("teamspeak audio frame payload is empty"))
	}
	_, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "send_audio_frame", Format: format, Payload: base64.StdEncoding.EncodeToString(payload), DurationMs: frame.DurationMs})
	if err != nil {
		return c.recordError(err)
	}
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) bridgeRoundTrip(ctx context.Context, req teamspeakBridgeRequest) (teamspeakBridgeResponse, error) {
	if err := ctx.Err(); err != nil {
		return teamspeakBridgeResponse{}, err
	}
	c.roundTripMu.Lock()
	defer c.roundTripMu.Unlock()

	c.mu.Lock()
	stdin := c.stdin
	scanner := c.scanner
	cmd := c.cmd
	c.mu.Unlock()

	if stdin == nil || scanner == nil {
		return teamspeakBridgeResponse{}, ErrTeamSpeakExternalBridgeNotConfigured
	}
	encoded, err := json.Marshal(req)
	if err != nil {
		return teamspeakBridgeResponse{}, err
	}
	if _, err := stdin.Write(append(encoded, '\n')); err != nil {
		return teamspeakBridgeResponse{}, fmt.Errorf("teamspeak bridge write: %w", err)
	}

	type scanResult struct {
		line []byte
		err  error
	}
	result := make(chan scanResult, 1)
	go func() {
		if !scanner.Scan() {
			if scanErr := scanner.Err(); scanErr != nil {
				result <- scanResult{err: fmt.Errorf("teamspeak bridge read: %w", scanErr)}
			} else {
				// Bridge stdout closed (process exited). Call Wait() to populate
				// ProcessState so the exit code is available for diagnostics.
				if cmd != nil {
					_ = cmd.Wait()
				}
				stderrSnippet := c.bridgeStderrSnippet()
				exitCode := ""
				if cmd != nil && cmd.ProcessState != nil {
					exitCode = fmt.Sprintf("%d", cmd.ProcessState.ExitCode())
				}
				msg := "teamspeak bridge closed"
				if exitCode != "" {
					msg += "; exit_code=" + exitCode
				}
				if stderrSnippet != "" {
					msg += "; bridge_stderr: " + stderrSnippet
				}
				result <- scanResult{err: errors.New(msg)}
			}
			return
		}
		b := scanner.Bytes()
		cp := make([]byte, len(b))
		copy(cp, b)
		result <- scanResult{line: cp}
	}()

	select {
	case <-ctx.Done():
		return teamspeakBridgeResponse{}, ctx.Err()
	case r := <-result:
		if r.err != nil {
			return teamspeakBridgeResponse{}, r.err
		}
		var resp teamspeakBridgeResponse
		if err := json.Unmarshal(r.line, &resp); err != nil {
			return teamspeakBridgeResponse{}, fmt.Errorf("teamspeak bridge response: %w", err)
		}
		if !resp.OK {
			return resp, bridgeErrorFromCode(resp.ErrorCode, resp.Error)
		}
		c.mu.Lock()
		if resp.State == string(ConnectionStateConnected) {
			c.state = ConnectionStateConnected
		}
		if resp.ClientID != "" {
			c.clientID = resp.ClientID
		}
		c.mu.Unlock()
		return resp, nil
	}
}

// bridgeStderrSnippet returns the last 2000 bytes of the bridge's stderr output,
// trimmed and with newlines collapsed to spaces for inline logging. Safe to call
// after the bridge process has exited (stdout EOF). Returns "" if no stderr was
// captured or the buffer is empty.

func (c *ExternalBridgeTeamspeakVoiceClient) bridgeEventLogWriter() io.Writer {
	c.mu.Lock()
	logger := c.logger
	c.mu.Unlock()
	if logger == nil {
		return io.Discard
	}
	return &lineLogWriter{logger: logger, prefix: "bridge_event"}
}

type lineLogWriter struct {
	mu     sync.Mutex
	buf    bytes.Buffer
	logger interface{ Printf(string, ...any) }
	prefix string
}

func (w *lineLogWriter) Write(p []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	for _, b := range p {
		if b == '\n' {
			w.flushLocked()
			continue
		}
		_ = w.buf.WriteByte(b)
	}
	return len(p), nil
}

func (w *lineLogWriter) flushLocked() {
	line := strings.TrimSpace(w.buf.String())
	w.buf.Reset()
	if line == "" {
		return
	}
	if event := classifyBridgeLogEvent(line); event != "" {
		w.logger.Printf("%s=%s %s", w.prefix, event, line)
	}
}

func classifyBridgeLogEvent(line string) string {
	switch {
	case strings.Contains(line, "protocol_ready=true") || strings.Contains(line, "connect_step=start"):
		return "BridgeStarted"
	case strings.Contains(line, "connect_step=wait_clientquery_done") || strings.Contains(line, "clientquery_listening=true"):
		return "ClientQueryReady"
	case strings.Contains(line, "connect_sent=true"):
		return "ClientConnected"
	case strings.Contains(line, "ts_server_connected=true"):
		return "ServerConnected"
	case strings.Contains(line, "audio_injection_ready=true") || strings.Contains(line, "audio_injection_ready=false"):
		return "AudioInjectionReady"
	case strings.Contains(line, "state_connected=true") || strings.Contains(line, "capability_status=ready"):
		return "RuntimeStatusPublished"
	default:
		return ""
	}
}

func (c *ExternalBridgeTeamspeakVoiceClient) bridgeStderrSnippet() string {
	c.mu.Lock()
	buf := c.stderrBuf
	c.mu.Unlock()
	if buf == nil {
		return ""
	}
	raw := buf.String()
	if len(raw) > 2000 {
		raw = raw[len(raw)-2000:]
	}
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return ""
	}
	return strings.ReplaceAll(raw, "\n", " | ")
}

func (c *ExternalBridgeTeamspeakVoiceClient) pulseAudioStateFromBridgeLogs() (sink, source, pulseSocket string) {
	raw := c.bridgeStderrSnippet()
	for _, line := range strings.Split(raw, "|") {
		fields := strings.Fields(strings.TrimSpace(line))
		for _, field := range fields {
			switch {
			case strings.HasPrefix(field, "sink="):
				sink = strings.Trim(strings.TrimPrefix(field, "sink="), `"'`)
			case strings.HasPrefix(field, "source="):
				source = strings.Trim(strings.TrimPrefix(field, "source="), `"'`)
			case strings.HasPrefix(field, "pulse_socket="):
				pulseSocket = strings.Trim(strings.TrimPrefix(field, "pulse_socket="), `"'`)
			case strings.HasPrefix(field, "pulse_server="):
				pulseSocket = strings.Trim(strings.TrimPrefix(field, "pulse_server="), `"'`)
			}
		}
	}
	return sink, source, pulseSocket
}

func bridgeErrorFromCode(code, message string) error {
	switch code {
	case "missing_bridge_binary":
		return ErrTeamSpeakBridgeMissingBridgeBinary
	case "missing_client_binary":
		return ErrTeamSpeakBridgeMissingClientBinary
	case "xvfb_failed":
		return ErrTeamSpeakBridgeXvfbFailed
	case "pulseaudio_failed":
		return ErrTeamSpeakBridgePulseaudioFailed
	case "ts3client_start_failed":
		return ErrTeamSpeakBridgeTsClientStartFailed
	case "connect_failed":
		return ErrTeamSpeakBridgeConnectFailed
	default:
		if message == "" {
			return errors.New("teamspeak bridge command failed")
		}
		return errors.New(message)
	}
}

func teamspeakBridgeAdapterType(config TeamSpeakConnectorConfig) string {
	// Direct backend_type takes priority for external_client_bridge.
	if bt := strings.TrimSpace(config.BackendType); bt == TeamSpeakBackendTypeExternalClientBridge {
		return bt
	}
	if v := teamspeakConfigString(config, "bridge_backend_type"); v != "" {
		return v
	}
	if v := teamspeakConfigString(config, "client_backend_type"); v != "" {
		return v
	}
	return "placeholder"
}

func teamspeakBridgeAdapterPath(config TeamSpeakConnectorConfig) string {
	// For external_client_bridge the bridge binary path is in backend_path or bridge_path.
	if strings.TrimSpace(config.BackendType) == TeamSpeakBackendTypeExternalClientBridge {
		if v := teamspeakConfigString(config, "bridge_path"); v != "" {
			return v
		}
	}
	for _, key := range []string{"client_binary_path", "client_library_path", "native_sdk_path", "sdk_path", "library_path"} {
		if v := teamspeakConfigString(config, key); v != "" {
			return v
		}
	}
	return ""
}

func validateTeamspeakCommonConfig(config TeamSpeakConnectorConfig) error {
	if !config.Enabled {
		return nil
	}
	profile := normalizeTeamspeakProfile(teamspeakConfigString(config, "profile"))
	if profile == "" && teamspeakBackendType(config) == TeamSpeakBackendTypeExternalClientBridge {
		profile = "ts3"
	}
	if profile == "" {
		return errors.New("teamspeak config profile must be ts3 or ts6")
	}
	backend := teamspeakConfigString(config, "backend")
	if backend != "" && backend != "ts3_client_compatible" {
		return errors.New("teamspeak config backend must be ts3_client_compatible")
	}
	if teamspeakBackendType(config) == TeamSpeakBackendTypeDisabled {
		return nil
	}
	if teamspeakConfigString(config, "host") == "" && teamspeakConfigString(config, "server_address") == "" {
		return errors.New("teamspeak config requires host or server_address")
	}
	return nil
}

func validateTeamspeakOpusFrame(frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		return err
	}
	if !strings.EqualFold(frame.Format, "opus") {
		return fmt.Errorf("teamspeak opus frame format is required, got %q", frame.Format)
	}
	return nil
}

func teamspeakSDKInstalled(config TeamSpeakConnectorConfig) bool {
	path := strings.TrimSpace(teamspeakConfigString(config, "backend_path"))
	if path == "" {
		path = strings.TrimSpace(teamspeakConfigString(config, "sdk_path"))
	}
	if path == "" {
		path = strings.TrimSpace(teamspeakConfigString(config, "library_path"))
	}
	if path == "" {
		return false
	}
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

func validateTeamspeakBridgePath(path string) error {
	base := strings.ToLower(filepath.Base(path))
	if strings.Contains(base, "sinusbot") || strings.Contains(base, "ts3audiobot") {
		return errors.New("unsupported TeamSpeak bridge binary")
	}
	info, err := os.Stat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return fmt.Errorf("%w: %s", ErrTeamSpeakBridgeMissingBridgeBinary, path)
		}
		return ErrTeamSpeakExternalBridgeNotConfigured
	}
	if info.IsDir() {
		return ErrTeamSpeakExternalBridgeNotConfigured
	}
	if info.Mode()&0o111 == 0 {
		return errors.New("TeamSpeak external client bridge is not executable")
	}
	return nil
}

func maskTeamspeakSecretError(message string, config TeamSpeakConnectorConfig) string {
	masked := message
	for _, secret := range []string{teamspeakConfigString(config, "server_password"), teamspeakConfigString(config, "channel_password")} {
		if secret != "" {
			masked = strings.ReplaceAll(masked, secret, "[redacted]")
		}
	}
	return masked
}
