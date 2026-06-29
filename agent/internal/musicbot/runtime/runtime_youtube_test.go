package musicbotruntime

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestYouTubePlaybackMissingYtDlpIsClear(t *testing.T) {
	t.Setenv("PATH", filepath.Join(t.TempDir(), "empty"))
	rt, err := New(Config{InstanceID: "yt", CustomerID: "c", ServiceName: "musicbot-test", DataDir: t.TempDir(), LogDir: t.TempDir(), PluginDir: t.TempDir()}, discardWriter{})
	if err != nil {
		t.Fatal(err)
	}
	resp := rt.HandleCommand(`{"command":"play","args":{"source_type":"youtube","youtube_url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","source":{"type":"youtube","youtube_url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ"}}}`)
	if resp.OK {
		t.Fatalf("expected missing yt-dlp failure")
	}
	if !strings.Contains(resp.Error, "yt-dlp") {
		t.Fatalf("error = %q, want yt-dlp", resp.Error)
	}
}

func TestTeamSpeakHelpListsSinusbotLikeCommands(t *testing.T) {
	p := NewTeamSpeakIntegrationPlugin("1", TeamSpeakIntegrationConfig{Enabled: true, CommandsEnabled: true}, nil, nil, nil)
	got := p.forwardCommand(TeamSpeakCommand{Name: "help"})
	for _, want := range []string{"!radio <url>", "!yt <url>", "!seek", "!skip/!next", "!volume"} {
		if !strings.Contains(got, want) {
			t.Fatalf("help = %q, missing %q", got, want)
		}
	}
}

type captureRuntimeControl struct{ line string }

func (c *captureRuntimeControl) HandleCommand(line string) commandResponse {
	c.line = line
	return commandResponse{OK: true, Command: "play"}
}

func TestTeamSpeakRadioAndYouTubeCommandsForwardJSONPlayback(t *testing.T) {
	control := &captureRuntimeControl{}
	p := NewTeamSpeakIntegrationPlugin("1", TeamSpeakIntegrationConfig{Enabled: true, CommandsEnabled: true}, control, nil, nil)
	if got := p.forwardCommand(TeamSpeakCommand{Name: "radio", Args: []string{"https://stream.example/live.mp3"}}); !strings.Contains(got, "radio") {
		t.Fatalf("radio response = %q", got)
	}
	if !strings.Contains(control.line, `"source_type":"radio"`) || !strings.Contains(control.line, `"radio_url":"https://stream.example/live.mp3"`) {
		t.Fatalf("radio command line = %s", control.line)
	}
	if got := p.forwardCommand(TeamSpeakCommand{Name: "yt", Args: []string{"https://youtu.be/dQw4w9WgXcQ"}}); !strings.Contains(got, "yt") {
		t.Fatalf("yt response = %q", got)
	}
	if !strings.Contains(control.line, `"source_type":"youtube"`) || !strings.Contains(control.line, `"youtube_url":"https://youtu.be/dQw4w9WgXcQ"`) {
		t.Fatalf("yt command line = %s", control.line)
	}
}
