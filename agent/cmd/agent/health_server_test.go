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

func TestCollectMailHealthChecksContainsExpectedKeys(t *testing.T) {
	checks := collectMailHealthChecks()
	required := []string{
		"postfix_installed",
		"dovecot_installed",
		"postmap_available",
		"postfix_map_file",
		"postfix_domain_map",
		"postfix_alias_map",
		"dovecot_users_file",
		"maildir_writable",
		"postfix_active",
		"dovecot_active",
		"port_listen_25",
		"port_listen_587",
		"port_listen_993",
	}
	for _, key := range required {
		if _, ok := checks[key]; !ok {
			t.Fatalf("missing check key %s", key)
		}
	}
}

func TestCollectMailHealthChecksDoesNotExposeMailContentFields(t *testing.T) {
	checks := collectMailHealthChecks()
	for key, check := range checks {
		if key == "" {
			t.Fatal("empty key not allowed")
		}
		if check.Message == "subject" || check.Message == "body" {
			t.Fatalf("unexpected sensitive content marker in %s", key)
		}
	}
}
