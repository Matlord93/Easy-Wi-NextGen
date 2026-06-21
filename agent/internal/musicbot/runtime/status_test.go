package musicbotruntime

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newStatusTestRuntime(t *testing.T) *Runtime {
	t.Helper()
	dir := t.TempDir()
	r, err := New(Config{
		InstanceID:  "test-42",
		CustomerID:  "7",
		ServiceName: "musicbot-test",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	t.Cleanup(func() { _ = r.Close() })
	return r
}

func TestStatusPayloadContainsPlaybackStatus(t *testing.T) {
	t.Parallel()
	r := newStatusTestRuntime(t)

	resp := r.HandleCommand("status")
	if !resp.OK {
		t.Fatalf("status not OK: %v", resp.Error)
	}
	ps, ok := resp.Payload["playback_status"].(map[string]any)
	if !ok {
		t.Fatalf("playback_status missing or wrong type in status payload, got: %T", resp.Payload["playback_status"])
	}

	requiredFields := []string{
		"playback_state", "current_queue_item_id", "current_track_id",
		"current_title", "current_artist", "current_source",
		"playback_position_ms", "duration_ms", "queue_length",
		"repeat_mode", "shuffle", "decoder_backend", "decoder_status",
		"output_backend", "output_status", "frames_processed", "frames_sent",
		"last_output_error", "teamspeak_profile", "last_state_change_at",
	}
	for _, field := range requiredFields {
		if _, exists := ps[field]; !exists {
			t.Errorf("playback_status missing field %q", field)
		}
	}
}

func TestStatusOutputBackendIsNull(t *testing.T) {
	t.Parallel()
	r := newStatusTestRuntime(t)

	resp := r.HandleCommand("status")
	if !resp.OK {
		t.Fatalf("status not OK: %v", resp.Error)
	}
	ps := resp.Payload["playback_status"].(map[string]any)
	if ps["output_backend"] != "null" {
		t.Errorf("output_backend = %q, want %q (NullAudioOutput must be identified as null)", ps["output_backend"], "null")
	}
}

func TestStatusContainsNoFilePaths(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	r, err := New(Config{
		InstanceID:  "test-99",
		CustomerID:  "3",
		ServiceName: "musicbot-pathtest",
		InstallPath: "/srv/musicbot/instance-99",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer func() { _ = r.Close() }()

	// Simulate a queue with a file path
	r.mu.Lock()
	r.playback.Current = "/srv/musicbot/instance-99/data/tracks/secret.mp3"
	r.playback.Queue.Items = []QueueTrack{{
		QueueItemID: "qi-1",
		TrackID:     "t-1",
		Title:       "Secret Track",
		Source:      AudioSource{Type: TrackSourceUpload, URI: "/srv/musicbot/instance-99/data/tracks/secret.mp3", MimeType: "audio/mpeg"},
	}}
	r.mu.Unlock()

	resp := r.HandleCommand("status")
	if !resp.OK {
		t.Fatalf("status not OK: %v", resp.Error)
	}

	// Serialize the full payload to a string for path scanning
	encoded := encodeJSON(t, resp.Payload)
	secretPath := "/srv/musicbot/instance-99/data/tracks/secret.mp3"
	if strings.Contains(encoded, secretPath) {
		t.Errorf("status payload contains file path %q — must not leak server paths", secretPath)
	}

	// audio_pipeline.current_source must be empty
	ap, _ := resp.Payload["audio_pipeline"].(AudioPipelineStatus)
	if ap.CurrentSource != "" {
		t.Errorf("audio_pipeline.current_source = %q, must be empty in status response (struct path)", ap.CurrentSource)
	}
	// Also verify via JSON serialization
	if strings.Contains(encoded, `"current_source"`) && strings.Contains(encoded, "/srv/") {
		t.Errorf("audio_pipeline current_source leaks path in JSON output")
	}
}

func TestStatusSafePlaybackStripsURIs(t *testing.T) {
	t.Parallel()
	r := newStatusTestRuntime(t)

	r.mu.Lock()
	r.playback.CurrentTrack = &CurrentTrack{
		ID:    "t-42",
		Title: "My Song",
		Source: AudioSource{
			Type:     TrackSourceUpload,
			URI:      "/secret/path/to/song.mp3",
			MimeType: "audio/mpeg",
		},
	}
	r.mu.Unlock()

	resp := r.HandleCommand("status")
	encoded := encodeJSON(t, resp.Payload)

	if strings.Contains(encoded, "/secret/path") {
		t.Errorf("status payload leaks file URI in safe playback object")
	}

	// Safe playback current_track must not have a uri field with path
	pb, _ := resp.Payload["playback"].(map[string]any)
	ct, _ := pb["current_track"].(map[string]any)
	src, _ := ct["source"].(map[string]any)
	if uri, ok := src["uri"]; ok && uri != "" {
		t.Errorf("playback.current_track.source.uri = %q, must be empty or absent", uri)
	}
}

func TestStatusPlaybackStatusCurrentSourceIsSourceType(t *testing.T) {
	t.Parallel()
	r := newStatusTestRuntime(t)

	r.mu.Lock()
	r.playback.CurrentTrack = &CurrentTrack{
		ID:    "t-7",
		Title: "Track",
		Source: AudioSource{
			Type:     TrackSourceUpload,
			URI:      "/data/tracks/track.mp3",
			MimeType: "audio/mpeg",
		},
	}
	r.mu.Unlock()

	resp := r.HandleCommand("status")
	ps := resp.Payload["playback_status"].(map[string]any)

	// current_source must be the source type string, not a file path
	if ps["current_source"] != "upload" {
		t.Errorf("playback_status.current_source = %q, want %q (source type, not path)", ps["current_source"], "upload")
	}
}

func encodeJSON(t *testing.T, v any) string {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatalf("json encode: %v", err)
	}
	return string(b)
}
