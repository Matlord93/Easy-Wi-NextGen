package paneladapter

import "context"

type ErrorCode string

const (
	ErrAdapterUnavailable   ErrorCode = "ADAPTER_UNAVAILABLE"
	ErrActionUnsupported    ErrorCode = "ACTION_UNSUPPORTED"
	ErrAuthenticationFailed ErrorCode = "AUTHENTICATION_FAILED"
	ErrAuthorizationFailed  ErrorCode = "AUTHORIZATION_FAILED"
	ErrRateLimited          ErrorCode = "RATE_LIMITED"
	ErrTemporaryFailure     ErrorCode = "TEMPORARY_FAILURE"
	ErrValidationFailed     ErrorCode = "VALIDATION_FAILED"
	ErrInternal             ErrorCode = "INTERNAL_ERROR"
)

type StandardizedError struct {
	Code      ErrorCode      `json:"code"`
	Message   string         `json:"message"`
	Retryable bool           `json:"retryable"`
	Details   map[string]any `json:"details,omitempty"`
}

func (e *StandardizedError) Error() string {
	if e == nil {
		return ""
	}
	return string(e.Code) + ": " + e.Message
}

type Context struct {
	Panel         string
	Version       string
	NodeID        string
	CorrelationID string
}

type Adapter interface {
	DiscoverCapabilities(ctx context.Context, req Context) ([]string, *StandardizedError)
	ExecuteAction(ctx context.Context, action string, payload map[string]any, req Context) (map[string]any, *StandardizedError)
}

type TechPreviewAdapter struct{}

func (TechPreviewAdapter) DiscoverCapabilities(_ context.Context, _ Context) ([]string, *StandardizedError) {
	return []string{"ping", "account.describe"}, nil
}

func (TechPreviewAdapter) ExecuteAction(_ context.Context, action string, payload map[string]any, _ Context) (map[string]any, *StandardizedError) {
	switch action {
	case "ping":
		return map[string]any{"status": "ok", "echo": payload}, nil
	case "account.describe":
		return map[string]any{"account_id": "tech-preview", "state": "synthetic"}, nil
	default:
		return nil, &StandardizedError{Code: ErrActionUnsupported, Message: "action is not supported by tech-preview adapter", Retryable: false, Details: map[string]any{"action": action}}
	}
}
