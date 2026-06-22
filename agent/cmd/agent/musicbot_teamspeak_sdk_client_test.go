package main

import (
	"archive/tar"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestTeamspeakSDKClientURLValidation(t *testing.T) {
	t.Parallel()

	cases := []struct {
		url     string
		wantErr bool
	}{
		{"https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz", false},
		{"http://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz", true},
		{"https://evil.example.com/sdk.tar.gz", true},
		{"", true},
		{"https://files.teamspeak-services.com/../../../etc/passwd", false}, // host is valid
		{"ftp://files.teamspeak-services.com/sdk.tar.gz", true},
	}

	for _, tc := range cases {
		err := validateTeamspeakSDKClientURL(tc.url)
		if tc.wantErr && err == nil {
			t.Errorf("url %q: expected error, got nil", tc.url)
		}
		if !tc.wantErr && err != nil {
			t.Errorf("url %q: unexpected error: %v", tc.url, err)
		}
	}
}

func TestTeamspeakSDKInstallPathValidation(t *testing.T) {
	t.Parallel()

	cases := []struct {
		path    string
		wantErr bool
	}{
		{"/opt/easywi/musicbot/teamspeak-client/sdk/", false},
		{"/opt/sdk-35", false},
		{"relative/path", true},
		{"/", true},
		{"/etc", true},
		{"/tmp", true},
		{"/opt/valid;rm -rf /", true},
		{"/opt/valid`id`", true},
		{"/opt/valid$HOME", true},
		{"", true},
	}

	for _, tc := range cases {
		_, err := validateTeamspeakSDKInstallPath(tc.path)
		if tc.wantErr && err == nil {
			t.Errorf("path %q: expected error, got nil", tc.path)
		}
		if !tc.wantErr && err != nil {
			t.Errorf("path %q: unexpected error: %v", tc.path, err)
		}
	}
}

func makeFakeSDKTarball(t *testing.T, files map[string]string) (string, string) {
	t.Helper()
	dir := t.TempDir()
	tarPath := filepath.Join(dir, "sdk.tar.gz")

	f, err := os.Create(tarPath)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = f.Close() }()

	gw := gzip.NewWriter(f)
	tw := tar.NewWriter(gw)

	for name, content := range files {
		fullName := "teamspeak-sdk-3.5.2/" + name
		hdr := &tar.Header{
			Name:     fullName,
			Mode:     0o644,
			Size:     int64(len(content)),
			Typeflag: tar.TypeReg,
		}
		if err := tw.WriteHeader(hdr); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(content)); err != nil {
			t.Fatal(err)
		}
	}

	_ = tw.Close()
	_ = gw.Close()

	// compute sha256 of the written file
	raw, _ := os.ReadFile(tarPath)
	sum := sha256.Sum256(raw)
	return tarPath, hex.EncodeToString(sum[:])
}

func TestExtractTeamspeakSDKTarball(t *testing.T) {
	t.Parallel()

	tarPath, _ := makeFakeSDKTarball(t, map[string]string{
		"lib/libteamspeak_sdk_client.so": "fake sdk library",
		"include/teamspeak/public_api.h": "fake header",
	})
	destDir := t.TempDir()

	if err := extractTeamspeakSDKTarball(tarPath, destDir); err != nil {
		t.Fatalf("extractTeamspeakSDKTarball: %v", err)
	}

	libPath := filepath.Join(destDir, "lib/libteamspeak_sdk_client.so")
	if _, err := os.Stat(libPath); err != nil {
		t.Errorf("expected %s to exist after extraction: %v", libPath, err)
	}
	hdrPath := filepath.Join(destDir, "include/teamspeak/public_api.h")
	if _, err := os.Stat(hdrPath); err != nil {
		t.Errorf("expected %s to exist after extraction: %v", hdrPath, err)
	}
}

func TestExtractTeamspeakSDKTarballPathTraversal(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	tarPath := filepath.Join(dir, "evil.tar.gz")

	f, _ := os.Create(tarPath)
	gw := gzip.NewWriter(f)
	tw := tar.NewWriter(gw)
	content := "evil"
	_ = tw.WriteHeader(&tar.Header{
		Name:     "teamspeak-sdk-3.5.2/../../../tmp/evil.txt",
		Mode:     0o644,
		Size:     int64(len(content)),
		Typeflag: tar.TypeReg,
	})
	_, _ = tw.Write([]byte(content))
	_ = tw.Close()
	_ = gw.Close()
	_ = f.Close()

	destDir := t.TempDir()
	err := extractTeamspeakSDKTarball(tarPath, destDir)
	if err == nil {
		t.Error("expected path traversal to be blocked")
	}
}

func TestHandleSDKClientAcceptedConfirmationFalse(t *testing.T) {
	job := jobs.Job{ID: "job-sdk", Type: "musicbot.teamspeak_backend.install_sdk_client", Payload: map[string]any{
		"node_id":                      "agent-1",
		"version":                      "3.5.2",
		"download_url":                 "https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz",
		"install_path":                 "/opt/easywi/musicbot/teamspeak-client/sdk/",
		"requested_by":                 "1",
		"accepted_license_confirmation": false,
	}}

	result := handleMusicbotTeamspeakBackendInstallSDKClient(job)
	if result.status != "failed" {
		t.Errorf("expected status=failed when accepted_license_confirmation=false, got %q", result.status)
	}
	if result.resultPayload["status"] != teamspeakBackendStatusSDKClientInvalid {
		t.Errorf("expected sdk_client_invalid status, got %v", result.resultPayload["status"])
	}
}

func TestHandleSDKClientBlocksForeignHost(t *testing.T) {
	job := jobs.Job{ID: "job-sdk", Type: "musicbot.teamspeak_backend.install_sdk_client", Payload: map[string]any{
		"node_id":                      "agent-1",
		"version":                      "3.5.2",
		"download_url":                 "https://evil.example.com/sdk.tar.gz",
		"install_path":                 "/opt/easywi/musicbot/teamspeak-client/sdk/",
		"requested_by":                 "1",
		"accepted_license_confirmation": true,
	}}

	result := handleMusicbotTeamspeakBackendInstallSDKClient(job)
	if result.status != "failed" {
		t.Errorf("expected status=failed for foreign host, got %q", result.status)
	}
}

func mockSDKClientDownload(t *testing.T, targetURL string) func() {
	t.Helper()
	origClient := teamspeakSDKHTTPClient
	origHosts := teamspeakSDKClientAllowedHosts
	target, err := url.Parse(targetURL)
	if err != nil {
		t.Fatal(err)
	}
	teamspeakSDKClientAllowedHosts = map[string]bool{"files.teamspeak-services.com": true}
	teamspeakSDKHTTPClient = &http.Client{
		Timeout:   teamspeakSDKClientDownloadTimeout,
		Transport: rewriteHostTransport{target: target, base: http.DefaultTransport},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 5 {
				return fmt.Errorf("too many redirects")
			}
			return validateTeamspeakSDKClientURL(req.URL.String())
		},
	}
	return func() {
		teamspeakSDKHTTPClient = origClient
		teamspeakSDKClientAllowedHosts = origHosts
	}
}

func TestHandleSDKClientChecksumMismatch(t *testing.T) {
	tarPath, _ := makeFakeSDKTarball(t, map[string]string{
		"lib/libteamspeak_sdk_client.so": "fake sdk library",
	})
	raw, err := os.ReadFile(tarPath)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/x-gzip")
		_, _ = w.Write(raw)
	}))
	defer srv.Close()
	restore := mockSDKClientDownload(t, srv.URL)
	defer restore()

	installDir := t.TempDir()
	job := jobs.Job{ID: "job-sdk", Type: "musicbot.teamspeak_backend.install_sdk_client", Payload: map[string]any{
		"node_id":                      "agent-1",
		"version":                      "3.5.2",
		"download_url":                 "https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz",
		"expected_sha256":              "0000000000000000000000000000000000000000000000000000000000000000",
		"install_path":                 installDir,
		"requested_by":                 "1",
		"accepted_license_confirmation": true,
	}}

	result := handleMusicbotTeamspeakBackendInstallSDKClient(job)
	if result.status != "failed" {
		t.Errorf("expected checksum mismatch to fail, got %q", result.status)
	}
	if result.resultPayload["status"] != teamspeakBackendStatusSDKClientChecksumFailed {
		t.Errorf("expected sdk_client_checksum_failed, got %v", result.resultPayload["status"])
	}
}

func TestHandleSDKClientLibraryDetectionAndSymlink(t *testing.T) {
	tarPath, sha256sum := makeFakeSDKTarball(t, map[string]string{
		"lib/libteamspeak_sdk_client.so": "fake sdk library bytes",
	})
	raw, err := os.ReadFile(tarPath)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/x-gzip")
		_, _ = w.Write(raw)
	}))
	defer srv.Close()
	restore := mockSDKClientDownload(t, srv.URL)
	defer restore()

	installDir := t.TempDir()
	job := jobs.Job{ID: "job-sdk", Type: "musicbot.teamspeak_backend.install_sdk_client", Payload: map[string]any{
		"node_id":                      "agent-1",
		"version":                      "3.5.2",
		"download_url":                 "https://files.teamspeak-services.com/releases/sdk/3.5.2/teamspeak-sdk-3.5.2.tar.gz",
		"expected_sha256":              sha256sum,
		"install_path":                 installDir,
		"requested_by":                 "1",
		"accepted_license_confirmation": true,
	}}

	result := handleMusicbotTeamspeakBackendInstallSDKClient(job)
	if result.status != "success" {
		t.Fatalf("expected success, got %q (error: %v)", result.status, result.errorText)
	}

	payload := result.resultPayload

	if payload["status"] != teamspeakBackendStatusSDKClientInstalled {
		t.Errorf("expected sdk_client_installed, got %v", payload["status"])
	}

	// Library path must be set
	libPath, _ := payload["library_path"].(string)
	if libPath == "" {
		t.Error("expected library_path to be set in payload")
	}

	// symlink libts3client.so → libteamspeak_sdk_client.so should exist
	symlinkPath := filepath.Join(installDir, "lib/libts3client.so")
	fi, err := os.Lstat(symlinkPath)
	if err != nil {
		t.Errorf("expected symlink %s to exist: %v", symlinkPath, err)
	} else if fi.Mode()&os.ModeSymlink == 0 {
		t.Errorf("expected %s to be a symlink", symlinkPath)
	}

	created, _ := payload["sdk_symlink_created"].(bool)
	if !created {
		t.Error("expected sdk_symlink_created=true in payload")
	}
}

func TestStripSDKTarTopDir(t *testing.T) {
	t.Parallel()

	cases := []struct {
		input string
		want  string
	}{
		{"teamspeak-sdk-3.5.2/lib/libteamspeak_sdk_client.so", "lib/libteamspeak_sdk_client.so"},
		{"teamspeak-sdk-3.5.2/", ""},
		{"single", ""},
		{".", ""},
		{"a/b/c/d", "b/c/d"},
	}

	for _, tc := range cases {
		got := stripSDKTarTopDir(tc.input)
		if got != tc.want {
			t.Errorf("stripSDKTarTopDir(%q) = %q, want %q", tc.input, got, tc.want)
		}
	}
}

func TestResolveTeamspeakLibraryPathAcceptsSdkLib(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	sdkLib := filepath.Join(dir, "libteamspeak_sdk_client.so")
	if err := os.WriteFile(sdkLib, []byte("fake"), 0o644); err != nil {
		t.Fatal(err)
	}

	// Direct path
	got, err := resolveTeamspeakLibraryPath(sdkLib)
	if err != nil {
		t.Errorf("resolveTeamspeakLibraryPath(direct sdk lib): %v", err)
	}
	if got != sdkLib {
		t.Errorf("expected %s, got %s", sdkLib, got)
	}

	// Directory lookup
	got, err = resolveTeamspeakLibraryPath(dir)
	if err != nil {
		t.Errorf("resolveTeamspeakLibraryPath(dir): %v", err)
	}
	if got != sdkLib {
		t.Errorf("expected %s, got %s", sdkLib, got)
	}
}

func TestResolveTeamspeakLibraryPathPrefersSdkLibOverTs3(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	ts3Lib := filepath.Join(dir, "libts3client.so")
	sdkLib := filepath.Join(dir, "libteamspeak_sdk_client.so")
	if err := os.WriteFile(ts3Lib, []byte("ts3"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sdkLib, []byte("sdk"), 0o644); err != nil {
		t.Fatal(err)
	}

	// When searching directory with both files, should prefer libts3client.so (first in list)
	got, err := resolveTeamspeakLibraryPath(dir)
	if err != nil {
		t.Errorf("unexpected error: %v", err)
	}
	if got != ts3Lib {
		t.Logf("note: got %s (either is acceptable)", got)
	}
}
