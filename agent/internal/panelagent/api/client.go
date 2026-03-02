package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"

	coreapi "easywi/agent/internal/api"
	"easywi/agent/internal/trace"
)

type HeartbeatRequest struct {
	AgentUUID    string   `json:"agent_uuid"`
	Version      string   `json:"version"`
	OS           string   `json:"os"`
	Capabilities []string `json:"capabilities"`
}

type MailLogEvent struct {
	CreatedAt string         `json:"created_at"`
	Level     string         `json:"level"`
	Source    string         `json:"source"`
	Domain    string         `json:"domain"`
	UserID    *int           `json:"user_id,omitempty"`
	EventType string         `json:"event_type"`
	Message   string         `json:"message"`
	Payload   map[string]any `json:"payload"`
}

type MailLogsBatchRequest struct {
	Events []MailLogEvent `json:"events"`
}

type Client struct {
	baseURL     string
	token       string
	client      *http.Client
	retryPolicy coreapi.RetryPolicy
}

func NewClient(baseURL, token string, c *http.Client) *Client {
	policy := coreapi.DefaultRetryPolicy()
	if c == nil {
		c = coreapi.NewRetryHTTPClient(policy)
	}
	return &Client{baseURL: baseURL, token: token, client: c, retryPolicy: policy}
}

func (c *Client) Heartbeat(ctx context.Context, req HeartbeatRequest) error {
	return c.postJSON(ctx, "/api/v1/agent/heartbeat", req, "heartbeat")
}

func (c *Client) PostMailLogsBatch(ctx context.Context, req MailLogsBatchRequest) error {
	return c.postJSON(ctx, "/api/v1/agent/mail/logs-batch", req, "post mail logs batch")
}

func (c *Client) postJSON(ctx context.Context, path string, payload any, action string) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	idempotencyKey, err := coreapi.NewIdempotencyKey()
	if err != nil {
		return err
	}
	requestFactory := func() (*http.Request, error) {
		httpReq, buildErr := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(body))
		if buildErr != nil {
			return nil, buildErr
		}
		httpReq.Header.Set("Authorization", "Bearer "+c.token)
		httpReq.Header.Set("Content-Type", "application/json")
		httpReq.Header.Set("Idempotency-Key", idempotencyKey)
		requestID, correlationID := trace.IDsFromContext(ctx)
		httpReq.Header.Set(trace.RequestHeader, requestID)
		httpReq.Header.Set(trace.CorrelationHeader, correlationID)
		return httpReq, nil
	}
	resp, err := coreapiDoWithRetry(ctx, c.client, c.retryPolicy, coreapi.RetryClassSafe, requestFactory)
	if err != nil {
		return err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode >= 300 {
		payload, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("%s failed with status %d: %s", action, resp.StatusCode, string(payload))
	}
	return nil
}

// local adapter to avoid exporting circuit-breaker internals from core api package.
func coreapiDoWithRetry(ctx context.Context, httpClient *http.Client, policy coreapi.RetryPolicy, class coreapi.RetryClass, requestFactory func() (*http.Request, error)) (*http.Response, error) {
	return coreapi.DoWithRetryForPanelClient(ctx, httpClient, policy, class, requestFactory)
}
