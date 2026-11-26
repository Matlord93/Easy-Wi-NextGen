//go:build linux

package system

import (
	"fmt"
	"os"
	"syscall"
)

func preserveAgentUpdateOwnership(binaryPath string, staged *os.File) error {
	current, err := os.Stat(binaryPath)
	if err != nil {
		return fmt.Errorf("stat installed agent binary: %w", err)
	}
	update, err := staged.Stat()
	if err != nil {
		return fmt.Errorf("stat staged agent binary: %w", err)
	}
	owner := current.Sys().(*syscall.Stat_t)
	stagedOwner := update.Sys().(*syscall.Stat_t)
	if owner.Uid != stagedOwner.Uid || owner.Gid != stagedOwner.Gid {
		if err := staged.Chown(int(owner.Uid), int(owner.Gid)); err != nil {
			return fmt.Errorf("preserve installed agent ownership: %w", err)
		}
	}
	return nil
}

// EnsureInstalledAgentExecutable repairs a standard installation affected by an older updater.
func EnsureInstalledAgentExecutable(executablePath string) error {
	if executablePath != "/usr/local/bin/easywi-agent" || os.Geteuid() != 0 {
		return nil
	}
	// Use the running executable's inode even if the path is changed concurrently.
	file, err := os.Open("/proc/self/exe")
	if err != nil {
		return fmt.Errorf("open running agent binary: %w", err)
	}
	defer func() { _ = file.Close() }()
	return ensureAgentExecutableMode(file)
}
