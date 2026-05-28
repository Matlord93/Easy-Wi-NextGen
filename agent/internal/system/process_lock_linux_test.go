//go:build linux

package system

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestAcquireAgentProcessLockPreventsDuplicateProcess(t *testing.T) {
	t.Setenv("EASYWI_AGENT_LOCK_PATH", filepath.Join(t.TempDir(), "easywi-agent.lock"))

	lock, err := AcquireAgentProcessLock()
	if err != nil {
		t.Fatalf("AcquireAgentProcessLock() error = %v", err)
	}
	defer func() { _ = lock.Release() }()

	second, err := AcquireAgentProcessLock()
	if err == nil {
		_ = second.Release()
		t.Fatal("second AcquireAgentProcessLock() error = nil, want duplicate-process error")
	}
	if !strings.Contains(err.Error(), "already running") {
		t.Fatalf("second AcquireAgentProcessLock() error = %q, want already running", err)
	}
}
