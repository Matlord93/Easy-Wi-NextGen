package ptyconsole

import (
	"context"
	"runtime"
	"strings"
	"testing"
	"time"
)

func TestSessionLifecycleAndCommand(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("pty tests require unix")
	}
	mgr := NewManager("test")
	s, err := mgr.Start(context.Background(), "i-1", StartSpec{Command: "/bin/sh", Args: []string{"-c", "echo ready; while read line; do echo cmd:$line; [ \"$line\" = \"quit\" ] && exit 0; done"}}, "start-1")
	if err != nil {
		if strings.Contains(err.Error(), "operation not permitted") {
			t.Skipf("pty unavailable in test environment: %v", err)
		}
		t.Fatalf("start: %v", err)
	}
	ch, detach := s.Attach()
	defer detach()

	_, err = s.SendCommand("status", "k1")
	if err != nil {
		t.Fatalf("send command: %v", err)
	}
	written, err := s.SendCommand("status", "k1")
	if err != nil {
		t.Fatalf("send command duplicate: %v", err)
	}
	if written {
		t.Fatalf("expected duplicate command to be idempotent")
	}
	_, _ = s.SendCommand("quit", "k2")

	deadline := time.After(2 * time.Second)
	found := false
	for !found {
		select {
		case evt, ok := <-ch:
			if !ok {
				found = true
				continue
			}
			if string(evt.Chunk) != "" && (strings.Contains(string(evt.Chunk), "cmd:status") || strings.Contains(string(evt.Chunk), "ready")) {
				found = true
			}
		case <-deadline:
			t.Fatal("timeout waiting for output")
		}
	}
}

func TestCapability(t *testing.T) {
	cap := NewManager("v1").Capability()
	if cap.OS == "" || cap.Version != "v1" {
		t.Fatalf("unexpected capability: %+v", cap)
	}
}

func TestSendCommandAckDuplicate(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("pty tests require unix")
	}
	mgr := NewManager("test")
	s, err := mgr.Start(context.Background(), "i-2", StartSpec{Command: "/bin/sh", Args: []string{"-c", "while read line; do [ \"$line\" = \"quit\" ] && exit 0; done"}}, "start-2")
	if err != nil {
		if strings.Contains(err.Error(), "operation not permitted") {
			t.Skipf("pty unavailable in test environment: %v", err)
		}
		t.Fatalf("start: %v", err)
	}

	first, err := s.SendCommandWithAck("status", "same")
	if err != nil {
		t.Fatalf("first send failed: %v", err)
	}
	second, err := s.SendCommandWithAck("status", "same")
	if err != nil {
		t.Fatalf("second send failed: %v", err)
	}
	if first.Ack != CommandAckAccepted || !first.Written {
		t.Fatalf("unexpected first ack: %+v", first)
	}
	if second.Ack != CommandAckDuplicate || second.Written {
		t.Fatalf("unexpected second ack: %+v", second)
	}
	_, _ = s.SendCommand("quit", "quit")
}

func TestSendCommandRejectsTooLong(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("pty tests require unix")
	}
	mgr := NewManager("test")
	s, err := mgr.Start(context.Background(), "i-3", StartSpec{Command: "/bin/sh", Args: []string{"-c", "while read line; do [ \"$line\" = \"quit\" ] && exit 0; done"}}, "start-3")
	if err != nil {
		if strings.Contains(err.Error(), "operation not permitted") {
			t.Skipf("pty unavailable in test environment: %v", err)
		}
		t.Fatalf("start: %v", err)
	}
	long := strings.Repeat("a", 1025)
	if _, err := s.SendCommandWithAck(long, "long"); err == nil {
		t.Fatal("expected too long command error")
	}
	_, _ = s.SendCommand("quit", "quit")
}
