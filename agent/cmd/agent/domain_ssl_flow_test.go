package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestDomainSSLRenewAndRevokeFlow(t *testing.T) {
	binDir := t.TempDir()
	logFile := filepath.Join(binDir, "certbot.log")
	if err := os.WriteFile(filepath.Join(binDir, "certbot"), []byte("#!/bin/sh\necho \"$@\" >> \""+logFile+"\"\nexit 0\n"), 0o755); err != nil {
		t.Fatalf("certbot mock: %v", err)
	}
	if err := os.WriteFile(filepath.Join(binDir, "systemctl"), []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatalf("systemctl mock: %v", err)
	}
	t.Setenv("PATH", binDir+":"+os.Getenv("PATH"))

	renew, _ := handleDomainSSLRenew(jobs.Job{ID: "1", Payload: map[string]any{"domain": "example.com"}})
	if renew.Status != "success" {
		t.Fatalf("renew failed: %#v", renew.Output)
	}
	revoke, _ := handleDomainSSLRevoke(jobs.Job{ID: "2", Payload: map[string]any{"cert_path": "/tmp/cert.pem", "domain": "example.com"}})
	if revoke.Status != "success" {
		t.Fatalf("revoke failed: %#v", revoke.Output)
	}

	calls, err := os.ReadFile(logFile)
	if err != nil {
		t.Fatalf("read certbot log: %v", err)
	}
	payload := string(calls)
	if !strings.Contains(payload, "--cert-name example.com") || !strings.Contains(payload, "revoke --non-interactive") {
		t.Fatalf("unexpected certbot flow: %s", payload)
	}
}
