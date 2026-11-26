//go:build linux

package system

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"

	"golang.org/x/sys/unix"
)

func TestAtomicSwapRestoresAgentExecutePermissionsUnderServiceUmask(t *testing.T) {
	oldMask := unix.Umask(0o027)
	t.Cleanup(func() { unix.Umask(oldMask) })

	for _, format := range []string{"raw", "tar.gz", "zip"} {
		t.Run(format, func(t *testing.T) {
			dir := t.TempDir()
			installed := filepath.Join(dir, "easywi-agent")
			if err := os.WriteFile(installed, []byte("old binary"), 0o750); err != nil {
				t.Fatal(err)
			}
			if os.Geteuid() == 0 {
				if err := os.Chown(installed, 0, 1234); err != nil {
					t.Fatal(err)
				}
			}
			previous, err := os.Stat(installed)
			if err != nil {
				t.Fatal(err)
			}
			assetName := "easywi-agent-linux-amd64"
			if format != "raw" {
				assetName += "." + format
			}
			archive := filepath.Join(dir, assetName)
			const contents = "new binary"
			switch format {
			case "tar.gz":
				createTarGzWithFile(t, archive, "easywi-agent", contents)
			case "zip":
				createZipWithFile(t, archive, "easywi-agent", contents)
			default:
				if err := os.WriteFile(archive, []byte(contents), 0o755); err != nil {
					t.Fatal(err)
				}
			}
			staged, err := prepareUpdateBinary(archive, assetName)
			if err != nil {
				t.Fatal(err)
			}
			if err := atomicSwap(installed, staged); err != nil {
				t.Fatal(err)
			}
			info, err := os.Stat(installed)
			if err != nil {
				t.Fatal(err)
			}
			if info.Mode().Perm() != 0o755 {
				t.Errorf("installed agent mode = %04o, want 0755", info.Mode().Perm())
			}
			previousOwner := previous.Sys().(*syscall.Stat_t)
			owner := info.Sys().(*syscall.Stat_t)
			if owner.Uid != previousOwner.Uid || owner.Gid != previousOwner.Gid {
				t.Errorf("installed agent owner = %d:%d, want %d:%d", owner.Uid, owner.Gid, previousOwner.Uid, previousOwner.Gid)
			}
			data, err := os.ReadFile(installed)
			if err != nil || string(data) != contents {
				t.Fatalf("read installed agent: %q, %v", data, err)
			}
		})
	}
}

func TestAtomicSwapKeepsInstalledAgentWhenStagingIsInvalid(t *testing.T) {
	installed := filepath.Join(t.TempDir(), "easywi-agent")
	if err := os.WriteFile(installed, []byte("original"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := atomicSwap(installed, t.TempDir()); err == nil {
		t.Fatal("replacing the agent with a directory succeeded")
	}
	data, err := os.ReadFile(installed)
	if err != nil || string(data) != "original" {
		t.Fatalf("original agent changed: %q, %v", data, err)
	}
}
