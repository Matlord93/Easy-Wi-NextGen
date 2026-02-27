//go:build windows

package main

import "os"

func fileOwnerIDs(_ os.FileInfo) (int, int) {
	return -1, -1
}
