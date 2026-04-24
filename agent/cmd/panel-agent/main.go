package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"os/signal"
	"runtime"
	"syscall"
	"time"

	"easywi/agent/internal/panelagent/api"
	"easywi/agent/internal/panelagent/logging"
	runt "easywi/agent/internal/panelagent/runtime"
)

func main() {
	logger := logging.New()
	endpoint, err := getenvRequired("PANEL_ENDPOINT")
	if err != nil {
		logger.Log("error", "missing_config", map[string]interface{}{"error": err.Error()})
		os.Exit(1)
	}
	token, err := getenvRequired("PANEL_AGENT_TOKEN")
	if err != nil {
		logger.Log("error", "missing_config", map[string]interface{}{"error": err.Error()})
		os.Exit(1)
	}
	agentUUID, err := getenvRequired("PANEL_AGENT_UUID")
	if err != nil {
		logger.Log("error", "missing_config", map[string]interface{}{"error": err.Error()})
		os.Exit(1)
	}
	cachePath := getenv("PANEL_AGENT_CACHE", "/tmp/panel-agent-cache.json")

	client := api.NewClient(endpoint, token, &http.Client{Timeout: 10 * time.Second})
	backoff := runt.Backoff{Base: 2 * time.Second, Max: 60 * time.Second}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	attempt := 0
	for {
		select {
		case <-ctx.Done():
			logger.Log("info", "shutdown", map[string]interface{}{"reason": ctx.Err().Error()})
			return
		default:
		}

		hb := api.HeartbeatRequest{AgentUUID: agentUUID, Version: "0.1.0", OS: runtime.GOOS, Capabilities: []string{"jobs", "metrics", "backups"}}
		err = client.Heartbeat(ctx, hb)
		if err != nil {
			attempt++
			logger.Log("error", "heartbeat_failed", map[string]interface{}{"error": err.Error(), "attempt": attempt})
			if body, mErr := json.Marshal(hb); mErr == nil {
				_ = runt.AppendCache(cachePath, runt.CachedEvent{Type: "heartbeat", Body: body})
			}
			if !sleepWithContext(ctx, backoff.Next(attempt)) {
				logger.Log("info", "shutdown", map[string]interface{}{"reason": "terminated_during_backoff"})
				return
			}
			continue
		}

		if cached, cErr := runt.LoadCache(cachePath); cErr == nil && len(cached) > 0 {
			logger.Log("info", "replay_cached_events", map[string]interface{}{"count": len(cached)})
			failed := make([]runt.CachedEvent, 0)
			for _, event := range cached {
				if event.Type != "heartbeat" {
					failed = append(failed, event)
					continue
				}

				var cachedHeartbeat api.HeartbeatRequest
				if uErr := json.Unmarshal(event.Body, &cachedHeartbeat); uErr != nil {
					logger.Log("error", "replay_cached_event_unmarshal_failed", map[string]interface{}{"error": uErr.Error()})
					failed = append(failed, event)
					continue
				}

				if hbErr := client.Heartbeat(ctx, cachedHeartbeat); hbErr != nil {
					logger.Log("error", "replay_cached_event_failed", map[string]interface{}{"error": hbErr.Error()})
					failed = append(failed, event)
				}
			}

			if sErr := runt.SaveCache(cachePath, failed); sErr != nil {
				logger.Log("error", "cache_save_failed", map[string]interface{}{"error": sErr.Error()})
			}
		}

		attempt = 0
		logger.Log("info", "heartbeat_ok", map[string]interface{}{"agent_uuid": agentUUID})
		if !sleepWithContext(ctx, 5*time.Second) {
			logger.Log("info", "shutdown", map[string]interface{}{"reason": "terminated_during_interval"})
			return
		}
	}
}

func getenv(key, fallback string) string {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	return value
}

func getenvRequired(key string) (string, error) {
	value := os.Getenv(key)
	if value == "" {
		return "", errors.New(key + " is required")
	}

	return value, nil
}

func sleepWithContext(ctx context.Context, duration time.Duration) bool {
	timer := time.NewTimer(duration)
	defer timer.Stop()

	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
	}
}
