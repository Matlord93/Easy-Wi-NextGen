package musicbotruntime

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const Version = "easywi-musicbot-preview"

type Config struct {
	InstanceID  string                   `json:"instance_id"`
	CustomerID  string                   `json:"customer_id"`
	NodeID      string                   `json:"node_id,omitempty"`
	ServiceName string                   `json:"service_name"`
	InstallPath string                   `json:"install_path,omitempty"`
	DataDir     string                   `json:"data_dir"`
	LogDir      string                   `json:"log_dir"`
	TeamSpeak   TeamSpeakConnectorConfig `json:"teamspeak"`
	Discord     ConnectorConfig          `json:"discord"`
	Limits      LimitsConfig             `json:"limits"`
	PluginDir   string                   `json:"plugin_dir"`
	Runtime     string                   `json:"runtime,omitempty"`
	CreatedAt   string                   `json:"created_at,omitempty"`
	Note        string                   `json:"note,omitempty"`
	Control     ControlConfig            `json:"control,omitempty"`
	Stream      WebradioStreamConfig     `json:"stream,omitempty"`
}

type ConnectorConfig struct {
	Enabled bool           `json:"enabled"`
	Config  map[string]any `json:"config"`
}

type TeamSpeakConnectorConfig struct {
	Enabled             bool           `json:"enabled"`
	Autoconnect         bool           `json:"autoconnect,omitempty"`
	Profile             string         `json:"profile,omitempty"`
	Backend             string         `json:"backend,omitempty"`
	BackendType         string         `json:"backend_type,omitempty"`
	BackendPath         string         `json:"backend_path,omitempty"`
	IdentityPath        string         `json:"identity_path,omitempty"`
	CommandPrefix       string         `json:"command_prefix,omitempty"`
	CommandsEnabled     bool           `json:"commands_enabled,omitempty"`
	EventsEnabled       bool           `json:"events_enabled,omitempty"`
	AllowedServerGroups []string       `json:"allowed_server_groups,omitempty"`
	DJServerGroups      []string       `json:"dj_server_groups,omitempty"`
	AdminServerGroups   []string       `json:"admin_server_groups,omitempty"`
	Host                string         `json:"host,omitempty"`
	Port                int            `json:"port,omitempty"`
	Nickname            string         `json:"nickname,omitempty"`
	ChannelID           string         `json:"channel_id,omitempty"`
	ServerPassword      string         `json:"server_password,omitempty"`
	ChannelPassword     string         `json:"channel_password,omitempty"`
	BridgePath          string         `json:"bridge_path,omitempty"`
	ClientBinaryPath    string         `json:"client_binary_path,omitempty"`
	ClientRunscriptPath string         `json:"client_runscript_path,omitempty"`
	AudioBackend        string         `json:"audio_backend,omitempty"`
	InstancePath        string         `json:"instance_path,omitempty"`
	RuntimeDir          string         `json:"runtime_dir,omitempty"`
	ClientQueryHost     string         `json:"client_query_host,omitempty"`
	ClientQueryPort     int            `json:"client_query_port,omitempty"`
	Config              map[string]any `json:"config,omitempty"`
}

type LimitsConfig struct {
	CPU  int `json:"cpu"`
	RAM  int `json:"ram"`
	Disk int `json:"disk"`
}

type PlaybackState struct {
	State                string        `json:"state"`
	Current              string        `json:"current,omitempty"`
	CurrentTrack         *CurrentTrack `json:"current_track,omitempty"`
	Queue                QueueSnapshot `json:"queue"`
	Volume               int           `json:"volume"`
	EffectivePulseVolume int           `json:"effective_pulse_volume,omitempty"`
	ActualPulseVolume    int           `json:"actual_pulse_volume,omitempty"`
	PulseSink            string        `json:"pulse_sink,omitempty"`
	PulseServer          string        `json:"pulse_server,omitempty"`
	PulseSource          string        `json:"pulse_source,omitempty"`
	VolumeMapping        string        `json:"volume_mapping,omitempty"`
	LastVolumeChangeAt   string        `json:"last_volume_change_at,omitempty"`
	LastVolumeError      string        `json:"last_volume_error,omitempty"`
	Shuffle              bool          `json:"shuffle"`
	Repeat               string        `json:"repeat"`
	UpdatedAt            string        `json:"updated_at"`
	LastCommand          string        `json:"last_command,omitempty"`
	LastError            string        `json:"last_error,omitempty"`
}

type Runtime struct {
	config       Config
	logger       *log.Logger
	logFile      *os.File
	playback     PlaybackState
	pipeline     *AudioPipeline
	connectors   map[string]Connector
	streamOutput *WebradioStreamOutput
	playCancel   context.CancelFunc
	mu           sync.Mutex
	started      time.Time
}

var execCommandContext = exec.CommandContext

type commandRequest struct {
	Command string         `json:"command"`
	Action  string         `json:"action"`
	Args    map[string]any `json:"args"`
}

type commandResponse struct {
	OK      bool           `json:"ok"`
	Command string         `json:"command"`
	Error   string         `json:"error,omitempty"`
	Payload map[string]any `json:"payload,omitempty"`
}

func LoadConfig(path string) (Config, error) {
	content, err := os.ReadFile(path)
	if err != nil {
		return Config{}, err
	}
	var config Config
	if err := json.Unmarshal(content, &config); err != nil {
		return Config{}, err
	}
	if strings.TrimSpace(config.InstanceID) == "" {
		return Config{}, errors.New("missing instance_id")
	}
	if strings.TrimSpace(config.ServiceName) == "" {
		return Config{}, errors.New("missing service_name")
	}
	baseDir := config.InstallPath
	if baseDir == "" {
		baseDir = filepath.Dir(path)
	}
	if config.DataDir == "" {
		config.DataDir = filepath.Join(baseDir, "data")
	}
	if config.LogDir == "" {
		config.LogDir = filepath.Join(baseDir, "logs")
	}
	if config.PluginDir == "" {
		config.PluginDir = filepath.Join(baseDir, "plugins")
	}
	if config.Runtime == "" {
		config.Runtime = Version
	}
	return config, nil
}

func New(config Config, console io.Writer) (*Runtime, error) {
	for _, dir := range []string{config.DataDir, config.LogDir, config.PluginDir} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return nil, fmt.Errorf("create %s: %w", dir, err)
		}
	}
	logFile, err := os.OpenFile(filepath.Join(config.LogDir, "runtime.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o640)
	if err != nil {
		return nil, fmt.Errorf("open runtime log: %w", err)
	}
	logger := log.New(io.MultiWriter(console, logFile), "musicbot-runtime ", log.LstdFlags|log.LUTC|log.Lmsgprefix)
	connectors, err := buildConnectors(config)
	if err != nil {
		_ = logFile.Close()
		return nil, err
	}
	return &Runtime{
		config:     config,
		logger:     logger,
		logFile:    logFile,
		connectors: connectors,
		pipeline:   NewAudioPipeline(NewFileAudioSourceResolver(config.DataDir), FFmpegDecoder{}, DummyResampler{}, DummyOpusEncoder{}, NullAudioOutput{}),
		playback: PlaybackState{
			State:                "stopped",
			Volume:               100,
			EffectivePulseVolume: mapPanelVolumeToPulseVolume(100),
			VolumeMapping:        volumeMappingDescription,
			Queue: QueueSnapshot{
				InstanceID:  config.InstanceID,
				Items:       []QueueTrack{},
				Repeat:      "off",
				Shuffle:     false,
				GeneratedAt: time.Now().UTC().Format(time.RFC3339),
			},
			Shuffle:   false,
			Repeat:    "off",
			UpdatedAt: time.Now().UTC().Format(time.RFC3339),
		},
		started: time.Now().UTC(),
	}, nil
}

func (r *Runtime) Close() error {
	if r.logFile == nil {
		return nil
	}
	return r.logFile.Close()
}

// RunService runs the musicbot as a long-running systemd service.
// Connectors are started synchronously before the process blocks. The service
// only exits when ctx is cancelled (SIGTERM/SIGINT). Stdin EOF — which systemd
// delivers immediately via /dev/null — does NOT terminate the process.
func (r *Runtime) RunService(ctx context.Context) error {
	r.logger.Printf("started instance=%s service=%s", r.config.InstanceID, r.config.ServiceName)
	defer r.logger.Printf("stopped instance=%s service=%s", r.config.InstanceID, r.config.ServiceName)

	r.autoConnectAll(ctx)

	if ctx.Err() != nil {
		return nil
	}

	r.logger.Printf("runtime idle, waiting for commands/events")
	<-ctx.Done()
	r.logger.Printf("shutdown signal received")

	for platform, connector := range r.connectors {
		if err := connector.Disconnect(context.Background()); err != nil {
			r.logger.Printf("disconnect %s: %v", platform, err)
		}
	}

	return nil
}

// Run processes JSON commands from input and writes responses to output.
// It exits when ctx is cancelled or when input reaches EOF. Use this for
// interactive / local-testing mode (--interactive flag). For the systemd
// service mode use RunService instead, which does not tie process lifetime
// to stdin.
func (r *Runtime) Run(ctx context.Context, input io.Reader, output io.Writer) error {
	r.logger.Printf("started instance=%s service=%s", r.config.InstanceID, r.config.ServiceName)
	defer r.logger.Printf("stopped instance=%s service=%s", r.config.InstanceID, r.config.ServiceName)

	go r.autoConnectAll(ctx)

	responses := make(chan commandResponse)
	done := make(chan struct{})
	go func() {
		defer close(done)
		scanner := bufio.NewScanner(input)
		for scanner.Scan() {
			responses <- r.HandleCommand(scanner.Text())
		}
		if err := scanner.Err(); err != nil {
			responses <- commandResponse{OK: false, Command: "read", Error: err.Error()}
		}
	}()

	encoder := json.NewEncoder(output)
	for {
		select {
		case <-ctx.Done():
			return nil
		case <-done:
			return nil
		case response := <-responses:
			if err := encoder.Encode(response); err != nil {
				return err
			}
		}
	}
}

// autoConnectAll connects all enabled connectors and auto-joins configured
// channels. Context cancellation (e.g. from a racing shutdown) is treated as
// a clean exit, not a logged error.
func (r *Runtime) autoConnectAll(ctx context.Context) {
	if ctx.Err() != nil {
		return
	}
	for platform, connector := range r.connectors {
		if ctx.Err() != nil {
			return
		}
		if !connector.ShouldAutoconnect() {
			continue
		}
		// TeamSpeak external-client-bridge requires logger attachment before Connect.
		if ts, ok := connector.(*TeamSpeakVoiceConnector); ok {
			if teamspeakBackendType(ts.config) == TeamSpeakBackendTypeExternalClientBridge {
				r.attachTeamspeakBridgeLogger(connector)
				r.logger.Printf("event=BridgeStarted phase=runtime_start external_client_bridge starting")
			}
		}
		if err := connector.Connect(ctx); err != nil {
			if errors.Is(err, context.Canceled) {
				return
			}
			r.logger.Printf("auto-connect %s: %v", platform, err)
			continue
		}
		if ts, ok := connector.(*TeamSpeakVoiceConnector); ok && teamspeakBackendType(ts.config) == TeamSpeakBackendTypeExternalClientBridge {
			r.logTeamspeakRuntimeStatus("ClientConnected", connector.GetStatus(context.Background()))
			r.logger.Printf("teamspeak auto-connect started")
		} else {
			r.logger.Printf("auto-connect %s: ok", platform)
		}
		channelID := connector.InitialChannelID()
		if channelID == "" {
			continue
		}
		if err := connector.JoinChannel(ctx, channelID); err != nil {
			if errors.Is(err, context.Canceled) {
				return
			}
			r.logger.Printf("auto-join %s channel %s: %v", platform, channelID, err)
		} else {
			r.logger.Printf("auto-join %s channel %s: ok", platform, channelID)
			if ts, ok := connector.(*TeamSpeakVoiceConnector); ok && teamspeakBackendType(ts.config) == TeamSpeakBackendTypeExternalClientBridge {
				r.logTeamspeakRuntimeStatus("RuntimeStatusPublished", connector.GetStatus(context.Background()))
			}
		}
	}
}

func (r *Runtime) attachTeamspeakBridgeLogger(connector Connector) {
	ts, ok := connector.(*TeamSpeakVoiceConnector)
	if !ok {
		return
	}
	if external, ok := ts.voiceClient.(*ExternalBridgeTeamspeakVoiceClient); ok {
		external.SetLogger(r.logger)
	}
}

func (r *Runtime) logTeamspeakRuntimeStatus(event string, status ConnectionStatus) {
	audioReady := status.Connected && status.VoiceClientAvailable && status.CapabilityStatus == CapabilityStatusReady && status.OutputBackend == "teamspeak_voice"
	r.logger.Printf("event=%s platform=teamspeak state_connected=%v server_connected=%v voice_client_available=%v audio_injection_ready=%v capability_status=%s output_backend=%s connected_clid=%s connected_cid=%s",
		event, status.Connected, status.Connected, status.VoiceClientAvailable, audioReady, status.CapabilityStatus, status.OutputBackend, status.ClientID, status.ChannelID)
}

func (r *Runtime) HandleCommand(line string) commandResponse {
	request := parseCommand(line)
	command := strings.ToLower(strings.TrimSpace(request.Command))
	if command == "" {
		command = strings.ToLower(strings.TrimSpace(request.Action))
	}
	if command == "" {
		return commandResponse{OK: false, Error: "missing command"}
	}
	switch command {
	case "status":
		return commandResponse{OK: true, Command: command, Payload: r.statusPayload()}
	case "connection_status":
		return r.handleConnectionStatus(request.Args)
	case "reconnect", "reload_config":
		return r.handleReconnect(command)
	case "play", "pause", "resume", "stop", "skip", "volume", "seek", "shuffle", "repeat":
		return r.handlePlayback(command, request.Args)
	case "queue.sync":
		return r.handleQueueSync(request.Args)
	default:
		return commandResponse{OK: false, Command: command, Error: "unsupported command"}
	}
}

func (r *Runtime) handleReconnect(command string) commandResponse {
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()

	reconnected := []string{}
	for platform, connector := range r.connectors {
		if err := connector.Reconnect(ctx); err != nil {
			return commandResponse{OK: false, Command: command, Error: fmt.Sprintf("%s reconnect failed: %v", platform, err)}
		}
		reconnected = append(reconnected, platform)
	}

	return commandResponse{OK: true, Command: command, Payload: map[string]any{
		"reconnected": reconnected,
		"connectors":  r.connectorStatuses(),
	}}
}

func parseCommand(line string) commandRequest {
	trimmed := strings.TrimSpace(line)
	if trimmed == "" {
		return commandRequest{}
	}
	var request commandRequest
	if strings.HasPrefix(trimmed, "{") && json.Unmarshal([]byte(trimmed), &request) == nil {
		return request
	}
	parts := strings.Fields(trimmed)
	request.Command = parts[0]
	request.Args = map[string]any{}
	if len(parts) > 1 {
		request.Args["value"] = parts[1]
	}
	return request
}

func buildConnectors(config Config) (map[string]Connector, error) {
	connectors := map[string]Connector{}
	if config.TeamSpeak.Enabled {
		connector := NewTeamSpeakConnector(config.TeamSpeak)
		if err := connector.ValidateConfig(); err != nil && !isTeamSpeakBackendRequiredError(err) {
			return nil, err
		}
		connectors["teamspeak"] = connector
	}
	if config.Discord.Enabled {
		var connector *DiscordConnector
		if discordConfigString(config.Discord, "command_mode") == "placeholder" || discordConfigString(config.Discord, "bot_token") == "" {
			connector = NewDiscordConnector(config.Discord)
		} else {
			connector = NewDiscordConnectorWithClient(config.Discord, NewRealDiscordVoiceClient())
		}
		if err := connector.ValidateConfig(); err != nil {
			return nil, err
		}
		connectors["discord"] = connector
	}
	return connectors, nil
}

func (r *Runtime) connectorStatuses() map[string]any {
	statuses := map[string]any{
		"teamspeak": map[string]any{"enabled": r.config.TeamSpeak.Enabled, "state": ConnectionStateDisconnected},
		"discord":   map[string]any{"enabled": r.config.Discord.Enabled, "state": ConnectionStateDisconnected},
	}
	for platform, connector := range r.connectors {
		statuses[platform] = connector.GetStatus(context.Background())
	}
	return statuses
}

// primaryConnectorStatus returns the status of the primary active connector.
// TeamSpeak is preferred for backward compat; falls back to any other connector.
func (r *Runtime) primaryConnectorStatus(ctx context.Context) ConnectionStatus {
	if connector, ok := r.connectors["teamspeak"]; ok {
		return connector.GetStatus(ctx)
	}
	for _, connector := range r.connectors {
		return connector.GetStatus(ctx)
	}
	return ConnectionStatus{}
}

func (r *Runtime) statusPayload() map[string]any {
	r.mu.Lock()
	snap := r.pipeline.Snapshot()
	snap.CurrentSource = "" // strip absolute path — must not leak
	playbackStatus := r.buildPlaybackStatusLocked(snap)
	safePlayback := r.buildSafePlaybackLocked()
	primaryStatus := r.primaryConnectorStatus(context.Background())
	stateConnected := primaryStatus.Connected
	tsServerConnected := primaryStatus.Connected // backward compat alias
	voiceClientAvailable := primaryStatus.VoiceClientAvailable
	capabilityStatus := string(primaryStatus.CapabilityStatus)
	audioInjectionReady := playbackStatus["audio_injection_ready"] == true
	runtimeReady := stateConnected && tsServerConnected && voiceClientAvailable && audioInjectionReady && capabilityStatus == string(CapabilityStatusReady)
	r.mu.Unlock()
	payload := map[string]any{
		"installed":  true,
		"running":    true,
		"version":    Version,
		"uptime_sec": int(time.Since(r.started).Seconds()),
		"instance": map[string]any{
			"instance_id":  r.config.InstanceID,
			"customer_id":  r.config.CustomerID,
			"service_name": r.config.ServiceName,
		},
		"connectors":      r.connectorStatuses(),
		"playback":        safePlayback,
		"playback_status": playbackStatus,
		"audio_pipeline":  snap,
		"stream":          r.streamStatusPayload(),
		"plugins": map[string]any{
			"directory":         r.config.PluginDir,
			"manifests":         r.pluginManifestSummaries(),
			"execution_enabled": false,
		},
		"state_connected":        stateConnected,
		"ts_server_connected":    tsServerConnected,
		"voice_client_available": voiceClientAvailable,
		"audio_injection_ready":  audioInjectionReady,
		"capability_status":      capabilityStatus,
		"runtime_ready":          runtimeReady,
	}
	r.logger.Printf("event=RuntimeStatusPublished payload=%s", mustJSON(payload))
	return payload
}

func mustJSON(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return fmt.Sprintf(`{"error":%q}`, err.Error())
	}
	return string(b)
}

// buildPlaybackStatusLocked returns a flat map with all playback telemetry fields.
// Must be called with r.mu held. Never includes file paths or URIs.
func (r *Runtime) buildPlaybackStatusLocked(snap AudioPipelineStatus) map[string]any {
	currentQueueItemID, currentTrackID, currentTitle, currentArtist, currentSource := "", "", "", "", ""
	durationMs := 0
	if ct := r.playback.CurrentTrack; ct != nil {
		currentTrackID = ct.ID
		currentTitle = ct.Title
		currentArtist = ct.Artist
		currentSource = string(ct.Source.Type)
		durationMs = ct.DurationSeconds * 1000
	}
	if currentTrackID != "" {
		for _, item := range r.playback.Queue.Items {
			if item.TrackID == currentTrackID {
				currentQueueItemID = item.QueueItemID
				break
			}
		}
	}
	audioReady, audioBackendStatus, audioBackendMessage := r.audioBackendStatusLocked()
	return map[string]any{
		"playback_state":                        r.playback.State,
		"current_queue_item_id":                 currentQueueItemID,
		"current_track_id":                      currentTrackID,
		"current_title":                         currentTitle,
		"current_artist":                        currentArtist,
		"current_source":                        currentSource,
		"playback_position_ms":                  snap.PlaybackPositionMs,
		"duration_ms":                           durationMs,
		"queue_length":                          len(r.playback.Queue.Items),
		"repeat_mode":                           r.playback.Repeat,
		"shuffle":                               r.playback.Shuffle,
		"decoder_backend":                       snap.DecoderBackend,
		"decoder_status":                        snap.DecoderStatus,
		"output_backend":                        r.pipeline.OutputBackendName(),
		"output_status":                         snap.OutputStatus,
		"frames_processed":                      snap.FramesProcessed,
		"frames_sent":                           snap.FramesSent,
		"frame_interval_ms":                     snap.FrameIntervalMs,
		"late_frames":                           snap.LateFrames,
		"write_latency_ms":                      snap.WriteLatencyMs,
		"last_error":                            firstNonEmpty(r.playback.LastVolumeError, r.playback.LastError, snap.LastError),
		"last_output_error":                     snap.LastOutputError,
		"source_type":                           snap.SourceType,
		"mime_type":                             snap.MimeType,
		"detected_extension":                    snap.DetectedExtension,
		"ffmpeg_path":                           snap.FFmpegPath,
		"ffmpeg_command_without_sensitive_data": snap.FFmpegCommand,
		"ffmpeg_exit_error":                     snap.FFmpegExitError,
		"stderr_summary":                        snap.FFmpegStderr,
		"bytes_read":                            snap.BytesRead,
		"audio_injection_ready":                 audioReady,
		"audio_backend_ready":                   audioReady,
		"audio_backend_status":                  audioBackendStatus,
		"audio_backend_message":                 audioBackendMessage,
		"pcm_writer_mode":                       pcmWriterModeForStatus(audioReady, r.pipeline.OutputBackendName()),
		"frame_duration_ms":                     20,
		"frame_size_bytes":                      3840,
		"output_sample_rate":                    48000,
		"output_channels":                       2,
		"teamspeak_profile":                     r.teamspeakProfileForStatus(),
		"last_state_change_at":                  r.playback.UpdatedAt,
		"panel_volume":                          r.playback.Volume,
		"effective_pulse_volume":                r.playback.EffectivePulseVolume,
		"actual_pulse_volume":                   r.playback.ActualPulseVolume,
		"pulse_sink":                            r.playback.PulseSink,
		"pulse_server":                          r.playback.PulseServer,
		"pulse_source":                          r.playback.PulseSource,
		"volume_mapping":                        r.playback.VolumeMapping,
		"last_volume_change_at":                 r.playback.LastVolumeChangeAt,
		"last_volume_error":                     r.playback.LastVolumeError,
	}
}

func (r *Runtime) audioBackendStatusLocked() (bool, string, string) {
	for _, platform := range []string{"teamspeak", "discord"} {
		connector, ok := r.connectors[platform]
		if !ok {
			continue
		}
		status := connector.GetStatus(context.Background())
		if status.Connected && status.VoiceClientAvailable && status.CapabilityStatus == CapabilityStatusReady {
			return true, "ready", connector.Platform() + " Audio bereit"
		}
		if status.Connected {
			reason := status.StatusMessage
			if reason == "" {
				reason = status.LastError
			}
			if reason == "" {
				reason = "Audio wird vorbereitet"
			}
			return false, "not_ready", connector.Platform() + " verbunden, Audio nicht bereit: " + reason
		}
	}
	// Derive a helpful message based on what is configured.
	if r.config.TeamSpeak.Enabled {
		return false, "not_ready", "TeamSpeak muss verbunden und Backend eingerichtet sein"
	}
	if r.config.Discord.Enabled {
		return false, "not_ready", "Discord muss verbunden und Voice-Backend eingerichtet sein"
	}
	return false, "not_ready", "Kein Connector verbunden"
}

func pcmWriterModeForStatus(audioReady bool, outputBackend string) string {
	if audioReady && (outputBackend == "teamspeak_voice" || outputBackend == "discord_voice") {
		return "persistent"
	}
	return ""
}

func (r *Runtime) teamspeakProfileForStatus() string {
	if connector, ok := r.connectors["teamspeak"].(*TeamSpeakVoiceConnector); ok {
		return connector.GetStatus(context.Background()).Profile
	}
	return normalizeTeamspeakProfile(teamspeakConfigString(r.config.TeamSpeak, "profile"))
}

// buildSafePlaybackLocked returns the playback state with all file URIs stripped.
// Must be called with r.mu held.
func (r *Runtime) buildSafePlaybackLocked() map[string]any {
	var safeCurrentTrack map[string]any
	if ct := r.playback.CurrentTrack; ct != nil {
		safeCurrentTrack = map[string]any{
			"id":               ct.ID,
			"title":            ct.Title,
			"artist":           ct.Artist,
			"duration_seconds": ct.DurationSeconds,
			"source": map[string]any{
				"type":      string(ct.Source.Type),
				"mime_type": ct.Source.MimeType,
			},
			"position_seconds": ct.PositionSeconds,
			"started_at":       ct.StartedAt,
			"metadata":         ct.Metadata,
		}
	}
	safeItems := make([]map[string]any, 0, len(r.playback.Queue.Items))
	for _, item := range r.playback.Queue.Items {
		safeItems = append(safeItems, map[string]any{
			"queue_item_id":    item.QueueItemID,
			"track_id":         item.TrackID,
			"title":            item.Title,
			"artist":           item.Artist,
			"duration_seconds": item.DurationSeconds,
			"source": map[string]any{
				"type":      string(item.Source.Type),
				"mime_type": item.Source.MimeType,
			},
			"metadata": item.Metadata,
		})
	}
	return map[string]any{
		"state":         r.playback.State,
		"current_track": safeCurrentTrack,
		"queue": map[string]any{
			"instance_id":  r.playback.Queue.InstanceID,
			"items":        safeItems,
			"repeat":       r.playback.Queue.Repeat,
			"shuffle":      r.playback.Queue.Shuffle,
			"revision":     r.playback.Queue.Revision,
			"generated_at": r.playback.Queue.GeneratedAt,
		},
		"volume":                 r.playback.Volume,
		"effective_pulse_volume": r.playback.EffectivePulseVolume,
		"actual_pulse_volume":    r.playback.ActualPulseVolume,
		"pulse_sink":             r.playback.PulseSink,
		"pulse_server":           r.playback.PulseServer,
		"pulse_source":           r.playback.PulseSource,
		"volume_mapping":         r.playback.VolumeMapping,
		"last_volume_change_at":  r.playback.LastVolumeChangeAt,
		"last_volume_error":      r.playback.LastVolumeError,
		"shuffle":                r.playback.Shuffle,
		"repeat":                 r.playback.Repeat,
		"updated_at":             r.playback.UpdatedAt,
		"last_command":           r.playback.LastCommand,
	}
}

func (r *Runtime) pluginManifestSummaries() []map[string]any {
	entries, err := os.ReadDir(r.config.PluginDir)
	if err != nil {
		return []map[string]any{}
	}
	manifests := []map[string]any{}
	for _, entry := range entries {
		if !entry.IsDir() || strings.Contains(entry.Name(), "..") || strings.ContainsAny(entry.Name(), `/\`) {
			continue
		}
		path := filepath.Join(r.config.PluginDir, entry.Name(), "manifest.json")
		content, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		var manifest map[string]any
		if err := json.Unmarshal(content, &manifest); err != nil {
			continue
		}
		identifier := strings.TrimSpace(asString(manifest["identifier"]))
		if identifier == "" || strings.Contains(identifier, "..") || strings.ContainsAny(identifier, `/\`) {
			continue
		}
		manifests = append(manifests, map[string]any{
			"identifier": identifier,
			"name":       asString(manifest["name"]),
			"version":    asString(manifest["version"]),
		})
	}
	return manifests
}

func (r *Runtime) streamStatusPayload() WebradioStreamStatus {
	r.mu.Lock()
	out := r.streamOutput
	r.mu.Unlock()
	if out == nil {
		return WebradioStreamStatus{Enabled: r.config.Stream.Enabled}
	}
	return out.Status()
}

func (r *Runtime) handleConnectionStatus(args map[string]any) commandResponse {
	platform := strings.ToLower(strings.TrimSpace(asString(args["platform"])))
	if platform == "" {
		return commandResponse{OK: true, Command: "connection_status", Payload: map[string]any{"connectors": r.connectorStatuses()}}
	}
	connector, ok := r.connectors[platform]
	if !ok {
		return commandResponse{OK: false, Command: "connection_status", Error: "connector is not configured"}
	}
	return commandResponse{OK: true, Command: "connection_status", Payload: map[string]any{"platform": platform, "status": connector.GetStatus(context.Background())}}
}

func (r *Runtime) handlePlayback(command string, args map[string]any) commandResponse {
	r.mu.Lock()
	r.playback.LastCommand = command
	r.playback.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	switch command {
	case "play":
		source, track, err := r.sourceFromPlaybackArgsLocked(args)
		if err != nil {
			r.playback.State = "stopped"
			r.playback.LastError = err.Error()
			playback := r.playback
			r.mu.Unlock()
			r.logger.Printf("playback command=%s state=%s without source: %v", command, playback.State, err)
			return commandResponse{OK: false, Command: command, Error: err.Error(), Payload: map[string]any{"playback": playback}}
		}
		if source.Type == TrackSourceYouTube && strings.TrimSpace(source.URI) != "" && !strings.Contains(source.URI, "googlevideo.com") {
			resolved, resolveErr := resolveYouTubeAudioURL(context.Background(), source.URI)
			if resolveErr != nil {
				r.playback.State = "error"
				r.playback.LastError = resolveErr.Error()
				playback := r.playback
				r.mu.Unlock()
				return commandResponse{OK: false, Command: command, Error: resolveErr.Error(), Payload: map[string]any{"playback": playback}}
			}
			if source.Metadata == nil {
				source.Metadata = map[string]any{}
			}
			source.Metadata["youtube_url"] = source.URI
			source.URI = resolved
		}
		explicitSource := hasExplicitPlaybackSource(args)
		if explicitSource && !isRemoteAudioSource(source) {
			resolved, prepErr := r.pipeline.LoadSource(context.Background(), source)
			if resolved.Reader != nil {
				_ = resolved.Reader.Close()
			}
			if prepErr != nil {
				r.playback.State = "error"
				r.playback.LastError = prepErr.Error()
				playback := r.playback
				r.mu.Unlock()
				r.logger.Printf("playback command=%s state=%s source_error=%v", command, playback.State, prepErr)
				return commandResponse{OK: false, Command: command, Error: prepErr.Error(), Payload: map[string]any{"accepted": false, "playback": playback}}
			}
		}
		if r.playCancel != nil {
			r.playCancel()
		}
		ctx, cancel := context.WithCancel(context.Background())
		r.playCancel = cancel
		r.playback.State = "playing"
		r.playback.LastError = ""
		r.playback.Current = source.URI
		if track != nil {
			copy := *track
			copy.StartedAt = r.playback.UpdatedAt
			r.playback.CurrentTrack = &copy
		}
		volume := float64(r.playback.Volume) / 100.0
		playback := r.playback
		r.mu.Unlock()
		go r.runPipelineTrack(ctx, source, volume)
		r.logger.Printf("playback command=%s state=%s", command, "playing")
		return commandResponse{OK: true, Command: command, Payload: map[string]any{"playback": playback}}
	case "pause":
		if r.playCancel != nil {
			r.playCancel()
			r.playCancel = nil
		}
		r.playback.State = "paused"
	case "resume":
		if r.playback.CurrentTrack != nil && strings.TrimSpace(r.playback.CurrentTrack.Source.URI) != "" {
			source := r.playback.CurrentTrack.Source
			ctx, cancel := context.WithCancel(context.Background())
			r.playCancel = cancel
			volume := float64(r.playback.Volume) / 100.0
			r.playback.State = "playing"
			playback := r.playback
			r.mu.Unlock()
			go r.runPipelineTrack(ctx, source, volume)
			r.logger.Printf("playback command=%s state=%s", command, playback.State)
			return commandResponse{OK: true, Command: command, Payload: map[string]any{"playback": playback}}
		}
		r.playback.State = "playing"
	case "stop":
		if r.playCancel != nil {
			r.playCancel()
			r.playCancel = nil
		}
		r.playback.State = "stopped"
	case "skip":
		if r.playCancel != nil {
			r.playCancel()
			r.playCancel = nil
		}
		r.playback.CurrentTrack = nil
		r.playback.Queue.Current = nil
		if len(r.playback.Queue.Items) > 0 {
			r.playback.Queue.Items = r.playback.Queue.Items[1:]
		}
		if len(r.playback.Queue.Items) > 0 {
			next := r.playback.Queue.Items[0]
			nextTrack := &CurrentTrack{
				ID:              next.TrackID,
				Title:           next.Title,
				Artist:          next.Artist,
				DurationSeconds: next.DurationSeconds,
				Source:          next.Source,
				StartedAt:       r.playback.UpdatedAt,
				Metadata:        next.Metadata,
			}
			r.playback.CurrentTrack = nextTrack
			r.playback.Queue.Current = nextTrack
			r.playback.Current = next.Source.URI
			r.playback.State = "playing"
			ctx, cancel := context.WithCancel(context.Background())
			r.playCancel = cancel
			volume := float64(r.playback.Volume) / 100.0
			r.playback.Queue.GeneratedAt = r.playback.UpdatedAt
			playback := r.playback
			r.mu.Unlock()
			go r.runPipelineTrack(ctx, next.Source, volume)
			r.logger.Printf("playback command=%s state=%s next_track=%s", command, "playing", next.TrackID)
			return commandResponse{OK: true, Command: command, Payload: map[string]any{"playback": playback}}
		}
		r.playback.State = "stopped"
	case "volume":
		panelVolume := r.playback.Volume
		if value, ok := args["volume"]; ok {
			panelVolume = clampVolume(value)
		} else if value, ok := args["value"]; ok {
			panelVolume = clampVolume(value)
		}
		pulseVolume := mapPanelVolumeToPulseVolume(panelVolume)
		r.playback.Volume = panelVolume
		r.playback.EffectivePulseVolume = pulseVolume
		r.playback.VolumeMapping = volumeMappingDescription
		result, err := r.setPulseSinkVolume(context.Background(), pulseVolume)
		r.playback.PulseSink = result.Sink
		r.playback.PulseServer = result.PulseServer
		r.playback.ActualPulseVolume = result.ActualVolume
		if err != nil {
			r.playback.LastError = err.Error()
			r.playback.LastVolumeError = err.Error()
			r.logger.Printf("musicbot-runtime volume_apply_failed panel_volume=%d effective_pulse_volume=%d pulse_sink=%s pulse_server=%s error=%q", panelVolume, pulseVolume, result.Sink, result.PulseServer, err.Error())
		} else {
			r.playback.LastVolumeError = ""
			r.playback.LastVolumeChangeAt = time.Now().UTC().Format(time.RFC3339)
			r.logger.Printf("musicbot-runtime volume_apply panel_volume=%d effective_pulse_volume=%d pulse_sink=%s pulse_server=%s pactl_exit=0 actual_pulse_volume=%d", panelVolume, pulseVolume, result.Sink, result.PulseServer, result.ActualVolume)
		}
	case "seek":
		// The current pipeline is streaming-only; keep the command accepted and
		// reflected in playback state so status refreshes show the live control.
		if position, ok := args["position_ms"]; ok && r.playback.CurrentTrack != nil {
			if r.playback.CurrentTrack.Metadata == nil {
				r.playback.CurrentTrack.Metadata = map[string]any{}
			}
			r.playback.CurrentTrack.Metadata["position_ms"] = asString(position)
		}
	case "shuffle":
		r.playback.Shuffle = !r.playback.Shuffle
		r.playback.Queue.Shuffle = r.playback.Shuffle
	case "repeat":
		if value, ok := args["value"].(string); ok && value != "" {
			r.playback.Repeat = value
		} else {
			r.playback.Repeat = "all"
		}
		r.playback.Queue.Repeat = r.playback.Repeat
	}
	r.playback.Queue.GeneratedAt = r.playback.UpdatedAt
	playback := r.playback
	r.mu.Unlock()
	r.logger.Printf("playback command=%s state=%s", command, playback.State)
	payload := map[string]any{"playback": playback}
	if command == "volume" {
		payload["panel_volume"] = playback.Volume
		payload["pulse_volume"] = playback.EffectivePulseVolume
		payload["effective_pulse_volume"] = playback.EffectivePulseVolume
		payload["actual_pulse_volume"] = playback.ActualPulseVolume
		payload["pulse_sink"] = playback.PulseSink
		payload["pulse_server"] = playback.PulseServer
		payload["pulse_source"] = playback.PulseSource
		payload["volume_mapping"] = playback.VolumeMapping
		payload["last_volume_change_at"] = playback.LastVolumeChangeAt
		payload["last_volume_error"] = playback.LastVolumeError
	}
	return commandResponse{OK: true, Command: command, Payload: payload}
}

func (r *Runtime) sourceFromPlaybackArgsLocked(args map[string]any) (AudioSource, *CurrentTrack, error) {
	sourceType := TrackSourceType(strings.TrimSpace(asString(args["source_type"])))
	var sourceRaw map[string]any
	if raw, ok := args["source"].(map[string]any); ok {
		sourceRaw = raw
		if sourceType == "" {
			sourceType = TrackSourceType(strings.TrimSpace(asString(raw["type"])))
		}
	}
	mimeType := firstNonEmpty(asString(args["mime_type"]), asString(sourceRaw["mime_type"]))
	if raw := firstNonEmpty(asString(args["youtube_url"]), asString(sourceRaw["youtube_url"])); raw != "" && sourceType == TrackSourceYouTube {
		resolved, err := resolveYouTubeAudioURL(context.Background(), raw)
		if err != nil {
			return AudioSource{}, nil, err
		}
		return AudioSource{Type: TrackSourceYouTube, URI: resolved, MimeType: mimeType, Metadata: map[string]any{"youtube_url": raw, "resolved_by": "yt-dlp"}}, nil, nil
	}
	if raw := firstNonEmpty(asString(args["radio_url"]), asString(args["url"]), asString(args["youtube_url"]), asString(sourceRaw["url"]), asString(sourceRaw["uri"])); raw != "" && (sourceType == TrackSourceRadio || sourceType == TrackSourceStream || sourceType == TrackSourceURL || sourceType == TrackSourceYouTube || strings.HasPrefix(raw, "http://") || strings.HasPrefix(raw, "https://")) {
		if sourceType == "" {
			sourceType = TrackSourceRadio
		}
		return AudioSource{Type: sourceType, URI: raw, MimeType: mimeType}, nil, nil
	}
	if raw := firstNonEmpty(asString(args["file_path"]), asString(args["path"]), asString(sourceRaw["file_path"]), asString(sourceRaw["path"]), asString(sourceRaw["uri"])); raw != "" && (sourceType == "" || sourceType == TrackSourceUpload || sourceType == "local_file") {
		return AudioSource{Type: TrackSourceUpload, URI: raw, MimeType: mimeType}, nil, nil
	}
	if raw := asString(args["file"]); raw != "" {
		return AudioSource{Type: TrackSourceUpload, URI: raw}, nil, nil
	}
	if raw := asString(args["track"]); raw != "" {
		return AudioSource{Type: TrackSourceUpload, URI: raw}, nil, nil
	}
	if r.playback.Queue.Current != nil && strings.TrimSpace(r.playback.Queue.Current.Source.URI) != "" {
		return r.playback.Queue.Current.Source, r.playback.Queue.Current, nil
	}
	if len(r.playback.Queue.Items) > 0 && strings.TrimSpace(r.playback.Queue.Items[0].Source.URI) != "" {
		item := r.playback.Queue.Items[0]
		track := &CurrentTrack{ID: item.TrackID, Title: item.Title, Artist: item.Artist, DurationSeconds: item.DurationSeconds, Source: item.Source, Metadata: item.Metadata}
		return item.Source, track, nil
	}
	return AudioSource{}, nil, errors.New("play requires a local track/file path or a queue item")
}

func resolveYouTubeAudioURL(ctx context.Context, youtubeURL string) (string, error) {
	if _, err := exec.LookPath("yt-dlp"); err != nil {
		return "", fmt.Errorf("youtube playback unavailable: install yt-dlp and ensure it is in PATH: %w", err)
	}
	cmdCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	out, err := exec.CommandContext(cmdCtx, "yt-dlp", "--get-url", "--format", "bestaudio/best", "--no-playlist", "--no-warnings", "--quiet", youtubeURL).CombinedOutput()
	if cmdCtx.Err() != nil {
		return "", fmt.Errorf("youtube playback unavailable: yt-dlp timed out")
	}
	if err != nil {
		return "", fmt.Errorf("youtube playback unavailable: yt-dlp failed: %s", strings.TrimSpace(string(out)))
	}
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "http://") || strings.HasPrefix(line, "https://") {
			return line, nil
		}
	}
	return "", errors.New("youtube playback unavailable: yt-dlp returned no audio stream URL")
}

// StartStreamServer starts the webradio HTTP stream server if configured and enabled.
// It returns immediately; the server shuts down when ctx is cancelled.
func (r *Runtime) StartStreamServer(ctx context.Context) error {
	if !r.config.Stream.Enabled || r.config.Stream.Port == 0 {
		return nil
	}
	out := NewWebradioStreamOutput(r.config.Stream, r.logger)
	if err := out.Start(ctx); err != nil {
		return fmt.Errorf("start stream server: %w", err)
	}
	r.mu.Lock()
	r.streamOutput = out
	r.mu.Unlock()
	return nil
}

func (r *Runtime) selectAudioOutput(ctx context.Context) {
	var primary AudioOutput = NullAudioOutput{}
	// Iterate in priority order: TeamSpeak first (default), Discord as fallback.
	for _, platform := range []string{"teamspeak", "discord"} {
		connector, ok := r.connectors[platform]
		if !ok {
			continue
		}
		if out := connector.CreateAudioOutput(ctx); out != nil {
			if _, isNull := out.(NullAudioOutput); !isNull {
				primary = out
				break
			}
		}
	}
	r.mu.Lock()
	streamOut := r.streamOutput
	r.mu.Unlock()
	if streamOut != nil && streamOut.IsRunning() {
		r.pipeline.SetOutput(NewFanOutAudioOutput(r.logger, primary, streamOut))
	} else {
		r.pipeline.SetOutput(primary)
	}
}

func (r *Runtime) runPipelineTrack(ctx context.Context, source AudioSource, volume float64) {
	r.selectAudioOutput(ctx)
	err := r.pipeline.ProcessWithVolume(ctx, source, volume)
	r.mu.Lock()
	if errors.Is(err, context.Canceled) {
		r.mu.Unlock()
		return
	}
	if err != nil {
		r.playback.State = "error"
		r.playback.LastError = err.Error()
		r.logger.Printf("audio pipeline error: %v", err)
		r.mu.Unlock()
		return
	}
	if r.playback.State != "playing" {
		r.mu.Unlock()
		return
	}
	// Track finished naturally — advance queue
	if len(r.playback.Queue.Items) > 0 {
		r.playback.Queue.Items = r.playback.Queue.Items[1:]
	}
	r.playback.CurrentTrack = nil
	r.playback.Queue.Current = nil
	if len(r.playback.Queue.Items) == 0 {
		r.playback.State = "stopped"
		r.playback.Queue.GeneratedAt = time.Now().UTC().Format(time.RFC3339)
		r.logger.Printf("playback track.finished queue.empty")
		r.mu.Unlock()
		return
	}
	next := r.playback.Queue.Items[0]
	nextVolume := float64(r.playback.Volume) / 100.0
	nextTrack := &CurrentTrack{
		ID:              next.TrackID,
		Title:           next.Title,
		Artist:          next.Artist,
		DurationSeconds: next.DurationSeconds,
		Source:          next.Source,
		StartedAt:       time.Now().UTC().Format(time.RFC3339),
		Metadata:        next.Metadata,
	}
	r.playback.CurrentTrack = nextTrack
	r.playback.Queue.Current = nextTrack
	r.playback.Current = next.Source.URI
	r.playback.Queue.GeneratedAt = time.Now().UTC().Format(time.RFC3339)
	ctx2, cancel := context.WithCancel(context.Background())
	r.playCancel = cancel
	r.logger.Printf("playback track.finished auto-advance next=%s", next.TrackID)
	r.mu.Unlock()
	go r.runPipelineTrack(ctx2, next.Source, nextVolume)
}

func (r *Runtime) handleQueueSync(args map[string]any) commandResponse {
	queueData, ok := args["queue"].(map[string]any)
	if !ok {
		return commandResponse{OK: false, Command: "queue.sync", Error: "queue.sync requires a queue object in args"}
	}

	// Verify instance ID matches to prevent cross-instance data injection
	if instanceID, ok := queueData["instance_id"].(string); ok && instanceID != "" && instanceID != r.config.InstanceID {
		return commandResponse{OK: false, Command: "queue.sync", Error: "queue.sync instance_id mismatch"}
	}

	itemsRaw, _ := queueData["items"].([]any)
	items := make([]QueueTrack, 0, len(itemsRaw))
	for _, raw := range itemsRaw {
		item, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		sourceRaw, _ := item["source"].(map[string]any)
		sourceType := TrackSourceType(strings.TrimSpace(asString(sourceRaw["type"])))
		uri := strings.TrimSpace(firstNonEmpty(asString(sourceRaw["uri"]), asString(sourceRaw["url"]), asString(sourceRaw["youtube_url"])))
		if uri == "" || (sourceType != TrackSourceUpload && sourceType != TrackSourceRadio && sourceType != TrackSourceYouTube) {
			continue
		}
		metadata, _ := item["metadata"].(map[string]any)
		items = append(items, QueueTrack{
			QueueItemID:     asString(item["queue_item_id"]),
			TrackID:         asString(item["track_id"]),
			Title:           asString(item["title"]),
			Artist:          asString(item["artist"]),
			DurationSeconds: asInt(item["duration_seconds"]),
			Source: AudioSource{
				Type:     sourceType,
				URI:      uri,
				MimeType: asString(sourceRaw["mime_type"]),
			},
			Metadata: metadata,
		})
	}

	r.mu.Lock()
	defer r.mu.Unlock()

	// Keep CurrentTrack if its TrackID still appears in the new queue
	currentTrack := r.playback.CurrentTrack
	if currentTrack != nil {
		found := false
		for _, item := range items {
			if item.TrackID == currentTrack.ID {
				found = true
				break
			}
		}
		if !found {
			currentTrack = nil
		}
	}

	r.playback.Queue.Items = items
	r.playback.Queue.InstanceID = r.config.InstanceID
	r.playback.Queue.Revision++
	r.playback.Queue.GeneratedAt = time.Now().UTC().Format(time.RFC3339)
	r.playback.Queue.Current = currentTrack
	r.playback.CurrentTrack = currentTrack
	if currentTrack == nil && len(items) == 0 && r.playback.State == "stopped" {
		r.playback.Current = ""
	}
	r.playback.UpdatedAt = time.Now().UTC().Format(time.RFC3339)

	r.logger.Printf("queue.sync instance=%s items=%d revision=%d", r.config.InstanceID, len(items), r.playback.Queue.Revision)
	return commandResponse{OK: true, Command: "queue.sync", Payload: map[string]any{
		"synced":   true,
		"items":    len(items),
		"revision": r.playback.Queue.Revision,
		"playback": r.playback,
	}}
}

const volumeMappingDescription = "0=>0, 1=>15, 100=>115"

func mapPanelVolumeToPulseVolume(panelVolume int) int {
	if panelVolume < 0 {
		panelVolume = 0
	}
	if panelVolume > 100 {
		panelVolume = 100
	}
	if panelVolume == 0 {
		return 0
	}
	pulseVolume := 15 + ((panelVolume - 1) * 100 / 99)
	if pulseVolume < 0 {
		return 0
	}
	if pulseVolume > 115 {
		return 115
	}
	return pulseVolume
}

type pulseVolumeApplyResult struct {
	Sink         string
	PulseServer  string
	ActualVolume int
}

func (r *Runtime) setPulseSinkVolume(ctx context.Context, pulseVolume int) (pulseVolumeApplyResult, error) {
	result := pulseVolumeApplyResult{PulseServer: r.instancePulseServer()}
	sink, discoveryOutput, discoveryErr := r.activeMusicPulseSink(ctx, result.PulseServer)
	result.Sink = sink
	if strings.Contains(sink, "blackhole") {
		return result, fmt.Errorf("PulseAudio sink volume unavailable: refusing blackhole sink %s", sink)
	}
	if sink == "" {
		details := strings.TrimSpace(discoveryOutput)
		if details == "" {
			details = "<empty>"
		}
		if discoveryErr != nil {
			return result, fmt.Errorf("PulseAudio sink volume unavailable: no active easywi_sink_* sink found (blackhole ignored); pulse_server=%s; pactl list short sinks error=%v; output=%s", result.PulseServer, discoveryErr, details)
		}
		return result, fmt.Errorf("PulseAudio sink volume unavailable: no active easywi_sink_* sink found (blackhole ignored); pulse_server=%s; pactl list short sinks output=%s", result.PulseServer, details)
	}
	if result.PulseServer == "" {
		return result, errors.New("PulseAudio sink volume unavailable: no instance PulseAudio socket configured")
	}
	if pulseVolume < 0 {
		pulseVolume = 0
	}
	if pulseVolume > 115 {
		pulseVolume = 115
	}
	env := append(os.Environ(), "PULSE_SERVER="+result.PulseServer)
	cmd := execCommandContext(ctx, "pactl", "set-sink-volume", sink, fmt.Sprintf("%d%%", pulseVolume))
	cmd.Env = env
	output, err := cmd.CombinedOutput()
	if err != nil {
		msg := strings.TrimSpace(string(output))
		if msg != "" {
			return result, fmt.Errorf("set PulseAudio sink volume %s to %d%%: %w: %s", sink, pulseVolume, err, msg)
		}
		return result, fmt.Errorf("set PulseAudio sink volume %s to %d%%: %w", sink, pulseVolume, err)
	}
	result.ActualVolume = pulseVolume
	if actual, err := r.readPulseSinkVolume(ctx, env, sink); err == nil && actual >= 0 {
		result.ActualVolume = actual
	}
	return result, nil
}

func (r *Runtime) activeMusicPulseSink(ctx context.Context, pulseServer string) (string, string, error) {
	if connector, ok := r.connectors["teamspeak"].(*TeamSpeakVoiceConnector); ok {
		if external, ok := connector.voiceClient.(*ExternalBridgeTeamspeakVoiceClient); ok {
			if sink, source, pulseSocket := external.pulseAudioStateFromBridgeLogs(); strings.TrimSpace(sink) != "" {
				r.playback.PulseSink = strings.TrimSpace(sink)
				r.playback.PulseSource = strings.TrimSpace(source)
				if strings.TrimSpace(pulseSocket) != "" {
					r.playback.PulseServer = resolvePulseServer(r.installPathForPulseServer(), pulseSocket)
				}
				return strings.TrimSpace(sink), "", nil
			}
		}
	}
	cached := strings.TrimSpace(firstNonEmpty(
		r.playback.PulseSink,
		teamspeakConfigString(r.config.TeamSpeak, "playback_device"),
		teamspeakConfigString(r.config.TeamSpeak, "pulse_sink"),
		teamspeakConfigString(r.config.TeamSpeak, "sink"),
	))
	if cached != "" {
		return cached, "", nil
	}
	if pulseServer == "" {
		return "", "", nil
	}
	output, err := r.listPulseSinks(ctx, pulseServer)
	if sink := findMusicSink(output); sink != "" {
		r.playback.PulseSink = sink
		return sink, output, nil
	}
	return "", output, err
}

func (r *Runtime) instancePulseServer() string {
	if connector, ok := r.connectors["teamspeak"].(*TeamSpeakVoiceConnector); ok {
		if external, ok := connector.voiceClient.(*ExternalBridgeTeamspeakVoiceClient); ok {
			if sink, source, pulseSocket := external.pulseAudioStateFromBridgeLogs(); strings.TrimSpace(pulseSocket) != "" {
				r.playback.PulseSink = strings.TrimSpace(sink)
				r.playback.PulseSource = strings.TrimSpace(source)
				r.playback.PulseServer = resolvePulseServer(r.installPathForPulseServer(), pulseSocket)
				return r.playback.PulseServer
			}
		}
	}
	return resolvePulseServer(r.installPathForPulseServer(), teamspeakConfigString(r.config.TeamSpeak, "pulse_socket"))
}

func (r *Runtime) installPathForPulseServer() string {
	return strings.TrimSpace(firstNonEmpty(
		teamspeakConfigString(r.config.TeamSpeak, "runtime_dir"),
		r.config.TeamSpeak.RuntimeDir,
		r.config.InstallPath,
		teamspeakConfigString(r.config.TeamSpeak, "instance_path"),
		r.config.TeamSpeak.InstancePath,
	))
}

func resolvePulseServer(installPath, configuredPulseSocket string) string {
	socket := strings.TrimSpace(configuredPulseSocket)
	if strings.HasPrefix(socket, "unix:") {
		return socket
	}
	if socket == "" {
		if strings.TrimSpace(installPath) == "" {
			return ""
		}
		return "unix:" + filepath.Join(trimBridgeRuntimeSuffix(installPath), "runtime", "teamspeak-bridge", "pulse", "pulse.sock")
	}
	if filepath.IsAbs(socket) {
		return "unix:" + removeDuplicateBridgeRuntime(filepath.Clean(socket))
	}
	return "unix:" + removeDuplicateBridgeRuntime(filepath.Join(trimBridgeRuntimeSuffix(installPath), socket))
}

func trimBridgeRuntimeSuffix(path string) string {
	path = filepath.Clean(strings.TrimSpace(path))
	suffix := filepath.Join("runtime", "teamspeak-bridge")
	if strings.HasSuffix(path, suffix) {
		return strings.TrimSuffix(path, suffix)
	}
	return path
}

func removeDuplicateBridgeRuntime(path string) string {
	dup := filepath.Join("runtime", "teamspeak-bridge", "runtime", "teamspeak-bridge")
	for strings.Contains(path, dup) {
		path = strings.ReplaceAll(path, dup, filepath.Join("runtime", "teamspeak-bridge"))
	}
	return path
}

func (r *Runtime) listPulseSinks(ctx context.Context, pulseServer string) (string, error) {
	cmd := execCommandContext(ctx, "pactl", "list", "short", "sinks")
	cmd.Env = append(os.Environ(), "PULSE_SERVER="+pulseServer)
	output, err := cmd.CombinedOutput()
	return string(output), err
}

func findMusicSink(output string) string {
	var first string
	for _, line := range strings.Split(output, "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		name := fields[1]
		if !strings.HasPrefix(name, "easywi_sink_") || strings.HasPrefix(name, "easywi_ts3_playback_blackhole_") || strings.Contains(name, "blackhole") {
			continue
		}
		if first == "" {
			first = name
		}
		for _, field := range fields[2:] {
			if field == "RUNNING" {
				return name
			}
		}
	}
	return first
}

func (r *Runtime) readPulseSinkVolume(ctx context.Context, env []string, sink string) (int, error) {
	cmd := execCommandContext(ctx, "pactl", "get-sink-volume", sink)
	cmd.Env = env
	output, err := cmd.CombinedOutput()
	if err != nil {
		return -1, err
	}
	fields := strings.Fields(string(output))
	for _, field := range fields {
		field = strings.TrimSpace(field)
		if strings.HasSuffix(field, "%") {
			var value int
			if _, scanErr := fmt.Sscanf(field, "%d%%", &value); scanErr == nil {
				return value, nil
			}
		}
	}
	return -1, errors.New("PulseAudio sink volume unavailable: pactl get-sink-volume returned no percentage")
}

func clampVolume(value any) int {
	volume := 100
	switch typed := value.(type) {
	case float64:
		volume = int(typed)
	case int:
		volume = typed
	case string:
		_, _ = fmt.Sscanf(typed, "%d", &volume)
	}
	if volume < 0 {
		return 0
	}
	if volume > 100 {
		return 100
	}
	return volume
}

func hasExplicitPlaybackSource(args map[string]any) bool {
	if args == nil {
		return false
	}
	for _, key := range []string{"radio_url", "url", "path", "file_path", "file", "track", "source"} {
		if _, ok := args[key]; ok {
			return true
		}
	}
	return false
}
