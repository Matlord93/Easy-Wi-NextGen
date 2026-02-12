package fileapi

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestValidateServerRootAgainstBase_AllowsCanonicalSymlinkPaths(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlink setup is unreliable on windows")
	}

	workspace := t.TempDir()
	realBase := filepath.Join(workspace, "real-base")
	realRoot := filepath.Join(realBase, "instance-1")
	if err := os.MkdirAll(realRoot, 0o755); err != nil {
		t.Fatalf("create real root: %v", err)
	}

	baseAlias := filepath.Join(workspace, "base-alias")
	if err := os.Symlink(realBase, baseAlias); err != nil {
		t.Fatalf("create base symlink: %v", err)
	}

	rootAlias := filepath.Join(baseAlias, "instance-1")
	resolved, err := validateServerRootAgainstBase(rootAlias, baseAlias)
	if err != nil {
		t.Fatalf("expected symlinked canonical root to be accepted, got error: %v", err)
	}

	if resolved != realRoot {
		t.Fatalf("expected canonical root %q, got %q", realRoot, resolved)
	}
}

func TestValidateServerRootAgainstBase_RejectsPathsOutsideBase(t *testing.T) {
	workspace := t.TempDir()
	base := filepath.Join(workspace, "base")
	outside := filepath.Join(workspace, "outside")
	if err := os.MkdirAll(base, 0o755); err != nil {
		t.Fatalf("create base dir: %v", err)
	}
	if err := os.MkdirAll(outside, 0o755); err != nil {
		t.Fatalf("create outside dir: %v", err)
	}

	_, err := validateServerRootAgainstBase(outside, base)
	if err == nil || err.Error() != "INVALID_SERVER_ROOT" {
		t.Fatalf("expected INVALID_SERVER_ROOT, got %v", err)
	}
}
