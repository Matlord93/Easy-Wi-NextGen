package main

import (
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleServerRebootCheckRequired(job jobs.Job) (jobs.Result, func() error) {
	required, details, err := checkRebootRequired()
	if err != nil {
		return failureResult(job.ID, err)
	}

	output := map[string]string{
		"reboot_required": fmt.Sprintf("%t", required),
	}
	if details != "" {
		output["details"] = details
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleServerRebootRun(job jobs.Job) (jobs.Result, func() error) {
	details, err := requestReboot()
	if err != nil {
		return failureResult(job.ID, err)
	}

	output := map[string]string{
		"message": "reboot requested",
	}
	if details != "" {
		output["details"] = details
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func checkRebootRequired() (bool, string, error) {
	switch runtime.GOOS {
	case "linux":
		return checkRebootRequiredLinux()
	case "windows":
		return checkRebootRequiredWindows()
	default:
		return false, "", fmt.Errorf("reboot checks are only supported on linux and windows agents")
	}
}

func checkRebootRequiredLinux() (bool, string, error) {
	paths := []string{"/run/reboot-required", "/var/run/reboot-required"}
	for _, path := range paths {
		if _, err := os.Stat(path); err == nil {
			return true, "flag_file=" + path, nil
		}
	}

	if commandExists("needs-restarting") {
		cmd := exec.Command("needs-restarting", "-r")
		if err := cmd.Run(); err != nil {
			if exitErr, ok := err.(*exec.ExitError); ok && exitErr.ExitCode() == 1 {
				return true, "needs-restarting", nil
			}
			return false, "", fmt.Errorf("needs-restarting failed: %w", err)
		}
		return false, "needs-restarting", nil
	}

	return false, "no reboot flag files found", nil
}

func checkRebootRequiredWindows() (bool, string, error) {
	shell := windowsPowerShellBinary()
	if shell == "" {
		return false, "", fmt.Errorf("powershell is required to check reboot status on windows")
	}

	script := strings.Join([]string{
		`$pending = $false`,
		`if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') { $pending = $true }`,
		`if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') { $pending = $true }`,
		`$session = Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -Name PendingFileRenameOperations -ErrorAction SilentlyContinue`,
		`if ($null -ne $session) { $pending = $true }`,
		`if ($pending) { 'true' } else { 'false' }`,
	}, "; ")

	output, err := runCommandOutput(shell, "-NoProfile", "-NonInteractive", "-Command", script)
	if err != nil {
		return false, "", err
	}
	value := strings.TrimSpace(strings.ToLower(output))
	switch value {
	case "true":
		return true, "windows_registry", nil
	case "false":
		return false, "windows_registry", nil
	default:
		return false, "", fmt.Errorf("unexpected reboot check output: %s", strings.TrimSpace(output))
	}
}

func requestReboot() (string, error) {
	switch runtime.GOOS {
	case "linux":
		return requestRebootLinux()
	case "windows":
		return requestRebootWindows()
	default:
		return "", fmt.Errorf("reboot is only supported on linux and windows agents")
	}
}

func requestRebootLinux() (string, error) {
	if commandExists("systemctl") {
		if err := runCommand("systemctl", "reboot"); err != nil {
			return "", err
		}
		return "systemctl reboot", nil
	}
	if err := runCommand("shutdown", "-r", "now"); err != nil {
		return "", err
	}
	return "shutdown -r now", nil
}

func requestRebootWindows() (string, error) {
	if err := runCommand("shutdown", "/r", "/t", "5", "/f"); err != nil {
		return "", err
	}
	return "shutdown /r /t 5 /f", nil
}
