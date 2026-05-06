package main

import (
	"encoding/json"
	"errors"
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

func TestLinuxPackagesForDistro(t *testing.T) {
	tests := []struct {
		name string
		osID string
		like string
		want []string
	}{
		{
			name: "debian like distro uses crypto module",
			osID: "ubuntu",
			want: []string{"proftpd-basic", "proftpd-mod-crypto"},
		},
		{
			name: "rhel like distro keeps mod_sftp package",
			osID: "rocky",
			want: []string{"proftpd", "proftpd-utils", "proftpd-mod_sftp"},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := linuxPackagesForDistro(tc.osID, tc.like)
			if strings.Join(got, ",") != strings.Join(tc.want, ",") {
				t.Fatalf("linuxPackagesForDistro(%q,%q)=%v, want %v", tc.osID, tc.like, got, tc.want)
			}
		})
	}
}

func TestProFTPDModuleDetectionRequiresModSFTP(t *testing.T) {
	withModule := "Compiled-in modules:\n  mod_core.c\n  mod_sftp.c\n  mod_auth_file.c\n"
	if !proFTPDModuleListHasSFTP(withModule) {
		t.Fatal("expected module list with mod_sftp.c to be accepted")
	}

	withoutModule := "Compiled-in modules:\n  mod_core.c\n  mod_auth_file.c\n"
	if proFTPDModuleListHasSFTP(withoutModule) {
		t.Fatal("expected module list without mod_sftp.c to be rejected")
	}

	err := assertErr("PROFTPD_SFTP_MODULE_MISSING: ProFTPD mod_sftp is not installed or enabled")
	if got := mapAccessErr(err); got != "PROFTPD_SFTP_MODULE_MISSING" {
		t.Fatalf("expected PROFTPD_SFTP_MODULE_MISSING, got %s", got)
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
	t.Cleanup(func() {
		if closeErr := ln.Close(); closeErr != nil && !errors.Is(closeErr, net.ErrClosed) {
			t.Errorf("close listener: %v", closeErr)
		}
	})
	port := ln.Addr().(*net.TCPAddr).Port
	if err := checkTCPListening(port); err != nil {
		t.Fatalf("expected reachable listener: %v", err)
	}
}

type assertErr string

func (e assertErr) Error() string { return string(e) }

func TestInstallLinuxPackagesInstallsModulePackagesEvenWhenProftpdExists(t *testing.T) {
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
	})

	accessLookPath = func(file string) (string, error) {
		switch file {
		case "proftpd", "apt-get":
			return "/usr/bin/" + file, nil
		default:
			return "", errors.New("missing")
		}
	}
	commands := []string{}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		commands = append(commands, name+" "+strings.Join(args, " "))
		return "", nil
	}

	packages := []string{"proftpd-basic", "proftpd-mod-crypto"}
	if err := installLinuxPackages(packages); err != nil {
		t.Fatalf("installLinuxPackages failed: %v", err)
	}
	joined := strings.Join(commands, "\n")
	if !strings.Contains(joined, "apt-get install -y proftpd-basic proftpd-mod-crypto") {
		t.Fatalf("expected apt-get install with module package despite proftpd binary, got:\n%s", joined)
	}
}

func TestEnableProFTPDSFTPModuleInFileIsIdempotent(t *testing.T) {
	path := filepath.Join(t.TempDir(), "modules.conf")
	if err := os.WriteFile(path, []byte("LoadModule mod_sftp.c\nLoadModule mod_tls.c\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	repaired, err := enableProFTPDSFTPModuleInFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if repaired {
		t.Fatal("expected already-enabled module file not to be repaired")
	}
	raw, _ := os.ReadFile(path)
	if got := strings.Count(string(raw), "LoadModule mod_sftp.c"); got != 1 {
		t.Fatalf("expected exactly one active module line, got %d in %q", got, string(raw))
	}
}

func TestEnableProFTPDSFTPModuleInFileUncommentsModule(t *testing.T) {
	path := filepath.Join(t.TempDir(), "modules.conf")
	if err := os.WriteFile(path, []byte("#LoadModule mod_sftp.c\nLoadModule mod_tls.c\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	repaired, err := enableProFTPDSFTPModuleInFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !repaired {
		t.Fatal("expected commented module line to be repaired")
	}
	raw, _ := os.ReadFile(path)
	if !strings.Contains(string(raw), "LoadModule mod_sftp.c\n") {
		t.Fatalf("expected uncommented module line, got %q", string(raw))
	}
	if strings.Contains(string(raw), "#LoadModule mod_sftp.c") {
		t.Fatalf("expected commented module line to be removed, got %q", string(raw))
	}
}

func TestEnsureProFTPDIncludeInFileIsIdempotent(t *testing.T) {
	path := filepath.Join(t.TempDir(), "proftpd.conf")
	confDir := filepath.Join(t.TempDir(), "conf.d")
	if err := os.WriteFile(path, []byte("ServerName test\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	repaired, err := ensureProFTPDIncludeInFile(path, confDir)
	if err != nil {
		t.Fatal(err)
	}
	if !repaired {
		t.Fatal("expected missing include to be repaired")
	}
	repaired, err = ensureProFTPDIncludeInFile(path, confDir)
	if err != nil {
		t.Fatal(err)
	}
	if repaired {
		t.Fatal("expected second include repair to be idempotent")
	}
	raw, _ := os.ReadFile(path)
	include := "Include " + confDir + "/*.conf"
	if got := strings.Count(string(raw), include); got != 1 {
		t.Fatalf("expected exactly one include line, got %d in %q", got, string(raw))
	}
}

func TestProvisionLinuxProFTPDUsesSharedReadyPath(t *testing.T) {
	origReady := ensureLinuxProFTPDSFTPReadyFunc
	origUser := ensureProFTPDUserFunc
	origHealth := checkLinuxProFTPDHealthFunc
	t.Cleanup(func() {
		ensureLinuxProFTPDSFTPReadyFunc = origReady
		ensureProFTPDUserFunc = origUser
		checkLinuxProFTPDHealthFunc = origHealth
	})
	calls := []string{}
	ensureLinuxProFTPDSFTPReadyFunc = func() error { calls = append(calls, "ready"); return nil }
	ensureProFTPDUserFunc = func(username, password, rootPath string) error {
		calls = append(calls, "user:"+username+":"+rootPath)
		return nil
	}
	checkLinuxProFTPDHealthFunc = func() error { calls = append(calls, "health"); return nil }

	if err := provisionLinuxProFTPD("gs_1", "secret", "/srv/game"); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(calls, ","); got != "ready,user:gs_1:/srv/game,health" {
		t.Fatalf("unexpected calls %s", got)
	}
}
