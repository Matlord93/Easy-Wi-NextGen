package main

import (
	"context"
	"os/exec"
	"sync/atomic"
	"testing"
	"time"
)

type collectingSender struct{}

func (c *collectingSender) Send(jobID string, lines []string, progress *int) {}

func TestJournalStreamManagerReusesSessionPerInstance(t *testing.T) {
	manager := newJournalStreamManager(2, 500*time.Millisecond)
	var starts int32
	manager.commandFactory = func(ctx context.Context, serviceName string) *exec.Cmd {
		atomic.AddInt32(&starts, 1)
		return exec.CommandContext(ctx, "bash", "-lc", "while true; do sleep 1; done")
	}

	sender := &collectingSender{}
	detachA, err := manager.Subscribe("1", "gs-1", "job-a", sender)
	if err != nil {
		t.Fatalf("subscribe A failed: %v", err)
	}
	detachB, err := manager.Subscribe("1", "gs-1", "job-b", sender)
	if err != nil {
		t.Fatalf("subscribe B failed: %v", err)
	}

	time.Sleep(150 * time.Millisecond)
	if got := atomic.LoadInt32(&starts); got != 1 {
		t.Fatalf("expected one journalctl process for reused instance stream, got %d", got)
	}

	detachA()
	detachB()
	time.Sleep(3 * time.Second)

	manager.mu.Lock()
	remaining := len(manager.sessions)
	manager.mu.Unlock()
	if remaining != 0 {
		t.Fatalf("expected stream session cleanup after ttl, still active=%d", remaining)
	}
}
