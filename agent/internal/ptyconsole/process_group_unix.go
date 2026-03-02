//go:build !windows

package ptyconsole

import "syscall"

func processGroupSysProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setpgid: true}
}

func terminateProcessGroup(pid int) error {
	return syscall.Kill(-pid, syscall.SIGTERM)
}

func killProcessGroupHard(pid int) error {
	return syscall.Kill(-pid, syscall.SIGKILL)
}
