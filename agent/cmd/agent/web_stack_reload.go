package main

import (
	"fmt"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleWebStackReload(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS != "linux" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "web.stack_reload is only supported on linux"},
			Completed: time.Now().UTC(),
		}, nil
	}

	var out strings.Builder

	ok := reloadWebStack(&out, webStackServiceActive, webStackRunService)
	status := "success"
	message := "web stack reload completed"
	if !ok {
		status = "failed"
		message = "web stack activation failed; check PHP/web service reload logs"
	}
	return jobs.Result{
		JobID: job.ID, Status: status,
		Output:    map[string]string{"message": message, "details": out.String()},
		Completed: time.Now().UTC(),
	}, nil
}

func reloadWebStack(out *strings.Builder, active func(string) bool, run func(string, string, *strings.Builder) bool) bool {
	foundPHP := false
	ok := true
	services := []string{"php8.6-fpm", "php8.5-fpm", "php8.4-fpm", "php8.3-fpm", "php8.2-fpm", "php8.1-fpm", "php-fpm"}
	for _, service := range services {
		if !active(service) {
			continue
		}
		foundPHP = true
		if !run("reload", service, out) && !run("restart", service, out) {
			ok = false
		}
	}
	// Apache installations may serve PHP through mod_php instead of FPM.
	for _, service := range []string{"apache2", "httpd"} {
		if !active(service) {
			continue
		}
		foundPHP = true
		if !run("reload", service, out) && !run("restart", service, out) {
			ok = false
		}
	}
	if !foundPHP {
		appendOutput(out, "no active PHP-FPM/Apache service found; activation cannot be confirmed")
		ok = false
	}
	if active("nginx") {
		if !run("reload", "nginx", out) && !run("restart", "nginx", out) {
			ok = false
		}
	}
	return ok
}

// webStackServiceActive returns true when `systemctl is-active <service>` exits 0.
func webStackServiceActive(service string) bool {
	return exec.Command("systemctl", "is-active", "--quiet", service).Run() == nil
}

// webStackRunService runs `systemctl <action> <service>`, appends the result to
// out, and returns true on success.
func webStackRunService(action, service string, out *strings.Builder) bool {
	cmd := exec.Command("systemctl", action, service)
	combined, err := cmd.CombinedOutput()
	detail := strings.TrimSpace(string(combined))
	if err != nil {
		msg := fmt.Sprintf("%s %s: failed", service, action)
		if detail != "" {
			msg += " – " + detail
		}
		appendOutput(out, msg)
		return false
	}
	msg := fmt.Sprintf("%s %s: ok", service, action)
	if detail != "" {
		msg += " – " + detail
	}
	appendOutput(out, msg)
	return true
}
