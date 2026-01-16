package main

import (
	"net/http"
	"net/url"
	"testing"
	"time"
)

func TestSanitizeInstancePathBlocksTraversal(t *testing.T) {
	root := "/home/gs1demo"

	path, err := sanitizeInstancePath(root, "../etc/passwd")
	if err == nil {
		t.Fatalf("expected traversal error, got path %s", path)
	}

	path, err = sanitizeInstancePath(root, "config/server.properties")
	if err != nil {
		t.Fatalf("expected safe path, got %v", err)
	}
	if path != "/home/gs1demo/config/server.properties" {
		t.Fatalf("unexpected path: %s", path)
	}
}

func TestVerifyRequestSignature(t *testing.T) {
	cfg := filesvcConfig{
		AgentID: "agent-1",
		Secret:  "secret",
		MaxSkew: 30 * time.Second,
	}

	req := &http.Request{
		Method: http.MethodGet,
		URL: &url.URL{
			Path:     "/v1/servers/42/files",
			RawQuery: "path=config",
		},
		Header: make(http.Header),
	}

	timestamp := time.Now().UTC().Format(time.RFC3339)
	payload := buildSignaturePayload(cfg.AgentID, "99", req.Method, req.URL.RequestURI(), timestamp)
	signature := signPayload(payload, cfg.Secret)

	req.Header.Set(headerAgentID, cfg.AgentID)
	req.Header.Set(headerCustomerID, "99")
	req.Header.Set(headerTimestamp, timestamp)
	req.Header.Set(headerSignature, signature)

	if _, err := verifyRequestSignature(req, cfg); err != nil {
		t.Fatalf("expected valid signature, got %v", err)
	}

	req.Header.Set(headerCustomerID, "")
	if _, err := verifyRequestSignature(req, cfg); err == nil {
		t.Fatalf("expected missing customer id error")
	}
}
