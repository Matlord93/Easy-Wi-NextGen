//go:build linux

package system

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

func CleanupStaleAgentProcesses(service string) error {
	mainPID := systemdMainPID(service)
	expectedConfig := "/etc/easywi/agent.conf"
	ownPID := os.Getpid()
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return fmt.Errorf("read /proc: %w", err)
	}
	var failures []string
	for _, entry := range entries {
		pid, err := strconv.Atoi(entry.Name())
		if err != nil || pid == ownPID || pid == mainPID {
			continue
		}
		if !isEasyWIAgentProcess(pid, expectedConfig) {
			continue
		}
		if err := syscall.Kill(pid, syscall.SIGTERM); err != nil && err != syscall.ESRCH {
			failures = append(failures, fmt.Sprintf("pid %d: %v", pid, err))
			continue
		}
		time.Sleep(100 * time.Millisecond)
	}
	if len(failures) > 0 {
		return fmt.Errorf("stop stale easywi-agent processes: %s", strings.Join(failures, "; "))
	}
	return nil
}

func systemdMainPID(service string) int {
	if _, err := exec.LookPath("systemctl"); err != nil {
		return 0
	}
	out, err := exec.Command("systemctl", "show", "--property", "MainPID", "--value", service).Output()
	if err != nil {
		return 0
	}
	pid, _ := strconv.Atoi(strings.TrimSpace(string(out)))
	return pid
}

func isEasyWIAgentProcess(pid int, expectedConfig string) bool {
	exe, _ := os.Readlink(filepath.Join("/proc", strconv.Itoa(pid), "exe"))
	resolved, err := filepath.EvalSymlinks(strings.TrimSuffix(exe, " (deleted)"))
	if err != nil || resolved != "/usr/local/bin/easywi-agent" {
		return false
	}
	cmdlineBytes, err := os.ReadFile(filepath.Join("/proc", strconv.Itoa(pid), "cmdline"))
	if err != nil {
		return false
	}
	return cmdlineMatchesAgentConfig(strings.Split(strings.TrimRight(string(cmdlineBytes), "\x00"), "\x00"), expectedConfig)
}

func cmdlineMatchesAgentConfig(args []string, expectedConfig string) bool {
	if len(args) == 0 || args[0] != "/usr/local/bin/easywi-agent" {
		return false
	}
	for i, arg := range args[1:] {
		switch {
		case arg == "--config" && i+2 <= len(args)-1 && args[i+2] == expectedConfig:
			return true
		case arg == "--config="+expectedConfig:
			return true
		case strings.HasPrefix(arg, "--config="):
			return false
		case arg == "--wrapper" || arg == "--self-update" || arg == "--version":
			return false
		}
	}
	return false
}
