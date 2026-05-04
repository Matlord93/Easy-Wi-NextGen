package main

import (
	"fmt"
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

	if backend != "local" && backend != "panel" && backend != "external" {
		return map[string]string{
			"message":    fmt.Sprintf("unsupported mail backend %q", backend),
			"error_code": "MAIL_BACKEND_INVALID",
		}, false
	}

	return nil, true
}
