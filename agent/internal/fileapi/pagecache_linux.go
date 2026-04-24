//go:build linux

package fileapi

import (
	"os"

	"golang.org/x/sys/unix"
)

func releaseFileFromPageCache(file *os.File) {
	if file == nil {
		return
	}
	_ = unix.Fadvise(int(file.Fd()), 0, 0, unix.FADV_DONTNEED)
}
