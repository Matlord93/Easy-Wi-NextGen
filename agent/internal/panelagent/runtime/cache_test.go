package runtime

import (
    "encoding/json"
    "path/filepath"
    "testing"
)

func TestCacheAppendLoadClear(t *testing.T) {
    path := filepath.Join(t.TempDir(), "cache.json")
    body, _ := json.Marshal(map[string]string{"ok": "yes"})

    if err := AppendCache(path, CachedEvent{Type: "heartbeat", Body: body}); err != nil {
        t.Fatal(err)
    }

    events, err := LoadCache(path)
    if err != nil {
        t.Fatal(err)
    }
    if len(events) != 1 {
        t.Fatalf("expected one event, got %d", len(events))
    }

    if err := ClearCache(path); err != nil {
        t.Fatal(err)
    }
}
