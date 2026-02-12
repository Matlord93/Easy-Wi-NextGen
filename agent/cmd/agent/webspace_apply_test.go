package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestResolvePathWithinRootRejectsOutside(t *testing.T) {
	root := t.TempDir()
	outside := t.TempDir()
	link := filepath.Join(root, "link")
	if err := os.Symlink(outside, link); err != nil {
		t.Fatalf("symlink: %v", err)
	}

	if err := os.MkdirAll(filepath.Join(outside, "escape"), 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	if _, err := resolvePathWithinRoot(root, "link/escape"); err == nil {
		t.Fatalf("expected outside root error")
	}
}

func TestResolvePathWithinRootRejectsAbsolutePath(t *testing.T) {
	root := t.TempDir()
	if _, err := resolvePathWithinRoot(root, "/etc/passwd"); err == nil {
		t.Fatalf("expected invalid path error")
	}
}

func TestResolvePathWithinRootRejectsControlChars(t *testing.T) {
	root := t.TempDir()
	if _, err := resolvePathWithinRoot(root, "pub\x01lic"); err == nil {
		t.Fatalf("expected invalid path error")
	}
}

func TestLockWebspaceApplyRejectsParallelLock(t *testing.T) {
	release, err := lockWebspaceApply(map[string]any{"webspace_id": "99"})
	if err != nil {
		t.Fatalf("first lock failed: %v", err)
	}
	defer release()

	if _, err = lockWebspaceApply(map[string]any{"webspace_id": "99"}); err == nil {
		t.Fatalf("expected parallel lock failure")
	}
}
