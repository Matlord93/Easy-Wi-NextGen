package apienvelope

import (
	"encoding/json"
	"net/http"
	"strings"

	"easywi/agent/internal/trace"
)

type ErrorCode string

const (
	ErrorInvalidPayload   ErrorCode = "INVALID_PAYLOAD"
	ErrorValidationFailed ErrorCode = "VALIDATION_FAILED"
	ErrorConflict         ErrorCode = "CONFLICT"
	ErrorNotFound         ErrorCode = "NOT_FOUND"
	ErrorMethodNotAllowed ErrorCode = "METHOD_NOT_ALLOWED"
	ErrorInternal         ErrorCode = "INTERNAL_ERROR"
)

func HTTPStatus(code ErrorCode) int {
	switch code {
	case ErrorInvalidPayload, ErrorValidationFailed:
		return http.StatusBadRequest
	case ErrorConflict:
		return http.StatusConflict
	case ErrorNotFound:
		return http.StatusNotFound
	case ErrorMethodNotAllowed:
		return http.StatusMethodNotAllowed
	default:
		return http.StatusInternalServerError
	}
}

func WriteError(w http.ResponseWriter, r *http.Request, status int, code ErrorCode, message string, details map[string]any) {
	requestID := ""
	if r != nil {
		requestID = strings.TrimSpace(r.Header.Get(trace.RequestHeader))
	}
	payload := map[string]any{
		"error": map[string]any{
			"code":       code,
			"message":    message,
			"request_id": requestID,
		},
	}
	if len(details) > 0 {
		payload["error"].(map[string]any)["details"] = details
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}
