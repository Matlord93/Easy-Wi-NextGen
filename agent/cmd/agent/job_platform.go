package main

import (
	"fmt"
	"runtime"
	"strings"
)

func ensureJobSupportedByPlatform(jobType string) error {
	jobType = strings.TrimSpace(jobType)
	if jobType == "" {
		return nil
	}

	if runtime.GOOS != "windows" && strings.HasPrefix(jobType, "windows.service.") {
		return fmt.Errorf("job type %q is blocked on %s agent", jobType, runtime.GOOS)
	}
	if runtime.GOOS == "windows" && strings.HasPrefix(jobType, "kvm.") {
		return fmt.Errorf("job type %q requires a Linux agent", jobType)
	}
	if runtime.GOOS == "windows" && strings.HasPrefix(jobType, "docker.") {
		return fmt.Errorf("job type %q requires a Linux agent", jobType)
	}

	return nil
}
