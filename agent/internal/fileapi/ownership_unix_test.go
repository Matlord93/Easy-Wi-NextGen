//go:build !windows

package fileapi

import (
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

func TestWriteFileAtomicPreservesExistingOwner(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("requires root to chown test fixture")
	}

	dir := t.TempDir()
	target := filepath.Join(dir, "server.cfg")
	if err := os.WriteFile(target, []byte("old"), 0o640); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
	if err := os.Chown(target, 1, 1); err != nil {
		t.Fatalf("chown fixture: %v", err)
	}

	if err := writeFileAtomic(target, strings.NewReader("new"), 0o640); err != nil {
		t.Fatalf("write atomic: %v", err)
	}

	info, err := os.Stat(target)
	if err != nil {
		t.Fatalf("stat target: %v", err)
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		t.Fatalf("expected syscall stat")
	}
	if stat.Uid != 1 || stat.Gid != 1 {
		t.Fatalf("expected owner 1:1, got %d:%d", stat.Uid, stat.Gid)
	}
}

func TestWriteFileAtomicUsesParentOwnerForNewFile(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("requires root to chown test fixture")
	}

	dir := t.TempDir()
	if err := os.Chown(dir, 1, 1); err != nil {
		t.Fatalf("chown dir: %v", err)
	}
	target := filepath.Join(dir, "new.cfg")

	if err := writeFileAtomic(target, strings.NewReader("new"), 0o640); err != nil {
		t.Fatalf("write atomic: %v", err)
	}

	info, err := os.Stat(target)
	if err != nil {
		t.Fatalf("stat target: %v", err)
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		t.Fatalf("expected syscall stat")
	}
	if stat.Uid != 1 || stat.Gid != 1 {
		t.Fatalf("expected owner 1:1, got %d:%d", stat.Uid, stat.Gid)
	}
}

func TestMkdirAllPreserveOwnerUsesNearestExistingOwner(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("requires root to chown test fixture")
	}

	dir := t.TempDir()
	if err := os.Chown(dir, 1, 1); err != nil {
		t.Fatalf("chown dir: %v", err)
	}
	target := filepath.Join(dir, "nested", "configs")

	if err := mkdirAllPreserveOwner(target, 0o750); err != nil {
		t.Fatalf("mkdir preserve owner: %v", err)
	}

	for _, path := range []string{filepath.Join(dir, "nested"), target} {
		info, err := os.Stat(path)
		if err != nil {
			t.Fatalf("stat %s: %v", path, err)
		}
		stat, ok := info.Sys().(*syscall.Stat_t)
		if !ok {
			t.Fatalf("expected syscall stat for %s", path)
		}
		if stat.Uid != 1 || stat.Gid != 1 {
			t.Fatalf("expected %s owner 1:1, got %d:%d", path, stat.Uid, stat.Gid)
		}
	}
}
