package main

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestConsoleRuntimeRepairPreservesPrivateInstance(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("Unix permissions")
	}
	root := filepath.Join(t.TempDir(), "easywi")
	private := filepath.Join(root, "instances", "1")
	if err := os.MkdirAll(private, 0o750); err != nil {
		t.Fatal(err)
	}
	for _, dir := range []string{root, filepath.Dir(private), private} {
		if err := os.Chmod(dir, 0o750); err != nil {
			t.Fatal(err)
		}
	}
	for i := 0; i < 2; i++ {
		if err := ensureConsoleRuntimeParents(root); err != nil {
			t.Fatal(err)
		}
	}
	for _, dir := range []string{root, filepath.Dir(private), private} {
		info, err := os.Stat(dir)
		if err != nil {
			t.Fatal(err)
		}
		want := os.FileMode(0o751)
		if dir == private {
			want = 0o750
		}
		if info.Mode().Perm() != want {
			t.Fatalf("%s: got %o, want %o", dir, info.Mode().Perm(), want)
		}
	}
}

func TestPhpFpmCannotRevokeConsoleTraversal(t *testing.T) {
	if phpFpmRuntimeMode("/run/easywi")&0o001 == 0 {
		t.Fatal("shared parent must allow traversal")
	}
	if phpFpmRuntimeMode("/run/easywi/php-fpm") != 0o750 {
		t.Fatal("PHP directory must remain private")
	}
}
