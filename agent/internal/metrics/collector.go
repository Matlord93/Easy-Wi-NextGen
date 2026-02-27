package metrics

import "time"

const processSampleLimit = 10

// Collect gathers a snapshot of system metrics suitable for heartbeat payloads.
func Collect() map[string]any {
	snapshot := map[string]any{
		"collected_at": time.Now().UTC().Format(time.RFC3339),
	}

	if cpuPercent, err := cpuUsagePercent(); err == nil {
		snapshot["cpu"] = map[string]any{
			"percent": cpuPercent,
		}
	}

	if total, available, usedPercent, err := memoryUsage(); err == nil {
		snapshot["memory"] = map[string]any{
			"total":     total,
			"available": available,
			"used":      total - available,
			"percent":   usedPercent,
		}
	}

	if total, free, usedPercent, err := diskUsage("/"); err == nil {
		snapshot["disk"] = map[string]any{
			"total":   total,
			"free":    free,
			"used":    total - free,
			"percent": usedPercent,
		}
	}

	if sent, received, err := networkCounters(); err == nil {
		snapshot["net"] = map[string]any{
			"bytes_sent": sent,
			"bytes_recv": received,
		}
	}

	if processes, err := processSample(); err == nil {
		snapshot["processes"] = processes
	}

	if load, err := loadAverage(); err == nil {
		snapshot["load"] = load
	}

	if uptimeSeconds, err := uptimeSeconds(); err == nil {
		snapshot["uptime"] = map[string]any{"seconds": uptimeSeconds}
	}

	if count, err := processCount(); err == nil {
		snapshot["process_count"] = count
	}

	if temp, err := temperatureCelsius(); err == nil {
		snapshot["temperature"] = map[string]any{
			"celsius": temp,
		}
	}

	snapshot["instance_metrics"] = CollectInstanceMetrics()
	snapshot["capabilities"] = map[string]any{
		"temperature": snapshot["temperature"] != nil,
		"load":        snapshot["load"] != nil,
		"uptime":      snapshot["uptime"] != nil,
	}

	return snapshot
}
