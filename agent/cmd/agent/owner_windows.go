//go:build windows

package main

func resolveOwnerFromPath(path string) (int, int, bool) {
	return 0, 0, false
}
