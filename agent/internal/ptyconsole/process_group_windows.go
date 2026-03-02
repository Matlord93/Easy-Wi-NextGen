//go:build windows

package ptyconsole

import "syscall"

func processGroupSysProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{}
}

func terminateProcessGroup(pid int) error {
	proc, err := syscall.OpenProcess(syscall.PROCESS_TERMINATE, false, uint32(pid))
	if err != nil {
		return err
	}
	defer syscall.CloseHandle(proc)
	return syscall.TerminateProcess(proc, 1)
}

func killProcessGroupHard(pid int) error {
	return terminateProcessGroup(pid)
}
