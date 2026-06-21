package musicbotruntime

import (
	"os"
	"path/filepath"
	"testing"
)

func newTestRuntime(t *testing.T) *Runtime {
	t.Helper()
	dir := t.TempDir()
	rt, err := New(Config{
		InstanceID:  "inst-1",
		CustomerID:  "cust-1",
		ServiceName: "musicbot-test",
		DataDir:     filepath.Join(dir, "data"),
		LogDir:      filepath.Join(dir, "logs"),
		PluginDir:   filepath.Join(dir, "plugins"),
	}, os.Stderr)
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	t.Cleanup(func() { _ = rt.Close() })
	return rt
}

func queueSyncCmd(instanceID string, items []map[string]any) string {
	itemsJSON := "["
	for i, item := range items {
		if i > 0 {
			itemsJSON += ","
		}
		itemsJSON += `{"queue_item_id":"` + asString(item["queue_item_id"]) + `","track_id":"` + asString(item["track_id"]) + `","title":"` + asString(item["title"]) + `","artist":"` + asString(item["artist"]) + `","duration_seconds":` + asString(item["duration_seconds"]) + `,"source":{"type":"upload","uri":"` + asString(item["uri"]) + `","mime_type":"audio/mpeg"},"metadata":{}}`
	}
	itemsJSON += "]"
	return `{"command":"queue.sync","args":{"queue":{"instance_id":"` + instanceID + `","items":` + itemsJSON + `,"revision":0,"generated_at":"2024-01-01T00:00:00Z"}}}`
}

func TestQueueSyncReplacesItems(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	resp := rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "Song A", "artist": "Artist", "duration_seconds": "180", "uri": "tracks/a.mp3"},
		{"queue_item_id": "2", "track_id": "102", "title": "Song B", "artist": "Artist", "duration_seconds": "200", "uri": "tracks/b.mp3"},
	}))
	if !resp.OK {
		t.Fatalf("queue.sync failed: %s", resp.Error)
	}
	if n, ok := resp.Payload["items"].(int); !ok || n != 2 {
		t.Fatalf("items = %v, want 2", resp.Payload["items"])
	}

	rt.mu.Lock()
	items := append([]QueueTrack{}, rt.playback.Queue.Items...)
	rt.mu.Unlock()
	if len(items) != 2 {
		t.Fatalf("queue items = %d, want 2", len(items))
	}
	if items[0].TrackID != "101" {
		t.Fatalf("first track = %s, want 101", items[0].TrackID)
	}
}

func TestQueueSyncOverwritesPreviousQueue(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "Song A", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
		{"queue_item_id": "2", "track_id": "102", "title": "Song B", "artist": "", "duration_seconds": "60", "uri": "tracks/b.mp3"},
	}))

	resp := rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "3", "track_id": "103", "title": "Song C", "artist": "", "duration_seconds": "90", "uri": "tracks/c.mp3"},
	}))
	if !resp.OK {
		t.Fatalf("second queue.sync failed: %s", resp.Error)
	}

	rt.mu.Lock()
	items := append([]QueueTrack{}, rt.playback.Queue.Items...)
	rt.mu.Unlock()
	if len(items) != 1 || items[0].TrackID != "103" {
		t.Fatalf("queue = %+v, want single item 103", items)
	}
}

func TestQueueSyncIncrementsRevision(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	r1 := rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "T", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
	}))
	r2 := rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "2", "track_id": "102", "title": "T2", "artist": "", "duration_seconds": "60", "uri": "tracks/b.mp3"},
	}))
	rev1, _ := r1.Payload["revision"].(uint64)
	rev2, _ := r2.Payload["revision"].(uint64)
	if rev2 <= rev1 {
		t.Fatalf("revision did not increment: %d -> %d", rev1, rev2)
	}
}

func TestQueueSyncEmptyQueueProducesCleanState(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "T", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
	}))
	resp := rt.HandleCommand(queueSyncCmd("inst-1", nil))
	if !resp.OK {
		t.Fatalf("empty queue.sync failed: %s", resp.Error)
	}
	if n, ok := resp.Payload["items"].(int); !ok || n != 0 {
		t.Fatalf("items = %v, want 0", resp.Payload["items"])
	}
	rt.mu.Lock()
	itemCount := len(rt.playback.Queue.Items)
	rt.mu.Unlock()
	if itemCount != 0 {
		t.Fatalf("queue not empty after sync: %d items remain", itemCount)
	}
}

func TestQueueSyncRejectsMismatchedInstanceID(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	resp := rt.HandleCommand(queueSyncCmd("other-instance", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "T", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
	}))
	if resp.OK {
		t.Fatal("expected queue.sync to fail for mismatched instance_id")
	}
}

func TestQueueSyncFiltersNonUploadSources(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	// Send a mix of upload and stream tracks; only upload should be accepted
	resp := rt.HandleCommand(`{"command":"queue.sync","args":{"queue":{"instance_id":"inst-1","items":[` +
		`{"queue_item_id":"1","track_id":"101","title":"Upload","artist":"","duration_seconds":60,"source":{"type":"upload","uri":"tracks/a.mp3","mime_type":"audio/mpeg"},"metadata":{}},` +
		`{"queue_item_id":"2","track_id":"102","title":"Stream","artist":"","duration_seconds":0,"source":{"type":"stream","uri":"http://stream.example","mime_type":"audio/mpeg"},"metadata":{}}` +
		`],"revision":0,"generated_at":"2024-01-01T00:00:00Z"}}}`)
	if !resp.OK {
		t.Fatalf("queue.sync failed: %s", resp.Error)
	}
	if n, ok := resp.Payload["items"].(int); !ok || n != 1 {
		t.Fatalf("items = %v, want 1 (stream filtered)", resp.Payload["items"])
	}
	rt.mu.Lock()
	items := append([]QueueTrack{}, rt.playback.Queue.Items...)
	rt.mu.Unlock()
	if len(items) != 1 || items[0].TrackID != "101" {
		t.Fatalf("queue items = %+v", items)
	}
}

func TestQueueSyncPreservesCurrentTrackWhenStillQueued(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	// Load queue and start playing (without actual audio)
	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "T", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
	}))
	rt.HandleCommand(`{"command":"play"}`)

	// Sync queue still containing track 101
	resp := rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "T", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
		{"queue_item_id": "2", "track_id": "102", "title": "T2", "artist": "", "duration_seconds": "60", "uri": "tracks/b.mp3"},
	}))
	if !resp.OK {
		t.Fatalf("queue.sync failed: %s", resp.Error)
	}
	pb := resp.Payload["playback"].(PlaybackState)
	if pb.CurrentTrack == nil || pb.CurrentTrack.ID != "101" {
		t.Fatalf("CurrentTrack = %v, want 101", pb.CurrentTrack)
	}
}

func TestQueueSyncClearsCurrentTrackWhenRemovedFromQueue(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "T", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
	}))
	rt.HandleCommand(`{"command":"play"}`)

	// Sync without track 101
	resp := rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "2", "track_id": "102", "title": "T2", "artist": "", "duration_seconds": "60", "uri": "tracks/b.mp3"},
	}))
	if !resp.OK {
		t.Fatalf("queue.sync failed: %s", resp.Error)
	}
	pb := resp.Payload["playback"].(PlaybackState)
	if pb.CurrentTrack != nil {
		t.Fatalf("CurrentTrack = %v, want nil (track removed from queue)", pb.CurrentTrack)
	}
}

func TestPlayWithoutArgsStartsFirstQueueItem(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "First", "artist": "A", "duration_seconds": "120", "uri": "tracks/first.mp3"},
	}))

	resp := rt.HandleCommand(`{"command":"play"}`)
	if !resp.OK {
		t.Fatalf("play failed: %s", resp.Error)
	}
	pb, ok := resp.Payload["playback"].(PlaybackState)
	if !ok || pb.State != "playing" {
		t.Fatalf("playback state = %+v", resp.Payload["playback"])
	}
	if pb.CurrentTrack == nil || pb.CurrentTrack.ID != "101" {
		t.Fatalf("CurrentTrack = %v, want track 101", pb.CurrentTrack)
	}
}

func TestSkipAdvancesToNextQueueItem(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "First", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
		{"queue_item_id": "2", "track_id": "102", "title": "Second", "artist": "", "duration_seconds": "60", "uri": "tracks/b.mp3"},
	}))
	rt.HandleCommand(`{"command":"play"}`)

	resp := rt.HandleCommand(`{"command":"skip"}`)
	if !resp.OK {
		t.Fatalf("skip failed: %s", resp.Error)
	}
	pb := resp.Payload["playback"].(PlaybackState)
	if pb.State != "playing" {
		t.Fatalf("state = %s, want playing", pb.State)
	}
	if pb.CurrentTrack == nil || pb.CurrentTrack.ID != "102" {
		t.Fatalf("CurrentTrack = %v, want track 102", pb.CurrentTrack)
	}
}

func TestSkipOnEmptyQueueSetsStoppedState(t *testing.T) {
	t.Parallel()
	rt := newTestRuntime(t)

	rt.HandleCommand(queueSyncCmd("inst-1", []map[string]any{
		{"queue_item_id": "1", "track_id": "101", "title": "Only", "artist": "", "duration_seconds": "60", "uri": "tracks/a.mp3"},
	}))
	rt.HandleCommand(`{"command":"play"}`)

	resp := rt.HandleCommand(`{"command":"skip"}`)
	if !resp.OK {
		t.Fatalf("skip failed: %s", resp.Error)
	}
	pb := resp.Payload["playback"].(PlaybackState)
	if pb.State != "stopped" {
		t.Fatalf("state = %s, want stopped after skipping last item", pb.State)
	}
	if pb.CurrentTrack != nil {
		t.Fatalf("CurrentTrack = %v, want nil", pb.CurrentTrack)
	}
}
