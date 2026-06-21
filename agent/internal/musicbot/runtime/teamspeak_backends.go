package musicbotruntime

import (
	"bufio"
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

// ExternalBridgeTeamspeakVoiceClient speaks a small newline-delimited JSON
// protocol to an explicitly configured local bridge process. It does not use a
// shell and refuses known third-party musicbot binaries.
type ExternalBridgeTeamspeakVoiceClient struct {
	teamspeakBackendBase
	mu      sync.Mutex
	cmd     *exec.Cmd
	stdin   io.WriteCloser
	scanner *bufio.Scanner
	channel string
}

type teamspeakBridgeRequest struct {
	Action          string `json:"action"`
	Host            string `json:"host,omitempty"`
	Port            int    `json:"port,omitempty"`
	Profile         string `json:"profile,omitempty"`
	Nickname        string `json:"nickname,omitempty"`
	IdentityPath    string `json:"identity_path,omitempty"`
	ServerPassword  string `json:"server_password,omitempty"`
	ChannelID       string `json:"channel_id,omitempty"`
	ChannelPassword string `json:"channel_password,omitempty"`
	Format          string `json:"format,omitempty"`
	Payload         string `json:"payload,omitempty"`
	DurationMs      int    `json:"duration_ms,omitempty"`
}

type teamspeakBridgeResponse struct {
	OK        bool   `json:"ok"`
	Error     string `json:"error,omitempty"`
	State     string `json:"state,omitempty"`
	ClientID  string `json:"client_id,omitempty"`
	ChannelID string `json:"channel_id,omitempty"`
}

func NewExternalBridgeTeamspeakVoiceClient() *ExternalBridgeTeamspeakVoiceClient {
	return &ExternalBridgeTeamspeakVoiceClient{}
}

func (c *ExternalBridgeTeamspeakVoiceClient) ValidateConfig(config TeamSpeakConnectorConfig) error {
	if err := validateTeamspeakCommonConfig(config); err != nil {
		return err
	}
	if !config.Enabled || teamspeakBackendType(config) == TeamSpeakBackendTypeDisabled {
		return nil
	}
	path := strings.TrimSpace(teamspeakConfigString(config, "backend_path"))
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
	path := teamspeakConfigString(config, "backend_path")
	cmd := exec.Command(path)
	cmd.Env = append(os.Environ(), "EASYWI_TS_BRIDGE=1")
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
	c.mu.Unlock()

	resp, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "connect", Host: teamspeakConfigString(config, "host"), Port: teamspeakConfigPort(config), Profile: normalizeTeamspeakProfile(teamspeakConfigString(config, "profile")), Nickname: teamspeakConfigString(config, "nickname"), IdentityPath: teamspeakConfigString(config, "identity_path"), ServerPassword: teamspeakConfigString(config, "server_password")})
	if err != nil {
		_ = c.Disconnect(context.Background())
		return c.recordError(err)
	}
	c.state = ConnectionStateConnected
	c.clientID = resp.ClientID
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) Disconnect(ctx context.Context) error {
	_ = ctx.Err()
	_, _ = c.bridgeRoundTrip(context.Background(), teamspeakBridgeRequest{Action: "disconnect"})
	c.mu.Lock()
	cmd := c.cmd
	stdin := c.stdin
	c.cmd = nil
	c.stdin = nil
	c.scanner = nil
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
	return nil
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
	resp, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "join_channel", ChannelID: channelID, ChannelPassword: password})
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
	if err := validateTeamspeakOpusFrame(frame); err != nil {
		return c.recordError(err)
	}
	if c.state != ConnectionStateConnected {
		return c.recordError(ErrTeamSpeakExternalBridgeNotConfigured)
	}
	payload := frame.Payload
	if len(payload) == 0 {
		payload = frame.PCM
	}
	_, err := c.bridgeRoundTrip(ctx, teamspeakBridgeRequest{Action: "send_opus_frame", Format: "opus", Payload: base64.StdEncoding.EncodeToString(payload), DurationMs: frame.DurationMs})
	if err != nil {
		return c.recordError(err)
	}
	return nil
}

func (c *ExternalBridgeTeamspeakVoiceClient) bridgeRoundTrip(ctx context.Context, req teamspeakBridgeRequest) (teamspeakBridgeResponse, error) {
	if err := ctx.Err(); err != nil {
		return teamspeakBridgeResponse{}, err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.stdin == nil || c.scanner == nil {
		return teamspeakBridgeResponse{}, ErrTeamSpeakExternalBridgeNotConfigured
	}
	encoded, err := json.Marshal(req)
	if err != nil {
		return teamspeakBridgeResponse{}, err
	}
	if _, err := c.stdin.Write(append(encoded, '\n')); err != nil {
		return teamspeakBridgeResponse{}, fmt.Errorf("teamspeak bridge write: %w", err)
	}
	if !c.scanner.Scan() {
		if err := c.scanner.Err(); err != nil {
			return teamspeakBridgeResponse{}, fmt.Errorf("teamspeak bridge read: %w", err)
		}
		return teamspeakBridgeResponse{}, errors.New("teamspeak bridge closed")
	}
	var resp teamspeakBridgeResponse
	if err := json.Unmarshal(c.scanner.Bytes(), &resp); err != nil {
		return teamspeakBridgeResponse{}, fmt.Errorf("teamspeak bridge response: %w", err)
	}
	if !resp.OK {
		if resp.Error == "" {
			resp.Error = "teamspeak bridge command failed"
		}
		return resp, errors.New(resp.Error)
	}
	if resp.State == string(ConnectionStateConnected) {
		c.state = ConnectionStateConnected
	}
	if resp.ClientID != "" {
		c.clientID = resp.ClientID
	}
	return resp, nil
}

func validateTeamspeakCommonConfig(config TeamSpeakConnectorConfig) error {
	if !config.Enabled {
		return nil
	}
	profile := normalizeTeamspeakProfile(teamspeakConfigString(config, "profile"))
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
	if err != nil || info.IsDir() {
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
