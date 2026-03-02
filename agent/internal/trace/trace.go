package trace

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"regexp"
	"strings"
)

const (
	RequestHeader     = "X-Request-ID"
	CorrelationHeader = "X-Correlation-ID"
)

type contextKey string

const (
	requestIDKey     contextKey = "request_id"
	correlationIDKey contextKey = "correlation_id"
)

var uuidPattern = regexp.MustCompile("^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[1-5][a-fA-F0-9]{3}-[89abAB][a-fA-F0-9]{3}-[a-fA-F0-9]{12}$")

func newUUID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	hexv := hex.EncodeToString(b)
	return hexv[0:8] + "-" + hexv[8:12] + "-" + hexv[12:16] + "-" + hexv[16:20] + "-" + hexv[20:32]
}

func isValidUUID(value string) bool {
	return uuidPattern.MatchString(strings.TrimSpace(value))
}

func Normalize(requestID, correlationID string) (string, string) {
	req := strings.TrimSpace(requestID)
	if !isValidUUID(req) {
		req = newUUID()
	}

	corr := strings.TrimSpace(correlationID)
	if corr == "" || !isValidUUID(corr) {
		corr = req
	}

	return req, corr
}

func WithIDs(ctx context.Context, requestID, correlationID string) context.Context {
	req, corr := Normalize(requestID, correlationID)
	ctx = context.WithValue(ctx, requestIDKey, req)
	ctx = context.WithValue(ctx, correlationIDKey, corr)
	return ctx
}

func IDsFromContext(ctx context.Context) (string, string) {
	if ctx == nil {
		return Normalize("", "")
	}
	req, _ := ctx.Value(requestIDKey).(string)
	corr, _ := ctx.Value(correlationIDKey).(string)
	return Normalize(req, corr)
}
