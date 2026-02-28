package logstream

import "time"

type Event struct {
	CreatedAt time.Time      `json:"created_at"`
	Level     string         `json:"level"`
	Source    string         `json:"source"`
	Domain    string         `json:"domain"`
	UserID    *int           `json:"user_id,omitempty"`
	EventType string         `json:"event_type"`
	Message   string         `json:"message"`
	Payload   map[string]any `json:"payload"`
}

type Batch struct {
	Events []Event `json:"events"`
}
