package main

import (
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"easywi/agent/jobs"
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
