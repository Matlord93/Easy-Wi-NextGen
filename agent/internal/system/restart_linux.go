//go:build linux

package system

import (
	"os"

	"golang.org/x/sys/unix"
)

func restartManualAgent(binary string, args []string) error {
	// Replacing this process closes the old agent's lock before the new binary starts.
	return unix.Exec(binary, append([]string{binary}, args...), os.Environ())
}
