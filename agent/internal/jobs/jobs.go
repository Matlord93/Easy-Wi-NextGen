package jobs

import "time"

// Job describes a unit of work retrieved from the API.
type Job struct {
	ID        string            `json:"id"`
	Type      string            `json:"type"`
	Payload   map[string]string `json:"payload"`
	CreatedAt time.Time         `json:"created_at"`
}

// Result describes a completed job payload.
type Result struct {
	JobID     string            `json:"job_id"`
	Status    string            `json:"status"`
	Output    map[string]string `json:"output,omitempty"`
	Completed time.Time         `json:"completed_at"`
}
