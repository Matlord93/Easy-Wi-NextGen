//go:build !windows

package main

import (
	"fmt"
	"os"
	"syscall"
)

func chownToInstanceOwner(instanceDir, path string) error {
	info, err := os.Stat(instanceDir)
	if err != nil {
		return fmt.Errorf("stat instance directory: %w", err)
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return fmt.Errorf("stat instance directory: unsupported")
	}
	if err := os.Chown(path, int(stat.Uid), int(stat.Gid)); err != nil {
		return fmt.Errorf("chown %s: %w", path, err)
	}
	return nil
}
