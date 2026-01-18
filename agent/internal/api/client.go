package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
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

// PollJobs fetches outstanding jobs for the agent.
func (c *Client) PollJobs(ctx context.Context) ([]jobs.Job, error) {
	var response struct {
		Jobs []jobs.Job `json:"jobs"`
	}

	_, err := c.doSignedJSON(ctx, http.MethodGet, "/agent/jobs", nil, &response)
	if err != nil {
		return nil, err
	}
	return response.Jobs, nil
}

// PollAgentJobs fetches orchestrator jobs for this agent.
func (c *Client) PollAgentJobs(ctx context.Context, agentID string, limit int) ([]jobs.Job, error) {
	var response struct {
		Jobs []jobs.Job `json:"jobs"`
	}

	path := fmt.Sprintf("/agent/%s/jobs?status=queued&limit=%d", url.PathEscape(agentID), limit)
	_, err := c.doSignedJSON(ctx, http.MethodGet, path, nil, &response)
	if err != nil {
		return nil, err
	}
	return response.Jobs, nil
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

func (c *Client) doSignedJSON(ctx context.Context, method, path string, body any, out any) (*http.Response, error) {
	var requestBody []byte
	if body != nil {
		var err error
		requestBody, err = json.Marshal(body)
		if err != nil {
			return nil, fmt.Errorf("encode json: %w", err)
		}
	}

	requestURL := c.BaseURL.ResolveReference(&url.URL{Path: path})
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

	headers, err := agentcrypto.Sign(c.AgentID, c.Secret, method, path, requestBody, time.Now(), nonce)
	if err != nil {
		return nil, err
	}

	req.Header.Set("X-Agent-ID", headers.AgentID)
	req.Header.Set("X-Timestamp", headers.Timestamp)
	req.Header.Set("X-Nonce", headers.Nonce)
	req.Header.Set("X-Signature", headers.Signature)
	req.Header.Set("User-Agent", c.UserAgent)

	resp, err := c.Client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request: %w", err)
	}
	defer resp.Body.Close()

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
