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
	"time"

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

// PTY-specific tests

func TestTeamspeakOfficialClientPTYRequiresTTY(t *testing.T) {
	// Installer exits with error unless stdin is a real TTY.
	installer := []byte("#!/bin/sh\nif ! [ -t 0 ]; then echo 'TTY required' >&2; exit 1; fi\nmkdir -p \"$2\"\nprintf library > \"$2/libts3client.so\"\nprintf opus > \"$2/libopus.so\"\n")
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
		t.Fatalf("PTY-required installer: status=%s payload_status=%v error=%s", result.status, result.resultPayload["status"], result.errorText)
	}
	if fmt.Sprint(result.resultPayload["library_path"]) == "" {
		t.Fatalf("library_path missing in result: %#v", result.resultPayload)
	}
}

func TestTeamspeakOfficialClientPTYSendsEnterAndY(t *testing.T) {
	// Installer prints a "license agreement" prompt, reads Enter, then reads the accept answer.
	// The PTY loop must send "\r" for the first read and "y\r" for the second.
	installer := []byte("#!/bin/sh\nprintf 'Please review the license terms and conditions.\\nDo you accept the license agreement? [y/n]: '\nread -r _dummy\nread -r answer\nif [ \"$answer\" = \"y\" ]; then\nmkdir -p \"$2\"\nprintf library > \"$2/libts3client.so\"\nprintf opus > \"$2/libopus.so\"\nexit 0\nfi\nexit 1\n")
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
		t.Fatalf("PTY Enter+Y: status=%s payload_status=%v error=%s", result.status, result.resultPayload["status"], result.errorText)
	}
}

func TestTeamspeakOfficialClientPTYTimeout(t *testing.T) {
	// Installer sleeps forever; expect timeout error.
	installer := []byte("#!/bin/sh\nsleep 9999\n")
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	// Use a very short installer timeout so the test finishes quickly.
	old := teamspeakOfficialClientInstallerTimeout
	teamspeakOfficialClientInstallerTimeout = 2 * time.Second
	defer func() { teamspeakOfficialClientInstallerTimeout = old }()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.status == "success" {
		t.Fatalf("expected failure for sleep-forever installer, got success")
	}
	if !strings.Contains(result.errorText, "timed out") {
		t.Fatalf("expected timeout error, got: %s", result.errorText)
	}
}

func TestTeamspeakOfficialClientOutputLimited(t *testing.T) {
	// Installer emits 16 KB of noise then creates the files.
	var sb strings.Builder
	sb.WriteString("#!/bin/sh\n")
	for i := 0; i < 200; i++ {
		sb.WriteString("printf 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\\n'\n")
	}
	sb.WriteString("mkdir -p \"$2\"\nprintf library > \"$2/libts3client.so\"\nprintf opus > \"$2/libopus.so\"\n")
	installer := []byte(sb.String())
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	// Should succeed despite large output.
	if result.status != "success" {
		t.Fatalf("large-output installer failed unexpectedly: status=%s error=%s", result.status, result.errorText)
	}
	// last_error must be bounded even when set.
	if errStr := fmt.Sprint(result.resultPayload["last_error"]); len(errStr) > 2500 {
		t.Fatalf("last_error too long: %d chars", len(errStr))
	}
}

func TestTeamspeakOfficialClientTermsNotInLastError(t *testing.T) {
	// Installer prints a fake license block then exits with error.
	installer := []byte("#!/bin/sh\nprintf 'END USER LICENSE AGREEMENT\\nLorem ipsum legal boilerplate\\nterms and conditions apply\\n'\nexit 1\n")
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.status == "success" {
		t.Skip("installer unexpectedly succeeded")
	}
	combined := result.errorText + fmt.Sprint(result.resultPayload["last_error"])
	if len(combined) > 5000 {
		t.Fatalf("error output too long (%d chars), license text may not be sanitized", len(combined))
	}
	// Full license body must not appear verbatim; only placeholder is acceptable.
	if strings.Count(combined, "boilerplate") > 1 {
		t.Fatalf("license text leaked into error output: %s", combined[:min(300, len(combined))])
	}
}

func TestTeamspeakOfficialClientNoShellInjection(t *testing.T) {
	t.Parallel()
	for _, bad := range []string{
		"/opt/teamspeak-client/;rm -rf /tmp/x", // semicolon
		"/opt/teamspeak-client/$(whoami)",      // command substitution
		"/opt/teamspeak-client/`id`",           // backtick substitution
		"/opt/teamspeak-client/\x00evil",       // null byte
		"/opt/teamspeak-client/\nevil",         // newline
		"/opt/teamspeak-client/a|b",            // pipe
		"/opt/teamspeak-client/a&b",            // background operator
	} {
		job := jobs.Job{ID: "job-ts-inject", Type: "musicbot.teamspeak_backend.install_official_client", Payload: map[string]any{
			"node_id":                       "agent-1",
			"version":                       "3.6.2",
			"download_url":                  "https://files.teamspeak-services.com/client.run",
			"expected_sha256":               "",
			"install_path":                  bad,
			"requested_by":                  "1",
			"accepted_license_confirmation": "true",
		}}
		result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
		if result.status == "success" {
			t.Fatalf("injection path %q was accepted, want rejection", bad)
		}
	}
}

func TestTeamspeakOfficialClientAcceptedConfirmationFalseNoPTY(t *testing.T) {
	t.Parallel()
	// When confirmation is false the installer must never be run.
	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", "")
	job.Payload["accepted_license_confirmation"] = "false"
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)
	if result.resultPayload["status"] != teamspeakBackendStatusOfficialInvalid {
		t.Fatalf("status=%v, want official_client_invalid", result.resultPayload["status"])
	}
	if result.status == "success" {
		t.Fatalf("expected failure when confirmation=false")
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

// TestTeamspeakExternalBridgePackagesComplete verifies that all packages required
// for Xvfb + Qt/XCB + PulseAudio are included in the dependency installer list.
func TestTeamspeakExternalBridgePackagesComplete(t *testing.T) {
	t.Parallel()
	required := []string{
		"xvfb",
		"x11-utils",
		"dbus-x11",
		"libxcb-xinerama0",
		"libxkbcommon-x11-0",
		"libgtk-3-0",
		"libpulse0",
		"pulseaudio",
	}
	if len(teamspeakExternalBridgePackages) == 0 {
		t.Fatal("teamspeakExternalBridgePackages is empty")
	}
	for _, pkg := range required {
		found := false
		for _, p := range teamspeakExternalBridgePackages {
			if p == pkg {
				found = true
				break
			}
		}
		if !found {
			t.Errorf("required package %q not in teamspeakExternalBridgePackages", pkg)
		}
	}
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

// teamspeakExternalBridgeDir creates a minimal external_client_bridge directory
// layout in a temp dir. When withPlugin=true the ClientQuery plugin file is
// also created so validation advances past the plugin check.
func teamspeakExternalBridgeDir(t *testing.T, withPlugin bool) (bridgePath, officialClientDir string) {
	t.Helper()
	dir := t.TempDir()

	// Bridge binary
	bridgePath = filepath.Join(dir, "easywi-teamspeak-bridge")
	if err := os.WriteFile(bridgePath, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	// Official client dir with executable client binary and runscript
	officialClientDir = filepath.Join(dir, "official-client")
	if err := os.MkdirAll(officialClientDir, 0o755); err != nil {
		t.Fatal(err)
	}
	clientBin := filepath.Join(officialClientDir, "ts3client_linux_amd64")
	if err := os.WriteFile(clientBin, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	runscript := filepath.Join(officialClientDir, "ts3client_runscript.sh")
	if err := os.WriteFile(runscript, []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	if withPlugin {
		pluginDir := filepath.Join(officialClientDir, "plugins")
		if err := os.MkdirAll(pluginDir, 0o755); err != nil {
			t.Fatal(err)
		}
		pluginFile := filepath.Join(pluginDir, clientQueryPluginFilename)
		if err := os.WriteFile(pluginFile, []byte("mock plugin so"), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	return bridgePath, officialClientDir
}

// TestExternalBridgeValidateClientQueryPluginMissing verifies that validation
// returns clientquery_plugin_missing when the plugin file is absent from
// official-client/plugins/.
func TestExternalBridgeValidateClientQueryPluginMissing(t *testing.T) {
	t.Parallel()
	bridgePath, officialClientDir := teamspeakExternalBridgeDir(t, false /* withPlugin=false */)
	clientBin := filepath.Join(officialClientDir, "ts3client_linux_amd64")
	runscript := filepath.Join(officialClientDir, "ts3client_runscript.sh")

	cfg := teamspeakBackendConfig{
		BackendType:         "external_client_bridge",
		BridgePath:          bridgePath,
		ClientBinaryPath:    clientBin,
		ClientRunscriptPath: runscript,
	}
	result := validateTeamspeakExternalClientBridgeConfig(cfg)
	if result.Status != teamspeakBackendStatusClientQueryPluginMissing {
		t.Fatalf("status=%s, want %s", result.Status, teamspeakBackendStatusClientQueryPluginMissing)
	}
	if !strings.Contains(result.LastError, clientQueryPluginFilename) {
		t.Fatalf("last_error %q does not mention plugin filename", result.LastError)
	}
}

// TestExternalBridgeValidatePluginPresent verifies that when the ClientQuery
// plugin file exists validation proceeds past the plugin check (even if it
// fails later at Xvfb/audio which are not available in CI).
func TestExternalBridgeValidatePluginPresent(t *testing.T) {
	t.Parallel()
	bridgePath, officialClientDir := teamspeakExternalBridgeDir(t, true /* withPlugin=true */)
	clientBin := filepath.Join(officialClientDir, "ts3client_linux_amd64")
	runscript := filepath.Join(officialClientDir, "ts3client_runscript.sh")

	cfg := teamspeakBackendConfig{
		BackendType:         "external_client_bridge",
		BridgePath:          bridgePath,
		ClientBinaryPath:    clientBin,
		ClientRunscriptPath: runscript,
	}
	result := validateTeamspeakExternalClientBridgeConfig(cfg)
	if result.Status == teamspeakBackendStatusClientQueryPluginMissing {
		t.Fatalf("plugin is present but status is still %s", teamspeakBackendStatusClientQueryPluginMissing)
	}
	if !result.ClientQueryPluginAvailable {
		t.Fatalf("ClientQueryPluginAvailable should be true when plugin file exists")
	}
}

// TestRepairTeamspeakClientQueryPluginAlreadyPresent verifies that repair is a
// no-op when the plugin already exists in official-client/plugins/.
func TestRepairTeamspeakClientQueryPluginAlreadyPresent(t *testing.T) {
	t.Parallel()
	_, officialClientDir := teamspeakExternalBridgeDir(t, true /* withPlugin=true */)
	clientBin := filepath.Join(officialClientDir, "ts3client_linux_amd64")
	runscript := filepath.Join(officialClientDir, "ts3client_runscript.sh")
	pluginDst := filepath.Join(officialClientDir, "plugins", clientQueryPluginFilename)

	// Capture the original mtime so we can confirm nothing was rewritten.
	infoOrig, err := os.Stat(pluginDst)
	if err != nil {
		t.Fatal(err)
	}

	cfg := teamspeakBackendConfig{
		BackendType:         "external_client_bridge",
		ClientBinaryPath:    clientBin,
		ClientRunscriptPath: runscript,
	}
	if err := repairTeamspeakClientQueryPlugin(cfg); err != nil {
		t.Fatalf("repair returned error for already-present plugin: %v", err)
	}

	infoAfter, err := os.Stat(pluginDst)
	if err != nil {
		t.Fatal(err)
	}
	if !infoAfter.ModTime().Equal(infoOrig.ModTime()) {
		t.Fatalf("plugin file was modified by no-op repair")
	}
}

// TestRepairTeamspeakClientQueryPluginFromInstancePath verifies that repair
// copies the plugin from instancePath/runtime/teamspeak-bridge/ts3home/… when
// the plugin is absent from official-client/plugins/.
func TestRepairTeamspeakClientQueryPluginFromInstancePath(t *testing.T) {
	t.Parallel()
	_, officialClientDir := teamspeakExternalBridgeDir(t, false /* withPlugin=false */)
	clientBin := filepath.Join(officialClientDir, "ts3client_linux_amd64")
	runscript := filepath.Join(officialClientDir, "ts3client_runscript.sh")

	// Create the migration source in instancePath/runtime/teamspeak-bridge/ts3home/...
	instancePath := t.TempDir()
	sourceDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge", "ts3home", ".ts3client", "plugins")
	if err := os.MkdirAll(sourceDir, 0o755); err != nil {
		t.Fatal(err)
	}
	sourcePath := filepath.Join(sourceDir, clientQueryPluginFilename)
	if err := os.WriteFile(sourcePath, []byte("migrated plugin so"), 0o755); err != nil {
		t.Fatal(err)
	}

	cfg := teamspeakBackendConfig{
		BackendType:         "external_client_bridge",
		ClientBinaryPath:    clientBin,
		ClientRunscriptPath: runscript,
		InstancePath:        instancePath,
	}
	if err := repairTeamspeakClientQueryPlugin(cfg); err != nil {
		t.Fatalf("repair failed: %v", err)
	}

	pluginDst := filepath.Join(officialClientDir, "plugins", clientQueryPluginFilename)
	if _, err := os.Stat(pluginDst); err != nil {
		t.Fatalf("plugin was not copied to official-client/plugins/: %v", err)
	}
	content, _ := os.ReadFile(pluginDst)
	if string(content) != "migrated plugin so" {
		t.Fatalf("copied plugin content mismatch: %q", content)
	}
}

// TestChmodOfficialTreeMakesRunscriptExecutable verifies that
// chmodTeamspeakOfficialTree sets ts3client_runscript.sh to 0o755.
func TestChmodOfficialTreeMakesRunscriptExecutable(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	runscript := filepath.Join(dir, "ts3client_runscript.sh")
	if err := os.WriteFile(runscript, []byte("#!/bin/sh\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := chmodTeamspeakOfficialTree(dir); err != nil {
		t.Fatal(err)
	}

	info, err := os.Stat(runscript)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm()&0o111 == 0 {
		t.Fatalf("ts3client_runscript.sh mode %04o is not executable", info.Mode().Perm())
	}
}

// TestChmodOfficialTreeMakesTs3clientExecutable verifies that
// chmodTeamspeakOfficialTree sets ts3client_linux_amd64 to 0o755.
func TestChmodOfficialTreeMakesTs3clientExecutable(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	clientBin := filepath.Join(dir, "ts3client_linux_amd64")
	if err := os.WriteFile(clientBin, []byte("ELF stub"), 0o644); err != nil {
		t.Fatal(err)
	}
	// A data file that must NOT become executable.
	dataFile := filepath.Join(dir, "readme.txt")
	if err := os.WriteFile(dataFile, []byte("readme"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := chmodTeamspeakOfficialTree(dir); err != nil {
		t.Fatal(err)
	}

	info, err := os.Stat(clientBin)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm()&0o111 == 0 {
		t.Fatalf("ts3client_linux_amd64 mode %04o is not executable", info.Mode().Perm())
	}

	infoTxt, err := os.Stat(dataFile)
	if err != nil {
		t.Fatal(err)
	}
	if infoTxt.Mode().Perm()&0o111 != 0 {
		t.Fatalf("readme.txt mode %04o should not be executable", infoTxt.Mode().Perm())
	}
}

// TestOfficialClientInstallWarnsOnMissingPlugin verifies that when the TS3
// installer runs successfully but the ClientQuery plugin is absent from the
// extracted tree, last_error contains a repair hint.
func TestOfficialClientInstallWarnsOnMissingPlugin(t *testing.T) {
	// No t.Parallel(): patches teamspeakOfficialHTTPClient global (shared with other install tests).
	// Build a fake installer that creates library files but NOT the plugin.
	installer := []byte(`#!/bin/sh
TARGET=""
while [ $# -gt 0 ]; do
  case "$1" in
    --target) TARGET="$2"; shift 2;;
    --noexec) shift;;
    *) shift;;
  esac
done
if [ -n "$TARGET" ]; then
  mkdir -p "$TARGET"
  printf 'mock ts3 library\n' > "$TARGET/libts3client.so"
  printf 'mock opus\n' > "$TARGET/libopus.so"
  printf '#!/bin/sh\n' > "$TARGET/ts3client_runscript.sh"
  printf 'ELF stub\n' > "$TARGET/ts3client_linux_amd64"
fi
exit 0
`)
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	// Use a short timeout for the fake installer.
	origTimeout := teamspeakOfficialClientInstallerTimeout
	teamspeakOfficialClientInstallerTimeout = 10 * time.Second
	defer func() { teamspeakOfficialClientInstallerTimeout = origTimeout }()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)

	lastErr, _ := result.resultPayload["last_error"].(string)
	if !strings.Contains(lastErr, clientQueryPluginFilename) {
		t.Fatalf("expected last_error to mention %s when plugin is missing; got: %q", clientQueryPluginFilename, lastErr)
	}
	if !strings.Contains(lastErr, "repair") {
		t.Fatalf("expected last_error to mention 'repair'; got: %q", lastErr)
	}
}

// TestOfficialClientInstallReportsPluginPath verifies that when the TS3
// installer creates the ClientQuery plugin, client_query_plugin_path is present
// in the result payload.
func TestOfficialClientInstallReportsPluginPath(t *testing.T) {
	// No t.Parallel(): patches teamspeakOfficialHTTPClient global (shared with other install tests).
	// Build a fake installer that creates the full expected layout including plugin.
	installer := []byte(`#!/bin/sh
TARGET=""
while [ $# -gt 0 ]; do
  case "$1" in
    --target) TARGET="$2"; shift 2;;
    --noexec) shift;;
    *) shift;;
  esac
done
if [ -n "$TARGET" ]; then
  mkdir -p "$TARGET/plugins"
  printf 'mock ts3 library\n' > "$TARGET/libts3client.so"
  printf 'mock opus\n' > "$TARGET/libopus.so"
  printf '#!/bin/sh\n' > "$TARGET/ts3client_runscript.sh"
  printf 'ELF stub\n' > "$TARGET/ts3client_linux_amd64"
  printf 'mock clientquery plugin\n' > "$TARGET/plugins/libclientquery_plugin_linux_amd64.so"
fi
exit 0
`)
	sum := sha256.Sum256(installer)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write(installer)
	}))
	defer server.Close()
	restore := mockOfficialClientDownload(t, server.URL, "files.teamspeak-services.com")
	defer restore()

	origTimeout := teamspeakOfficialClientInstallerTimeout
	teamspeakOfficialClientInstallerTimeout = 10 * time.Second
	defer func() { teamspeakOfficialClientInstallerTimeout = origTimeout }()

	job := teamspeakOfficialClientJob(t, "https://files.teamspeak-services.com/client.run", hex.EncodeToString(sum[:]))
	result := handleMusicbotTeamspeakBackendInstallOfficialClient(job)

	if result.status != "success" {
		t.Fatalf("install failed: status=%s error=%s payload=%#v", result.status, result.errorText, result.resultPayload)
	}
	pluginPath, _ := result.resultPayload["client_query_plugin_path"].(string)
	if pluginPath == "" {
		t.Fatalf("client_query_plugin_path missing from result payload; got=%#v", result.resultPayload)
	}
	if !strings.Contains(pluginPath, clientQueryPluginFilename) {
		t.Fatalf("client_query_plugin_path %q does not contain expected filename", pluginPath)
	}
}

// TestUbuntu2404DepsIncludeLibasound2t64 verifies that libasound2t64 appears
// as the preferred alternative for the Ubuntu 24.04 package rename
// (libasound2 → libasound2t64).
func TestUbuntu2404DepsIncludeLibasound2t64(t *testing.T) {
	t.Parallel()
	found := false
	for _, pair := range teamspeakExternalBridgePackageAlternatives {
		if pair[0] == "libasound2t64" {
			found = true
			if pair[1] != "libasound2" {
				t.Fatalf("libasound2t64 fallback should be libasound2, got %q", pair[1])
			}
			break
		}
	}
	if !found {
		t.Fatal("libasound2t64 not found in teamspeakExternalBridgePackageAlternatives as preferred package")
	}
}
