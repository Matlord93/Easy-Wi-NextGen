package paneladapter

import (
	"context"
	"crypto/md5"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// AAPanelAdapter talks to the aaPanel API (used by AApanel / BT Panel).
// Configure:
//
//	AAPANEL_API_URL  – e.g. http://aapanel.example.com:7800
//	AAPANEL_API_KEY  – API key from aaPanel Settings > API
type AAPanelAdapter struct {
	BaseURL string
	APIKey  string
	client  *http.Client
}

func NewAAPanelAdapter(baseURL, apiKey string) *AAPanelAdapter {
	return &AAPanelAdapter{
		BaseURL: strings.TrimRight(baseURL, "/"),
		APIKey:  apiKey,
		client:  &http.Client{Timeout: 30 * time.Second},
	}
}

func (a *AAPanelAdapter) DiscoverCapabilities(_ context.Context, _ Context) ([]string, *StandardizedError) {
	return []string{
		"ping",
		"site.list",
		"site.create",
		"site.delete",
		"database.list",
		"database.create",
		"database.delete",
		"ftp.list",
		"ftp.create",
		"ftp.delete",
		"ssl.auto",
	}, nil
}

func (a *AAPanelAdapter) ExecuteAction(ctx context.Context, action string, payload map[string]any, _ Context) (map[string]any, *StandardizedError) {
	switch action {
	case "ping":
		return a.api(ctx, "/api/system", nil)
	case "site.list":
		return a.api(ctx, "/data?action=getData&table=sites&limit=100&tojs=1", nil)
	case "site.create":
		webname, _ := payload["domain"].(string)
		if webname == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain is required"}
		}
		params := url.Values{}
		params.Set("webname", webname)
		params.Set("path", fmt.Sprintf("/www/wwwroot/%s", webname))
		params.Set("type_id", "0")
		params.Set("type", "PHP")
		if php, _ := payload["php_version"].(string); php != "" {
			params.Set("version", php)
		} else {
			params.Set("version", "74")
		}
		return a.postForm(ctx, "/site", params)
	case "site.delete":
		id, _ := payload["id"].(string)
		name, _ := payload["name"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "site id is required"}
		}
		params := url.Values{}
		params.Set("id", id)
		params.Set("webname", name)
		params.Set("ftp", "0")
		params.Set("database", "0")
		params.Set("path", "0")
		return a.postForm(ctx, "/site?action=DeleteSite", params)
	case "database.list":
		return a.api(ctx, "/data?action=getData&table=databases&limit=100&tojs=1", nil)
	case "database.create":
		dbName, _ := payload["name"].(string)
		dbPassword, _ := payload["password"].(string)
		if dbName == "" || dbPassword == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "name and password are required"}
		}
		params := url.Values{}
		params.Set("name", dbName)
		params.Set("password", dbPassword)
		params.Set("codeing", "utf8mb4")
		return a.postForm(ctx, "/database", params)
	case "database.delete":
		id, _ := payload["id"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "database id is required"}
		}
		params := url.Values{}
		params.Set("id", id)
		return a.postForm(ctx, "/database?action=DeleteDatabase", params)
	case "ftp.list":
		return a.api(ctx, "/data?action=getData&table=ftps&limit=100&tojs=1", nil)
	case "ftp.create":
		username, _ := payload["username"].(string)
		password, _ := payload["password"].(string)
		path, _ := payload["path"].(string)
		if username == "" || password == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "username and password are required"}
		}
		params := url.Values{}
		params.Set("username", username)
		params.Set("password", password)
		if path != "" {
			params.Set("path", path)
		}
		return a.postForm(ctx, "/ftp", params)
	case "ftp.delete":
		id, _ := payload["id"].(string)
		username, _ := payload["username"].(string)
		if id == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "ftp id is required"}
		}
		params := url.Values{}
		params.Set("id", id)
		params.Set("username", username)
		return a.postForm(ctx, "/ftp?action=DeleteUser", params)
	case "ssl.auto":
		webname, _ := payload["domain"].(string)
		if webname == "" {
			return nil, &StandardizedError{Code: ErrValidationFailed, Message: "domain is required for ssl.auto"}
		}
		params := url.Values{}
		params.Set("webname", webname)
		return a.postForm(ctx, "/acme?action=apply_cert_api", params)
	default:
		return nil, &StandardizedError{Code: ErrActionUnsupported, Message: fmt.Sprintf("action %q is not supported by aaPanel adapter", action)}
	}
}

// api performs a GET to an aaPanel API endpoint with HMAC-like auth.
func (a *AAPanelAdapter) api(ctx context.Context, path string, extraParams url.Values) (map[string]any, *StandardizedError) {
	timestamp := fmt.Sprintf("%d", time.Now().Unix())
	sign := a.sign(timestamp)

	params := url.Values{}
	params.Set("request_time", timestamp)
	params.Set("request_token", sign)
	if extraParams != nil {
		for k, vs := range extraParams {
			for _, v := range vs {
				params.Set(k, v)
			}
		}
	}

	endpoint := a.BaseURL + path
	if strings.Contains(path, "?") {
		endpoint += "&" + params.Encode()
	} else {
		endpoint += "?" + params.Encode()
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to build request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Accept", "application/json")

	return a.doRequest(req)
}

func (a *AAPanelAdapter) postForm(ctx context.Context, path string, params url.Values) (map[string]any, *StandardizedError) {
	timestamp := fmt.Sprintf("%d", time.Now().Unix())
	sign := a.sign(timestamp)

	params.Set("request_time", timestamp)
	params.Set("request_token", sign)

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.BaseURL+path, strings.NewReader(params.Encode()))
	if err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to build request: " + err.Error(), Retryable: true}
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")

	return a.doRequest(req)
}

func (a *AAPanelAdapter) doRequest(req *http.Request) (map[string]any, *StandardizedError) {
	resp, err := a.client.Do(req)
	if err != nil {
		return nil, &StandardizedError{Code: ErrAdapterUnavailable, Message: "aaPanel request failed: " + err.Error(), Retryable: true}
	}
	defer resp.Body.Close()

	rawBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return nil, &StandardizedError{Code: ErrAuthenticationFailed, Message: fmt.Sprintf("aaPanel auth failed (%d)", resp.StatusCode)}
	}
	if resp.StatusCode >= 500 {
		return nil, &StandardizedError{Code: ErrTemporaryFailure, Message: fmt.Sprintf("aaPanel server error (%d)", resp.StatusCode), Retryable: true}
	}

	var result map[string]any
	if err := json.Unmarshal(rawBody, &result); err != nil {
		return nil, &StandardizedError{Code: ErrInternal, Message: "failed to decode aaPanel response: " + err.Error()}
	}

	if status, ok := result["status"].(bool); ok && !status {
		msg := ""
		if m, ok := result["msg"].(string); ok {
			msg = m
		}
		return nil, &StandardizedError{Code: ErrInternal, Message: "aaPanel error: " + msg}
	}

	return result, nil
}

// sign builds the aaPanel HMAC-MD5 signature: md5(timestamp + md5(apikey)).
func (a *AAPanelAdapter) sign(timestamp string) string {
	keyHash := fmt.Sprintf("%x", md5.Sum([]byte(a.APIKey)))
	combined := timestamp + keyHash
	return fmt.Sprintf("%x", md5.Sum([]byte(combined)))
}
