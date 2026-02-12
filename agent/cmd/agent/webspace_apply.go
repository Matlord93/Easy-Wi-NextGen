package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/jobs"
)

var (
	webspaceApplyLocks sync.Map
)

func handleWebspaceApply(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return webspaceApplyFailure(job.ID, "webspace_unsupported_os", "webspace apply unsupported on windows"), nil
	}

	runtimeType := strings.ToLower(payloadValue(job.Payload, "runtime"))
	if runtimeType == "" {
		runtimeType = "nginx"
	}

	release, err := lockWebspaceApply(job.Payload)
	if err != nil {
		return webspaceApplyFailure(job.ID, "webspace_action_in_progress", err.Error()), nil
	}
	defer release()

	if err := validateWebserverConfig(runtimeType); err != nil {
		return webspaceApplyFailure(job.ID, "configtest_failed", err.Error()), nil
	}
	if err := reloadWebserver(runtimeType); err != nil {
		return webspaceApplyFailure(job.ID, "reload_failed", err.Error()), nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"runtime": runtimeType, "apply_status": "succeeded"}, Completed: time.Now().UTC()}, nil
}

func handleWebspaceDomainApply(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return webspaceApplyFailure(job.ID, "webspace_unsupported_os", "webspace apply unsupported on windows"), nil
	}

	webRoot := payloadValue(job.Payload, "web_root")
	targetPath := payloadValue(job.Payload, "target_path")
	runtimeType := strings.ToLower(payloadValue(job.Payload, "runtime"))
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	if runtimeType == "" {
		runtimeType = "nginx"
	}

	release, err := lockWebspaceApply(job.Payload)
	if err != nil {
		return webspaceApplyFailure(job.ID, "webspace_action_in_progress", err.Error()), nil
	}
	defer release()

	if action == "remove" {
		vhost := strings.TrimSpace(payloadValue(job.Payload, "nginx_vhost_path", "vhost_path"))
		if vhost != "" {
			if err := os.Remove(vhost); err != nil && !os.IsNotExist(err) {
				return webspaceApplyFailure(job.ID, "write_failed", err.Error()), nil
			}
		}
	}

	if webRoot != "" && targetPath != "" {
		if _, err := resolvePathWithinRoot(webRoot, targetPath); err != nil {
			errCode := "invalid_path"
			if strings.Contains(strings.ToLower(err.Error()), "outside") {
				errCode = "path_outside_webspace_root"
			}
			return webspaceApplyFailure(job.ID, errCode, err.Error()), nil
		}
	}

	if err := validateWebserverConfig(runtimeType); err != nil {
		return webspaceApplyFailure(job.ID, "configtest_failed", err.Error()), nil
	}
	if err := reloadWebserver(runtimeType); err != nil {
		return webspaceApplyFailure(job.ID, "reload_failed", err.Error()), nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"runtime": runtimeType, "apply_status": "succeeded"}, Completed: time.Now().UTC()}, nil
}

func validateWebserverConfig(runtimeType string) error {
	switch runtimeType {
	case "apache":
		return runValidationCommand("apachectl", "configtest")
	case "both":
		if err := runValidationCommand("nginx", "-t"); err != nil {
			return err
		}
		return runValidationCommand("apachectl", "configtest")
	case "nginx":
		fallthrough
	default:
		return runValidationCommand("nginx", "-t")
	}
}

func reloadWebserver(runtimeType string) error {
	switch runtimeType {
	case "apache":
		return reloadApache()
	case "both":
		if err := reloadNginx(); err != nil {
			return err
		}
		return reloadApache()
	case "nginx":
		fallthrough
	default:
		return reloadNginx()
	}
}

func reloadApache() error {
	if err := runCommand("systemctl", "reload", "apache2"); err == nil {
		return nil
	}
	if err := runCommand("systemctl", "reload", "httpd"); err == nil {
		return nil
	}
	if err := runCommand("apachectl", "graceful"); err != nil {
		return fmt.Errorf("reload apache failed")
	}
	return nil
}

func runValidationCommand(name string, args ...string) error {
	cmd := exec.Command(name, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s failed: %s", name, sanitizeOutput(string(out)))
	}
	return nil
}

func resolvePathWithinRoot(root, relative string) (string, error) {
	if strings.HasPrefix(relative, "/") {
		return "", fmt.Errorf("invalid path")
	}
	for _, r := range relative {
		if r < 32 || r == 127 {
			return "", fmt.Errorf("invalid path")
		}
	}
	cleanRoot, err := filepath.EvalSymlinks(filepath.Clean(root))
	if err != nil {
		return "", fmt.Errorf("resolve webspace root: %w", err)
	}
	candidate := filepath.Join(cleanRoot, filepath.Clean(relative))
	candidateEval := candidate
	if eval, err := filepath.EvalSymlinks(candidate); err == nil {
		candidateEval = eval
	}
	if rel, err := filepath.Rel(cleanRoot, candidateEval); err != nil || strings.HasPrefix(rel, "..") {
		return "", fmt.Errorf("path outside webspace root")
	}
	return candidateEval, nil
}

func sanitizeOutput(value string) string {
	value = strings.TrimSpace(value)
	value = strings.ReplaceAll(value, "\n", " ")
	if len(value) > 500 {
		return value[:500]
	}
	return value
}

func webspaceApplyFailure(jobID, errorCode, message string) jobs.Result {
	return jobs.Result{JobID: jobID, Status: "failed", Output: map[string]string{"error_code": errorCode, "error_message": sanitizeOutput(message)}, Completed: time.Now().UTC()}
}

func lockWebspaceApply(payload map[string]any) (func(), error) {
	webspaceID := strings.TrimSpace(payloadValue(payload, "webspace_id"))
	if webspaceID == "" {
		return func() {}, nil
	}

	lockRaw, _ := webspaceApplyLocks.LoadOrStore(webspaceID, &sync.Mutex{})
	lock := lockRaw.(*sync.Mutex)
	if !lock.TryLock() {
		return nil, fmt.Errorf("webspace %s apply already running", webspaceID)
	}

	return func() { lock.Unlock() }, nil
}
