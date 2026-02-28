package main

import (
	"fmt"
	"runtime"
	"strings"
)

var windowsAllowedJobTypes = map[string]struct{}{
	"agent.update":                    {},
	"agent.self_update":               {},
	"agent.diagnostics":               {},
	"role.ensure_base":                {},
	"game.ensure_base":                {},
	"security.ensure_base":            {},
	"web.ensure_base":                 {},
	"instance.create":                 {},
	"instance.start":                  {},
	"instance.stop":                   {},
	"instance.restart":                {},
	"instance.delete":                 {},
	"instance.config.apply":           {},
	"instance.files.list":             {},
	"instance.files.read":             {},
	"instance.files.write":            {},
	"instance.files.delete":           {},
	"instance.files.mkdir":            {},
	"instance.sftp.credentials.reset": {},
	"windows.service.start":           {},
	"windows.service.stop":            {},
	"windows.service.restart":         {},
}

func ensureJobSupportedByPlatform(jobType string) error {
	jobType = strings.TrimSpace(jobType)
	if jobType == "" {
		return nil
	}

	switch runtime.GOOS {
	case "windows":
		if _, ok := windowsAllowedJobTypes[jobType]; ok {
			return nil
		}
		return fmt.Errorf("job type %q is blocked on windows agent; only windows-compatible jobs are allowed", jobType)
	default:
		if strings.HasPrefix(jobType, "windows.service.") {
			return fmt.Errorf("job type %q is blocked on %s agent", jobType, runtime.GOOS)
		}
		return nil
	}
}
