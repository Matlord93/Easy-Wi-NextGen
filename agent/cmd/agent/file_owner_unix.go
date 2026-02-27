//go:build !windows

package main

import (
	"os"
	"syscall"
)

func fileOwnerIDs(info os.FileInfo) (int, int) {
	if statT, ok := info.Sys().(*syscall.Stat_t); ok {
		return int(statT.Uid), int(statT.Gid)
	}
	return -1, -1
}
