package main

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleWebspaceDomainApplyRollsBackOnConfigtestFailure(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("webspace apply flow is linux-only")
	}
	binDir := t.TempDir()
	nginxLog := filepath.Join(binDir, "nginx.log")
	nginxScript := "#!/bin/sh\n" +
		"echo \"$@\" >> \"" + nginxLog + "\"\n" +
		"if [ \"$1\" = \"-t\" ]; then\n" +
		"  exit 1\n" +
		"fi\n" +
		"exit 0\n"
	if err := os.WriteFile(filepath.Join(binDir, "nginx"), []byte(nginxScript), 0o755); err != nil {
		t.Fatalf("write nginx mock: %v", err)
	}
	if err := os.WriteFile(filepath.Join(binDir, "systemctl"), []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatalf("write systemctl mock: %v", err)
	}

	oldPath := os.Getenv("PATH")
	t.Setenv("PATH", binDir+":"+oldPath)

	vhost := filepath.Join(t.TempDir(), "example.conf")
	if err := os.WriteFile(vhost, []byte("before"), 0o644); err != nil {
		t.Fatalf("seed vhost: %v", err)
	}

	job := jobs.Job{ID: "job-1", Payload: map[string]any{
		"webspace_id":      "1",
		"domain":           "example.com",
		"docroot":          t.TempDir(),
		"php_fpm_listen":   "/run/php.sock",
		"nginx_vhost_path": vhost,
	}}

	result, _ := handleWebspaceDomainApply(job)
	if result.Status != "failed" {
		t.Fatalf("expected failed result, got %s", result.Status)
	}
	if result.Output["error_code"] != "configtest_failed" {
		t.Fatalf("expected configtest_failed, got %q", result.Output["error_code"])
	}

	content, err := os.ReadFile(vhost)
	if err != nil {
		t.Fatalf("read vhost: %v", err)
	}
	if string(content) != "before" {
		t.Fatalf("expected rollback to restore old vhost, got %q", string(content))
	}

	calls, err := os.ReadFile(nginxLog)
	if err != nil {
		t.Fatalf("read nginx log: %v", err)
	}
	if string(calls) == "" {
		t.Fatalf("expected nginx -t to be invoked")
	}
}
