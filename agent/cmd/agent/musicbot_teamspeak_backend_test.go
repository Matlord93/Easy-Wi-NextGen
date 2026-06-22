package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestTeamspeakBackendValidPathAccepted(t *testing.T) {
	t.Parallel()
	job := teamspeakBackendJob(t, "mock")
	result := handleMusicbotTeamspeakBackendValidate(job)
	if result.status != "success" {
		t.Fatalf("validate status=%s error=%s payload=%#v", result.status, result.errorText, result.resultPayload)
	}
	if result.resultPayload["status"] != teamspeakBackendStatusReady {
		t.Fatalf("status=%v, want ready", result.resultPayload["status"])
	}
}

func TestTeamspeakBackendMissingPathIsBinaryMissing(t *testing.T) {
	t.Parallel()
	job := teamspeakBackendJob(t, "mock")
	job.Payload["backend_path"] = filepath.Join(t.TempDir(), "missing-easywi-teamspeak-client")
	result := handleMusicbotTeamspeakBackendValidate(job)
	if result.resultPayload["status"] != teamspeakBackendStatusBinaryMissing {
		t.Fatalf("status=%v, want binary_missing", result.resultPayload["status"])
	}
}

func TestTeamspeakBackendNonExecutablePath(t *testing.T) {
	t.Parallel()
	job := teamspeakBackendJob(t, "mock")
	path := job.Payload["backend_path"].(string)
	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatal(err)
	}
	result := handleMusicbotTeamspeakBackendValidate(job)
	if result.resultPayload["status"] != teamspeakBackendStatusBinaryNotExecutable {
		t.Fatalf("status=%v, want binary_not_executable", result.resultPayload["status"])
	}
}

func TestTeamspeakBackendMissingLibraryAndOpus(t *testing.T) {
	t.Parallel()
	job := teamspeakBackendJob(t, "mock")
	job.Payload["library_path"] = filepath.Join(t.TempDir(), "missing")
	result := handleMusicbotTeamspeakBackendValidate(job)
	if result.resultPayload["status"] != teamspeakBackendStatusLibraryMissing {
		t.Fatalf("status=%v, want library_missing", result.resultPayload["status"])
	}

	job = teamspeakBackendJob(t, "mock")
	if err := os.Remove(filepath.Join(job.Payload["install_path"].(string), "libopus.so")); err != nil {
		t.Fatal(err)
	}
	job.Payload["opus_library_path"] = filepath.Join(job.Payload["install_path"].(string), "libopus.so")
	result = handleMusicbotTeamspeakBackendValidate(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOpusMissing {
		t.Fatalf("status=%v, want opus_missing", result.resultPayload["status"])
	}
}

func TestTeamspeakBackendStubBuildRequiresClientBackend(t *testing.T) {
	t.Parallel()
	job := teamspeakBackendJob(t, "stub")
	result := handleMusicbotTeamspeakBackendValidate(job)
	if result.resultPayload["status"] != teamspeakBackendStatusClientBackendRequired {
		t.Fatalf("status=%v, want client_backend_required", result.resultPayload["status"])
	}
}

func TestTeamspeakBackendConnectionTestConnectedAndSecretMasked(t *testing.T) {
	t.Parallel()
	job := teamspeakBackendJob(t, "mock")
	job.Payload["host"] = "127.0.0.1"
	job.Payload["channel_id"] = "42"
	job.Payload["server_password"] = "wrong-secret"
	job.Payload["channel_password"] = "channel-secret"
	result := handleMusicbotTeamspeakBackendTestConnection(job)
	if result.status != "success" || result.resultPayload["status"] != teamspeakBackendStatusConnected {
		t.Fatalf("result=%s status=%v error=%s", result.status, result.resultPayload["status"], result.errorText)
	}
	if strings.Contains(fmt.Sprint(result.resultPayload), "wrong-secret") || strings.Contains(result.errorText, "wrong-secret") {
		t.Fatalf("secret leaked in result: %#v error=%q", result.resultPayload, result.errorText)
	}

	failJob := teamspeakBackendJob(t, "fail-secret")
	failJob.Payload["host"] = "127.0.0.1"
	failJob.Payload["server_password"] = "wrong-secret"
	failResult := handleMusicbotTeamspeakBackendTestConnection(failJob)
	if failResult.resultPayload["status"] != teamspeakBackendStatusFailed {
		t.Fatalf("status=%v, want failed", failResult.resultPayload["status"])
	}
	if strings.Contains(failResult.errorText, "wrong-secret") || strings.Contains(fmt.Sprint(failResult.resultPayload), "wrong-secret") {
		t.Fatalf("secret leaked in failure: %#v error=%q", failResult.resultPayload, failResult.errorText)
	}
}

func TestTeamspeakBackendRejectsForeignMusicbotBinaries(t *testing.T) {
	t.Parallel()
	for _, name := range []string{"sinusbot", "TS3AudioBot", "lavalink"} {
		job := teamspeakBackendJob(t, "mock")
		bad := filepath.Join(filepath.Dir(job.Payload["backend_path"].(string)), name)
		if err := os.Rename(job.Payload["backend_path"].(string), bad); err != nil {
			t.Fatal(err)
		}
		job.Payload["backend_path"] = bad
		job.Payload["binary_path"] = bad
		result := handleMusicbotTeamspeakBackendValidate(job)
		if result.resultPayload["status"] != teamspeakBackendStatusInvalidPermissions {
			t.Fatalf("%s status=%v, want invalid_permissions", name, result.resultPayload["status"])
		}
	}
}

func TestTeamspeakOfficialClientURLAllowlist(t *testing.T) {
	if err := validateTeamspeakOfficialClientURL("https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run"); err != nil {
		t.Fatalf("official URL rejected: %v", err)
	}
	for _, rawURL := range []string{
		"http://files.teamspeak-services.com/client.run",
		"https://example.com/client.run",
		"file:///tmp/client.run",
		"ftp://files.teamspeak-services.com/client.run",
		"https://localhost/client.run",
	} {
		if err := validateTeamspeakOfficialClientURL(rawURL); err == nil {
			t.Fatalf("URL %q accepted, want blocked", rawURL)
		}
	}
}

func TestTeamspeakOfficialClientChecksumMismatch(t *testing.T) {
	body := []byte("#!/bin/sh\nexit 0\n")
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(body)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", "wrong")
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOfficialChecksumFailed {
		t.Fatalf("status=%v, want checksum_failed", result.resultPayload["status"])
	}
}

func TestTeamspeakOfficialClientDownloadFailed(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "nope", http.StatusNotFound)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", "")
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOfficialDownloadFailed {
		t.Fatalf("status=%v, want download_failed", result.resultPayload["status"])
	}
}

func TestTeamspeakOfficialClientInstallerWithoutLibrary(t *testing.T) {
	installer := []byte("#!/bin/sh\nmkdir -p \"$2\"\nprintf helper > \"$2/TeamSpeak3-Client\"\n")
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOfficialLibraryMissing {
		t.Fatalf("status=%v, want installed_library_missing, error=%s", result.resultPayload["status"], result.errorText)
	}
}

func TestTeamspeakOfficialClientInstallerWithLibrary(t *testing.T) {
	installer := []byte("#!/bin/sh\nmkdir -p \"$2\"\nprintf library > \"$2/libts3client.so\"\nprintf opus > \"$2/libopus.so\"\n")
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.status != "success" || result.resultPayload["status"] != teamspeakBackendStatusOfficialInstalled {
		t.Fatalf("result=%s status=%v error=%s", result.status, result.resultPayload["status"], result.errorText)
	}
	if fmt.Sprint(result.resultPayload["library_path"]) == "" {
		t.Fatalf("library_path missing in result: %#v", result.resultPayload)
	}
	if strings.Contains(fmt.Sprint(result.resultPayload), "server_password") || strings.Contains(fmt.Sprint(result.resultPayload), "channel_password") {
		t.Fatalf("secret-looking keys leaked in result: %#v", result.resultPayload)
	}
}

func TestTeamspeakOfficialClientForeignRedirectBlocked(t *testing.T) {
	foreign := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte("payload"))
	}))
	defer foreign.Close()

	redirecting := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, foreign.URL+"/client.run", http.StatusFound)
	}))
	defer redirecting.Close()

	redirectingURL, _ := url.Parse(redirecting.URL)
	restore := mockOfficialClientDownload(t, redirecting.URL, "files.teamspeak-services.com")
	defer restore()

	// The redirect target is the foreign test server, not the allowed host.
	// The CheckRedirect validator must reject it.
	teamspeakOfficialHTTPClient = &http.Client{
		Timeout:   teamspeakOfficialClientDownloadTimeout,
		Transport: rewriteHostTransport{target: redirectingURL, base: http.DefaultTransport},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 5 {
				return fmt.Errorf("too many redirects")
			}
			return validateTeamspeakOfficialClientURL(req.URL.String())
		},
	}

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", "")
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOfficialDownloadFailed {
		t.Fatalf("foreign redirect: status=%v, want download_failed; error=%s", result.resultPayload["status"], result.errorText)
	}
	if result.status == "success" {
		t.Fatalf("foreign redirect: expected failure but got success")
	}
}

func TestTeamspeakOfficialClientInstallPathInjectionRejected(t *testing.T) {
	for _, badPath := range []string{
		"relative/path",
		"/valid/teamspeak-client\x00injected",
		"/valid/teamspeak-client\ninjected",
		"/no-teamspeak-here/",
		"/",
	} {
		job := jobs.Job{ID: "job-ts-official", Type: "musicbot.teamspeak_backend.install_official_client", Payload: map[string]any{
			"node_id":                       "agent-1",
			"version":                       "3.6.2",
			"download_url":                  "https://files.teamspeak-services.com/client.run",
			"expected_sha256":               "",
			"install_path":                  badPath,
			"requested_by":                  "1",
			"accepted_license_confirmation": "true",
		}}
		result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
		if result.status == "success" {
			t.Fatalf("inject path %q was accepted, want failure", badPath)
		}
	}
}

func TestTeamspeakOfficialClientInstallerSuggestsBackendType(t *testing.T) {
	installer := []byte("#!/bin/sh\nmkdir -p \"$2\"\nprintf library > \"$2/libts3client.so\"\nprintf opus > \"$2/libopus.so\"\n")
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.status != "success" {
		t.Fatalf("expected success, got status=%v error=%s", result.resultPayload["status"], result.errorText)
	}
	if fmt.Sprint(result.resultPayload["backend_type_suggestion"]) != "client_library" {
		t.Fatalf("backend_type_suggestion missing or wrong: %v", result.resultPayload["backend_type_suggestion"])
	}
}

func TestTeamspeakOfficialClientRequiresConfirmation(t *testing.T) {
	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", "")
	job.Payload["accepted_license_confirmation"] = "false"
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOfficialInvalid {
		t.Fatalf("status=%v, want official_client_invalid", result.resultPayload["status"])
	}
}

func teamspeakBackendJob(t *testing.T, mode string) jobs.Job {
	t.Helper()
	dir := t.TempDir()
	bin := filepath.Join(dir, "easywi-teamspeak-client")
	library := filepath.Join(dir, "libts3client.so")
	opus := filepath.Join(dir, "libopus.so")
	if err := os.WriteFile(library, []byte("mock ts library"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(opus, []byte("mock opus library"), 0o644); err != nil {
		t.Fatal(err)
	}
	script := mockTeamspeakClientScript(mode)
	if err := os.WriteFile(bin, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	return jobs.Job{ID: "job-ts-backend", Type: "musicbot.teamspeak_backend.validate", Payload: map[string]any{
		"backend_type":      "client_library",
		"backend_path":      bin,
		"binary_path":       bin,
		"library_path":      library,
		"opus_library_path": opus,
		"install_path":      dir,
		"profile":           "ts3",
		"nickname":          "EasyWi-Test",
	}}
}

func teamspeakOfficialClientJob(t *testing.T, downloadURL string, expectedSHA string) jobs.Job {
	t.Helper()
	return jobs.Job{ID: "job-ts-official", Type: "musicbot.teamspeak_backend.install_official_client", Payload: map[string]any{
		"node_id":                       "agent-1",
		"version":                       "3.6.2",
		"download_url":                  downloadURL,
		"expected_sha256":               expectedSHA,
		"install_path":                  filepath.Join(t.TempDir(), "teamspeak-client", "official-client"),
		"requested_by":                  "1",
		"accepted_license_confirmation": "true",
	}}
}

func mockOfficialClientDownload(t *testing.T, targetURL string, allowedHost string) func() {
	t.Helper()
	originalHosts := teamspeakOfficialClientAllowedHosts
	originalClient := teamspeakOfficialHTTPClient
	target, err := url.Parse(targetURL)
	if err != nil {
		t.Fatal(err)
	}
	teamspeakOfficialClientAllowedHosts = map[string]bool{allowedHost: true}
	teamspeakOfficialHTTPClient = &http.Client{
		Timeout:   teamspeakOfficialClientDownloadTimeout,
		Transport: rewriteHostTransport{target: target, base: http.DefaultTransport},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 5 {
				return fmt.Errorf("too many redirects")
			}
			return validateTeamspeakOfficialClientURL(req.URL.String())
		},
	}
	return func() {
		teamspeakOfficialClientAllowedHosts = originalHosts
		teamspeakOfficialHTTPClient = originalClient
	}
}

type rewriteHostTransport struct {
	target *url.URL
	base   http.RoundTripper
}

func (t rewriteHostTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	clone := req.Clone(req.Context())
	clone.URL.Scheme = t.target.Scheme
	clone.URL.Host = t.target.Host
	return t.base.RoundTrip(clone)
}

func mockTeamspeakClientScript(mode string) string {
	return fmt.Sprintf(`#!/bin/sh
mode=%q
while IFS= read -r line; do
  case "$line" in
    *'"action":"status"'*) printf '{"ok":true,"state":"disconnected","build_mode":"%%s"}\n' "$mode" ;;
    *'"action":"connect"'*)
      if [ "$mode" = "stub" ]; then printf '{"ok":false,"error":"TeamSpeak client SDK not installed"}\n';
      elif [ "$mode" = "fail-secret" ]; then printf '{"ok":false,"error":"login failed for wrong-secret"}\n';
      else printf '{"ok":true,"ready":true,"state":"connected","client_id":"7","build_mode":"%%s"}\n' "$mode"; fi ;;
    *'"action":"join_channel"'*) printf '{"ok":true,"channel_id":"42","build_mode":"%%s"}\n' "$mode" ;;
    *'"action":"set_nickname"'*) printf '{"ok":true,"build_mode":"%%s"}\n' "$mode" ;;
    *'"action":"send_opus_frame"'*) printf '{"ok":true,"build_mode":"%%s"}\n' "$mode" ;;
    *'"action":"shutdown"'*) printf '{"ok":true,"state":"disconnected","build_mode":"%%s"}\n' "$mode"; exit 0 ;;
    *) printf '{"ok":false,"error":"unknown"}\n' ;;
  esac
done
`, mode)
}
