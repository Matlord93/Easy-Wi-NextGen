package sinusbotsvcembed

import (
	"bytes"
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

// mockSystemctlScript returns always exit 0 for all subcommands and echoes
// "active" for "is-active" so serviceStatus returns "running".
const mockSystemctlScript = `#!/bin/sh
case "$1" in
  is-active) echo "active" ; exit 0 ;;
  *) exit 0 ;;
esac
`

// setupTestEnv configures package-level variables to use temp paths and a mock
// systemctl, and returns the temp instance root and a cleanup function.
func setupTestEnv(t *testing.T) (instanceRoot string, installDir string, cfg Config) {
	t.Helper()

	// Create mock systemctl binary.
	scriptPath := filepath.Join(t.TempDir(), "mock-systemctl")
	if err := os.WriteFile(scriptPath, []byte(mockSystemctlScript), 0o755); err != nil {
		t.Fatalf("write mock systemctl: %v", err)
	}

	// Temp directories for instances, unit files, and "install dir".
	instanceRoot = t.TempDir()
	tmpUnitDir := t.TempDir()
	installDir = t.TempDir()

	// Create a fake sinusbot binary so the existence check passes.
	if err := os.WriteFile(filepath.Join(installDir, "sinusbot"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatalf("create fake sinusbot binary: %v", err)
	}

	// Override package variables.
	origSystemctl := systemctlBin
	origUnitDir := unitDir
	systemctlBin = scriptPath
	unitDir = tmpUnitDir
	t.Cleanup(func() {
		systemctlBin = origSystemctl
		unitDir = origUnitDir
	})

	cfg = Config{
		AgentID:      "test-agent",
		Secret:       "test-secret",
		InstallDir:   installDir,
		InstanceRoot: instanceRoot,
		ServiceUser:  "root", // root always exists in test environments
		WebPortBase:  19000,
		MaxSkew:      60 * time.Second,
	}
	return instanceRoot, installDir, cfg
}

// signedRequest creates an HTTP request with the required HMAC auth headers.
func signedRequest(t *testing.T, cfg Config, method, path, body string) *http.Request {
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

// createTestInstance is a helper that creates an instance via the HTTP handler
// and returns the response body decoded as a map.
func createTestInstance(t *testing.T, srv *Server, customerID, quota int, username string) map[string]any {
	t.Helper()
	portBase := srv.cfg.WebPortBase
	if portBase == 0 {
		portBase = 19000
	}
	body := fmt.Sprintf(`{"customerId":%d,"quota":%d,"username":%q,"installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":%d}`,
		customerID, quota, username, srv.cfg.InstallDir, srv.cfg.InstanceRoot, portBase)
	req := signedRequest(t, srv.cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusCreated {
		t.Fatalf("create instance: expected 201, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	if err := json.NewDecoder(rr.Body).Decode(&result); err != nil {
		t.Fatalf("decode create response: %v", err)
	}
	return result
}

// seedInstance writes a meta.json directly into the instanceRoot so tests can
// exercise handlers that expect an existing instance without going through create.
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

// --- Tests ---

func TestCreateInstance(t *testing.T) {
	instanceRoot, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

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
	if result["status"] == "" {
		t.Error("expected non-empty status")
	}

	// meta.json must exist on disk.
	instanceID := result["instanceId"].(string)
	metaPath := filepath.Join(instanceRoot, instanceID, "meta.json")
	if _, err := os.Stat(metaPath); err != nil {
		t.Errorf("meta.json not found: %v", err)
	}

	// config.ini must exist.
	configPath := filepath.Join(instanceRoot, instanceID, "config.ini")
	if _, err := os.Stat(configPath); err != nil {
		t.Errorf("config.ini not found: %v", err)
	}
}

func TestCreateInstance_DefaultUsername(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	body := fmt.Sprintf(`{"customerId":7,"quota":3,"username":"","installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir, cfg.InstanceRoot)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d", rr.Code)
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["username"] != "customer-7" {
		t.Errorf("expected username customer-7, got %v", result["username"])
	}
}

func TestCreateInstance_MissingRequiredFields(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	for _, body := range []string{
		`{"customerId":0,"quota":5}`,
		`{"customerId":1,"quota":0}`,
		`{}`,
	} {
		req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
		rr := httptest.NewRecorder()
		srv.Handler().ServeHTTP(rr, req)
		if rr.Code != http.StatusBadRequest {
			t.Errorf("body %s: expected 400, got %d", body, rr.Code)
		}
	}
}

func TestCreateInstance_InvalidUsername(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	body := fmt.Sprintf(`{"customerId":1,"quota":5,"username":"bad user!","installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir, cfg.InstanceRoot)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Errorf("expected 500 for invalid username, got %d: %s", rr.Code, rr.Body.String())
	}
	if !strings.Contains(rr.Body.String(), "invalid username") {
		t.Errorf("expected error message about invalid username, got: %s", rr.Body.String())
	}
}

func TestCreateInstance_RelativePathRejected(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	for _, tc := range []struct {
		field string
		body  string
	}{
		{"instanceRoot", fmt.Sprintf(`{"customerId":1,"quota":5,"installDir":%q,"instanceRoot":"relative/path","webBindIp":"0.0.0.0","webPortBase":19000}`, cfg.InstallDir)},
		{"installDir", fmt.Sprintf(`{"customerId":1,"quota":5,"installDir":"relative/dir","instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`, cfg.InstanceRoot)},
	} {
		req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", tc.body)
		rr := httptest.NewRecorder()
		srv.Handler().ServeHTTP(rr, req)
		if rr.Code != http.StatusInternalServerError {
			t.Errorf("%s: expected 500 for relative path, got %d", tc.field, rr.Code)
		}
	}
}

func TestCreateInstance_BinaryNotFound(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	// Point installDir to a dir without the sinusbot binary.
	cfg.InstallDir = t.TempDir()
	srv := NewServer(cfg)

	body := fmt.Sprintf(`{"customerId":1,"quota":5,"installDir":%q,"instanceRoot":%q,"webBindIp":"0.0.0.0","webPortBase":19000}`,
		cfg.InstallDir, cfg.InstanceRoot)
	req := signedRequest(t, cfg, http.MethodPost, "/internal/sinusbot/instances", body)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusInternalServerError {
		t.Errorf("expected 500 when binary missing, got %d", rr.Code)
	}
	if !strings.Contains(rr.Body.String(), "sinusbot binary not found") {
		t.Errorf("expected binary-not-found error, got: %s", rr.Body.String())
	}
}

func TestCreateInstance_PortFromMeta(t *testing.T) {
	instanceRoot, _, cfg := setupTestEnv(t)
	cfg.WebPortBase = 19100
	srv := NewServer(cfg)

	// Seed a fake existing instance that occupies port 19100.
	seedInstance(t, instanceRoot, instanceMeta{
		InstanceID: "existing-001",
		WebPort:    19100,
		Status:     "running",
	})

	result := createTestInstance(t, srv, 3, 2, "customer-3")

	port := int(result["webPort"].(float64))
	if port == 19100 {
		t.Error("new instance should not reuse port 19100 that is already in meta.json")
	}
	// Must be somewhere in the search range starting from base 19100.
	if port < 19101 || port > 19200 {
		t.Errorf("port %d out of expected range [19101, 19200]", port)
	}
}

func TestGetInstanceInfo(t *testing.T) {
	instanceRoot, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 5, 4, "customer-5")
	instanceID := created["instanceId"].(string)
	_ = instanceRoot

	path := "/internal/sinusbot/instances/" + instanceID
	req := signedRequest(t, cfg, http.MethodGet, path, "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["instanceId"] != instanceID {
		t.Errorf("expected instanceId %s, got %v", instanceID, result["instanceId"])
	}
}

func TestGetInstanceInfo_NotFound(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	req := signedRequest(t, cfg, http.MethodGet, "/internal/sinusbot/instances/nonexistent", "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusNotFound {
		t.Errorf("expected 404, got %d", rr.Code)
	}
}

func TestStartInstance(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 6, 3, "customer-6")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/start"
	req := signedRequest(t, cfg, http.MethodPost, path, "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["status"] != "running" {
		t.Errorf("expected status running, got %v", result["status"])
	}
}

func TestStopInstance(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 8, 2, "customer-8")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/stop"
	req := signedRequest(t, cfg, http.MethodPost, path, "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["status"] != "stopped" {
		t.Errorf("expected status stopped, got %v", result["status"])
	}
}

func TestRestartInstance(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 9, 2, "customer-9")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/restart"
	req := signedRequest(t, cfg, http.MethodPost, path, "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["status"] != "running" {
		t.Errorf("expected status running after restart, got %v", result["status"])
	}
}

func TestResetPassword(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 10, 2, "customer-10")
	instanceID := created["instanceId"].(string)
	oldPassword := created["password"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/reset-password"
	req := signedRequest(t, cfg, http.MethodPost, path, "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)

	newPassword, ok := result["password"].(string)
	if !ok || newPassword == "" {
		t.Error("expected non-empty new password")
	}
	if newPassword == oldPassword {
		t.Error("reset password should generate a new password")
	}
	if result["username"] != "customer-10" {
		t.Errorf("expected username customer-10, got %v", result["username"])
	}
}

func TestUpdateQuota(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 11, 3, "customer-11")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID + "/quota"
	body := `{"quota":10}`
	req := signedRequest(t, cfg, http.MethodPost, path, body)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if int(result["quota"].(float64)) != 10 {
		t.Errorf("expected quota 10, got %v", result["quota"])
	}
}

func TestUpdateQuota_Invalid(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 12, 3, "customer-12")
	instanceID := created["instanceId"].(string)

	for _, body := range []string{`{"quota":0}`, `{"quota":-1}`, `{}`} {
		path := "/internal/sinusbot/instances/" + instanceID + "/quota"
		req := signedRequest(t, cfg, http.MethodPost, path, body)
		rr := httptest.NewRecorder()
		srv.Handler().ServeHTTP(rr, req)
		if rr.Code != http.StatusBadRequest {
			t.Errorf("body %s: expected 400, got %d", body, rr.Code)
		}
	}
}

func TestDeleteInstance(t *testing.T) {
	instanceRoot, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	created := createTestInstance(t, srv, 13, 2, "customer-13")
	instanceID := created["instanceId"].(string)

	path := "/internal/sinusbot/instances/" + instanceID
	req := signedRequest(t, cfg, http.MethodDelete, path, "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var result map[string]any
	_ = json.NewDecoder(rr.Body).Decode(&result)
	if result["status"] != "deleted" {
		t.Errorf("expected status deleted, got %v", result["status"])
	}

	// Instance directory must be gone.
	instancePath := filepath.Join(instanceRoot, instanceID)
	if _, err := os.Stat(instancePath); !os.IsNotExist(err) {
		t.Errorf("expected instance dir to be removed, stat err: %v", err)
	}
}

func TestDeleteInstance_NotFound(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	req := signedRequest(t, cfg, http.MethodDelete, "/internal/sinusbot/instances/nonexistent", "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusNotFound {
		t.Errorf("expected 404, got %d", rr.Code)
	}
}

func TestUnauthorized_MissingHeaders(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	req := httptest.NewRequest(http.MethodPost, "/internal/sinusbot/instances", bytes.NewBufferString(`{}`))
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusUnauthorized {
		t.Errorf("expected 401 without auth headers, got %d", rr.Code)
	}
}

func TestUnauthorized_WrongSecret(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	ts := time.Now().UTC().Format(time.RFC3339)
	payload := buildSignaturePayload(cfg.AgentID, "POST", "/internal/sinusbot/instances", ts, `{}`)
	wrongSig := signPayload(payload, "wrong-secret")

	req := httptest.NewRequest(http.MethodPost, "/internal/sinusbot/instances", bytes.NewBufferString(`{}`))
	req.Header.Set("X-Agent-ID", cfg.AgentID)
	req.Header.Set("X-Timestamp", ts)
	req.Header.Set("X-Signature", wrongSig)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusUnauthorized {
		t.Errorf("expected 401 for wrong secret, got %d", rr.Code)
	}
}

func TestMethodNotAllowed(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	req := signedRequest(t, cfg, http.MethodGet, "/internal/sinusbot/instances", "")
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusMethodNotAllowed {
		t.Errorf("expected 405 for GET on /instances, got %d", rr.Code)
	}
}

func TestActionsOnNonExistentInstance(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	for _, action := range []string{"start", "stop", "restart", "reset-password"} {
		path := "/internal/sinusbot/instances/ghost/" + action
		req := signedRequest(t, cfg, http.MethodPost, path, "")
		rr := httptest.NewRecorder()
		srv.Handler().ServeHTTP(rr, req)
		if rr.Code != http.StatusNotFound {
			t.Errorf("action %s: expected 404, got %d", action, rr.Code)
		}
	}
}

func TestValidateName(t *testing.T) {
	valid := []string{"customer-1", "botuser", "my_bot", "Bot123", "A-B-C"}
	for _, name := range valid {
		if err := validateName(name); err != nil {
			t.Errorf("validateName(%q) returned unexpected error: %v", name, err)
		}
	}

	invalid := []string{"", "bad user", "has.dot", "has/slash", "has@at", "../traversal"}
	for _, name := range invalid {
		if err := validateName(name); err == nil {
			t.Errorf("validateName(%q) expected error but got nil", name)
		}
	}
}

func TestNextAvailablePort_SkipsUsedPorts(t *testing.T) {
	instanceRoot := t.TempDir()

	// Seed two instances occupying ports 19000 and 19001.
	for _, port := range []int{19000, 19001} {
		seedInstance(t, instanceRoot, instanceMeta{
			InstanceID: fmt.Sprintf("inst-%d", port),
			WebPort:    port,
		})
	}

	port, err := nextAvailablePort(instanceRoot, 19000)
	if err != nil {
		t.Fatalf("nextAvailablePort: %v", err)
	}
	if port == 19000 || port == 19001 {
		t.Errorf("expected port other than 19000/19001, got %d", port)
	}
}

func TestHealthz(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	srv := NewServer(cfg)

	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rr := httptest.NewRecorder()
	srv.Handler().ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Errorf("expected 200 for /healthz, got %d", rr.Code)
	}
}

func TestCreateMultipleInstancesDifferentPorts(t *testing.T) {
	_, _, cfg := setupTestEnv(t)
	cfg.WebPortBase = 19200
	srv := NewServer(cfg)

	first := createTestInstance(t, srv, 20, 2, "customer-20")
	second := createTestInstance(t, srv, 21, 2, "customer-21")

	port1 := int(first["webPort"].(float64))
	port2 := int(second["webPort"].(float64))

	if port1 == port2 {
		t.Errorf("two instances must not share a port, both got %d", port1)
	}
}
