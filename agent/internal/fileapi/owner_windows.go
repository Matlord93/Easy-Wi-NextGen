//go:build windows

package fileapi

func fileOwnership(_ string) (int, int, bool) {
	return -1, -1, false
}

func chownPath(_ string, _, _ int) error {
	return nil
}
