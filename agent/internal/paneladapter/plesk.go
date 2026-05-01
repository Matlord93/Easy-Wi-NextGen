package paneladapter

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

// PleskAdapter talks to the Plesk XML-RPC / REST API.
// Configure via environment or agent config:
//
//	PLESK_API_URL  – e.g. https://plesk.example.com:8443
//	PLESK_API_KEY  – secret key generated in Plesk > API Keys
type PleskAdapter struct {
	BaseURL string
	APIKey  string
	client  *http.Client
}

func NewPleskAdapter(baseURL, apiKey string) *PleskAdapter {
	return &PleskAdapter{
		BaseURL: baseURL,
		APIKey:  apiKey,
		client:  &http.Client{Timeout: 30 * time.Second},
	}
}

func (a *PleskAdapter) DiscoverCapabilities(_ context.Context, _ Context) ([]string, *StandardizedError) {
	return []string{
		"ping",
		"domain.list",
		"domain.create",
		"domain.delete",
		"subscription.list",
		"subscription.create",
		"subscription.delete",
		"database.list",
		"database.create",
		"database.delete",
		"mail.create",
		"mail.delete",
		"ssl.install",
	}, nil
}

func (a *PleskAdapter) ExecuteAction(ctx context.Context, action string, payload map[string]any, _ Context) (map[string]any, *StandardizedError) {
	switch action {
	case "ping":
		return a.ping(ctx)
	case "domain.list":
		return a.domainList(ctx)
	case "domain.create":
		return a.domainCreate(ctx, payload)
	case "domain.delete":
		return a.domainDelete(ctx, payload)
	case "subscription.list":
		return a.subscriptionList(ctx)
	case "subscription.create":
		return a.subscriptionCreate(ctx, payload)
	case "subscription.delete":
		return a.subscriptionDelete(ctx, payload)
	case "database.list":
		return a.databaseList(ctx, payload)
	case "database.create":
		return a.databaseCreate(ctx, payload)
	case "database.delete":
		return a.databaseDelete(ctx, payload)
	case "mail.create":
		return a.mailCreate(ctx, payload)
	case "mail.delete":
		return a.mailDelete(ctx, payload)
	case "ssl.install":
		return a.sslInstall(ctx, payload)
	default:
		return nil, &StandardizedError{Code: ErrActionUnsupported, Message: fmt.Sprintf("action %q is not supported by Plesk adapter", action), Retryable: false}
	}
}

func (a *PleskAdapter) ping(ctx context.Context) (map[string]any, *StandardizedError) {
	resp, err := a.get(ctx, "/api/v2/server")
	if err != nil {
		return nil, err
	}
	return map[string]any{"status": "ok", "data": resp}, nil
}

func (a *PleskAdapter) domainList(ctx context.Context) (map[string]any, *StandardizedError) {
	resp, err := a.get(ctx, "/api/v2/domains")
	if err != nil {
		return nil, err
	}
	return map[string]any{"domains": resp}, nil
}

func (a *PleskAdapter) domainCreate(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	name, ok := payload["name"].(string)
	if !ok || name == "" {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain name is required"}
	}
	body := map[string]any{
		"name":             name,
		"hosting_type":     "virtual",
		"php_handler_id":   "fpm",
		"base_domain_name": name,
		"hosting_settings": map[string]any{},
	}
	resp, err := a.post(ctx, "/api/v2/domains", body)
	if err != nil {
		return nil, err
	}
	return resp, nil
}

func (a *PleskAdapter) domainDelete(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	name, ok := payload["name"].(string)
	if !ok || name == "" {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain name is required"}
	}
	return a.del(ctx, "/api/v2/domains/"+name)
}

func (a *PleskAdapter) subscriptionList(ctx context.Context) (map[string]any, *StandardizedError) {
	resp, err := a.get(ctx, "/api/v2/clients")
	if err != nil {
		return nil, err
	}
	return map[string]any{"subscriptions": resp}, nil
}

func (a *PleskAdapter) subscriptionCreate(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	return a.post(ctx, "/api/v2/clients", payload)
}

func (a *PleskAdapter) subscriptionDelete(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	id, ok := payload["id"].(string)
	if !ok || id == "" {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: "subscription id is required"}
	}
	return a.del(ctx, "/api/v2/clients/"+id)
}

func (a *PleskAdapter) databaseList(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	domain, _ := payload["domain"].(string)
	path := "/api/v2/databases"
	if domain != "" {
		path += "?domain=" + domain
	}
	resp, err := a.get(ctx, path)
	if err != nil {
		return nil, err
	}
	return map[string]any{"databases": resp}, nil
}

func (a *PleskAdapter) databaseCreate(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	return a.post(ctx, "/api/v2/databases", payload)
}

func (a *PleskAdapter) databaseDelete(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	id, ok := payload["id"].(string)
	if !ok || id == "" {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: "database id is required"}
	}
	return a.del(ctx, "/api/v2/databases/"+id)
}

func (a *PleskAdapter) mailCreate(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	return a.post(ctx, "/api/v2/mail", payload)
}

func (a *PleskAdapter) mailDelete(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	id, ok := payload["id"].(string)
	if !ok || id == "" {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: "mail id is required"}
	}
	return a.del(ctx, "/api/v2/mail/"+id)
}

func (a *PleskAdapter) sslInstall(ctx context.Context, payload map[string]any) (map[string]any, *StandardizedError) {
	domain, ok := payload["domain"].(string)
	if !ok || domain == "" {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain is required for ssl.install"}
	}
	return a.post(ctx, "/api/v2/certificates", payload)
}

// --- HTTP helpers ---

func (a *PleskAdapter) get(ctx context.Context, path string) (map[string]any, *StandardizedError) {
	return a.do(ctx, http.MethodGet, path, nil)
}

func (a *PleskAdapter) post(ctx context.Context, path string, body map[string]any) (map[string]any, *StandardizedError) {
	return a.do(ctx, http.MethodPost, path, body)
}

func (a *PleskAdapter) del(ctx context.Context, path string) (map[string]any, *StandardizedError) {
	return a.do(ctx, http.MethodDelete, path, nil)
}

func (a *PleskAdapter) do(ctx context.Context, method, path string, body map[string]any) (map[string]any, *StandardizedError) {
	var reqBody io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, &StandardizedError{Code: ErrInternal, Message: "failed to encode request body: " + err.Error()}
		}
		reqBody = bytes.NewReader(b)
	}

	req, err := http.NewRequestWithContext(ctx, method, a.BaseURL+path, reqBody)
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to build request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-API-Key", a.APIKey)

	resp, err := a.client.Do(req)
	if err != nil {
		return nil, &StandardizedError{Code: ErrAdapterUnavailable, Message: "plesk request failed: " + err.Error(), Retryable: true}
	}
	defer func() { _ = resp.Body.Close() }()

	rawBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return nil, &StandardizedError{Code: ErrAuthenticationFailed, Message: fmt.Sprintf("plesk auth failed (%d)", resp.StatusCode)}
	}
	if resp.StatusCode == http.StatusTooManyRequests {
		return nil, &StandardizedError{Code: ErrRateLimited, Message: "plesk rate limit", Retryable: true}
	}
	if resp.StatusCode >= 500 {
		return nil, &StandardizedError{Code: ErrTemporaryFailure, Message: fmt.Sprintf("plesk server error (%d): %s", resp.StatusCode, string(rawBody)), Retryable: true}
	}
	if resp.StatusCode >= 400 {
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: fmt.Sprintf("plesk client error (%d): %s", resp.StatusCode, string(rawBody))}
	}

	if len(rawBody) == 0 || method == http.MethodDelete {
		return map[string]any{"status": "ok"}, nil
	}

	var result map[string]any
	if err := json.Unmarshal(rawBody, &result); err != nil {
		return map[string]any{"raw": string(rawBody)}, nil
	}

	return result, nil
}
