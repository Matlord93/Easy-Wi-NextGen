package musicbotruntime

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
)

const TeamSpeakIntegrationPluginIdentifier = "easywi.teamspeak.integration"

var supportedTeamspeakCommands = map[string]string{
	"help": "musicbot.status.read", "play": "musicbot.playback.control", "pause": "musicbot.playback.control",
	"resume": "musicbot.playback.control", "stop": "musicbot.playback.control", "skip": "musicbot.playback.control",
	"queue": "musicbot.queue.read", "volume": "musicbot.playback.control", "shuffle": "musicbot.playback.control",
	"repeat": "musicbot.playback.control", "playlist": "musicbot.playlist.manage", "autodj": "musicbot.autodj.manage",
	"status": "musicbot.status.read",
}

type TeamSpeakIntegrationConfig struct {
	Enabled             bool                `json:"enabled"`
	CommandPrefix       string              `json:"command_prefix"`
	CommandsEnabled     bool                `json:"commands_enabled"`
	EventsEnabled       bool                `json:"events_enabled"`
	AllowedServerGroups []string            `json:"allowed_server_groups,omitempty"`
	DJServerGroups      []string            `json:"dj_server_groups,omitempty"`
	AdminServerGroups   []string            `json:"admin_server_groups,omitempty"`
	ChannelGroups       map[string][]string `json:"channel_groups,omitempty"`
	RateLimitWindowMs   int                 `json:"rate_limit_window_ms,omitempty"`
}

type TeamSpeakTextMessage struct {
	InstanceID    string    `json:"instance_id"`
	ClientID      string    `json:"client_id"`
	Nickname      string    `json:"nickname"`
	Text          string    `json:"text"`
	ServerGroups  []string  `json:"server_groups,omitempty"`
	ChannelGroups []string  `json:"channel_groups,omitempty"`
	At            time.Time `json:"at"`
}

type TeamSpeakCommand struct {
	Name       string
	Args       []string
	Raw        string
	Permission string
	Sender     TeamSpeakTextMessage
}

type TeamSpeakPluginEvent struct {
	Name       string         `json:"name"`
	InstanceID string         `json:"instance_id"`
	ClientID   string         `json:"client_id,omitempty"`
	Payload    map[string]any `json:"payload,omitempty"`
	At         time.Time      `json:"at"`
}

type RuntimeControl interface {
	HandleCommand(line string) commandResponse
}

type TeamSpeakChatSender interface {
	SendTeamSpeakChat(ctx context.Context, clientID string, message string) error
}

type TeamSpeakWorkflowDispatcher interface {
	DispatchTeamSpeakWorkflowEvent(ctx context.Context, event TeamSpeakPluginEvent) error
}

type TeamSpeakCommandRouter struct {
	prefix      string
	permissions *TeamSpeakPermissionMapper
	lastCommand map[string]time.Time
	rateWindow  time.Duration
	mu          sync.Mutex
}

func NewTeamSpeakCommandRouter(prefix string, mapper *TeamSpeakPermissionMapper, rateWindow time.Duration) *TeamSpeakCommandRouter {
	if strings.TrimSpace(prefix) == "" {
		prefix = "!"
	}
	if mapper == nil {
		mapper = NewTeamSpeakPermissionMapper(TeamSpeakIntegrationConfig{})
	}
	if rateWindow <= 0 {
		rateWindow = 750 * time.Millisecond
	}
	return &TeamSpeakCommandRouter{prefix: prefix, permissions: mapper, rateWindow: rateWindow, lastCommand: map[string]time.Time{}}
}

func (r *TeamSpeakCommandRouter) Parse(msg TeamSpeakTextMessage) (TeamSpeakCommand, bool, error) {
	text := strings.TrimSpace(msg.Text)
	if !strings.HasPrefix(text, r.prefix) {
		return TeamSpeakCommand{}, false, nil
	}
	fields := strings.Fields(strings.TrimPrefix(text, r.prefix))
	if len(fields) == 0 {
		return TeamSpeakCommand{}, true, errors.New("empty teamspeak command")
	}
	name := strings.ToLower(fields[0])
	permission, ok := supportedTeamspeakCommands[name]
	if !ok {
		return TeamSpeakCommand{Name: name, Raw: text, Sender: msg}, true, fmt.Errorf("unknown teamspeak command: %s", name)
	}
	cmd := TeamSpeakCommand{Name: name, Args: fields[1:], Raw: text, Permission: permission, Sender: msg}
	if !r.permissions.Allowed(permission, msg.ServerGroups, msg.ChannelGroups) {
		return cmd, true, errors.New("permission denied")
	}
	if !r.allowRate(msg.ClientID, msg.At) {
		return cmd, true, errors.New("rate limit exceeded")
	}
	return cmd, true, nil
}

func (r *TeamSpeakCommandRouter) allowRate(clientID string, at time.Time) bool {
	if at.IsZero() {
		at = time.Now()
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	last := r.lastCommand[clientID]
	if !last.IsZero() && at.Sub(last) < r.rateWindow {
		return false
	}
	r.lastCommand[clientID] = at
	return true
}

type TeamSpeakPermissionMapper struct{ cfg TeamSpeakIntegrationConfig }

func NewTeamSpeakPermissionMapper(cfg TeamSpeakIntegrationConfig) *TeamSpeakPermissionMapper {
	return &TeamSpeakPermissionMapper{cfg: cfg}
}

func (m *TeamSpeakPermissionMapper) Allowed(permission string, serverGroups []string, channelGroups []string) bool {
	if hasGroup(serverGroups, m.cfg.AdminServerGroups) {
		return true
	}
	switch permission {
	case "musicbot.status.read", "musicbot.queue.read":
		return hasGroup(serverGroups, m.cfg.AllowedServerGroups) || hasAnyChannelGroup(channelGroups, m.cfg.ChannelGroups)
	case "musicbot.playback.control", "musicbot.playlist.manage", "musicbot.autodj.manage":
		return hasGroup(serverGroups, m.cfg.DJServerGroups) || hasAnyChannelGroup(channelGroups, m.cfg.ChannelGroups)
	default:
		return false
	}
}

func hasGroup(actual []string, allowed []string) bool {
	set := map[string]struct{}{}
	for _, group := range allowed {
		if strings.TrimSpace(group) != "" {
			set[strings.TrimSpace(group)] = struct{}{}
		}
	}
	for _, group := range actual {
		if _, ok := set[strings.TrimSpace(group)]; ok {
			return true
		}
	}
	return false
}

func hasAnyChannelGroup(actual []string, allowed map[string][]string) bool {
	for _, groups := range allowed {
		if hasGroup(actual, groups) {
			return true
		}
	}
	return false
}

type TeamSpeakChatResponder struct{ sender TeamSpeakChatSender }

func NewTeamSpeakChatResponder(sender TeamSpeakChatSender) *TeamSpeakChatResponder {
	return &TeamSpeakChatResponder{sender: sender}
}

func (r *TeamSpeakChatResponder) Reply(ctx context.Context, clientID string, message string) error {
	if r == nil || r.sender == nil {
		return nil
	}
	return r.sender.SendTeamSpeakChat(ctx, clientID, sanitizeTeamspeakChat(message))
}

func sanitizeTeamspeakChat(message string) string {
	message = strings.ReplaceAll(message, "\n", " ")
	message = strings.ReplaceAll(message, "\r", " ")
	if len(message) > 500 {
		return message[:500]
	}
	return message
}

type TeamSpeakEventBridge struct{ dispatcher TeamSpeakWorkflowDispatcher }

func NewTeamSpeakEventBridge(dispatcher TeamSpeakWorkflowDispatcher) *TeamSpeakEventBridge {
	return &TeamSpeakEventBridge{dispatcher: dispatcher}
}

func (b *TeamSpeakEventBridge) Dispatch(ctx context.Context, event TeamSpeakPluginEvent) error {
	if event.At.IsZero() {
		event.At = time.Now().UTC()
	}
	if event.Name == "" {
		return errors.New("teamspeak event name is required")
	}
	if b == nil || b.dispatcher == nil {
		return nil
	}
	return b.dispatcher.DispatchTeamSpeakWorkflowEvent(ctx, event)
}

type TeamSpeakIntegrationPlugin struct {
	identifier string
	config     TeamSpeakIntegrationConfig
	router     *TeamSpeakCommandRouter
	events     *TeamSpeakEventBridge
	responder  *TeamSpeakChatResponder
	control    RuntimeControl
	instanceID string
}

func NewTeamSpeakIntegrationPlugin(instanceID string, cfg TeamSpeakIntegrationConfig, control RuntimeControl, chat TeamSpeakChatSender, workflows TeamSpeakWorkflowDispatcher) *TeamSpeakIntegrationPlugin {
	cfg = normalizeTeamSpeakIntegrationConfig(cfg)
	mapper := NewTeamSpeakPermissionMapper(cfg)
	return &TeamSpeakIntegrationPlugin{identifier: TeamSpeakIntegrationPluginIdentifier, instanceID: instanceID, config: cfg, control: control, router: NewTeamSpeakCommandRouter(cfg.CommandPrefix, mapper, time.Duration(cfg.RateLimitWindowMs)*time.Millisecond), events: NewTeamSpeakEventBridge(workflows), responder: NewTeamSpeakChatResponder(chat)}
}

func normalizeTeamSpeakIntegrationConfig(cfg TeamSpeakIntegrationConfig) TeamSpeakIntegrationConfig {
	if strings.TrimSpace(cfg.CommandPrefix) == "" {
		cfg.CommandPrefix = "!"
	}
	if cfg.RateLimitWindowMs <= 0 {
		cfg.RateLimitWindowMs = 750
	}
	return cfg
}

func (p *TeamSpeakIntegrationPlugin) FirstParty() bool   { return true }
func (p *TeamSpeakIntegrationPlugin) Removable() bool    { return false }
func (p *TeamSpeakIntegrationPlugin) Identifier() string { return p.identifier }

func (p *TeamSpeakIntegrationPlugin) HandleTextMessage(ctx context.Context, msg TeamSpeakTextMessage) error {
	if p == nil || !p.config.Enabled || !p.config.CommandsEnabled {
		return nil
	}
	if msg.InstanceID != "" && p.instanceID != "" && msg.InstanceID != p.instanceID {
		return errors.New("teamspeak command belongs to another musicbot instance")
	}
	cmd, matched, err := p.router.Parse(msg)
	if !matched {
		return nil
	}
	_ = p.events.Dispatch(ctx, TeamSpeakPluginEvent{Name: "text.command", InstanceID: p.instanceID, ClientID: msg.ClientID, Payload: map[string]any{"command": cmd.Name}, At: msg.At})
	if err != nil {
		return p.responder.Reply(ctx, msg.ClientID, err.Error())
	}
	resp := p.forwardCommand(cmd)
	if resp == "" {
		resp = "OK"
	}
	return p.responder.Reply(ctx, msg.ClientID, resp)
}

func (p *TeamSpeakIntegrationPlugin) HandleEvent(ctx context.Context, event TeamSpeakPluginEvent) error {
	if p == nil || !p.config.Enabled || !p.config.EventsEnabled {
		return nil
	}
	if event.InstanceID != "" && p.instanceID != "" && event.InstanceID != p.instanceID {
		return errors.New("teamspeak event belongs to another musicbot instance")
	}
	return p.events.Dispatch(ctx, event)
}

func (p *TeamSpeakIntegrationPlugin) forwardCommand(cmd TeamSpeakCommand) string {
	if cmd.Name == "help" {
		return "Commands: !help !play !pause !resume !stop !skip !queue !volume !shuffle !repeat !playlist !autodj !status"
	}
	if cmd.Name == "queue" {
		return "Queue status requested."
	}
	if p.control == nil {
		return "Command accepted."
	}
	line := cmd.Name
	if len(cmd.Args) > 0 {
		line += " " + strings.Join(cmd.Args, " ")
	}
	response := p.control.HandleCommand(line)
	if !response.OK {
		return response.Error
	}
	return fmt.Sprintf("%s ausgeführt", cmd.Name)
}
