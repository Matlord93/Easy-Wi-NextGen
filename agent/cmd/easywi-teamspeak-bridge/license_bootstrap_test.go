package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

// TestLicenseAcceptanceJSONPath verifies the canonical marker path derivation.
func TestLicenseAcceptanceJSONPath(t *testing.T) {
	got := licenseAcceptanceJSONPath("/var/lib/easywi/musicbot/inst-1")
	want := "/var/lib/easywi/musicbot/inst-1/data/teamspeak-client/license_acceptance.json"
	if got != want {
		t.Errorf("licenseAcceptanceJSONPath = %q, want %q", got, want)
	}
}

// TestWriteAndReadLicenseAcceptanceJSON verifies round-trip write/read.
func TestWriteAndReadLicenseAcceptanceJSON(t *testing.T) {
	base := t.TempDir()
	la := LicenseAcceptance{
		Status:            licenseBootstrapStatusReady,
		CheckedAt:         time.Now().UTC().Format(time.RFC3339),
		TS3LogPath:        "/path/to/ts3client_2026-06-24.log",
		Message:           "TS3 client started without a license dialog; instance is ready",
		AdminConsentGiven: true,
	}
	if err := writeLicenseAcceptanceJSON(base, la); err != nil {
		t.Fatalf("writeLicenseAcceptanceJSON: %v", err)
	}
	got, err := ReadLicenseAcceptanceJSON(base)
	if err != nil {
		t.Fatalf("ReadLicenseAcceptanceJSON: %v", err)
	}
	if got.Status != la.Status {
		t.Errorf("Status = %q, want %q", got.Status, la.Status)
	}
	if !got.AdminConsentGiven {
		t.Error("AdminConsentGiven should be true")
	}
	if got.TS3LogPath != la.TS3LogPath {
		t.Errorf("TS3LogPath = %q, want %q", got.TS3LogPath, la.TS3LogPath)
	}
}

// TestReadLicenseAcceptanceJSONMissingFile verifies error when file does not exist.
func TestReadLicenseAcceptanceJSONMissingFile(t *testing.T) {
	_, err := ReadLicenseAcceptanceJSON("/nonexistent/path/for/test")
	if err == nil {
		t.Error("ReadLicenseAcceptanceJSON should return error for missing file")
	}
}

// TestWriteLicenseAcceptanceJSONCreatesParentDirs verifies that parent directories
// are created automatically even when they don't exist yet.
func TestWriteLicenseAcceptanceJSONCreatesParentDirs(t *testing.T) {
	base := t.TempDir()
	instancePath := filepath.Join(base, "deeply", "nested", "instance")
	la := LicenseAcceptance{
		Status:            licenseBootstrapStatusFailed,
		CheckedAt:         time.Now().UTC().Format(time.RFC3339),
		AdminConsentGiven: true,
	}
	if err := writeLicenseAcceptanceJSON(instancePath, la); err != nil {
		t.Fatalf("writeLicenseAcceptanceJSON: %v", err)
	}
	path := licenseAcceptanceJSONPath(instancePath)
	if _, err := os.Stat(path); err != nil {
		t.Errorf("expected marker file to exist at %s: %v", path, err)
	}
}

// TestParseBootstrapFlagsRequiresInstancePath verifies that missing --instance-path
// returns an error.
func TestParseBootstrapFlagsRequiresInstancePath(t *testing.T) {
	_, err := parseBootstrapFlags([]string{"--client-binary", "/opt/ts3/ts3client_linux_amd64"})
	if err == nil {
		t.Error("parseBootstrapFlags should return error when --instance-path is missing")
	}
}

// TestParseBootstrapFlagsRequiresClientPath verifies that neither --client-runscript
// nor --client-binary causes an error.
func TestParseBootstrapFlagsRequiresClientPath(t *testing.T) {
	_, err := parseBootstrapFlags([]string{"--instance-path", "/srv/inst"})
	if err == nil {
		t.Error("parseBootstrapFlags should return error when no client path is provided")
	}
}

// TestParseBootstrapFlagsAcceptsRunscript verifies that --client-runscript is accepted.
func TestParseBootstrapFlagsAcceptsRunscript(t *testing.T) {
	p, err := parseBootstrapFlags([]string{
		"--instance-path", "/srv/inst",
		"--client-runscript", "/opt/ts3/ts3client_runscript.sh",
	})
	if err != nil {
		t.Fatalf("parseBootstrapFlags: %v", err)
	}
	if p.instancePath != "/srv/inst" {
		t.Errorf("instancePath = %q, want /srv/inst", p.instancePath)
	}
	if p.runscriptPath != "/opt/ts3/ts3client_runscript.sh" {
		t.Errorf("runscriptPath = %q, want /opt/ts3/ts3client_runscript.sh", p.runscriptPath)
	}
}

// TestIsLicenseAcceptError verifies the sentinel error detection.
func TestIsLicenseAcceptError(t *testing.T) {
	cases := []struct {
		msg  string
		want bool
	}{
		{"license_accept_required: TeamSpeak Client license must be accepted", true},
		{"license_accept_required", true},
		{"connection refused", false},
		{"", false},
	}
	for _, tc := range cases {
		var err error
		if tc.msg != "" {
			err = &testError{tc.msg}
		}
		if got := isLicenseAcceptError(err); got != tc.want {
			t.Errorf("isLicenseAcceptError(%q) = %v, want %v", tc.msg, got, tc.want)
		}
	}
}

type testError struct{ msg string }

func (e *testError) Error() string { return e.msg }
