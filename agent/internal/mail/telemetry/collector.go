package telemetry

import (
	"context"
	"os"
	"strings"
	"time"
)

type Collector interface {
	Collect(ctx context.Context) (Snapshot, error)
}

type RuntimeCollector struct{}

func NewRuntimeCollector() *RuntimeCollector {
	return &RuntimeCollector{}
}

func (c *RuntimeCollector) Collect(_ context.Context) (Snapshot, error) {
	node := strings.TrimSpace(os.Getenv("EASYWI_AGENT_ID"))
	if node == "" {
		node = "unknown"
	}
	now := time.Now().UTC().Truncate(time.Minute)

	return Snapshot{
		GeneratedAt:   now,
		NodeID:        node,
		WindowSeconds: 60,
		Metrics: []MetricPoint{
			{Name: "queue.depth", Type: "gauge", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now},
			{Name: "queue.deferred", Type: "gauge", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now},
			{Name: "delivery.bounce", Type: "counter", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now},
			{Name: "dkim.failures", Type: "counter", Unit: "events", Value: 0, BucketSize: 60, Timestamp: now},
			{Name: "auth.failures", Type: "counter", Unit: "events", Value: 0, BucketSize: 60, Timestamp: now},
			{Name: "mail.sent", Type: "counter", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now},
		},
		Queue: QueueState{Depth: 0, Deferred: 0, Active: 0},
		Meta:  map[string]any{"source": "agent_mail_telemetry"},
	}, nil
}
