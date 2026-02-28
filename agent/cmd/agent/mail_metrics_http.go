package main

import (
	"encoding/json"
	"net/http"

	"easywi/agent/internal/mail/telemetry"
)

var mailMetricsCollector telemetry.Collector = telemetry.NewRuntimeCollector()

func handleMailMetricsHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
		return
	}

	snapshot, err := mailMetricsCollector.Collect(r.Context())
	if err != nil {
		writeJSONError(w, http.StatusServiceUnavailable, "METRICS_UNAVAILABLE", err.Error())
		return
	}

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(snapshot)
}
