package musicbotruntime

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
)

type ConnectionState string

const (
	ConnectionStateDisconnected ConnectionState = "disconnected"
	ConnectionStateConnecting   ConnectionState = "connecting"
	ConnectionStateConnected    ConnectionState = "connected"
	ConnectionStateError        ConnectionState = "error"
)

type ConnectionStatus struct {
	Platform             string           `json:"platform"`
	Profile              string           `json:"profile,omitempty"`
	Backend              string           `json:"backend,omitempty"`
	Enabled              bool             `json:"enabled"`
	Connected            bool             `json:"connected"`
	VoiceClientAvailable bool             `json:"voice_client_available"`
	CapabilityStatus     CapabilityStatus `json:"capability_status,omitempty"`
	State                ConnectionState  `json:"state"`
	ChannelID            string           `json:"channel_id,omitempty"`
	ListenerIDs          []string         `json:"listener_ids,omitempty"`
	LastError            string           `json:"last_error,omitempty"`
	VoiceGatewayState    string           `json:"voice_gateway_state,omitempty"`
	VoiceUDPState        string           `json:"voice_udp_state,omitempty"`
	ReconnectCount       uint64           `json:"reconnect_count,omitempty"`
	LastVoiceError       string           `json:"last_voice_error,omitempty"`
	LastHeartbeatAt      string           `json:"last_heartbeat_at,omitempty"`
	LastHeartbeatAckAt   string           `json:"last_heartbeat_ack_at,omitempty"`
	LastReconnectAt      string           `json:"last_reconnect_at,omitempty"`
	BackendType          string           `json:"backend_type,omitempty"`
	Host                 string           `json:"host,omitempty"`
	Port                 int              `json:"port,omitempty"`
	Nickname             string           `json:"nickname,omitempty"`
	IdentityPath         string           `json:"identity_path,omitempty"`
	BackendPath          string           `json:"backend_path,omitempty"`
	ClientID             string           `json:"client_id,omitempty"`
	OutputBackend        string           `json:"output_backend,omitempty"`
	UpdatedAt            string           `json:"updated_at"`
}

type AudioFrame struct {
	Format       string         `json:"format"`
	SampleRateHz int            `json:"sample_rate_hz"`
	SampleRate   int            `json:"sample_rate"`
	Channels     int            `json:"channels"`
	Sequence     uint64         `json:"sequence"`
	PCM          []byte         `json:"-"`
	Payload      []byte         `json:"payload,omitempty"`
	DurationMs   int            `json:"duration_ms"`
	Duration     time.Duration  `json:"duration"`
	Timestamp    time.Time      `json:"timestamp"`
	Metadata     map[string]any `json:"metadata,omitempty"`
}

type Listener struct {
	ID          string `json:"id"`
	DisplayName string `json:"display_name"`
}

type AudioOutput interface {
	SendAudioFrame(ctx context.Context, frame AudioFrame) error
}

type ListenerProvider interface {
	Listeners(ctx context.Context) ([]Listener, error)
}

type Connector interface {
	AudioOutput
	ListenerProvider
	ValidateConfig() error
	Connect(ctx context.Context) error
	Disconnect(ctx context.Context) error
	Reconnect(ctx context.Context) error
	JoinChannel(ctx context.Context, channelID string) error
	GetStatus(ctx context.Context) ConnectionStatus
}

type TeamSpeakVoiceConnector struct {
	config      TeamSpeakConnectorConfig
	voiceClient NativeTeamspeakVoiceClient
	mu          sync.Mutex
	status      ConnectionStatus
}

func NewTeamSpeakConnector(config TeamSpeakConnectorConfig) *TeamSpeakVoiceConnector {
	return NewTeamSpeakVoiceConnector(config)
}

func NewTeamSpeakVoiceConnector(config TeamSpeakConnectorConfig) *TeamSpeakVoiceConnector {
	return NewTeamSpeakVoiceConnectorWithClient(config, newTeamspeakVoiceClientForConfig(config))
}

func NewTeamSpeakVoiceConnectorWithClient(config TeamSpeakConnectorConfig, voiceClient NativeTeamspeakVoiceClient) *TeamSpeakVoiceConnector {
	if voiceClient == nil {
		voiceClient = NewPlaceholderTeamspeakVoiceClient()
	}
	profile := normalizeTeamspeakProfile(teamspeakConfigString(config, "profile"))
	return &TeamSpeakVoiceConnector{
		config:      config,
		voiceClient: voiceClient,
		status: ConnectionStatus{
			Platform:             "teamspeak",
			Profile:              profile,
			Backend:              "ts3_client_compatible",
			BackendType:          teamspeakBackendType(config),
			Host:                 teamspeakConfigString(config, "host"),
			Port:                 teamspeakConfigPort(config),
			Nickname:             teamspeakConfigString(config, "nickname"),
			IdentityPath:         teamspeakConfigString(config, "identity_path"),
			BackendPath:          teamspeakConfigString(config, "backend_path"),
			ChannelID:            teamspeakConfigString(config, "channel_id"),
			OutputBackend:        teamspeakOutputBackend(initialTeamspeakCapabilityStatus(config)),
			Enabled:              config.Enabled,
			Connected:            false,
			VoiceClientAvailable: false,
			CapabilityStatus:     initialTeamspeakCapabilityStatus(config),
			State:                ConnectionStateDisconnected,
			UpdatedAt:            time.Now().UTC().Format(time.RFC3339),
		},
	}
}

func (c *TeamSpeakVoiceConnector) ValidateConfig() error {
	validator, ok := c.voiceClient.(interface {
		ValidateConfig(TeamSpeakConnectorConfig) error
	})
	if !ok {
		validator = NewPlaceholderTeamspeakVoiceClient()
	}
	return validator.ValidateConfig(c.config)
}

func (c *TeamSpeakVoiceConnector) Connect(ctx context.Context) error {
	if err := c.ValidateConfig(); err != nil {
		if isTeamSpeakBackendRequiredError(err) {
			c.setBackendRequired(err)
		} else {
			c.setError(err)
		}
		return err
	}
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.Connect(ctx, c.config); err != nil {
		c.setBackendRequired(err)
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = c.voiceClient.GetConnectionState(ctx)
	c.status.Connected = c.status.State == ConnectionStateConnected
	c.status.Profile = normalizeTeamspeakProfile(teamspeakConfigString(c.config, "profile"))
	c.status.Backend = "ts3_client_compatible"
	c.status.BackendType = teamspeakBackendType(c.config)
	c.status.Host = teamspeakConfigString(c.config, "host")
	c.status.Port = teamspeakConfigPort(c.config)
	c.status.Nickname = teamspeakConfigString(c.config, "nickname")
	c.status.IdentityPath = teamspeakConfigString(c.config, "identity_path")
	c.status.BackendPath = teamspeakConfigString(c.config, "backend_path")
	c.status.VoiceClientAvailable = c.status.Connected
	c.status.CapabilityStatus = c.capabilityStatusLocked()
	c.status.ClientID, _ = c.voiceClient.GetClientID(ctx)
	c.status.OutputBackend = teamspeakOutputBackend(c.status.CapabilityStatus)
	c.status.LastError = maskTeamspeakSecretError(c.voiceClient.GetLastError(), c.config)
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	return nil
}

func (c *TeamSpeakVoiceConnector) Disconnect(ctx context.Context) error {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.Disconnect(ctx); err != nil {
		c.setError(err)
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = ConnectionStateDisconnected
	c.status.Connected = false
	c.status.VoiceClientAvailable = false
	c.status.CapabilityStatus = c.capabilityStatusLocked()
	c.status.OutputBackend = "null"
	c.status.ClientID = ""
	c.status.ChannelID = ""
	c.status.ListenerIDs = nil
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	return nil
}

func (c *TeamSpeakVoiceConnector) Reconnect(ctx context.Context) error {
	if err := c.Disconnect(ctx); err != nil {
		return err
	}
	return c.Connect(ctx)
}

func (c *TeamSpeakVoiceConnector) JoinChannel(ctx context.Context, channelID string) error {
	if channelID == "" {
		err := errors.New("channel_id is required")
		c.setError(err)
		return err
	}
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.JoinChannel(ctx, channelID, teamspeakConfigString(c.config, "channel_password")); err != nil {
		c.setBackendRequired(err)
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = c.voiceClient.GetConnectionState(ctx)
	c.status.Connected = c.status.State == ConnectionStateConnected
	c.status.VoiceClientAvailable = c.status.Connected
	c.status.CapabilityStatus = c.capabilityStatusLocked()
	c.status.ClientID, _ = c.voiceClient.GetClientID(ctx)
	c.status.OutputBackend = teamspeakOutputBackend(c.status.CapabilityStatus)
	c.status.ChannelID = channelID
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	return nil
}

func (c *TeamSpeakVoiceConnector) GetStatus(ctx context.Context) ConnectionStatus {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
	default:
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.status
}

func (c *TeamSpeakVoiceConnector) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		c.setError(err)
		return err
	}
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if !c.isVoiceClientAvailable() {
		err := ErrTeamSpeakVoiceBackendNotConfigured
		c.setBackendRequired(err)
		return err
	}
	if err := c.voiceClient.SendOpusFrame(ctx, frame); err != nil {
		c.setBackendRequired(err)
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = c.voiceClient.GetConnectionState(ctx)
	c.status.Connected = c.status.State == ConnectionStateConnected
	c.status.VoiceClientAvailable = c.status.Connected
	c.status.CapabilityStatus = c.capabilityStatusLocked()
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	return nil
}

func (c *TeamSpeakVoiceConnector) Listeners(ctx context.Context) ([]Listener, error) {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return nil, ctx.Err()
	default:
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	listeners := make([]Listener, 0, len(c.status.ListenerIDs))
	for _, id := range c.status.ListenerIDs {
		listeners = append(listeners, Listener{ID: id, DisplayName: id})
	}
	return listeners, nil
}

func (c *TeamSpeakVoiceConnector) setError(err error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = ConnectionStateError
	c.status.Connected = false
	c.status.VoiceClientAvailable = false
	c.status.CapabilityStatus = CapabilityStatusError
	c.status.LastError = maskTeamspeakSecretError(err.Error(), c.config)
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

func (c *TeamSpeakVoiceConnector) setBackendRequired(err error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = ConnectionStateDisconnected
	c.status.Connected = false
	c.status.VoiceClientAvailable = false
	c.status.CapabilityStatus = CapabilityStatusClientBackendRequired
	c.status.OutputBackend = "null"
	c.status.LastError = maskTeamspeakSecretError(err.Error(), c.config)
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

func (c *TeamSpeakVoiceConnector) isVoiceClientAvailable() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.status.VoiceClientAvailable && c.status.CapabilityStatus == CapabilityStatusReady
}

func (c *TeamSpeakVoiceConnector) capabilityStatusLocked() CapabilityStatus {
	if c.status.State == ConnectionStateError {
		return CapabilityStatusError
	}
	if teamspeakBackendType(c.config) == TeamSpeakBackendTypeDisabled {
		return CapabilityStatusPlaceholder
	}
	if c.status.Connected {
		return CapabilityStatusReady
	}
	return CapabilityStatusClientBackendRequired
}

func initialTeamspeakCapabilityStatus(config TeamSpeakConnectorConfig) CapabilityStatus {
	if teamspeakBackendType(config) == TeamSpeakBackendTypeDisabled {
		return CapabilityStatusPlaceholder
	}
	return CapabilityStatusClientBackendRequired
}

func newTeamspeakVoiceClientForConfig(config TeamSpeakConnectorConfig) NativeTeamspeakVoiceClient {
	switch teamspeakBackendType(config) {
	case TeamSpeakBackendTypeNativeSDK:
		return NewNativeSdkTeamspeakVoiceClient()
	case TeamSpeakBackendTypeExternalClientBridge:
		return NewExternalBridgeTeamspeakVoiceClient()
	default:
		return NewPlaceholderTeamspeakVoiceClient()
	}
}

func teamspeakBackendType(config TeamSpeakConnectorConfig) string {
	rawMap := ""
	if config.Config != nil {
		rawMap = asString(config.Config["backend_type"])
	}
	raw := strings.ToLower(strings.TrimSpace(firstNonEmpty(config.BackendType, rawMap)))
	switch raw {
	case TeamSpeakBackendTypeNativeSDK, TeamSpeakBackendTypeExternalClientBridge, TeamSpeakBackendTypeDisabled:
		return raw
	case "", TeamSpeakBackendTypePlaceholder:
		return TeamSpeakBackendTypePlaceholder
	default:
		return TeamSpeakBackendTypePlaceholder
	}
}

func isTeamSpeakBackendRequiredError(err error) bool {
	return errors.Is(err, ErrTeamSpeakVoiceBackendNotConfigured) ||
		errors.Is(err, ErrTeamSpeakNativeSDKNotInstalled) ||
		errors.Is(err, ErrTeamSpeakExternalBridgeNotConfigured) ||
		errors.Is(err, ErrTeamSpeakBridgeMissingBridgeBinary) ||
		errors.Is(err, ErrTeamSpeakBridgeMissingClientBinary)
}

func teamspeakOutputBackend(status CapabilityStatus) string {
	if status == CapabilityStatusReady {
		return "teamspeak_voice"
	}
	return "null"
}

func teamspeakConfigPort(config TeamSpeakConnectorConfig) int {
	if config.Port > 0 {
		return config.Port
	}
	port := 0
	if config.Config != nil {
		port = asInt(config.Config["port"])
	}
	if port > 0 {
		return port
	}
	return 9987
}

func teamspeakConfigString(config TeamSpeakConnectorConfig, key string) string {
	switch key {
	case "profile":
		if config.Profile != "" {
			return config.Profile
		}
	case "backend":
		if config.Backend != "" {
			return config.Backend
		}
	case "backend_type":
		if config.BackendType != "" {
			return config.BackendType
		}
	case "backend_path":
		if config.BackendPath != "" {
			return config.BackendPath
		}
	case "identity_path":
		if config.IdentityPath != "" {
			return config.IdentityPath
		}
	case "host":
		if config.Host != "" {
			return config.Host
		}
	case "channel_id":
		if config.ChannelID != "" {
			return config.ChannelID
		}
	case "server_password":
		if config.ServerPassword != "" {
			return config.ServerPassword
		}
	case "channel_password":
		if config.ChannelPassword != "" {
			return config.ChannelPassword
		}
	case "nickname":
		if config.Nickname != "" {
			return config.Nickname
		}
	case "bridge_path":
		if config.BridgePath != "" {
			return config.BridgePath
		}
	case "client_binary_path":
		if config.ClientBinaryPath != "" {
			return config.ClientBinaryPath
		}
	case "client_runscript_path":
		if config.ClientRunscriptPath != "" {
			return config.ClientRunscriptPath
		}
	case "audio_backend":
		if config.AudioBackend != "" {
			return config.AudioBackend
		}
	case "instance_path":
		if config.InstancePath != "" {
			return config.InstancePath
		}
	case "runtime_dir":
		if config.RuntimeDir != "" {
			return config.RuntimeDir
		}
	case "client_query_host":
		if config.ClientQueryHost != "" {
			return config.ClientQueryHost
		}
	}
	if config.Config == nil {
		return ""
	}
	return asString(config.Config[key])
}

func teamspeakConfigClientQueryPort(config TeamSpeakConnectorConfig) int {
	if config.ClientQueryPort > 0 {
		return config.ClientQueryPort
	}
	if config.Config != nil {
		if p := asInt(config.Config["client_query_port"]); p > 0 {
			return p
		}
	}
	return 0
}

func normalizeTeamspeakProfile(profile string) string {
	switch strings.ToLower(strings.TrimSpace(profile)) {
	case "", "ts3":
		return "ts3"
	case "ts6":
		return "ts6"
	default:
		return ""
	}
}

func validateAudioFrame(frame AudioFrame) error {
	if frame.Format == "" {
		return errors.New("audio frame format is required")
	}
	if frame.SampleRateHz <= 0 && frame.SampleRate <= 0 {
		return errors.New("audio frame sample rate must be positive")
	}
	if frame.SampleRateHz == 0 {
		frame.SampleRateHz = frame.SampleRate
	}
	if frame.Channels <= 0 {
		return errors.New("audio frame channel count must be positive")
	}
	if frame.DurationMs <= 0 && frame.Duration <= 0 {
		return errors.New("audio frame duration must be positive")
	}
	return nil
}

func asString(value any) string {
	if value == nil {
		return ""
	}
	return fmt.Sprint(value)
}

func asInt(value any) int {
	switch v := value.(type) {
	case int:
		return v
	case float64:
		return int(v)
	case string:
		var n int
		_, _ = fmt.Sscanf(v, "%d", &n)
		return n
	}
	return 0
}

type DiscordConnector struct {
	config      ConnectorConfig
	voiceClient DiscordVoiceClient
	mu          sync.Mutex
	status      ConnectionStatus
}

func NewDiscordConnector(config ConnectorConfig) *DiscordConnector {
	return NewDiscordConnectorWithClient(config, NewPlaceholderDiscordVoiceClient())
}

func NewDiscordConnectorWithClient(config ConnectorConfig, voiceClient DiscordVoiceClient) *DiscordConnector {
	if voiceClient == nil {
		voiceClient = NewPlaceholderDiscordVoiceClient()
	}
	return &DiscordConnector{
		config:      config,
		voiceClient: voiceClient,
		status: ConnectionStatus{
			Platform:             "discord",
			Backend:              "placeholder",
			Enabled:              config.Enabled,
			Connected:            false,
			VoiceClientAvailable: false,
			CapabilityStatus:     CapabilityStatusVoiceBackendRequired,
			State:                ConnectionStateDisconnected,
			ChannelID:            discordConfigString(config, "voice_channel_id"),
			UpdatedAt:            time.Now().UTC().Format(time.RFC3339),
		},
	}
}

func (c *DiscordConnector) ValidateConfig() error {
	if !c.config.Enabled {
		return nil
	}
	if c.config.Config == nil {
		return errors.New("discord config is required when connector is enabled")
	}
	if discordConfigString(c.config, "command_mode") == "placeholder" {
		return nil
	}
	if discordConfigString(c.config, "bot_token") == "" {
		return errors.New("discord config requires bot_token or command_mode=placeholder")
	}
	if discordConfigString(c.config, "application_id") == "" {
		return errors.New("discord config requires application_id")
	}
	if discordConfigString(c.config, "guild_id") == "" {
		return errors.New("discord config requires guild_id")
	}
	if discordConfigString(c.config, "voice_channel_id") == "" {
		return errors.New("discord config requires voice_channel_id")
	}
	return nil
}

func (c *DiscordConnector) Connect(ctx context.Context) error {
	if err := c.ValidateConfig(); err != nil {
		c.setError(err)
		return err
	}
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.ConnectGateway(ctx, c.config); err != nil {
		if errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
			c.setVoiceBackendRequired(err)
		} else {
			c.setError(err)
		}
		return err
	}
	c.refreshStatusFromVoiceState(ctx)
	return nil
}

func (c *DiscordConnector) Disconnect(ctx context.Context) error {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.DisconnectGateway(ctx); err != nil {
		c.setError(err)
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = ConnectionStateDisconnected
	c.status.Connected = false
	c.status.VoiceClientAvailable = false
	c.status.CapabilityStatus = c.capabilityStatusLocked()
	c.status.OutputBackend = "null"
	c.status.ClientID = ""
	c.status.ChannelID = ""
	c.status.ListenerIDs = nil
	c.status.LastError = ""
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	return nil
}

func (c *DiscordConnector) Reconnect(ctx context.Context) error {
	if err := c.ValidateConfig(); err != nil {
		c.setError(err)
		return err
	}
	if err := c.voiceClient.Reconnect(ctx, c.config); err != nil {
		if errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
			c.setVoiceBackendRequired(err)
		} else {
			c.setError(err)
		}
		return err
	}
	c.refreshStatusFromVoiceState(ctx)
	return nil
}

func (c *DiscordConnector) JoinChannel(ctx context.Context, channelID string) error {
	return c.JoinVoiceChannel(ctx, channelID)
}

func (c *DiscordConnector) JoinVoiceChannel(ctx context.Context, channelID string) error {
	if channelID == "" {
		channelID = discordConfigString(c.config, "voice_channel_id")
	}
	guildID := discordConfigString(c.config, "guild_id")
	if guildID == "" {
		err := errors.New("guild_id is required")
		c.setError(err)
		return err
	}
	if channelID == "" {
		err := errors.New("voice_channel_id is required")
		c.setError(err)
		return err
	}
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.JoinVoiceChannel(ctx, guildID, channelID); err != nil {
		if errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
			c.setVoiceBackendRequired(err)
		} else {
			c.setError(err)
		}
		return err
	}
	c.refreshStatusFromVoiceState(ctx)
	return nil
}

func (c *DiscordConnector) LeaveVoiceChannel(ctx context.Context) error {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.LeaveVoiceChannel(ctx); err != nil {
		c.setError(err)
		return err
	}
	c.refreshStatusFromVoiceState(ctx)
	return nil
}

func (c *DiscordConnector) GetStatus(ctx context.Context) ConnectionStatus {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
	default:
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.status
}

func (c *DiscordConnector) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		c.setError(err)
		return err
	}
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return ctx.Err()
	default:
	}
	if err := c.voiceClient.SendOpusFrame(ctx, frame); err != nil {
		if errors.Is(err, ErrDiscordVoiceBackendNotConfigured) {
			c.setVoiceBackendRequired(err)
		} else {
			c.setError(err)
		}
		return err
	}
	c.refreshStatusFromVoiceState(ctx)
	return nil
}

func (c *DiscordConnector) Listeners(ctx context.Context) ([]Listener, error) {
	select {
	case <-ctx.Done():
		c.setError(ctx.Err())
		return nil, ctx.Err()
	default:
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	listeners := make([]Listener, 0, len(c.status.ListenerIDs))
	for _, id := range c.status.ListenerIDs {
		listeners = append(listeners, Listener{ID: id, DisplayName: id})
	}
	return listeners, nil
}

func (c *DiscordConnector) refreshStatusFromVoiceState(ctx context.Context) {
	voiceState := c.voiceClient.GetVoiceState(ctx)
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.Enabled = c.config.Enabled
	switch c.voiceClient.(type) {
	case *PlaceholderDiscordVoiceClient:
		c.status.Backend = "placeholder"
	case *RealDiscordVoiceClient:
		c.status.Backend = "discord_gateway"
	default:
		c.status.Backend = "discord_voice_client"
	}
	c.status.Connected = voiceState.GatewayConnected
	c.status.VoiceGatewayState = voiceState.VoiceGatewayState
	c.status.VoiceUDPState = voiceState.VoiceUDPState
	c.status.ReconnectCount = voiceState.ReconnectCount
	c.status.LastVoiceError = maskSensitiveError(voiceState.LastVoiceError, c.config.Config)
	c.status.LastHeartbeatAt = voiceState.LastHeartbeatAt
	c.status.LastHeartbeatAckAt = voiceState.LastHeartbeatAckAt
	c.status.LastReconnectAt = voiceState.LastReconnectAt
	c.status.VoiceClientAvailable = voiceState.CapabilityStatus == CapabilityStatusReady
	c.status.CapabilityStatus = c.capabilityStatusFromVoiceStateLocked(voiceState)
	if voiceState.ChannelID != "" || voiceState.VoiceJoined {
		c.status.ChannelID = voiceState.ChannelID
	} else if c.status.ChannelID == "" {
		c.status.ChannelID = discordConfigString(c.config, "voice_channel_id")
	}
	switch c.status.CapabilityStatus {
	case CapabilityStatusReady:
		c.status.State = ConnectionStateConnected
		c.status.LastError = ""
	case CapabilityStatusError:
		c.status.State = ConnectionStateError
		c.status.LastError = maskSensitiveError(firstNonEmpty(voiceState.LastError, c.voiceClient.GetLastError()), c.config.Config)
	default:
		c.status.State = ConnectionStateDisconnected
		c.status.LastError = maskSensitiveError(firstNonEmpty(voiceState.LastError, c.voiceClient.GetLastError()), c.config.Config)
	}
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

func (c *DiscordConnector) setError(err error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = ConnectionStateError
	c.status.Connected = false
	c.status.VoiceClientAvailable = false
	c.status.CapabilityStatus = CapabilityStatusError
	c.status.LastError = maskSensitiveError(err.Error(), c.config.Config)
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

func (c *DiscordConnector) setVoiceBackendRequired(err error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.status.State = ConnectionStateDisconnected
	c.status.Connected = false
	c.status.VoiceClientAvailable = false
	c.status.CapabilityStatus = CapabilityStatusVoiceBackendRequired
	c.status.LastError = maskSensitiveError(err.Error(), c.config.Config)
	c.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

func (c *DiscordConnector) capabilityStatusLocked() CapabilityStatus {
	if c.status.State == ConnectionStateError {
		return CapabilityStatusError
	}
	if _, ok := c.voiceClient.(*PlaceholderDiscordVoiceClient); ok {
		return CapabilityStatusVoiceBackendRequired
	}
	if c.status.Connected {
		return CapabilityStatusReady
	}
	return CapabilityStatusPlaceholder
}

func (c *DiscordConnector) capabilityStatusFromVoiceStateLocked(voiceState DiscordVoiceState) CapabilityStatus {
	if voiceState.CapabilityStatus != "" {
		return voiceState.CapabilityStatus
	}
	return c.capabilityStatusLocked()
}

func discordConfigString(config ConnectorConfig, key string) string {
	if config.Config == nil {
		return ""
	}
	return strings.TrimSpace(asString(config.Config[key]))
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if value != "" {
			return value
		}
	}
	return ""
}

func maskSensitiveError(message string, config map[string]any) string {
	masked := message
	if config == nil {
		return masked
	}
	for _, key := range []string{"bot_token", "token"} {
		secret := asString(config[key])
		if secret != "" {
			masked = strings.ReplaceAll(masked, secret, "[redacted]")
		}
	}
	return masked
}
