package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
)

type HeartbeatRequest struct {
	AgentUUID    string   `json:"agent_uuid"`
	Version      string   `json:"version"`
	OS           string   `json:"os"`
	Capabilities []string `json:"capabilities"`
}

type Client struct {
	baseURL string
	token   string
	client  *http.Client
}

func NewClient(baseURL, token string, c *http.Client) *Client {
	if c == nil {
		c = http.DefaultClient
	}
	return &Client{baseURL: baseURL, token: token, client: c}
}

func (c *Client) Heartbeat(ctx context.Context, req HeartbeatRequest) error {
	body, err := json.Marshal(req)
	if err != nil {
		return err
	}

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/api/v1/agent/heartbeat", bytes.NewReader(body))
	if err != nil {
		return err
	}
	httpReq.Header.Set("Authorization", "Bearer "+c.token)
	httpReq.Header.Set("Content-Type", "application/json")

	resp, err := c.client.Do(httpReq)
	if err != nil {
		return err
	}
	defer func() {
		_ = resp.Body.Close()
	}()

	if resp.StatusCode >= 300 {
		return fmt.Errorf("heartbeat failed with status %d", resp.StatusCode)
	}

	return nil
}
