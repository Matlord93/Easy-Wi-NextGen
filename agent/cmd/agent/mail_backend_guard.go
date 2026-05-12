package main

import (
	"fmt"
	"runtime"
	"strings"

	"easywi/agent/internal/jobs"
)

func mailBackendGuard(job jobs.Job) (map[string]string, bool) {
	enabled := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "mail_enabled")))
	backend := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "mail_backend")))
	if enabled == "" {
		enabled = "true"
	}
	if backend == "" {
		backend = "local"
	}

	if enabled == "false" || enabled == "0" || backend == "none" {
		return map[string]string{
			"message":      "mail backend disabled; operation blocked",
			"error_code":   "MAIL_BACKEND_DISABLED",
			"ui_hint":      "Enable mail and choose backend local|panel|external.",
			"mail_backend": backend,
		}, false
	}

	if backend != "local" && backend != "panel" && backend != "external" && backend != mailEnableBackendName {
		return map[string]string{
			"message":    fmt.Sprintf("unsupported mail backend %q", backend),
			"error_code": "MAIL_BACKEND_INVALID",
		}, false
	}

	if runtime.GOOS != "windows" && backend == mailEnableBackendName {
		return map[string]string{
			"message":      "MailEnable backend is only supported on Windows agents",
			"error_code":   "MAIL_BACKEND_INVALID_PLATFORM",
			"mail_backend": backend,
		}, false
	}

	if runtime.GOOS == "windows" && backend == mailEnableBackendName && !mailEnablePowerShellAvailable() {
		return map[string]string{
			"message":      "Windows MailEnable mail requires the MailEnable.Provision.Command PowerShell snap-in",
			"error_code":   "MAIL_BACKEND_UNSUPPORTED_WINDOWS",
			"ui_hint":      "Install/configure MailEnable, use mail_backend=external/panel, or run local mail on a Linux node.",
			"mail_backend": backend,
		}, false
	}

	return nil, true
}
