package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestMailMutationHandlerReturnsAcceptedEnvelope(t *testing.T) {
	h := mailMutationHandler("mailbox")
	req := httptest.NewRequest(http.MethodPost, "/v1/mail/mailboxes", nil)
	w := httptest.NewRecorder()
	h.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}

	var payload map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if payload["ok"] != true {
		t.Fatalf("expected ok=true, got %#v", payload["ok"])
	}
	if payload["status"] != "accepted" {
		t.Fatalf("expected accepted status, got %#v", payload["status"])
	}
}

func TestWebspaceCompatHandlerRejectsTraversalId(t *testing.T) {
	delegate := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})
	h := makeWebspaceCompatHandler(delegate, "/srv/web")
	req := httptest.NewRequest(http.MethodGet, "/", nil)
	req.URL.Path = "/v1/webspaces/../files"
	w := httptest.NewRecorder()
	h.ServeHTTP(w, req)

	if w.Code != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", w.Code)
	}
}
