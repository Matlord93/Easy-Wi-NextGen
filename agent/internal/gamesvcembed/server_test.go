package gamesvcembed

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os/exec"
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
	const testToken = "test-secret"
	tmp := t.TempDir()
	srv := NewServer(Config{BaseDir: tmp, TemplateDir: filepath.Join(tmp, "tpl"), BearerToken: testToken})
	h := srv.Handler()

	body := `{"instance_id":"inst-1","files":[{"template":"missing.tpl","target":"out.cfg"}]}`
	req := httptest.NewRequest(http.MethodPost, "/instance/render-config", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Request-ID", "req-embed-5xx")
	req.Header.Set("Authorization", "Bearer "+testToken)
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("expected 500, got %d body=%s", rr.Code, rr.Body.String())
	}
	assertErrorEnvelope(t, rr.Body.Bytes(), "INTERNAL_ERROR", rr.Header().Get("X-Request-ID"))
}

func TestEmbeddedInstanceStatusStopped(t *testing.T) {
	const testToken = "test-secret"
	srv := NewServer(Config{BearerToken: testToken})
	h := srv.Handler()

	req := httptest.NewRequest(http.MethodPost, "/instance/status", strings.NewReader(`{"instance_id":"inst-unknown"}`))
	req.Header.Set("Authorization", "Bearer "+testToken)
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rr.Code, rr.Body.String())
	}
	payload := decodeEmbedJSONMap(t, rr.Body.Bytes())
	if got := payload["status"]; got != "stopped" {
		t.Fatalf("expected status=stopped, got %v", got)
	}
	if got, ok := payload["running"].(bool); !ok || got {
		t.Fatalf("expected running=false, got %v", payload["running"])
	}
	if payload["ok"] != true {
		t.Fatalf("expected ok=true, got %v", payload["ok"])
	}
}

func TestEmbeddedInstanceStatusRunning(t *testing.T) {
	const testToken = "test-secret"
	srv := NewServer(Config{BearerToken: testToken})

	cmd := exec.Command("sleep", "60")
	if err := cmd.Start(); err != nil {
		t.Skipf("cannot start sleep process: %v", err)
	}
	t.Cleanup(func() { _ = cmd.Process.Kill(); _ = cmd.Wait() })

	srv.mu.Lock()
	srv.processes["inst-running"] = cmd
	srv.mu.Unlock()

	h := srv.Handler()
	req := httptest.NewRequest(http.MethodPost, "/instance/status", strings.NewReader(`{"instance_id":"inst-running"}`))
	req.Header.Set("Authorization", "Bearer "+testToken)
	rr := httptest.NewRecorder()
	h.ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", rr.Code, rr.Body.String())
	}
	payload := decodeEmbedJSONMap(t, rr.Body.Bytes())
	if got := payload["status"]; got != "running" {
		t.Fatalf("expected status=running, got %v", got)
	}
	if got, ok := payload["running"].(bool); !ok || !got {
		t.Fatalf("expected running=true, got %v", payload["running"])
	}
	if payload["ok"] != true {
		t.Fatalf("expected ok=true, got %v", payload["ok"])
	}
}

func TestEmbeddedInstanceStatusAlwaysHasStatusField(t *testing.T) {
	const testToken = "test-secret"
	for _, instanceID := range []string{"inst-present", "inst-absent"} {
		t.Run(instanceID, func(t *testing.T) {
			srv := NewServer(Config{BearerToken: testToken})
			h := srv.Handler()

			body := `{"instance_id":"` + instanceID + `"}`
			req := httptest.NewRequest(http.MethodPost, "/instance/status", strings.NewReader(body))
			req.Header.Set("Authorization", "Bearer "+testToken)
			rr := httptest.NewRecorder()
			h.ServeHTTP(rr, req)

			if rr.Code != http.StatusOK {
				t.Fatalf("expected 200, got %d", rr.Code)
			}
			payload := decodeEmbedJSONMap(t, rr.Body.Bytes())
			status, hasStatus := payload["status"]
			if !hasStatus {
				t.Fatal("response missing required status field")
			}
			s, ok := status.(string)
			if !ok || (s != "running" && s != "stopped") {
				t.Fatalf("status must be \"running\" or \"stopped\", got %v", status)
			}
			if _, hasData := payload["data"]; hasData {
				data := payload["data"]
				if data == nil {
					t.Fatal("response must not contain a null data field")
				}
				if m, ok := data.(map[string]any); ok && len(m) == 0 {
					t.Fatal("response must not contain an empty data object")
				}
			}
		})
	}
}

func decodeEmbedJSONMap(t *testing.T, raw []byte) map[string]any {
	t.Helper()
	var payload map[string]any
	if err := json.Unmarshal(raw, &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	return payload
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
