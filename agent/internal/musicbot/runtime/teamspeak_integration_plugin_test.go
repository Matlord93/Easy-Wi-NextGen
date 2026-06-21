package musicbotruntime

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

func TestTeamSpeakCommandRouterPrefixAndParsing(t *testing.T) {
	mapper := NewTeamSpeakPermissionMapper(TeamSpeakIntegrationConfig{AllowedServerGroups: []string{"10"}, DJServerGroups: []string{"20"}})
	router := NewTeamSpeakCommandRouter("?", mapper, time.Second)
	cmd, matched, err := router.Parse(TeamSpeakTextMessage{ClientID: "1", Text: "?queue", ServerGroups: []string{"10"}, At: time.Now()})
	if err != nil || !matched || cmd.Name != "queue" || cmd.Permission != "musicbot.queue.read" {
		t.Fatalf("Parse() cmd=%#v matched=%v err=%v", cmd, matched, err)
	}
	_, matched, err = router.Parse(TeamSpeakTextMessage{ClientID: "1", Text: "!status", ServerGroups: []string{"10"}, At: time.Now().Add(2 * time.Second)})
	if matched || err != nil {
		t.Fatalf("Parse(non-prefix) matched=%v err=%v", matched, err)
	}
}

func TestTeamSpeakCommandRouterUnknownAndRateLimit(t *testing.T) {
	mapper := NewTeamSpeakPermissionMapper(TeamSpeakIntegrationConfig{AllowedServerGroups: []string{"10"}})
	router := NewTeamSpeakCommandRouter("!", mapper, time.Second)
	_, matched, err := router.Parse(TeamSpeakTextMessage{ClientID: "1", Text: "!doesnotexist", ServerGroups: []string{"10"}, At: time.Now()})
	if !matched || err == nil || !strings.Contains(err.Error(), "unknown") {
		t.Fatalf("unknown command matched=%v err=%v", matched, err)
	}
	at := time.Now().Add(2 * time.Second)
	if _, _, err := router.Parse(TeamSpeakTextMessage{ClientID: "1", Text: "!queue", ServerGroups: []string{"10"}, At: at}); err != nil {
		t.Fatalf("first command err=%v", err)
	}
	_, _, err = router.Parse(TeamSpeakTextMessage{ClientID: "1", Text: "!queue", ServerGroups: []string{"10"}, At: at.Add(100 * time.Millisecond)})
	if err == nil || !strings.Contains(err.Error(), "rate limit") {
		t.Fatalf("rate limit err=%v", err)
	}
}

func TestTeamSpeakPermissionMapping(t *testing.T) {
	mapper := NewTeamSpeakPermissionMapper(TeamSpeakIntegrationConfig{AllowedServerGroups: []string{"10"}, DJServerGroups: []string{"20"}, AdminServerGroups: []string{"99"}})
	if !mapper.Allowed("musicbot.queue.read", []string{"10"}, nil) {
		t.Fatal("allowed group should read queue")
	}
	if mapper.Allowed("musicbot.playback.control", []string{"10"}, nil) {
		t.Fatal("allowed group must not control playback")
	}
	if !mapper.Allowed("musicbot.playback.control", []string{"20"}, nil) {
		t.Fatal("dj group should control playback")
	}
	if !mapper.Allowed("unknown.permission", []string{"99"}, nil) {
		t.Fatal("admin group should pass explicit permission checks")
	}
}

func TestTeamSpeakIntegrationPluginDispatchesWorkflowAndRejectsForeignInstance(t *testing.T) {
	workflow := &recordingWorkflowDispatcher{}
	chat := &recordingChatSender{}
	control := &recordingRuntimeControl{}
	plugin := NewTeamSpeakIntegrationPlugin("instance-a", TeamSpeakIntegrationConfig{Enabled: true, CommandsEnabled: true, EventsEnabled: true, CommandPrefix: "!", AllowedServerGroups: []string{"10"}}, control, chat, workflow)
	msg := TeamSpeakTextMessage{InstanceID: "instance-a", ClientID: "c1", Text: "!status", ServerGroups: []string{"10"}, At: time.Now()}
	if err := plugin.HandleTextMessage(context.Background(), msg); err != nil {
		t.Fatalf("HandleTextMessage() = %v", err)
	}
	if len(workflow.events) != 1 || workflow.events[0].Name != "text.command" {
		t.Fatalf("workflow events = %#v", workflow.events)
	}
	if control.lastCommand != "status" || len(chat.messages) != 1 {
		t.Fatalf("control=%q chat=%#v", control.lastCommand, chat.messages)
	}
	err := plugin.HandleTextMessage(context.Background(), TeamSpeakTextMessage{InstanceID: "other", ClientID: "c2", Text: "!queue", ServerGroups: []string{"10"}})
	if err == nil || !strings.Contains(err.Error(), "another musicbot instance") {
		t.Fatalf("foreign instance err=%v", err)
	}
}

func TestTeamSpeakEventBridgeDispatch(t *testing.T) {
	workflow := &recordingWorkflowDispatcher{}
	bridge := NewTeamSpeakEventBridge(workflow)
	if err := bridge.Dispatch(context.Background(), TeamSpeakPluginEvent{Name: "queue.empty", InstanceID: "i"}); err != nil {
		t.Fatalf("Dispatch() = %v", err)
	}
	if len(workflow.events) != 1 || workflow.events[0].Name != "queue.empty" {
		t.Fatalf("events=%#v", workflow.events)
	}
	if err := bridge.Dispatch(context.Background(), TeamSpeakPluginEvent{}); err == nil {
		t.Fatal("Dispatch() error = nil, want missing name")
	}
}

type recordingChatSender struct{ messages []string }

func (s *recordingChatSender) SendTeamSpeakChat(ctx context.Context, clientID string, message string) error {
	s.messages = append(s.messages, message)
	return nil
}

type recordingWorkflowDispatcher struct{ events []TeamSpeakPluginEvent }

func (d *recordingWorkflowDispatcher) DispatchTeamSpeakWorkflowEvent(ctx context.Context, event TeamSpeakPluginEvent) error {
	d.events = append(d.events, event)
	return nil
}

type recordingRuntimeControl struct{ lastCommand string }

func (c *recordingRuntimeControl) HandleCommand(line string) commandResponse {
	c.lastCommand = line
	return commandResponse{OK: true, Command: line}
}

func TestTeamSpeakChatResponderSanitizesOutput(t *testing.T) {
	sender := &recordingChatSender{}
	responder := NewTeamSpeakChatResponder(sender)
	if err := responder.Reply(context.Background(), "c", "hello\nworld"); err != nil && !errors.Is(err, context.Canceled) {
		t.Fatalf("Reply() = %v", err)
	}
	if sender.messages[0] != "hello world" {
		t.Fatalf("message = %q", sender.messages[0])
	}
}
