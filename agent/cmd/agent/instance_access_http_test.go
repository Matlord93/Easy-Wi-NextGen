package main

import (
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"golang.org/x/crypto/bcrypt"
)

func TestBuildProFTPDManagedConfigContainsMarkers(t *testing.T) {
	cfg := buildProFTPDManagedConfig(2222)
	if !strings.Contains(cfg, "# BEGIN EASYWI MANAGED") || !strings.Contains(cfg, "# END EASYWI MANAGED") {
		t.Fatalf("managed markers missing")
	}
	if !strings.Contains(cfg, "Port 2222") {
		t.Fatalf("port missing from config")
	}
}

func TestHandleInstanceAccessHTTPInvalidInput(t *testing.T) {
	req := httptest.NewRequest(http.MethodPost, "/v1/instances/7/access/provision", strings.NewReader(`{"username":""}`))
	w := httptest.NewRecorder()
	handled := handleInstanceAccessHTTP(w, req, "7")
	if !handled {
		t.Fatalf("expected handler to handle path")
	}
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", w.Code)
	}
	if !strings.Contains(w.Body.String(), "INVALID_INPUT") {
		t.Fatalf("expected INVALID_INPUT, got %s", w.Body.String())
	}
}

func TestMapAccessErr(t *testing.T) {
	if got := mapAccessErr(assertErr("PACKAGE_INSTALL_FAILED: boom")); got != "PACKAGE_INSTALL_FAILED" {
		t.Fatalf("unexpected code %s", got)
	}
	if got := mapAccessErr(assertErr("ROOT_INVALID: bad")); got != "ROOT_INVALID" {
		t.Fatalf("unexpected code %s", got)
	}
}

func TestCapabilitiesEndpoint(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/v1/access/capabilities", nil)
	w := httptest.NewRecorder()
	handled := handleAccessCapabilitiesHTTP(w, req)
	if !handled {
		t.Fatalf("expected handled=true")
	}
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	if !strings.Contains(w.Body.String(), "supported_backends") {
		t.Fatalf("missing supported_backends in response: %s", w.Body.String())
	}
}

func TestProvisionUserWritesBcryptHashAndRoot(t *testing.T) {
	usersFile := filepath.Join(t.TempDir(), "users.json")
	if err := upsertWindowsEmbeddedUserFile(usersFile, "gs_7", "secret-1", `C:\games\instance7`); err != nil {
		t.Fatalf("upsert failed: %v", err)
	}
	raw, err := os.ReadFile(usersFile)
	if err != nil {
		t.Fatal(err)
	}
	var users []map[string]any
	if err := json.Unmarshal(raw, &users); err != nil {
		t.Fatal(err)
	}
	if len(users) != 1 {
		t.Fatalf("expected 1 user, got %d", len(users))
	}
	hash, _ := users[0]["password_hash"].(string)
	if hash == "" || bcrypt.CompareHashAndPassword([]byte(hash), []byte("secret-1")) != nil {
		t.Fatalf("password hash invalid")
	}
	if users[0]["root_path"] != `C:\games\instance7` {
		t.Fatalf("root path mismatch: %v", users[0]["root_path"])
	}
}

func TestHealthReachabilityCheck(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	port := ln.Addr().(*net.TCPAddr).Port
	if err := checkTCPListening(port); err != nil {
		t.Fatalf("expected reachable listener: %v", err)
	}
}

type assertErr string

func (e assertErr) Error() string { return string(e) }
