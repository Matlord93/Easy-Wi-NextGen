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

	if runtime.GOOS == "windows" {
		appendOutput(&output, "os.update=unsupported")
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "os updates are not supported on Windows agents yet"},
			Completed: time.Now().UTC(),
		}, nil
	}

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

	if runtime.GOOS == "windows" {
		appendOutput(&output, "server.update.check=unsupported")
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "server updates are not supported on Windows agents yet"},
			Completed: time.Now().UTC(),
		}, nil
	}

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

	if runtime.GOOS == "windows" {
		appendOutput(&output, "server.update.run=unsupported")
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "server updates are not supported on Windows agents yet"},
			Completed: time.Now().UTC(),
		}, nil
	}

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
		appendOutput(&output, "os.reboot=unsupported")
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "os reboot is not supported on Windows agents yet"},
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

func runCommandCapture(name string, args []string, output *strings.Builder) (string, error) {
	cmd := exec.Command(name, args...)
	if name == "apt-get" {
		cmd.Env = append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
	}
	bytes, err := cmd.CombinedOutput()
	appendOutput(output, fmt.Sprintf("cmd=%s %s", name, strings.Join(args, " ")))
	if len(bytes) > 0 {
		appendOutput(output, string(bytes))
	}
	if err != nil {
		return string(bytes), fmt.Errorf("command %s failed: %w", name, err)
	}
	return string(bytes), nil
}

func runRhelUpdateCheck(tool string, output *strings.Builder) (bool, int, error) {
	cmd := exec.Command(tool, "check-update")
	bytes, err := cmd.CombinedOutput()
	appendOutput(output, fmt.Sprintf("cmd=%s check-update", tool))
	if len(bytes) > 0 {
		appendOutput(output, string(bytes))
	}
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			if exitErr.ExitCode() == 100 {
				count := countPackageLines(string(bytes))
				return true, count, nil
			}
		}
		return false, -1, fmt.Errorf("%s check-update failed: %w", tool, err)
	}
	return false, 0, nil
}

func runPacmanCheckUpdates(tool string, args []string, output *strings.Builder) (bool, int, error) {
	cmd := exec.Command(tool, args...)
	bytes, err := cmd.CombinedOutput()
	appendOutput(output, fmt.Sprintf("cmd=%s %s", tool, strings.Join(args, " ")))
	if len(bytes) > 0 {
		appendOutput(output, string(bytes))
	}
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			if exitErr.ExitCode() == 2 {
				return false, 0, nil
			}
		}
		return false, -1, fmt.Errorf("%s update check failed: %w", tool, err)
	}
	count := countPackageLines(string(bytes))
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
	if runtime.GOOS != "linux" {
		return "unknown"
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
	if runtime.GOOS != "linux" {
		return false
	}

	_, err := os.Stat("/var/run/reboot-required")
	return err == nil
}

func collectServiceStatus(roles []string) map[string]string {
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
			services["easywi-runner"] = struct{}{}
		}
	}

	result := make([]string, 0, len(services))
	for service := range services {
		result = append(result, service)
	}

	return result
}
