package metrics

import "time"

type instanceMetricSample struct {
	InstanceID      int
	CPUPercent      *float64
	MemCurrentBytes *int64
	TasksCurrent    *int
	CollectedAt     time.Time
	ErrorCode       string
}

// CollectInstanceMetrics gathers per-instance metrics from platform-specific sources.
func CollectInstanceMetrics() map[string]any {
	samples, supported, reason := collectInstanceMetricsPlatform()
	payloadSamples := make([]map[string]any, 0, len(samples))
	for _, sample := range samples {
		entry := map[string]any{
			"instance_id":  sample.InstanceID,
			"collected_at": sample.CollectedAt.UTC().Format(time.RFC3339),
		}
		if sample.CPUPercent != nil {
			entry["cpu_percent"] = *sample.CPUPercent
		}
		if sample.MemCurrentBytes != nil {
			entry["mem_current_bytes"] = *sample.MemCurrentBytes
		}
		if sample.TasksCurrent != nil {
			entry["tasks_current"] = *sample.TasksCurrent
		}
		if sample.ErrorCode != "" {
			entry["error_code"] = sample.ErrorCode
		}
		payloadSamples = append(payloadSamples, entry)
	}

	return map[string]any{
		"supported": supported,
		"reason":    reason,
		"samples":   payloadSamples,
	}
}
