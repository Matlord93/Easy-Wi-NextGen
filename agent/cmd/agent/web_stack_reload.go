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

	webStackReloadPhpFpm(&out)
	webStackReloadNginx(&out)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message": "web stack reload completed",
			"details": out.String(),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func webStackReloadPhpFpm(out *strings.Builder) {
	versions := []string{"8.5", "8.4", "8.3", "8.2", "8.1"}
	for _, ver := range versions {
		service := fmt.Sprintf("php%s-fpm", ver)
		if !webStackServiceActive(service) {
			continue
		}
		if webStackRunService("reload", service, out) {
			return
		}
		// reload not supported by this unit – fall back to restart
		webStackRunService("restart", service, out)
		return
	}
	appendOutput(out, "php-fpm: no active php-fpm service found (tried "+strings.Join(versions, ", ")+")")
}

func webStackReloadNginx(out *strings.Builder) {
	if !webStackServiceActive("nginx") {
		appendOutput(out, "nginx: service not active")
		return
	}
	if webStackRunService("reload", "nginx", out) {
		return
	}
	// reload not supported – fall back to restart
	webStackRunService("restart", "nginx", out)
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
