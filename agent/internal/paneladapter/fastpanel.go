package paneladapter

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// FastPanelAdapter talks to the FastPanel REST API.
// Configure:
//
//	FASTPANEL_API_URL – e.g. https://fastpanel.example.com:8888
//	FASTPANEL_USER    – API username (admin)
//	FASTPANEL_PASSWORD – API password
type FastPanelAdapter struct {
	BaseURL  string
	Username string
	Password string
	client   *http.Client
	token    string
}

func NewFastPanelAdapter(baseURL, username, password string) *FastPanelAdapter {
	return &FastPanelAdapter{
		BaseURL:  strings.TrimRight(baseURL, "/"),
		Username: username,
		Password: password,
		client:   &http.Client{Timeout: 30 * time.Second},
	}
}

func (a *FastPanelAdapter) DiscoverCapabilities(_ context.Context, _ Context) ([]string, *StandardizedError) {
	return []string{
		"ping",
		"user.list",
		"user.create",
		"user.delete",
		"domain.list",
		"domain.create",
		"domain.delete",
		"database.list",
		"database.create",
		"database.delete",
		"mail.domain.list",
		"mail.domain.create",
		"mail.account.list",
		"mail.account.create",
		"mail.account.delete",
		"ssl.install",
	}, nil
}

func (a *FastPanelAdapter) ExecuteAction(ctx context.Context, action string, payload map[string]any, _ Context) (map[string]any, *StandardizedError) {
	if err := a.ensureToken(ctx); err != nil {
		return nil, err
	}

	switch action {
	case "ping":
		return a.get(ctx, "/api/system/info")
	case "user.list":
		return a.get(ctx, "/api/users")
	case "user.create":
		return a.post(ctx, "/api/users", payload)
	case "user.delete":
		id, _ := payload["id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "user id is required"}
		}
		return a.del(ctx, "/api/users/"+id)
	case "domain.list":
		return a.get(ctx, "/api/domains")
	case "domain.create":
		return a.post(ctx, "/api/domains", payload)
	case "domain.delete":
		id, _ := payload["id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain id is required"}
		}
		return a.del(ctx, "/api/domains/"+id)
	case "database.list":
		return a.get(ctx, "/api/databases")
	case "database.create":
		return a.post(ctx, "/api/databases", payload)
	case "database.delete":
		id, _ := payload["id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "database id is required"}
		}
		return a.del(ctx, "/api/databases/"+id)
	case "mail.domain.list":
		return a.get(ctx, "/api/mail/domains")
	case "mail.domain.create":
		return a.post(ctx, "/api/mail/domains", payload)
	case "mail.account.list":
		return a.get(ctx, "/api/mail/accounts")
	case "mail.account.create":
		return a.post(ctx, "/api/mail/accounts", payload)
	case "mail.account.delete":
		id, _ := payload["id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "account id is required"}
		}
		return a.del(ctx, "/api/mail/accounts/"+id)
	case "ssl.install":
		domain, _ := payload["domain"].(string)
		if domain == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain is required for ssl.install"}
		}
		return a.post(ctx, "/api/ssl/install", payload)
	default:
		return nil, &StandardizedError{Code: ErrActionUnsupported, Message: fmt.Sprintf("action %q is not supported by FastPanel adapter", action)}
	}
}

func (a *FastPanelAdapter) ensureToken(ctx context.Context) *StandardizedError {
	if a.token != "" {
		return nil
	}

	body := map[string]any{
		"username": a.Username,
		"password": a.Password,
	}
	b, _ := json.Marshal(body)

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.BaseURL+"/api/auth/login", bytes.NewReader(b))
	if err != nil {
		return &StandardizedError{Code: ErrInternal, Message: "failed to build auth request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := a.client.Do(req)
	if err != nil {
		return &StandardizedError{Code: ErrAdapterUnavailable, Message: "FastPanel auth request failed: " + err.Error(), Retryable: true}
	}
	defer resp.Body.Close()

	rawBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode != http.StatusOK {
		return &StandardizedError{Code: ErrAuthenticationFailed, Message: fmt.Sprintf("FastPanel auth failed (%d): %s", resp.StatusCode, string(rawBody))}
	}

	var result map[string]any
	if err := json.Unmarshal(rawBody, &result); err != nil {
		return &StandardizedError{Code: ErrInternal, Message: "failed to decode auth response"}
	}

	token, ok := result["token"].(string)
	if !ok || token == "" {
		return &StandardizedError{Code: ErrAuthenticationFailed, Message: "FastPanel auth did not return a token"}
	}
	a.token = token

	return nil
}

func (a *FastPanelAdapter) get(ctx context.Context, path string) (map[string]any, *StandardizedError) {
	return a.do(ctx, http.MethodGet, path, nil)
}

func (a *FastPanelAdapter) post(ctx context.Context, path string, body map[string]any) (map[string]any, *StandardizedError) {
	return a.do(ctx, http.MethodPost, path, body)
}

func (a *FastPanelAdapter) del(ctx context.Context, path string) (map[string]any, *StandardizedError) {
	return a.do(ctx, http.MethodDelete, path, nil)
}

func (a *FastPanelAdapter) do(ctx context.Context, method, path string, body map[string]any) (map[string]any, *StandardizedError) {
	var reqBody io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, &StandardizedError{Code: ErrInternal, Message: "failed to encode request: " + err.Error()}
		}
		reqBody = bytes.NewReader(b)
	}

	req, err := http.NewRequestWithContext(ctx, method, a.BaseURL+path, reqBody)
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to build request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+a.token)

	resp, err := a.client.Do(req)
	if err != nil {
		return nil, &StandardizedError{Code: ErrAdapterUnavailable, Message: "FastPanel request failed: " + err.Error(), Retryable: true}
	}
	defer resp.Body.Close()

	rawBody, _ := io.ReadAll(resp.Body)

	switch {
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		a.token = "" // force re-auth on next call
		return nil, &StandardizedError{Code: ErrAuthenticationFailed, Message: fmt.Sprintf("FastPanel auth failed (%d)", resp.StatusCode)}
	case resp.StatusCode == http.StatusTooManyRequests:
		return nil, &StandardizedError{Code: ErrRateLimited, Message: "FastPanel rate limit", Retryable: true}
	case resp.StatusCode >= 500:
		return nil, &StandardizedError{Code: ErrTemporaryFailure, Message: fmt.Sprintf("FastPanel server error (%d)", resp.StatusCode), Retryable: true}
	case resp.StatusCode >= 400:
		return nil, &StandardizedError{Code: ErrValidationFailed, Message: fmt.Sprintf("FastPanel error (%d): %s", resp.StatusCode, string(rawBody))}
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
