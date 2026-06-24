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
	State        string        `json:"state"`
	Current      string        `json:"current,omitempty"`
	CurrentTrack *CurrentTrack `json:"current_track,omitempty"`
	Queue        QueueSnapshot `json:"queue"`
	Volume       int           `json:"volume"`
	Shuffle      bool          `json:"shuffle"`
	Repeat       string        `json:"repeat"`
	UpdatedAt    string        `json:"updated_at"`
	LastCommand  string        `json:"last_command,omitempty"`
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
			State:  "stopped",
			Volume: 100,
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

// autoConnectAll connects all enabled connectors and auto-joins the configured
// TeamSpeak channel. Context cancellation (e.g. from a racing shutdown) is
// treated as a clean exit, not a logged error.
func (r *Runtime) autoConnectAll(ctx context.Context) {
	if ctx.Err() != nil {
		return
	}
	for platform, connector := range r.connectors {
		if ctx.Err() != nil {
			return
		}
		if platform == "teamspeak" && !r.config.TeamSpeak.Autoconnect {
			continue
		}
		if platform == "teamspeak" && teamspeakBackendType(r.config.TeamSpeak) == TeamSpeakBackendTypeExternalClientBridge {
			r.logger.Printf("external client bridge starting")
		}
		if err := connector.Connect(ctx); err != nil {
			if errors.Is(err, context.Canceled) {
				return
			}
			r.logger.Printf("auto-connect %s: %v", platform, err)
			continue
		}
		if platform == "teamspeak" && teamspeakBackendType(r.config.TeamSpeak) == TeamSpeakBackendTypeExternalClientBridge {
			r.logger.Printf("teamspeak auto-connect started")
		} else {
			r.logger.Printf("auto-connect %s: ok", platform)
		}
		if platform == "teamspeak" {
			channelID := teamspeakConfigString(r.config.TeamSpeak, "channel_id")
			if channelID == "" {
				continue
			}
			if err := connector.JoinChannel(ctx, channelID); err != nil {
				if errors.Is(err, context.Canceled) {
					return
				}
				r.logger.Printf("auto-join teamspeak channel %s: %v", channelID, err)
			} else {
				r.logger.Printf("auto-join teamspeak channel %s: ok", channelID)
			}
		}
	}
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
	case "play", "pause", "resume", "stop", "skip", "volume", "shuffle", "repeat":
		return r.handlePlayback(command, request.Args)
	case "queue.sync":
		return r.handleQueueSync(request.Args)
	default:
		return commandResponse{OK: false, Command: command, Error: "unsupported command"}
	}
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

func (r *Runtime) statusPayload() map[string]any {
	r.mu.Lock()
	snap := r.pipeline.Snapshot()
	snap.CurrentSource = "" // strip absolute path — must not leak
	playbackStatus := r.buildPlaybackStatusLocked(snap)
	safePlayback := r.buildSafePlaybackLocked()
	r.mu.Unlock()
	return map[string]any{
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
	}
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
	return map[string]any{
		"playback_state":        r.playback.State,
		"current_queue_item_id": currentQueueItemID,
		"current_track_id":      currentTrackID,
		"current_title":         currentTitle,
		"current_artist":        currentArtist,
		"current_source":        currentSource,
		"playback_position_ms":  snap.PlaybackPositionMs,
		"duration_ms":           durationMs,
		"queue_length":          len(r.playback.Queue.Items),
		"repeat_mode":           r.playback.Repeat,
		"shuffle":               r.playback.Shuffle,
		"decoder_backend":       snap.DecoderBackend,
		"decoder_status":        snap.DecoderStatus,
		"output_backend":        r.pipeline.OutputBackendName(),
		"output_status":         snap.OutputStatus,
		"frames_processed":      snap.FramesProcessed,
		"frames_sent":           snap.FramesSent,
		"last_error":            snap.LastError,
		"last_output_error":     snap.LastOutputError,
		"teamspeak_profile":     r.teamspeakProfileForStatus(),
		"last_state_change_at":  r.playback.UpdatedAt,
	}
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
		"volume":       r.playback.Volume,
		"shuffle":      r.playback.Shuffle,
		"repeat":       r.playback.Repeat,
		"updated_at":   r.playback.UpdatedAt,
		"last_command": r.playback.LastCommand,
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
			r.playback.State = "playing"
			playback := r.playback
			r.mu.Unlock()
			r.logger.Printf("playback command=%s state=%s without source: %v", command, playback.State, err)
			return commandResponse{OK: true, Command: command, Payload: map[string]any{"playback": playback}}
		}
		if r.playCancel != nil {
			r.playCancel()
		}
		ctx, cancel := context.WithCancel(context.Background())
		r.playCancel = cancel
		r.playback.State = "playing"
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
		if value, ok := args["value"]; ok {
			r.playback.Volume = clampVolume(value)
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
	return commandResponse{OK: true, Command: command, Payload: map[string]any{"playback": playback}}
}

func (r *Runtime) sourceFromPlaybackArgsLocked(args map[string]any) (AudioSource, *CurrentTrack, error) {
	if raw := asString(args["path"]); raw != "" {
		return AudioSource{Type: TrackSourceUpload, URI: raw}, nil, nil
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
	if connector, ok := r.connectors["teamspeak"].(*TeamSpeakVoiceConnector); ok {
		status := connector.GetStatus(ctx)
		if status.VoiceClientAvailable && status.CapabilityStatus == CapabilityStatusReady {
			primary = NewTeamspeakAudioOutputFromConnector(connector)
		}
	}
	if _, isNull := primary.(NullAudioOutput); isNull {
		if connector, ok := r.connectors["discord"].(*DiscordConnector); ok {
			status := connector.GetStatus(ctx)
			if status.VoiceClientAvailable && status.CapabilityStatus == CapabilityStatusReady {
				primary = NewDiscordAudioOutputWithConfig(connector.voiceClient, connector.config.Config)
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
		uri := strings.TrimSpace(asString(sourceRaw["uri"]))
		// Only accept local upload tracks — no external sources
		if sourceType != TrackSourceUpload || uri == "" {
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
