package telemetry

import "time"

type Snapshot struct {
	GeneratedAt   time.Time      `json:"generated_at"`
	NodeID        string         `json:"node_id"`
	WindowSeconds int            `json:"window_seconds"`
	Metrics       []MetricPoint  `json:"metrics"`
	TopSenders    []TopMetric    `json:"top_senders"`
	TopDomains    []TopMetric    `json:"top_domains"`
	Queue         QueueState     `json:"queue"`
	Meta          map[string]any `json:"meta,omitempty"`
}

type MetricPoint struct {
	Name       string            `json:"name"`
	Type       string            `json:"type"`
	Unit       string            `json:"unit"`
	Value      float64           `json:"value"`
	Labels     map[string]string `json:"labels,omitempty"`
	BucketSize int               `json:"bucket_size_seconds"`
	Timestamp  time.Time         `json:"timestamp"`
}

type TopMetric struct {
	Key   string  `json:"key"`
	Value float64 `json:"value"`
}

type QueueState struct {
	Depth    int `json:"depth"`
	Deferred int `json:"deferred"`
	Active   int `json:"active"`
}
