package logging

import (
	"context"
	"encoding/json"
	"io"
	"sync"
	"time"

	"easywi/agent/internal/trace"
)

type JSONLogger struct {
	writer  io.Writer
	service string
	agentID string
	mu      sync.Mutex
}

type entry struct {
	Level         string         `json:"level"`
	Service       string         `json:"service"`
	Timestamp     string         `json:"timestamp"`
	RequestID     string         `json:"request_id"`
	CorrelationID string         `json:"correlation_id"`
	AgentID       string         `json:"agent_id"`
	Event         string         `json:"event"`
	ErrorCode     string         `json:"error_code"`
	Message       string         `json:"msg"`
	Fields        map[string]any `json:"fields,omitempty"`
}

func NewJSONLogger(writer io.Writer, service, agentID string) *JSONLogger {
	return &JSONLogger{writer: writer, service: service, agentID: agentID}
}

func (l *JSONLogger) Info(ctx context.Context, event, msg string, fields map[string]any) {
	l.log(ctx, "info", event, "", msg, fields)
}

func (l *JSONLogger) Error(ctx context.Context, event, errorCode, msg string, fields map[string]any) {
	l.log(ctx, "error", event, errorCode, msg, fields)
}

func (l *JSONLogger) log(ctx context.Context, level, event, errorCode, msg string, fields map[string]any) {
	requestID, correlationID := trace.IDsFromContext(ctx)
	payload := entry{
		Level:         level,
		Service:       l.service,
		Timestamp:     time.Now().UTC().Format(time.RFC3339Nano),
		RequestID:     requestID,
		CorrelationID: correlationID,
		AgentID:       l.agentID,
		Event:         event,
		ErrorCode:     errorCode,
		Message:       msg,
		Fields:        fields,
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		return
	}

	l.mu.Lock()
	defer l.mu.Unlock()
	_, _ = l.writer.Write(append(encoded, '\n'))
}
