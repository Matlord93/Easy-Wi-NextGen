//go:build !windows

package ptyconsole

import "syscall"

func processGroupSysProcAttr() *syscall.SysProcAttr {
	// pty.Start enables Setsid, which creates a new session and process group.
	// Calling Setpgid as well would fail for that new session leader (EPERM).
	return &syscall.SysProcAttr{}
}

func terminateProcessGroup(pid int) error {
	return syscall.Kill(-pid, syscall.SIGTERM)
}

func killProcessGroupHard(pid int) error {
	return syscall.Kill(-pid, syscall.SIGKILL)
}
