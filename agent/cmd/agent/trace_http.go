package main

import (
	"net/http"

	"easywi/agent/internal/trace"
)

func withTraceContext(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestID, correlationID := trace.Normalize(r.Header.Get(trace.RequestHeader), r.Header.Get(trace.CorrelationHeader))
		r.Header.Set(trace.RequestHeader, requestID)
		r.Header.Set(trace.CorrelationHeader, correlationID)
		w.Header().Set(trace.RequestHeader, requestID)
		w.Header().Set(trace.CorrelationHeader, correlationID)
		next.ServeHTTP(w, r.WithContext(trace.WithIDs(r.Context(), requestID, correlationID)))
	})
}
