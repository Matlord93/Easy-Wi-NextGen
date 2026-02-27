package runtime

import (
    "encoding/json"
    "os"
)

type CachedEvent struct {
    Type string          `json:"type"`
    Body json.RawMessage `json:"body"`
}

func AppendCache(path string, event CachedEvent) error {
    events, _ := LoadCache(path)
    events = append(events, event)
    payload, err := json.Marshal(events)
    if err != nil {
        return err
    }
    return os.WriteFile(path, payload, 0o600)
}

func LoadCache(path string) ([]CachedEvent, error) {
    data, err := os.ReadFile(path)
    if err != nil {
        if os.IsNotExist(err) {
            return []CachedEvent{}, nil
        }
        return nil, err
    }

    var events []CachedEvent
    if err := json.Unmarshal(data, &events); err != nil {
        return nil, err
    }

    return events, nil
}

func ClearCache(path string) error {
    return os.Remove(path)
}
