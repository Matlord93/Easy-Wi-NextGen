//go:build !windows

package fileapi

import (
	"os"
	"syscall"
)

func fileOwnership(path string) (int, int, bool) {
	info, err := os.Stat(path)
	if err != nil {
		return -1, -1, false
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return -1, -1, false
	}
	return int(stat.Uid), int(stat.Gid), true
}

func chownPath(path string, uid, gid int) error {
	return os.Chown(path, uid, gid)
}
