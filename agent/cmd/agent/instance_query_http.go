package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"strconv"
	"strings"
	"time"
)

type queryHTTPResponse struct {
	OK        bool            `json:"ok"`
	Data      *queryHTTPData  `json:"data,omitempty"`
	ErrorCode string          `json:"error_code,omitempty"`
	Message   string          `json:"message,omitempty"`
	RequestID string          `json:"request_id"`
	Debug     *queryHTTPDebug `json:"debug,omitempty"`
}

type queryHTTPDebug struct {
	ResolvedHost          string `json:"resolved_host"`
	NetworkMode           string `json:"network_mode,omitempty"`
	ChosenDialHostSource  string `json:"chosen_dial_host_source,omitempty"`
	ResolvedHostSource    string `json:"resolved_host_source,omitempty"`
	LoopbackUsed          bool   `json:"loopback_used"`
	ResolvedPort          int    `json:"resolved_port"`
	ResolvedProtocol      string `json:"resolved_protocol"`
	TimeoutMS             int    `json:"timeout_ms"`
	InstanceGamePort      int    `json:"instance_game_port"`
	InstanceQueryPort     int    `json:"instance_query_port"`
	TemplateQueryPort     int    `json:"template_query_port"`
	TemplateQueryProtocol string `json:"template_query_protocol"`
	LastErrorCode         string `json:"last_error_code,omitempty"`
	LastErrorMessage      string `json:"last_error_message,omitempty"`
	RequestID             string `json:"request_id"`
}

type queryHTTPData struct {
	Status        string `json:"status"`
	PlayersOnline *int   `json:"players_online"`
	PlayersMax    *int   `json:"players_max"`
	Map           string `json:"map"`
	Version       string `json:"version"`
	LatencyMS     int64  `json:"latency_ms"`
}

func handleInstanceQueryHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
		return
	}

	instanceID := strings.TrimPrefix(r.URL.Path, "/v1/instances/")
	instanceID = strings.TrimSuffix(instanceID, "/query")
	instanceID = strings.Trim(instanceID, "/ ")
	if instanceID == "" {
		writeJSONError(w, http.StatusBadRequest, "INVALID_INSTANCE_ID", "instance id is required")
		return
	}

	requestID := strings.TrimSpace(r.Header.Get("X-Request-ID"))
	if queryPayloadDebugEnabled {
		log.Printf("instance query http request: path=%s raw_query=%s request_id=%s", r.URL.Path, r.URL.RawQuery, requestID)
		if r.Body != nil {
			bodyCopy, _ := io.ReadAll(io.LimitReader(r.Body, 8*1024))
			r.Body = io.NopCloser(strings.NewReader(string(bodyCopy)))
			if len(bodyCopy) > 0 {
				log.Printf("instance query http request body: request_id=%s body=%s", requestID, redactSensitiveQueryJSON(bodyCopy))
			}
		}
	}
	payload := parseQueryHTTPPayload(r)
	protocol := normalizeProtocol(queryParamOrPayload(r, payload, "query_protocol"))
	resolution := resolveQueryDialHost(
		queryParamOrPayload(r, payload, "host"),
		queryParamOrPayload(r, payload, "bind_ip"),
		queryParamOrPayload(r, payload, "instance_ip"),
		queryParamOrPayload(r, payload, "node_ip"),
		queryParamOrPayload(r, payload, "local_only"),
		queryParamOrPayload(r, payload, "network_mode"),
		queryParamOrPayload(r, payload, "share_host_network"),
	)
	host := resolution.Host
	port, err := resolveQueryPort(r, payload)
	if err != nil {
		writeQueryEnvelope(w, http.StatusOK, queryHTTPResponse{OK: false, ErrorCode: "INVALID_PORT", Message: err.Error(), RequestID: requestID, Debug: &queryHTTPDebug{ResolvedHost: host, NetworkMode: resolution.NetworkMode, ChosenDialHostSource: resolution.Source, ResolvedHostSource: resolution.Source, LoopbackUsed: resolution.LoopbackUsed, ResolvedProtocol: protocol, LastErrorCode: "INVALID_PORT", LastErrorMessage: err.Error(), RequestID: requestID}})
		return
	}

	if host == "" {
		writeQueryEnvelope(w, http.StatusUnprocessableEntity, queryHTTPResponse{OK: false, ErrorCode: "INVALID_INPUT", Message: "missing required values: host", RequestID: requestID, Debug: &queryHTTPDebug{ResolvedHost: host, NetworkMode: resolution.NetworkMode, ChosenDialHostSource: resolution.Source, ResolvedHostSource: resolution.Source, LoopbackUsed: resolution.LoopbackUsed, ResolvedProtocol: protocol, LastErrorCode: "INVALID_INPUT", LastErrorMessage: "missing required values: host", RequestID: requestID}})
		return
	}

	debug := &queryHTTPDebug{
		ResolvedHost:          host,
		NetworkMode:           resolution.NetworkMode,
		ChosenDialHostSource:  resolution.Source,
		ResolvedHostSource:    resolution.Source,
		LoopbackUsed:          resolution.LoopbackUsed,
		ResolvedPort:          port,
		ResolvedProtocol:      protocol,
		InstanceGamePort:      parseIntOrDefault(queryParamOrPayload(r, payload, "game_port"), 0),
		InstanceQueryPort:     parseIntOrDefault(queryParamOrPayload(r, payload, "query_port"), 0),
		TemplateQueryPort:     parseIntOrDefault(queryParamOrPayload(r, payload, "template_query_port"), 0),
		TemplateQueryProtocol: normalizeProtocol(queryParamOrPayload(r, payload, "template_query_protocol")),
		RequestID:             requestID,
	}

	timeoutMS := parseIntOrDefault(queryParamOrPayload(r, payload, "query_timeout_ms"), 3000)
	if timeoutMS < 250 {
		timeoutMS = 250
	}
	debug.TimeoutMS = timeoutMS
	ctx, cancel := context.WithTimeout(r.Context(), time.Duration(timeoutMS)*time.Millisecond)
	defer cancel()

	started := time.Now()
	lockKey := "instance:" + instanceID
	result := queryHTTPResponse{RequestID: requestID}
	globalInstanceLocks.WithReadLock(lockKey, func() {
		result = performProtocolQuery(ctx, protocol, host, port, requestID, debug)
		if result.Data != nil {
			result.Data.LatencyMS = time.Since(started).Milliseconds()
		}
	})

	if errors.Is(ctx.Err(), context.Canceled) {
		debug.LastErrorCode = "QUERY_CANCELED"
		debug.LastErrorMessage = "query canceled"
		writeQueryEnvelope(w, http.StatusOK, queryHTTPResponse{OK: false, ErrorCode: "QUERY_CANCELED", Message: "query canceled", RequestID: requestID, Debug: debug})
		return
	}
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		debug.LastErrorCode = "QUERY_TIMEOUT"
		debug.LastErrorMessage = "query timed out"
		writeQueryEnvelope(w, http.StatusOK, queryHTTPResponse{OK: false, ErrorCode: "QUERY_TIMEOUT", Message: "query timed out", RequestID: requestID, Debug: debug})
		return
	}

	if result.Debug == nil {
		result.Debug = debug
	}
	writeQueryEnvelope(w, http.StatusOK, result)
}

func parseQueryHTTPPayload(r *http.Request) map[string]string {
	if r.Body == nil {
		return nil
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 32*1024))
	if err != nil || len(body) == 0 {
		return nil
	}
	r.Body = io.NopCloser(strings.NewReader(string(body)))

	var payload map[string]any
	if err := json.Unmarshal(body, &payload); err != nil {
		return nil
	}

	result := map[string]string{}
	for key, value := range payload {
		switch typed := value.(type) {
		case string:
			result[key] = strings.TrimSpace(typed)
		case float64:
			result[key] = strconv.FormatFloat(typed, 'f', -1, 64)
		case bool:
			if typed {
				result[key] = "true"
			} else {
				result[key] = "false"
			}
		}
	}

	return result
}

func queryParamOrPayload(r *http.Request, payload map[string]string, key string) string {
	if value := strings.TrimSpace(r.URL.Query().Get(key)); value != "" {
		return value
	}
	if payload == nil {
		return ""
	}
	return strings.TrimSpace(payload[key])
}

func performProtocolQuery(ctx context.Context, protocol, host string, port int, requestID string, debug *queryHTTPDebug) queryHTTPResponse {
	portStr := strconv.Itoa(port)
	switch protocol {
	case "valve", "source", "source1", "source2", "a2s", "steam_a2s":
		payload, err := runWithContext(ctx, func() (map[string]string, error) { return queryA2S(host, portStr) })
		if err != nil {
			code := resolveQueryErrCode(err)
			debug.LastErrorCode = code
			debug.LastErrorMessage = err.Error()
			return queryHTTPResponse{OK: false, ErrorCode: code, Message: err.Error(), RequestID: requestID, Debug: debug}
		}
		return queryHTTPResponse{OK: true, Data: mapResultPayload("running", payload), RequestID: requestID, Debug: debug}
	case "minecraft", "minecraft_java", "java":
		payload, err := runWithContext(ctx, func() (map[string]string, error) { return queryMinecraftJava(host, portStr) })
		if err != nil {
			code := resolveQueryErrCode(err)
			debug.LastErrorCode = code
			debug.LastErrorMessage = err.Error()
			return queryHTTPResponse{OK: false, ErrorCode: code, Message: err.Error(), RequestID: requestID, Debug: debug}
		}
		return queryHTTPResponse{OK: true, Data: mapResultPayload("running", payload), RequestID: requestID, Debug: debug}
	case "bedrock", "minecraft_bedrock":
		payload, err := runWithContext(ctx, func() (map[string]string, error) { return queryMinecraftBedrock(host, portStr) })
		if err != nil {
			code := resolveQueryErrCode(err)
			debug.LastErrorCode = code
			debug.LastErrorMessage = err.Error()
			return queryHTTPResponse{OK: false, ErrorCode: code, Message: err.Error(), RequestID: requestID, Debug: debug}
		}
		return queryHTTPResponse{OK: true, Data: mapResultPayload("running", payload), RequestID: requestID, Debug: debug}
	case "custom":
		debug.LastErrorCode = "UNSUPPORTED_PROTOCOL"
		debug.LastErrorMessage = "custom protocol handler not implemented"
		return queryHTTPResponse{OK: false, ErrorCode: "UNSUPPORTED_PROTOCOL", Message: "custom protocol handler not implemented", RequestID: requestID, Debug: debug}
	default:
		debug.LastErrorCode = "UNSUPPORTED_PROTOCOL"
		debug.LastErrorMessage = "unsupported query protocol"
		return queryHTTPResponse{OK: false, ErrorCode: "UNSUPPORTED_PROTOCOL", Message: "unsupported query protocol", RequestID: requestID, Debug: debug}
	}
}

func runWithContext(ctx context.Context, fn func() (map[string]string, error)) (map[string]string, error) {
	type result struct {
		payload map[string]string
		err     error
	}
	ch := make(chan result, 1)
	go func() {
		payload, err := fn()
		ch <- result{payload: payload, err: err}
	}()

	select {
	case <-ctx.Done():
		return nil, ctx.Err()
	case out := <-ch:
		return out.payload, out.err
	}
}

func mapResultPayload(status string, payload map[string]string) *queryHTTPData {
	playersOnline := parseOptionalIntString(payload["players"])
	playersMax := parseOptionalIntString(payload["max_players"])
	return &queryHTTPData{
		Status:        status,
		PlayersOnline: playersOnline,
		PlayersMax:    playersMax,
		Map:           strings.TrimSpace(payload["map"]),
		Version:       strings.TrimSpace(payload["version"]),
		LatencyMS:     0,
	}
}

func resolveQueryPort(r *http.Request, payload map[string]string) (int, error) {
	for _, key := range []string{"query_port", "port", "game_port"} {
		value := queryParamOrPayload(r, payload, key)
		if value == "" {
			continue
		}
		port, err := strconv.Atoi(value)
		if err != nil || port <= 0 || port > 65535 {
			return 0, fmt.Errorf("invalid port %q", value)
		}
		return port, nil
	}
	return 0, errors.New("missing query port")
}

func resolveQueryErrCode(err error) string {
	message := strings.ToLower(err.Error())
	switch {
	case errors.Is(err, context.DeadlineExceeded) || strings.Contains(message, "timeout"):
		return "QUERY_TIMEOUT"
	case strings.Contains(message, "connection refused"):
		return "CONNECTION_REFUSED"
	case strings.Contains(message, "permission denied"):
		return "PERMISSION_DENIED"
	case strings.Contains(message, "invalid port"):
		return "INVALID_INPUT"
	case strings.Contains(message, "no such host"):
		return "DNS_FAILED"
	case strings.Contains(message, "network is unreachable"), strings.Contains(message, "connection reset"):
		return "PORT_UNREACHABLE"
	default:
		return "INSTANCE_OFFLINE"
	}
}

func writeQueryEnvelope(w http.ResponseWriter, code int, payload queryHTTPResponse) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(payload)
}

func normalizeProtocol(raw string) string {
	value := strings.ToLower(strings.TrimSpace(raw))
	switch value {
	case "", "none":
		return ""
	case "steam_a2s", "a2s", "source", "source1", "source2", "valve", "steam":
		return "valve"
	case "minecraft", "minecraft_java", "java":
		return "minecraft_java"
	case "minecraft_bedrock", "bedrock", "mcpe":
		return "minecraft_bedrock"
	}
	return value
}

func redactSensitiveQueryJSON(raw []byte) string {
	var payload map[string]any
	if err := json.Unmarshal(raw, &payload); err != nil {
		return string(raw)
	}

	for _, key := range []string{"token", "secret", "password", "authorization", "auth"} {
		if _, ok := payload[key]; ok {
			payload[key] = "[REDACTED]"
		}
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		return string(raw)
	}

	return string(encoded)
}

func parseOptionalIntString(raw string) *int {
	value := strings.TrimSpace(raw)
	if value == "" {
		return nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return nil
	}
	return &parsed
}

func parseIntOrDefault(raw string, fallback int) int {
	value := strings.TrimSpace(raw)
	if value == "" {
		return fallback
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return fallback
	}
	return parsed
}
