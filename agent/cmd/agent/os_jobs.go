package main

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleOSUpdate(job jobs.Job) (jobs.Result, func() error) {
	var output strings.Builder

	if err := runOSUpdate(&output); err != nil {
		return failureResult(job.ID, fmt.Errorf("os update failed: %w", err))
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"message": output.String()},
		Completed: time.Now().UTC(),
	}, nil
}

func handleServerUpdateCheck(job jobs.Job) (jobs.Result, func() error) {
	var output strings.Builder

	available, count, err := runOSUpdateCheck(&output)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("server update check failed: %w", err))
	}

	resultOutput := map[string]string{
		"message":           strings.TrimSpace(output.String()),
		"updates_available": strconv.FormatBool(available),
	}
	if count >= 0 {
		resultOutput["updates_count"] = strconv.Itoa(count)
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    resultOutput,
		Completed: time.Now().UTC(),
	}, nil
}

func handleServerUpdateRun(job jobs.Job) (jobs.Result, func() error) {
	var output strings.Builder

	if err := runOSUpdate(&output); err != nil {
		return failureResult(job.ID, fmt.Errorf("server update failed: %w", err))
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"message": strings.TrimSpace(output.String())},
		Completed: time.Now().UTC(),
	}, nil
}

func handleOSReboot(job jobs.Job) (jobs.Result, func() error) {
	var output strings.Builder

	if runtime.GOOS == "windows" {
		if err := runCommandWithOutput("shutdown", []string{"/r", "/t", "0"}, &output); err != nil {
			return failureResult(job.ID, fmt.Errorf("reboot failed: %w", err))
		}
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    map[string]string{"message": "reboot triggered"},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := runCommandWithOutput("systemctl", []string{"reboot"}, &output); err != nil {
		return failureResult(job.ID, fmt.Errorf("reboot failed: %w", err))
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"message": "reboot triggered"},
		Completed: time.Now().UTC(),
	}, nil
}

func runOSUpdate(output *strings.Builder) error {
	if runtime.GOOS == "windows" {
		return runWindowsUpdate(output)
	}
	if _, err := exec.LookPath("apt-get"); err == nil {
		if err := runCommandWithOutput("apt-get", []string{"update", "-y"}, output); err != nil {
			return err
		}
		return runCommandWithOutput("apt-get", []string{"upgrade", "-y"}, output)
	}

	if _, err := exec.LookPath("dnf"); err == nil {
		return runCommandWithOutput("dnf", []string{"upgrade", "-y"}, output)
	}

	if _, err := exec.LookPath("yum"); err == nil {
		return runCommandWithOutput("yum", []string{"upgrade", "-y"}, output)
	}

	if _, err := exec.LookPath("pacman"); err == nil {
		return runCommandWithOutput("pacman", []string{"-Syu", "--noconfirm"}, output)
	}

	return fmt.Errorf("no supported package manager found")
}

func runOSUpdateCheck(output *strings.Builder) (bool, int, error) {
	if runtime.GOOS == "windows" {
		return runWindowsUpdateCheck(output)
	}
	switch {
	case commandExists("apt-get"):
		commandOutput, err := runCommandCapture("apt-get", []string{"-s", "upgrade"}, output)
		if err != nil {
			return false, -1, err
		}
		return parseAptUpgradeable(commandOutput), parseAptUpgradeableCount(commandOutput), nil
	case commandExists("dnf"):
		return runRhelUpdateCheck("dnf", output)
	case commandExists("yum"):
		return runRhelUpdateCheck("yum", output)
	case commandExists("checkupdates"):
		return runPacmanCheckUpdates("checkupdates", []string{}, output)
	case commandExists("pacman"):
		return runPacmanCheckUpdates("pacman", []string{"-Qu"}, output)
	default:
		return false, -1, fmt.Errorf("no supported package manager found")
	}
}

func runWindowsUpdateCheck(output *strings.Builder) (bool, int, error) {
	shell := windowsPowerShellBinary()
	if shell == "" {
		return false, -1, fmt.Errorf("powershell is required to check Windows updates")
	}
	script := `$ErrorActionPreference='Stop'; $session=New-Object -ComObject Microsoft.Update.Session; $searcher=$session.CreateUpdateSearcher(); $result=$searcher.Search("IsInstalled=0 and Type='Software'"); Write-Output ("windows_updates_available=" + ($result.Updates.Count)); if ($result.Updates.Count -gt 0) { $result.Updates | ForEach-Object { Write-Output ("update=" + $_.Title) } }`
	commandOutput, err := runCommandCapture(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, output)
	if err != nil {
		return false, -1, err
	}
	count := parseWindowsUpdateCount(commandOutput)
	return count > 0, count, nil
}

func runWindowsUpdate(output *strings.Builder) error {
	shell := windowsPowerShellBinary()
	if shell == "" {
		return fmt.Errorf("powershell is required to install Windows updates")
	}
	script := `$ErrorActionPreference='Stop'; $session=New-Object -ComObject Microsoft.Update.Session; $searcher=$session.CreateUpdateSearcher(); $updates=$searcher.Search("IsInstalled=0 and Type='Software'").Updates; Write-Output ("windows_updates_available=" + $updates.Count); if ($updates.Count -eq 0) { return }; $collection=New-Object -ComObject Microsoft.Update.UpdateColl; foreach ($update in $updates) { if (-not $update.EulaAccepted) { $update.AcceptEula() }; [void]$collection.Add($update); Write-Output ("update=" + $update.Title) }; $downloader=$session.CreateUpdateDownloader(); $downloader.Updates=$collection; $download=$downloader.Download(); Write-Output ("windows_update_download_result=" + $download.ResultCode); $installer=$session.CreateUpdateInstaller(); $installer.Updates=$collection; $install=$installer.Install(); Write-Output ("windows_update_install_result=" + $install.ResultCode); Write-Output ("windows_reboot_required=" + $install.RebootRequired)`
	return runCommandWithOutput(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, output)
}

func parseWindowsUpdateCount(output string) int {
	re := regexp.MustCompile(`(?m)^windows_updates_available=(\d+)\s*$`)
	matches := re.FindStringSubmatch(output)
	if len(matches) < 2 {
		return 0
	}
	count, err := strconv.Atoi(matches[1])
	if err != nil {
		return 0
	}
	return count
}

func isWindowsRebootRequired() bool {
	checks := [][]string{
		{"reg", "query", `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired`},
		{"reg", "query", `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending`},
	}
	for _, check := range checks {
		if err := exec.Command(check[0], check[1:]...).Run(); err == nil {
			return true
		}
	}
	return false
}

func collectWindowsServiceStatus(roles []string) map[string]string {
	services := windowsRoleServices(roles)
	statuses := make(map[string]string, len(services))
	for _, service := range services {
		output, err := runCommandOutput("sc", "query", service)
		if err != nil {
			statuses[service] = "inactive"
			continue
		}
		state := strings.ToLower(parseWindowsServiceState(output))
		switch state {
		case "running":
			statuses[service] = "active"
		case "unknown":
			statuses[service] = "inactive"
		default:
			statuses[service] = state
		}
	}
	return statuses
}

func windowsRoleServices(roles []string) []string {
	services := make(map[string]struct{})
	for _, role := range roles {
		switch strings.ToLower(role) {
		case "web", "core":
			services["W3SVC"] = struct{}{}
		case "dns":
			services["DNS"] = struct{}{}
		case "mail":
			services["SMTPSVC"] = struct{}{}
		case "game":
			services["sshd"] = struct{}{}
		}
	}
	result := make([]string, 0, len(services))
	for service := range services {
		result = append(result, service)
	}
	return result
}

func runCommandCapture(name string, args []string, output *strings.Builder) (string, error) {
	cmd := exec.Command(name, args...)
	if name == "apt-get" {
		cmd.Env = append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
	}
	commandOutput, err := StreamCommand(cmd, "", nil)
	appendOutput(output, fmt.Sprintf("cmd=%s %s", name, strings.Join(args, " ")))
	if len(commandOutput) > 0 {
		appendOutput(output, commandOutput)
	}
	if err != nil {
		return commandOutput, fmt.Errorf("command %s failed: %w", name, err)
	}
	return commandOutput, nil
}

func runRhelUpdateCheck(tool string, output *strings.Builder) (bool, int, error) {
	cmd := exec.Command(tool, "check-update")
	commandOutput, err := StreamCommand(cmd, "", nil)
	appendOutput(output, fmt.Sprintf("cmd=%s check-update", tool))
	if len(commandOutput) > 0 {
		appendOutput(output, commandOutput)
	}
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			if exitErr.ExitCode() == 100 {
				count := countPackageLines(commandOutput)
				return true, count, nil
			}
		}
		return false, -1, fmt.Errorf("%s check-update failed: %w", tool, err)
	}
	return false, 0, nil
}

func runPacmanCheckUpdates(tool string, args []string, output *strings.Builder) (bool, int, error) {
	cmd := exec.Command(tool, args...)
	commandOutput, err := StreamCommand(cmd, "", nil)
	appendOutput(output, fmt.Sprintf("cmd=%s %s", tool, strings.Join(args, " ")))
	if len(commandOutput) > 0 {
		appendOutput(output, commandOutput)
	}
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			if exitErr.ExitCode() == 2 {
				return false, 0, nil
			}
		}
		return false, -1, fmt.Errorf("%s update check failed: %w", tool, err)
	}
	count := countPackageLines(commandOutput)
	return count > 0, count, nil
}

func parseAptUpgradeable(output string) bool {
	return parseAptUpgradeableCount(output) > 0
}

func parseAptUpgradeableCount(output string) int {
	re := regexp.MustCompile(`(?m)^(\\d+) upgraded,`)
	matches := re.FindStringSubmatch(output)
	if len(matches) < 2 {
		return 0
	}
	count, err := strconv.Atoi(matches[1])
	if err != nil {
		return 0
	}
	return count
}

func countPackageLines(output string) int {
	count := 0
	scanner := bufio.NewScanner(strings.NewReader(output))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			continue
		}
		if strings.HasPrefix(line, "Last metadata expiration") {
			continue
		}
		if strings.HasPrefix(line, "Obsoleting Packages") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) >= 2 {
			count++
		}
	}
	return count
}

func detectOSProvider() string {
	if runtime.GOOS == "windows" {
		return "windows"
	}

	if runtime.GOOS != "linux" {
		return runtime.GOOS
	}

	if _, err := exec.LookPath("apt-get"); err == nil {
		return "apt"
	}
	if _, err := exec.LookPath("dnf"); err == nil {
		return "dnf"
	}
	if _, err := exec.LookPath("yum"); err == nil {
		return "yum"
	}
	if _, err := exec.LookPath("pacman"); err == nil {
		return "pacman"
	}

	return "unknown"
}

func isRebootRequired() bool {
	if runtime.GOOS == "windows" {
		return isWindowsRebootRequired()
	}
	if runtime.GOOS != "linux" {
		return false
	}

	_, err := os.Stat("/var/run/reboot-required")
	return err == nil
}

func collectServiceStatus(roles []string) map[string]string {
	if runtime.GOOS == "windows" {
		return collectWindowsServiceStatus(roles)
	}
	if runtime.GOOS != "linux" {
		return map[string]string{}
	}

	services := roleServices(roles)
	statuses := make(map[string]string, len(services))

	for _, service := range services {
		if service == "" {
			continue
		}
		state := "inactive"
		if err := exec.Command("systemctl", "is-active", "--quiet", service).Run(); err == nil {
			state = "active"
		}
		statuses[service] = state
	}

	return statuses
}

func roleServices(roles []string) []string {
	services := make(map[string]struct{})

	for _, role := range roles {
		switch strings.ToLower(role) {
		case "web":
			services["nginx"] = struct{}{}
			services["php-fpm"] = struct{}{}
			services["php8.4-fpm"] = struct{}{}
			services["php8.5-fpm"] = struct{}{}
		case "dns":
			services["pdns"] = struct{}{}
			services["powerdns"] = struct{}{}
		case "mail":
			services["postfix"] = struct{}{}
			services["dovecot"] = struct{}{}
		case "db":
			services["mariadb"] = struct{}{}
			services["mysql"] = struct{}{}
			services["postgresql"] = struct{}{}
		case "game":
			services["easywi-agent"] = struct{}{}
		}
	}

	result := make([]string, 0, len(services))
	for service := range services {
		result = append(result, service)
	}

	return result
}
