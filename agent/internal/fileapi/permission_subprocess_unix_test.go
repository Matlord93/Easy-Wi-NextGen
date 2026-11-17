//go:build !windows

package fileapi

import (
	"context"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// Root is required for ownership tests, but bypasses Unix directory read
// permissions. Execute the original denial assertions as a genuinely separate
// unprivileged process instead of skipping them in the root test matrix.
func runPermissionDeniedUnprivileged(t *testing.T) {
	t.Helper()
	if os.Getenv("EASYWI_PERMISSION_CHECK_CHILD") == "1" {
		t.Fatal("permission test subprocess did not drop root privileges")
	}
	binary, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	fixture, err := os.MkdirTemp("/tmp", "easywi-permission-check-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if err := os.RemoveAll(fixture); err != nil {
			t.Errorf("remove permission test fixture: %v", err)
		}
	})
	if err = os.Chmod(fixture, 0755); err != nil {
		t.Fatal(err)
	}
	source, err := os.Open(binary)
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		if err := source.Close(); err != nil {
			t.Errorf("close permission test executable: %v", err)
		}
	}()
	copied := filepath.Join(fixture, "fileapi.test")
	destination, err := os.OpenFile(copied, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0755)
	if err != nil {
		t.Fatal(err)
	}
	_, copyErr := io.Copy(destination, source)
	closeErr := destination.Close()
	if copyErr != nil {
		t.Fatal(copyErr)
	}
	if closeErr != nil {
		t.Fatal(closeErr)
	}
	if err = os.Chmod(copied, 0o755); err != nil {
		t.Fatal(err)
	}
	work := filepath.Join(fixture, "work")
	if err = os.Mkdir(work, 0700); err != nil {
		t.Fatal(err)
	}
	const unprivilegedID = 65534
	if err = os.Chown(work, unprivilegedID, unprivilegedID); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, copied, "-test.run=^TestListPermissionDeniedReturnsClearError$", "-test.count=1", "-test.v")
	command.Dir = work
	command.Env = append(os.Environ(), "EASYWI_PERMISSION_CHECK_CHILD=1", "TMPDIR="+work, "HOME="+work)
	command.SysProcAttr = &syscall.SysProcAttr{Credential: &syscall.Credential{Uid: unprivilegedID, Gid: unprivilegedID}}
	output, err := command.CombinedOutput()
	if err != nil {
		t.Fatalf("unprivileged permission assertions failed: %v\n%s", err, output)
	}
	t.Logf("unprivileged permission assertions:\n%s", output)
}
