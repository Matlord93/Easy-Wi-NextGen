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
}

type ConnectorConfig struct {
	Enabled bool           `json:"enabled"`
	Config  map[string]any `json:"config"`
}

type TeamSpeakConnectorConfig struct {
	Enabled         bool           `json:"enabled"`
	Profile         string         `json:"profile,omitempty"`
	Backend         string         `json:"backend,omitempty"`
	Host            string         `json:"host,omitempty"`
	Port            int            `json:"port,omitempty"`
	Nickname        string         `json:"nickname,omitempty"`
	ChannelID       string         `json:"channel_id,omitempty"`
	ServerPassword  string         `json:"server_password,omitempty"`
	ChannelPassword string         `json:"channel_password,omitempty"`
	Config          map[string]any `json:"config,omitempty"`
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
	config     Config
	logger     *log.Logger
	logFile    *os.File
	playback   PlaybackState
	pipeline   *AudioPipeline
	connectors map[string]Connector
	playCancel context.CancelFunc
	mu         sync.Mutex
	started    time.Time
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

func (r *Runtime) Run(ctx context.Context, input io.Reader, output io.Writer) error {
	r.logger.Printf("started instance=%s service=%s", r.config.InstanceID, r.config.ServiceName)
	defer r.logger.Printf("stopped instance=%s service=%s", r.config.InstanceID, r.config.ServiceName)

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
		if err := connector.ValidateConfig(); err != nil {
			return nil, err
		}
		connectors["teamspeak"] = connector
	}
	if config.Discord.Enabled {
		connector := NewDiscordConnector(config.Discord)
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
	defer r.mu.Unlock()
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
		"connectors":     r.connectorStatuses(),
		"playback":       r.playback,
		"audio_pipeline": r.pipeline.Snapshot(),
		"plugins": map[string]any{
			"directory":         r.config.PluginDir,
			"manifests":         r.pluginManifestSummaries(),
			"execution_enabled": false,
		},
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

func (r *Runtime) runPipelineTrack(ctx context.Context, source AudioSource, volume float64) {
	err := r.pipeline.ProcessWithVolume(ctx, source, volume)
	r.mu.Lock()
	defer r.mu.Unlock()
	if errors.Is(err, context.Canceled) {
		return
	}
	if err != nil {
		r.playback.State = "error"
		r.logger.Printf("audio pipeline error: %v", err)
		return
	}
	if r.playback.State == "playing" {
		r.playback.State = "stopped"
	}
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
