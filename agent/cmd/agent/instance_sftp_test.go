package main

import (
	"runtime"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleInstanceSftpCredentialsResetMissingValues(t *testing.T) {
	job := jobs.Job{
		ID:      "job-1",
		Payload: map[string]any{},
	}

	result, _ := handleInstanceSftpCredentialsReset(job)
	if result.Status != "failed" {
		t.Fatalf("expected failed status, got %s", result.Status)
	}
	if result.Output["error_code"] != "INVALID_INPUT" {
		t.Fatalf("expected error_code INVALID_INPUT, got %v", result.Output["error_code"])
	}
}

func TestHandleWebspaceSftpCredentialsResetUsesSharedLinuxProFTPDReadyPath(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("Linux ProFTPD SFTP provisioning path uses POSIX roots and is not exercised on Windows")
	}
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

	job := jobs.Job{ID: "webspace-sftp", Payload: map[string]any{
		"username":  "web_1",
		"password":  "secret",
		"root_path": "/srv/www/web_1",
	}}
	result, _ := handleWebspaceSftpCredentialsReset(job)
	if result.Status != "success" {
		t.Fatalf("expected success, got %s (%v)", result.Status, result.Output)
	}
	if got := strings.Join(calls, ","); got != "ready,user:web_1:/srv/www/web_1,health" {
		t.Fatalf("unexpected calls %s", got)
	}
}
