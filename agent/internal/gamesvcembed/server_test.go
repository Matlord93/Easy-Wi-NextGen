package gamesvcembed

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
)

func TestEmbeddedGamesvcErrorEnvelope4xx(t *testing.T) {
	srv := NewServer(Config{})
	h := srv.Handler()

	req := httptest.NewRequest(http.MethodGet, "/instance/start", nil)
	req.Header.Set("X-Request-ID", "req-embed-4xx")
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, req)

	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("expected 405, got %d", rr.Code)
	}
	assertErrorEnvelope(t, rr.Body.Bytes(), "METHOD_NOT_ALLOWED", rr.Header().Get("X-Request-ID"))
}

func TestEmbeddedGamesvcErrorEnvelope5xx(t *testing.T) {
	tmp := t.TempDir()
	srv := NewServer(Config{BaseDir: tmp, TemplateDir: filepath.Join(tmp, "tpl")})
	h := srv.Handler()

	body := `{"instance_id":"inst-1","files":[{"template":"missing.tpl","target":"out.cfg"}]}`
	req := httptest.NewRequest(http.MethodPost, "/instance/render-config", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Request-ID", "req-embed-5xx")
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("expected 500, got %d body=%s", rr.Code, rr.Body.String())
	}
	assertErrorEnvelope(t, rr.Body.Bytes(), "INTERNAL_ERROR", rr.Header().Get("X-Request-ID"))
}

func assertErrorEnvelope(t *testing.T, raw []byte, expectedCode, expectedRequestID string) {
	t.Helper()
	var payload map[string]any
	if err := json.Unmarshal(raw, &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	errorObj, ok := payload["error"].(map[string]any)
	if !ok {
		t.Fatalf("missing error object: %#v", payload)
	}
	if got := errorObj["code"]; got != expectedCode {
		t.Fatalf("expected code %s, got %v", expectedCode, got)
	}
	if got := errorObj["request_id"]; got != expectedRequestID {
		t.Fatalf("expected request_id %s, got %v", expectedRequestID, got)
	}
	if msg, ok := errorObj["message"].(string); !ok || strings.TrimSpace(msg) == "" {
		t.Fatalf("expected non-empty message, got %v", errorObj["message"])
	}
}
