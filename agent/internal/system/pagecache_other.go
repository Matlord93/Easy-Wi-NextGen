//go:build !linux

package system

import "os"

func releaseFileFromPageCache(file *os.File) {
	_ = file
}
