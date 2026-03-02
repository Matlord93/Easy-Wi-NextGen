package main

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestWebspaceLifecycleSmokeApplyFlow(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("webspace apply flow is linux-only")
	}
	binDir := t.TempDir()
	if err := os.WriteFile(filepath.Join(binDir, "nginx"), []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatalf("mock nginx: %v", err)
	}
	if err := os.WriteFile(filepath.Join(binDir, "systemctl"), []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatalf("mock systemctl: %v", err)
	}
	t.Setenv("PATH", binDir+":"+os.Getenv("PATH"))

	webRoot := t.TempDir()
	docroot := filepath.Join(webRoot, "public")
	if err := os.MkdirAll(docroot, 0o755); err != nil {
		t.Fatalf("mkdir docroot: %v", err)
	}
	vhostPath := filepath.Join(t.TempDir(), "demo.conf")

	domainResult, _ := handleWebspaceDomainApply(jobs.Job{ID: "a", Payload: map[string]any{
		"webspace_id":      "42",
		"domain":           "demo.example.com",
		"docroot":          docroot,
		"php_fpm_listen":   "/run/php-fpm/demo.sock",
		"runtime":          "nginx",
		"nginx_vhost_path": vhostPath,
	}})
	if domainResult.Status != "success" {
		t.Fatalf("domain apply failed: %#v", domainResult.Output)
	}

	applyResult, _ := handleWebspaceApply(jobs.Job{ID: "b", Payload: map[string]any{"webspace_id": "42", "runtime": "nginx"}})
	if applyResult.Status != "success" {
		t.Fatalf("apply failed: %#v", applyResult.Output)
	}

	if _, err := os.Stat(vhostPath); err != nil {
		t.Fatalf("expected vhost file: %v", err)
	}
}
