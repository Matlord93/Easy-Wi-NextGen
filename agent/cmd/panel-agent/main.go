package main

import (
    "context"
    "encoding/json"
    "net/http"
    "os"
    "runtime"
    "time"

    "easywi/agent/internal/panelagent/api"
    "easywi/agent/internal/panelagent/logging"
    runt "easywi/agent/internal/panelagent/runtime"
)

func main() {
    logger := logging.New()
    endpoint := getenv("PANEL_ENDPOINT", "http://127.0.0.1:8080")
    token := getenv("PANEL_AGENT_TOKEN", "dev-token")
    agentUUID := getenv("PANEL_AGENT_UUID", "dev-agent")
    cachePath := getenv("PANEL_AGENT_CACHE", "/tmp/panel-agent-cache.json")

    client := api.NewClient(endpoint, token, &http.Client{Timeout: 10 * time.Second})
    backoff := runt.Backoff{Base: 2 * time.Second, Max: 60 * time.Second}

    attempt := 0
    for {
        hb := api.HeartbeatRequest{AgentUUID: agentUUID, Version: "0.1.0", OS: runtime.GOOS, Capabilities: []string{"jobs", "metrics", "backups"}}
        err := client.Heartbeat(context.Background(), hb)
        if err != nil {
            attempt++
            logger.Log("error", "heartbeat_failed", map[string]interface{}{"error": err.Error(), "attempt": attempt})
            if body, mErr := json.Marshal(hb); mErr == nil {
                _ = runt.AppendCache(cachePath, runt.CachedEvent{Type: "heartbeat", Body: body})
            }
            time.Sleep(backoff.Next(attempt))
            continue
        }

        if cached, cErr := runt.LoadCache(cachePath); cErr == nil && len(cached) > 0 {
            logger.Log("info", "replay_cached_events", map[string]interface{}{"count": len(cached)})
            _ = runt.ClearCache(cachePath)
        }

        attempt = 0
        logger.Log("info", "heartbeat_ok", map[string]interface{}{"agent_uuid": agentUUID})
        time.Sleep(5 * time.Second)
    }
}

func getenv(key, fallback string) string {
    value := os.Getenv(key)
    if value == "" {
        return fallback
    }
    return value
}
