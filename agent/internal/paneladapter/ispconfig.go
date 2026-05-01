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

// ISPConfigAdapter talks to the ISPConfig 3 Remote API (JSON-RPC over HTTPS).
// Configure:
//
//	ISPCONFIG_API_URL  – e.g. https://ispconfig.example.com:8080/remote/json.php
//	ISPCONFIG_USER     – remote API user (not the admin user)
//	ISPCONFIG_PASSWORD – remote API password
type ISPConfigAdapter struct {
	BaseURL  string
	Username string
	Password string
	client   *http.Client
	// sessionID is populated after login.
	sessionID string
}

func NewISPConfigAdapter(baseURL, username, password string) *ISPConfigAdapter {
	return &ISPConfigAdapter{
		BaseURL:  strings.TrimRight(baseURL, "/"),
		Username: username,
		Password: password,
		client:   &http.Client{Timeout: 30 * time.Second},
	}
}

func (a *ISPConfigAdapter) DiscoverCapabilities(_ context.Context, _ Context) ([]string, *StandardizedError) {
	return []string{
		"ping",
		"client.list",
		"client.create",
		"client.delete",
		"domain.list",
		"domain.create",
		"domain.delete",
		"database.list",
		"database.create",
		"database.delete",
		"mail.domain.list",
		"mail.domain.create",
		"mail.domain.delete",
		"mail.account.list",
		"mail.account.create",
		"mail.account.delete",
		"ssl.install",
	}, nil
}

func (a *ISPConfigAdapter) ExecuteAction(ctx context.Context, action string, payload map[string]any, _ Context) (map[string]any, *StandardizedError) {
	if err := a.ensureSession(ctx); err != nil {
		return nil, err
	}

	switch action {
	case "ping":
		return map[string]any{"status": "ok", "session": a.sessionID}, nil
	case "client.list":
		return a.call(ctx, "client_get_all", nil)
	case "client.create":
		return a.call(ctx, "client_add", payload)
	case "client.delete":
		id, _ := payload["client_id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "client_id is required"}
		}
		return a.call(ctx, "client_delete", map[string]any{"client_id": id})
	case "domain.list":
		return a.call(ctx, "sites_web_domain_get_all_by_user", nil)
	case "domain.create":
		return a.call(ctx, "sites_web_domain_add", payload)
	case "domain.delete":
		id, _ := payload["domain_id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain_id is required"}
		}
		return a.call(ctx, "sites_web_domain_delete", map[string]any{"domain_id": id})
	case "database.list":
		return a.call(ctx, "sites_database_get_all_by_user", nil)
	case "database.create":
		return a.call(ctx, "sites_database_add", payload)
	case "database.delete":
		id, _ := payload["database_id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "database_id is required"}
		}
		return a.call(ctx, "sites_database_delete", map[string]any{"database_id": id})
	case "mail.domain.list":
		return a.call(ctx, "mail_domain_get_all_by_user", nil)
	case "mail.domain.create":
		return a.call(ctx, "mail_domain_add", payload)
	case "mail.domain.delete":
		id, _ := payload["domain_id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain_id is required"}
		}
		return a.call(ctx, "mail_domain_delete", map[string]any{"domain_id": id})
	case "mail.account.list":
		return a.call(ctx, "mail_user_get_all_by_user", nil)
	case "mail.account.create":
		return a.call(ctx, "mail_user_add", payload)
	case "mail.account.delete":
		id, _ := payload["mailuser_id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "mailuser_id is required"}
		}
		return a.call(ctx, "mail_user_delete", map[string]any{"mailuser_id": id})
	case "ssl.install":
		return a.call(ctx, "sites_web_domain_set_letsencrypt", payload)
	default:
		return nil, &StandardizedError{Code: ErrActionUnsupported, Message: fmt.Sprintf("action %q is not supported by ISPConfig adapter", action)}
	}
}

func (a *ISPConfigAdapter) ensureSession(ctx context.Context) *StandardizedError {
	if a.sessionID != "" {
		return nil
	}

	resp, err := a.rpc(ctx, "login", map[string]any{
		"username": a.Username,
		"password": a.Password,
	})
	if err != nil {
		return err
	}

	sid, ok := resp["session_id"].(string)
	if !ok || sid == "" {
		return &StandardizedError{Code: ErrAuthenticationFailed, Message: "ISPConfig login did not return a session_id"}
	}
	a.sessionID = sid

	return nil
}

func (a *ISPConfigAdapter) call(ctx context.Context, method string, params map[string]any) (map[string]any, *StandardizedError) {
	if params == nil {
		params = map[string]any{}
	}
	params["session_id"] = a.sessionID
	return a.rpc(ctx, method, params)
}

func (a *ISPConfigAdapter) rpc(ctx context.Context, method string, params map[string]any) (map[string]any, *StandardizedError) {
	reqBody := map[string]any{
		"method": method,
		"params": params,
		"id":     1,
	}

	b, err := json.Marshal(reqBody)
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to encode request: " + err.Error()}
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.BaseURL, bytes.NewReader(b))
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to build request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := a.client.Do(req)
	if err != nil {
		return nil, &StandardizedError{Code: ErrAdapterUnavailable, Message: "ISPConfig request failed: " + err.Error(), Retryable: true}
	}
	defer resp.Body.Close()

	rawBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode >= 500 {
		return nil, &StandardizedError{Code: ErrTemporaryFailure, Message: fmt.Sprintf("ISPConfig server error (%d)", resp.StatusCode), Retryable: true}
	}

	var envelope struct {
		Result map[string]any `json:"result"`
		Error  *struct {
			Code    int    `json:"code"`
			Message string `json:"message"`
		} `json:"error"`
	}
	if jsonErr := json.Unmarshal(rawBody, &envelope); jsonErr != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to decode ISPConfig response: " + jsonErr.Error()}
	}

	if envelope.Error != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: fmt.Sprintf("ISPConfig error %d: %s", envelope.Error.Code, envelope.Error.Message)}
	}

	if envelope.Result == nil {
		return map[string]any{"status": "ok"}, nil
	}

	return envelope.Result, nil
}
