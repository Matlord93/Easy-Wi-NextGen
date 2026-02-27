package api

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"

	agentcrypto "easywi/agent/internal/crypto"
	"easywi/agent/internal/jobs"
)

// Client handles signed requests to the Easy-Wi API.
type Client struct {
	BaseURL   *url.URL
	AgentID   string
	Secret    string
	Client    *http.Client
	Version   string
	UserAgent string
}

// NewClient constructs a new API client.
func NewClient(baseURL, agentID, secret, version string) (*Client, error) {
	parsed, err := url.Parse(baseURL)
	if err != nil {
		return nil, fmt.Errorf("parse base url: %w", err)
	}

	return &Client{
		BaseURL:   parsed,
		AgentID:   agentID,
		Secret:    secret,
		Version:   version,
		Client:    &http.Client{Timeout: 15 * time.Second},
		UserAgent: "easywi-agent/" + version,
	}, nil
}

// HeartbeatPayload describes the heartbeat data sent to the API.
type HeartbeatPayload struct {
	Version  string         `json:"version"`
	Stats    map[string]any `json:"stats"`
	Roles    []string       `json:"roles,omitempty"`
	Metadata map[string]any `json:"metadata,omitempty"`
	Status   string         `json:"status,omitempty"`
}

// SendHeartbeat posts a heartbeat event to the API.
func (c *Client) SendHeartbeat(ctx context.Context, stats map[string]any, roles []string, metadata map[string]any, status string) error {
	payload := HeartbeatPayload{
		Version:  c.Version,
		Stats:    stats,
		Roles:    roles,
		Metadata: metadata,
		Status:   status,
	}

	_, err := c.doSignedJSON(ctx, http.MethodPost, "/agent/heartbeat", payload, nil)
	return err
}

// SendMetricsBatch posts compressed metric samples in one request.
func (c *Client) SendMetricsBatch(ctx context.Context, samples []map[string]any) error {
	payload := map[string]any{"samples": samples}
	body, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("encode metrics batch: %w", err)
	}
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	if _, err := gz.Write(body); err != nil {
		return fmt.Errorf("gzip metrics batch: %w", err)
	}
	if err := gz.Close(); err != nil {
		return fmt.Errorf("close gzip: %w", err)
	}
	_, err = c.doSignedRaw(ctx, http.MethodPost, "/agent/metrics-batch", buf.Bytes(), map[string]string{"Content-Type": "application/json", "Content-Encoding": "gzip"}, nil)
	return err
}

// PollJobs fetches outstanding jobs for the agent.
func (c *Client) PollJobs(ctx context.Context) ([]jobs.Job, int, error) {
	var response struct {
		Jobs          []jobs.Job `json:"jobs"`
		MaxConcurrent int        `json:"max_concurrency"`
	}

	_, err := c.doSignedJSON(ctx, http.MethodGet, "/agent/jobs", nil, &response)
	if err != nil {
		return nil, 0, err
	}
	return response.Jobs, response.MaxConcurrent, nil
}

// PollAgentJobs fetches orchestrator jobs for this agent.
func (c *Client) PollAgentJobs(ctx context.Context, agentID string, limit int) ([]jobs.Job, int, error) {
	var response struct {
		Jobs          []jobs.Job `json:"jobs"`
		MaxConcurrent int        `json:"max_concurrency"`
	}

	path := fmt.Sprintf("/agent/%s/jobs?status=queued&limit=%d", url.PathEscape(agentID), limit)
	_, err := c.doSignedJSON(ctx, http.MethodGet, path, nil, &response)
	if err != nil {
		return nil, 0, err
	}
	return response.Jobs, response.MaxConcurrent, nil
}

// StartAgentJob marks an orchestrator job as running.
func (c *Client) StartAgentJob(ctx context.Context, agentID, jobID string) error {
	path := fmt.Sprintf("/agent/%s/jobs/%s/start", url.PathEscape(agentID), url.PathEscape(jobID))
	_, err := c.doSignedJSON(ctx, http.MethodPost, path, map[string]any{}, nil)
	return err
}

// FinishAgentJob submits a result for an orchestrator job.
func (c *Client) FinishAgentJob(ctx context.Context, agentID, jobID, status string, logText string, errorText string, resultPayload map[string]any) error {
	path := fmt.Sprintf("/agent/%s/jobs/%s/finish", url.PathEscape(agentID), url.PathEscape(jobID))
	payload := map[string]any{
		"status":         status,
		"log_text":       logText,
		"error_text":     errorText,
		"result_payload": resultPayload,
	}
	_, err := c.doSignedJSON(ctx, http.MethodPost, path, payload, nil)
	return err
}

// SubmitJobResult submits a job result payload.
func (c *Client) SubmitJobResult(ctx context.Context, result jobs.Result) error {
	path := fmt.Sprintf("/agent/jobs/%s/result", url.PathEscape(result.JobID))
	_, err := c.doSignedJSON(ctx, http.MethodPost, path, result, nil)
	return err
}

// StartJob marks a core job as running.
func (c *Client) StartJob(ctx context.Context, jobID string) error {
	path := fmt.Sprintf("/agent/jobs/%s/start", url.PathEscape(jobID))
	_, err := c.doSignedJSON(ctx, http.MethodPost, path, map[string]any{}, nil)
	return err
}

func (c *Client) SubmitJobLogs(ctx context.Context, jobID string, logs []string, progress *int) error {
	path := fmt.Sprintf("/agent/jobs/%s/logs", url.PathEscape(jobID))
	payload := map[string]any{
		"job_id": jobID,
		"logs":   logs,
	}
	if progress != nil {
		payload["progress"] = *progress
	}
	_, err := c.doSignedJSON(ctx, http.MethodPost, path, payload, nil)
	return err
}

type bootstrapResponse struct {
	RegisterURL   string `json:"register_url"`
	RegisterToken string `json:"register_token"`
	AgentID       string `json:"agent_id"`
}

// RefreshSecretWithBootstrap requests a short-lived registration token and rotates the current agent secret.
func (c *Client) RefreshSecretWithBootstrap(ctx context.Context, bootstrapToken string, osName string) (string, error) {
	hostname, err := os.Hostname()
	if err != nil || strings.TrimSpace(hostname) == "" {
		hostname = c.AgentID
	}

	bootstrapPayload := map[string]any{
		"bootstrap_token": bootstrapToken,
		"hostname":        hostname,
		"os":              osName,
		"agent_version":   c.Version,
	}

	var boot bootstrapResponse
	if _, err := c.doUnsignedJSON(ctx, http.MethodPost, "/api/v1/agent/bootstrap", bootstrapPayload, &boot); err != nil {
		return "", err
	}
	if boot.RegisterURL == "" || boot.RegisterToken == "" || boot.AgentID == "" {
		return "", fmt.Errorf("bootstrap response missing registration fields")
	}
	if boot.AgentID != c.AgentID {
		return "", fmt.Errorf("bootstrap returned mismatched agent id: %s", boot.AgentID)
	}

	registerPayload := map[string]any{
		"agent_id":        c.AgentID,
		"register_token":  boot.RegisterToken,
		"rotate_existing": true,
	}
	var registerResp struct {
		Secret string `json:"secret"`
	}
	registerPath, err := pathFromURLOrFallback(boot.RegisterURL, "/api/v1/agent/register")
	if err != nil {
		return "", err
	}
	if _, err := c.doSignedJSONWithSecret(ctx, http.MethodPost, registerPath, registerPayload, &registerResp, boot.RegisterToken); err != nil {
		return "", err
	}
	if strings.TrimSpace(registerResp.Secret) == "" {
		return "", fmt.Errorf("register response did not include secret")
	}

	return strings.TrimSpace(registerResp.Secret), nil
}

func pathFromURLOrFallback(raw string, fallback string) (string, error) {
	parsed, err := url.Parse(raw)
	if err != nil {
		return "", fmt.Errorf("parse register url: %w", err)
	}
	path := parsed.EscapedPath()
	if path == "" {
		path = parsed.Path
	}
	if path == "" {
		path = fallback
	}
	if parsed.RawQuery != "" {
		path = path + "?" + parsed.RawQuery
	}
	return path, nil
}

func (c *Client) doUnsignedJSON(ctx context.Context, method, path string, body any, out any) (resp *http.Response, err error) {
	var requestBody []byte
	if body != nil {
		requestBody, err = json.Marshal(body)
		if err != nil {
			return nil, fmt.Errorf("encode json: %w", err)
		}
	}

	requestPath, err := url.Parse(path)
	if err != nil {
		return nil, fmt.Errorf("parse request path: %w", err)
	}
	requestURL := c.BaseURL.ResolveReference(requestPath)
	req, err := http.NewRequestWithContext(ctx, method, requestURL.String(), bytes.NewReader(requestBody))
	if err != nil {
		return nil, fmt.Errorf("build request: %w", err)
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("User-Agent", c.UserAgent)

	resp, err = c.Client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request: %w", err)
	}
	defer func() {
		if closeErr := resp.Body.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close response body: %w", closeErr)
		}
	}()
	if resp.StatusCode >= http.StatusBadRequest {
		bodyBytes, _ := io.ReadAll(resp.Body)
		return resp, fmt.Errorf("api error %s: %s", resp.Status, string(bodyBytes))
	}
	if out != nil {
		decoder := json.NewDecoder(resp.Body)
		if err := decoder.Decode(out); err != nil {
			return resp, fmt.Errorf("decode response: %w", err)
		}
	}
	return resp, nil
}

func (c *Client) doSignedJSON(ctx context.Context, method, path string, body any, out any) (resp *http.Response, err error) {
	return c.doSignedJSONWithSecret(ctx, method, path, body, out, c.Secret)
}

func (c *Client) doSignedJSONWithSecret(ctx context.Context, method, path string, body any, out any, secret string) (resp *http.Response, err error) {
	var requestBody []byte
	if body != nil {
		requestBody, err = json.Marshal(body)
		if err != nil {
			return nil, fmt.Errorf("encode json: %w", err)
		}
	}

	requestPath, err := url.Parse(path)
	if err != nil {
		return nil, fmt.Errorf("parse request path: %w", err)
	}
	if requestPath.RawQuery == "" && strings.Contains(strings.ToLower(requestPath.Path), "%3f") {
		unescapedPath, err := url.PathUnescape(requestPath.Path)
		if err != nil {
			return nil, fmt.Errorf("unescape request path: %w", err)
		}
		if pathWithQuery, query, found := strings.Cut(unescapedPath, "?"); found {
			requestPath.Path = pathWithQuery
			requestPath.RawQuery = query
		}
	}
	requestURL := c.BaseURL.ResolveReference(requestPath)

	req, err := http.NewRequestWithContext(ctx, method, requestURL.String(), bytes.NewReader(requestBody))
	if err != nil {
		return nil, fmt.Errorf("build request: %w", err)
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	nonce, err := agentcrypto.NewNonce()
	if err != nil {
		return nil, err
	}

	headers, err := agentcrypto.Sign(c.AgentID, secret, method, requestPath.Path, requestBody, time.Now(), nonce)
	if err != nil {
		return nil, err
	}

	req.Header.Set("X-Agent-ID", headers.AgentID)
	req.Header.Set("X-Timestamp", headers.Timestamp)
	req.Header.Set("X-Nonce", headers.Nonce)
	req.Header.Set("X-Signature", headers.Signature)
	req.Header.Set("User-Agent", c.UserAgent)

	resp, err = c.Client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request: %w", err)
	}
	defer func() {
		if closeErr := resp.Body.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close response body: %w", closeErr)
		}
	}()

	if resp.StatusCode >= http.StatusBadRequest {
		bodyBytes, _ := io.ReadAll(resp.Body)
		return resp, fmt.Errorf("api error %s: %s", resp.Status, string(bodyBytes))
	}

	if out != nil {
		decoder := json.NewDecoder(resp.Body)
		if err := decoder.Decode(out); err != nil {
			return resp, fmt.Errorf("decode response: %w", err)
		}
	}

	return resp, nil
}

func (c *Client) doSignedRaw(ctx context.Context, method, path string, body []byte, extraHeaders map[string]string, out any) (resp *http.Response, err error) {
	requestPath, err := url.Parse(path)
	if err != nil {
		return nil, fmt.Errorf("parse request path: %w", err)
	}
	requestURL := c.BaseURL.ResolveReference(requestPath)
	req, err := http.NewRequestWithContext(ctx, method, requestURL.String(), bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("build request: %w", err)
	}
	nonce, err := agentcrypto.NewNonce()
	if err != nil {
		return nil, err
	}
	headers, err := agentcrypto.Sign(c.AgentID, c.Secret, method, requestPath.Path, body, time.Now(), nonce)
	if err != nil {
		return nil, err
	}
	req.Header.Set("X-Agent-ID", headers.AgentID)
	req.Header.Set("X-Timestamp", headers.Timestamp)
	req.Header.Set("X-Nonce", headers.Nonce)
	req.Header.Set("X-Signature", headers.Signature)
	req.Header.Set("User-Agent", c.UserAgent)
	for k, v := range extraHeaders {
		req.Header.Set(k, v)
	}
	resp, err = c.Client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request: %w", err)
	}
	defer func() {
		if closeErr := resp.Body.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close response body: %w", closeErr)
		}
	}()
	if resp.StatusCode >= http.StatusBadRequest {
		bodyBytes, _ := io.ReadAll(resp.Body)
		return resp, fmt.Errorf("api error %s: %s", resp.Status, string(bodyBytes))
	}
	if out != nil {
		decoder := json.NewDecoder(resp.Body)
		if err := decoder.Decode(out); err != nil {
			return resp, fmt.Errorf("decode response: %w", err)
		}
	}
	return resp, nil
}
