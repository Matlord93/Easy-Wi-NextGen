package paneladapter

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// CPanelAdapter talks to the cPanel UAPI / WHM API.
// Configure:
//
//	CPANEL_API_URL  – e.g. https://cpanel.example.com:2083
//	CPANEL_USER     – cPanel username
//	CPANEL_TOKEN    – API token (Manage API Tokens in cPanel)
type CPanelAdapter struct {
	BaseURL  string
	Username string
	Token    string
	client   *http.Client
}

func NewCPanelAdapter(baseURL, username, token string) *CPanelAdapter {
	return &CPanelAdapter{
		BaseURL:  strings.TrimRight(baseURL, "/"),
		Username: username,
		Token:    token,
		client:   &http.Client{Timeout: 30 * time.Second},
	}
}

func (a *CPanelAdapter) DiscoverCapabilities(_ context.Context, _ Context) ([]string, *StandardizedError) {
	return []string{
		"ping",
		"domain.list",
		"domain.create",
		"domain.delete",
		"database.list",
		"database.create",
		"database.delete",
		"database.user.create",
		"database.user.assign",
		"mail.account.list",
		"mail.account.create",
		"mail.account.delete",
		"ssl.install",
	}, nil
}

func (a *CPanelAdapter) ExecuteAction(ctx context.Context, action string, payload map[string]any, _ Context) (map[string]any, *StandardizedError) {
	switch action {
	case "ping":
		return a.uapi(ctx, "Ftp", "list_ftp", nil) // lightweight UAPI call as health check
	case "domain.list":
		return a.uapi(ctx, "DomainInfo", "domains_data", map[string]string{"format": "list"})
	case "domain.create":
		domain, _ := payload["name"].(string)
		if domain == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain name is required"}
		}
		return a.uapi(ctx, "SubDomain", "addsubdomain", map[string]string{"domain": domain, "rootdomain": domain})
	case "domain.delete":
		domain, _ := payload["name"].(string)
		if domain == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain name is required"}
		}
		return a.uapi(ctx, "SubDomain", "delsubdomain", map[string]string{"domain": domain})
	case "database.list":
		return a.uapi(ctx, "Mysql", "list_databases", nil)
	case "database.create":
		dbName, _ := payload["name"].(string)
		if dbName == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "database name is required"}
		}
		return a.uapi(ctx, "Mysql", "create_database", map[string]string{"name": dbName})
	case "database.delete":
		dbName, _ := payload["name"].(string)
		if dbName == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "database name is required"}
		}
		return a.uapi(ctx, "Mysql", "delete_database", map[string]string{"name": dbName})
	case "database.user.create":
		user, _ := payload["user"].(string)
		pass, _ := payload["password"].(string)
		if user == "" || pass == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "user and password are required"}
		}
		return a.uapi(ctx, "Mysql", "create_user", map[string]string{"name": user, "password": pass})
	case "database.user.assign":
		user, _ := payload["user"].(string)
		db, _ := payload["database"].(string)
		if user == "" || db == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "user and database are required"}
		}
		return a.uapi(ctx, "Mysql", "set_privileges_on_database", map[string]string{"user": user, "database": db, "privileges": "ALL PRIVILEGES"})
	case "mail.account.list":
		return a.uapi(ctx, "Email", "list_pops", nil)
	case "mail.account.create":
		email, _ := payload["email"].(string)
		pass, _ := payload["password"].(string)
		if email == "" || pass == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "email and password are required"}
		}
		parts := strings.SplitN(email, "@", 2)
		if len(parts) != 2 {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "email must be in user@domain format"}
		}
		return a.uapi(ctx, "Email", "add_pop", map[string]string{"email": parts[0], "domain": parts[1], "password": pass})
	case "mail.account.delete":
		email, _ := payload["email"].(string)
		if email == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "email is required"}
		}
		parts := strings.SplitN(email, "@", 2)
		if len(parts) != 2 {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "email must be in user@domain format"}
		}
		return a.uapi(ctx, "Email", "delete_pop", map[string]string{"email": parts[0], "domain": parts[1]})
	case "ssl.install":
		domain, _ := payload["domain"].(string)
		cert, _ := payload["cert"].(string)
		key, _ := payload["key"].(string)
		if domain == "" || cert == "" || key == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain, cert and key are required for ssl.install"}
		}
		return a.uapi(ctx, "SSL", "install_ssl", map[string]string{"domain": domain, "cert": cert, "key": key})
	default:
		return nil, &StandardizedError{Code: ErrActionUnsupported, Message: fmt.Sprintf("action %q is not supported by cPanel adapter", action)}
	}
}

// uapi executes a cPanel UAPI call.
func (a *CPanelAdapter) uapi(ctx context.Context, module, function string, params map[string]string) (map[string]any, *StandardizedError) {
	endpoint := fmt.Sprintf("%s/execute/%s/%s", a.BaseURL, module, function)

	if len(params) > 0 {
		qp := url.Values{}
		for k, v := range params {
			qp.Set(k, v)
		}
		endpoint += "?" + qp.Encode()
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to build request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Authorization", fmt.Sprintf("cpanel %s:%s", a.Username, a.Token))
	req.Header.Set("Accept", "application/json")

	resp, err := a.client.Do(req)
	if err != nil {
		return nil, &StandardizedError{Code: ErrAdapterUnavailable, Message: "cPanel request failed: " + err.Error(), Retryable: true}
	}
	defer func() { _ = resp.Body.Close() }()

	rawBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return nil, &StandardizedError{Code: ErrAuthenticationFailed, Message: fmt.Sprintf("cPanel auth failed (%d)", resp.StatusCode)}
	}
	if resp.StatusCode >= 500 {
		return nil, &StandardizedError{Code: ErrTemporaryFailure, Message: fmt.Sprintf("cPanel server error (%d)", resp.StatusCode), Retryable: true}
	}

	var result map[string]any
	if err := json.Unmarshal(rawBody, &result); err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to decode cPanel response: " + err.Error()}
	}

	if status, _ := result["status"].(float64); status == 0 {
		errMsg := ""
		if errs, ok := result["errors"].([]any); ok && len(errs) > 0 {
			errMsg = fmt.Sprintf("%v", errs[0])
		}
		return nil, &StandardizedError{Code: ErrInternal, Message: "cPanel UAPI error: " + errMsg}
	}

	return result, nil
}
