package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const mockSystemctlScript = `#!/bin/sh
case "$1" in
  is-active) echo "active" ; exit 0 ;;
  *) exit 0 ;;
esac
`

func setupTestEnv(t *testing.T) (instanceRoot string, cfg sinusbotsvcConfig) {
	t.Helper()

	scriptPath := filepath.Join(t.TempDir(), "mock-systemctl")
	if err := os.WriteFile(scriptPath, []byte(mockSystemctlScript), 0o755); err != nil {
		t.Fatalf("write mock systemctl: %v", err)
	}

	instanceRoot = t.TempDir()
	tmpUnitDir := t.TempDir()
	installDir := t.TempDir()

	if err := os.WriteFile(filepath.Join(installDir, "sinusbot"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatalf("create fake sinusbot binary: %v", err)
	}

	origSystemctl := systemctlBin
	origUnitDir := unitDir
	systemctlBin = scriptPath
	unitDir = tmpUnitDir
	t.Cleanup(func() {
		systemctlBin = origSystemctl
		unitDir = origUnitDir
	})

	cfg = sinusbotsvcConfig{
		AgentID:      "test-agent",
		Secret:       "test-secret",
		InstallDir:   installDir,
		InstanceRoot: instanceRoot,
		ServiceUser:  "root",
		WebPortBase:  19000,
		MaxSkew:      60 * time.Second,
	}
	return instanceRoot, cfg
}

func signedRequest(t *testing.T, cfg sinusbotsvcConfig, method, path, body string) *http.Request {
	t.Helper()
	ts := time.Now().UTC().Format(time.RFC3339)
	payload := buildSignaturePayload(cfg.AgentID, method, path, ts, body)
	sig := signPayload(payload, cfg.Secret)

	req := httptest.NewRequest(method, path, strings.NewReader(body))
	req.Header.Set("X-Agent-ID", cfg.AgentID)
	req.Header.Set("X-Timestamp", ts)
	req.Header.Set("X-Signature", sig)
	req.Header.Set("Content-Type", "application/json")
	return req
}

func createTestInstance(t *testing.T, srv *sinusbotsvcServer, customerID, quota int, username string) map[string]any {
	t.Helper()
	portBase := srv.cfg.WebPortBase
	if portBase == 0 {
		portBase = 19000
	}
	body := fmt.Sprintf(`{"customerId":%d,"quota":%d,"username":%q,"installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":%d}`,
		customerID, quota, username, srv.cfg.InstallDir, srv.cfg.InstanceRoot, portBase)
	req := signedRequest(t, srv.cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusCreated {
		t.Fatalf("create instance: expected 201, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	if err := json.NewDecoder(rr.Body).Decode(&result); err != nil {
		t.Fatalf("decode create response: %v", err)
	}
	return result
}

func seedInstance(t *testing.T, instanceRoot string, meta instanceMeta) {
	t.Helper()
	dir := filepath.Join(instanceRoot, meta.InstanceID)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatalf("seed instance dir: %v", err)
	}
	data, err := json.MarshalIndent(meta, "", "  ")
	if err != nil {
		t.Fatalf("marshal seed meta: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "meta.json"), data, 0o644); err != nil {
		t.Fatalf("write seed meta.json: %v", err)
	}
}

func TestCreateInstance(t *testing.T) {
	instanceRoot, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	result := createTestInstance(t, srv, 2, 5, "customer-2")

	if result["instanceId"] == "" {
		t.Error("expected non-empty instanceId")
	}
	if result["username"] != "customer-2" {
		t.Errorf("expected username customer-2, got %v", result["username"])
	}
	if result["password"] == "" {
		t.Error("expected non-empty password")
	}
	if _, ok := result["webPort"]; !ok {
		t.Error("expected webPort in response")
	}

	instanceID := result["instanceId"].(string)
	if _, err := os.Stat(filepath.Join(instanceRoot, instanceID, "meta.json")); err != nil {
		t.Errorf("meta.json not found: %v", err)
	}
}

func TestCreateInstance_DefaultUsername(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	body := fmt.Sprintf(`{"customerId":7,"quota":3,"username":"","installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir, cfg.InstanceRoot)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d", rr.Code)
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["username"] != "customer-7" {
		t.Errorf("expected username customer-7, got %v", result["username"])
	}
}

func TestCreateInstance_BinaryNotFound(t *testing.T) {
	_, cfg := setupTestEnv(t)
	cfg.InstallDir = t.TempDir() // no sinusbot binary here
	srv := &sinusbotsvcServer{cfg: cfg}

	body := fmt.Sprintf(`{"customerId":1,"quota":5,"installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir, cfg.InstanceRoot)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Errorf("expected 500 when binary missing, got %d", rr.Code)
	}
	if !strings.Contains(rr.Body.String(), "sinusbot binary not found") {
		t.Errorf("expected binary-not-found error, got: %s", rr.Body.String())
	}
}

func TestCreateInstance_InvalidUsername(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	body := fmt.Sprintf(`{"customerId":1,"quota":5,"username":"bad user!","installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir, cfg.InstanceRoot)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Errorf("expected 500 for invalid username, got %d", rr.Code)
	}
}

func TestStartStopRestartInstance(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	created := createTestInstance(t, srv, 6, 3, "customer-6")
	instanceID := created["instanceId"].(string)

	for _, tc := range []struct {
		action         string
		expectedStatus string
	}{
		{"start", "running"},
		{"stop", "stopped"},
		{"restart", "running"},
	} {
		path := "/internal/sinusbot/instances/" + instanceID + "/" + tc.action
		req := signedRequest(t, cfg, http.MethodPost, path, "")
		rr := httptest.NewRecorder()
		srv.routes().ServeHTTP(rr, req)

		if rr.Code != http.StatusOK {
			t.Fatalf("action %s: expected 200, got %d: %s", tc.action, rr.Code, rr.Body.String())
		}
		var result map[string]any
		_ = json.NewDecoder(rr.Body).Decode(&result)
		if result["status"] != tc.expectedStatus {
			t.Errorf("action %s: expected status %s, got %v", tc.action, tc.expectedStatus, result["status"])
		}
	}
}

func TestResetPassword(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	created := createTestInstance(t, srv, 10, 2, "customer-10")
	instanceID := created["instanceId"].(string)
	oldPassword := created["password"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/reset-password"
	req := signedRequest(t, cfg, http.MethodPost, path, "")
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)

	newPassword := result["password"].(string)
	if newPassword == "" {
		t.Error("expected non-empty new password")
	}
	if newPassword == oldPassword {
		t.Error("reset should generate a new password")
	}
}

func TestUpdateQuota(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	created := createTestInstance(t, srv, 11, 3, "customer-11")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/quota"
	req := signedRequest(t, cfg, http.MethodPost, path, `{"quota":10}`)
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if int(result["quota"].(float64)) != 10 {
		t.Errorf("expected quota 10, got %v", result["quota"])
	}
}

func TestDeleteInstance(t *testing.T) {
	instanceRoot, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	created := createTestInstance(t, srv, 13, 2, "customer-13")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID
	req := signedRequest(t, cfg, http.MethodDelete, path, "")
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	instancePath := filepath.Join(instanceRoot, instanceID)
	if _, err := os.Stat(instancePath); !os.IsNotExist(err) {
		t.Errorf("instance dir should be removed, stat err: %v", err)
	}
}

func TestPortNotReuseFromMeta(t *testing.T) {
	instanceRoot, cfg := setupTestEnv(t)
	cfg.WebPortBase = 19300
	srv := &sinusbotsvcServer{cfg: cfg}

	seedInstance(t, instanceRoot, instanceMeta{
		InstanceID: "seed-001",
		WebPort:    19300,
		Status:     "running",
	})

	result := createTestInstance(t, srv, 15, 2, "customer-15")
	port := int(result["webPort"].(float64))
	if port == 19300 {
		t.Error("should not reuse port 19300 already in meta.json")
	}
}

func TestUnauthorized(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	req := httptest.NewRequest(http.MethodPost, "/internal/sinusbot/instances", strings.NewReader(`{}`))
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusUnauthorized {
		t.Errorf("expected 401 without auth, got %d", rr.Code)
	}
}

func TestRelativePathRejected(t *testing.T) {
	_, cfg := setupTestEnv(t)
	srv := &sinusbotsvcServer{cfg: cfg}

	body := fmt.Sprintf(`{"customerId":1,"quota":5,"installDir":%q,"instanceRoot":"relative/path","webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.routes().ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Errorf("expected 500 for relative instanceRoot, got %d", rr.Code)
	}
}

func TestValidateName(t *testing.T) {
	valid := []string{"customer-1", "botuser", "my_bot", "Bot123"}
	for _, name := range valid {
		if err := validateName(name); err != nil {
			t.Errorf("validateName(%q) unexpected error: %v", name, err)
		}
	}
	invalid := []string{"", "has space", "has.dot", "../traversal"}
	for _, name := range invalid {
		if err := validateName(name); err == nil {
			t.Errorf("validateName(%q) expected error, got nil", name)
		}
	}
}

func TestMultipleInstancesDifferentPorts(t *testing.T) {
	_, cfg := setupTestEnv(t)
	cfg.WebPortBase = 19400
	srv := &sinusbotsvcServer{cfg: cfg}

	first := createTestInstance(t, srv, 20, 2, "customer-20")
	second := createTestInstance(t, srv, 21, 2, "customer-21")

	port1 := int(first["webPort"].(float64))
	port2 := int(second["webPort"].(float64))

	if port1 == port2 {
		t.Errorf("two instances must not share port, both got %d", port1)
	}
}
