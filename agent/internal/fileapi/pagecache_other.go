//go:build !linux

package fileapi

import "os"

func releaseFileFromPageCache(file *os.File) {
	_ = file
}
