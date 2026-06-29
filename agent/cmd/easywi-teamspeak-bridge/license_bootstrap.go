package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// licenseAcceptanceStatus values written to license_acceptance.json.
const (
	licenseBootstrapStatusReady          = "ready"
	licenseBootstrapStatusRequiresManual = "requires_manual"
	licenseBootstrapStatusFailed         = "failed"
)

// LicenseAcceptance is the structure written to
// <instancePath>/data/teamspeak-client/license_acceptance.json.
// It records whether an administrator has explicitly run the bootstrap and
// what the outcome was.
type LicenseAcceptance struct {
	// Status is one of: "ready", "requires_manual", "failed".
	Status string `json:"status"`
	// CheckedAt is the RFC3339 timestamp of the last bootstrap run.
	CheckedAt string `json:"checked_at"`
	// TS3LogPath is the log file that was inspected during bootstrap.
	TS3LogPath string `json:"ts3_log_path,omitempty"`
	// Message is a human-readable explanation.
	Message string `json:"message,omitempty"`
	// AdminConsentGiven is always true when this file was written by the bootstrap CLI.
	AdminConsentGiven bool `json:"admin_consent_given"`
}

// licenseAcceptanceJSONPath returns the canonical path for the license
// acceptance marker file inside instancePath.
func licenseAcceptanceJSONPath(instancePath string) string {
	return filepath.Join(instancePath, "data", "teamspeak-client", "license_acceptance.json")
}

// ReadLicenseAcceptanceJSON reads and parses the license acceptance marker file.
// Returns an error when the file does not exist or cannot be parsed.
func ReadLicenseAcceptanceJSON(instancePath string) (LicenseAcceptance, error) {
	path := licenseAcceptanceJSONPath(instancePath)
	data, err := os.ReadFile(path)
	if err != nil {
		return LicenseAcceptance{}, err
	}
	var la LicenseAcceptance
	if err := json.Unmarshal(data, &la); err != nil {
		return LicenseAcceptance{}, fmt.Errorf("license_acceptance.json parse: %w", err)
	}
	return la, nil
}

// writeLicenseAcceptanceJSON writes la to the canonical marker file path inside
// instancePath, creating parent directories as needed.
func writeLicenseAcceptanceJSON(instancePath string, la LicenseAcceptance) error {
	path := licenseAcceptanceJSONPath(instancePath)
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return fmt.Errorf("create license_acceptance dir: %w", err)
	}
	data, err := json.MarshalIndent(la, "", "  ")
	if err != nil {
		return err
	}
	data = append(data, '\n')
	if err := os.WriteFile(path, data, 0o600); err != nil {
		return fmt.Errorf("write license_acceptance.json: %w", err)
	}
	return nil
}

// bootstrapParams holds the parsed CLI flags for the bootstrap mode.
type bootstrapParams struct {
	instancePath   string
	runscriptPath  string
	clientBinary   string
	timeoutSeconds int
}

// parseBootstrapFlags parses --instance-path, --client-runscript, --client-binary,
// and --bootstrap-timeout from args. Returns an error on missing required flags.
func parseBootstrapFlags(args []string) (bootstrapParams, error) {
	fs := flag.NewFlagSet("license-bootstrap", flag.ContinueOnError)
	var p bootstrapParams
	fs.StringVar(&p.instancePath, "instance-path", "", "Musicbot instance base directory (required)")
	fs.StringVar(&p.runscriptPath, "client-runscript", "", "Path to ts3client_runscript.sh")
	fs.StringVar(&p.clientBinary, "client-binary", "", "Path to ts3client_linux_amd64 binary")
	fs.IntVar(&p.timeoutSeconds, "bootstrap-timeout", 60, "Seconds to wait for TS3 client to start")
	if err := fs.Parse(args); err != nil {
		return p, err
	}
	if p.instancePath == "" {
		return p, errors.New("--instance-path is required")
	}
	if p.runscriptPath == "" && p.clientBinary == "" {
		return p, errors.New("one of --client-runscript or --client-binary is required")
	}
	return p, nil
}

// RunLicenseBootstrap is the entry point for
//
//	easywi-teamspeak-bridge --accept-ts3-client-license [flags]
//
// It starts the TS3 client headless once, inspects the current log file for a
// LicenseViewer dialog, and writes the result to license_acceptance.json. The
// function exits the process (os.Exit) so the caller must not do cleanup after
// invoking it.
//
// The admin must pass --accept-ts3-client-license explicitly; that flag IS the
// admin consent. No automatic license acceptance happens without it.
func RunLicenseBootstrap(args []string) {
	logger := log.New(os.Stderr, "license-bootstrap: ", 0)

	p, err := parseBootstrapFlags(args)
	if err != nil {
		logger.Printf("error: %v", err)
		logger.Printf("Usage: easywi-teamspeak-bridge --accept-ts3-client-license --instance-path <path> [--client-runscript <path> | --client-binary <path>]")
		os.Exit(2)
	}

	logger.Printf("license_bootstrap_admin_consent=true instance_path=%s", p.instancePath)
	logger.Printf("license_bootstrap_attempted=true")

	result, ts3LogPath := runBootstrapCheck(logger, p)

	la := LicenseAcceptance{
		Status:            result,
		CheckedAt:         time.Now().UTC().Format(time.RFC3339),
		TS3LogPath:        ts3LogPath,
		AdminConsentGiven: true,
	}
	switch result {
	case licenseBootstrapStatusReady:
		la.Message = "TS3 client started without a license dialog; instance is ready"
	case licenseBootstrapStatusRequiresManual:
		la.Message = "TS3 client shows a license dialog; an administrator must accept it once via VNC, then re-run the bootstrap"
	case licenseBootstrapStatusFailed:
		la.Message = "TS3 client failed to start or the log file could not be read"
	}

	if writeErr := writeLicenseAcceptanceJSON(p.instancePath, la); writeErr != nil {
		logger.Printf("license_bootstrap_write_json_failed: %v", writeErr)
		os.Exit(1)
	}

	markerPath := licenseAcceptanceJSONPath(p.instancePath)
	logger.Printf("license_bootstrap_result=%s ts3_log_path=%s marker_path=%s", result, ts3LogPath, markerPath)

	if result == licenseBootstrapStatusReady {
		os.Exit(0)
	}
	os.Exit(1)
}

// runBootstrapCheck starts the TS3 client, waits for the current-session log to
// appear, checks for LicenseViewer, then shuts everything down.
// Returns (status, ts3LogPath).
func runBootstrapCheck(logger *log.Logger, p bootstrapParams) (string, string) {
	timeout := time.Duration(p.timeoutSeconds) * time.Second
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()

	adapter := NewExternalClientBridgeAdapter()

	cp := connectParams{
		ClientBinaryPath:    p.clientBinary,
		ClientRunscriptPath: p.runscriptPath,
		InstancePath:        p.instancePath,
		// We use a dummy host/port; we only care about the license check step,
		// which runs before any actual server connect attempt.
		Host: "127.0.0.1",
		Port: 9987,
	}

	ts3StartTime := time.Now()
	persistentHome := buildPersistentTs3Home(p.instancePath, "")

	logger.Printf("license_bootstrap persistent_ts3home=%s", persistentHome)

	// Start the full connect flow in a goroutine. We expect it to either:
	//   a) fail at license_check_failed (LicenseViewer present), or
	//   b) fail at clientquery_connect / wait_server_connected (no real server),
	//      which means the license step passed.
	done := make(chan error, 1)
	go func() {
		_, err := adapter.Connect(ctx, cp)
		done <- err
	}()

	select {
	case err := <-done:
		_ = adapter.Shutdown(context.Background())
		ts3LogDirs := []string{
			filepath.Join(persistentHome, ".ts3client", "logs"),
		}
		licResult := checkCurrentTs3LogForLicense(ts3LogDirs, ts3StartTime)
		logger.Printf("license_bootstrap_check source=%s log=%s has_viewer=%v requires_accept=%v",
			licResult.Source, licResult.LogPath, licResult.CurrentLogHasLicenseViewer, licResult.CurrentLogRequiresAccept)

		if licResult.LicenseAcceptRequired {
			logger.Printf("license_bootstrap_result=requires_manual")
			return licenseBootstrapStatusRequiresManual, licResult.LogPath
		}
		if err != nil && isLicenseAcceptError(err) {
			logger.Printf("license_bootstrap_result=requires_manual (from connect error)")
			return licenseBootstrapStatusRequiresManual, licResult.LogPath
		}
		// Connect failed for a non-license reason (expected: no real TS3 server at 127.0.0.1:9987).
		// The license check passed, so the instance is ready.
		logger.Printf("license_bootstrap_result=ready (connect error unrelated to license: %v)", err)
		return licenseBootstrapStatusReady, licResult.LogPath

	case <-ctx.Done():
		_ = adapter.Shutdown(context.Background())
		logger.Printf("license_bootstrap_result=failed (timeout)")
		return licenseBootstrapStatusFailed, ""
	}
}

// isLicenseAcceptError returns true when err is the sentinel error produced by
// the license_check step.
func isLicenseAcceptError(err error) bool {
	return err != nil && strings.HasPrefix(err.Error(), "license_accept_required")
}
