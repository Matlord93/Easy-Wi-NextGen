//go:build windows

package fileapi

import "testing"

// Windows does not have Unix uid 0; this branch is unreachable there.
func runPermissionDeniedUnprivileged(t *testing.T) {
	t.Helper()
	t.Fatal("Unix privilege drop requested on Windows")
}
