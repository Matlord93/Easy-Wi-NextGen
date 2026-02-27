package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
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
	domainName := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "domain")))
	serverAliases := strings.TrimSpace(payloadValue(job.Payload, "server_aliases", "aliases"))
	docroot := strings.TrimSpace(payloadValue(job.Payload, "docroot", "document_root"))
	phpFpmListen := strings.TrimSpace(payloadValue(job.Payload, "php_fpm_listen", "fpm_listen"))
	directivesRaw := payloadValue(job.Payload, "extra_directives", "nginx_directives")
	redirectHTTPS := payloadValue(job.Payload, "redirect_https") == "1" || strings.EqualFold(payloadValue(job.Payload, "redirect_https"), "true")
	runtimeType := strings.ToLower(payloadValue(job.Payload, "runtime"))
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	rollback := func() error { return nil }
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
			rollback = captureVhostRollback(vhost)
			if err := os.Remove(vhost); err != nil && !os.IsNotExist(err) {
				return webspaceApplyFailure(job.ID, "write_failed", err.Error()), nil
			}
		}
	}

	if action != "remove" {
		if err := validateDomainName(domainName); err != nil {
			return webspaceApplyFailure(job.ID, "invalid_domain_name", err.Error()), nil
		}

		aliasValues := strings.FieldsFunc(serverAliases, func(r rune) bool { return r == ',' || r == ';' || r == ' ' || r == '\t' || r == '\n' })
		for _, alias := range aliasValues {
			if err := validateDomainName(alias); err != nil {
				return webspaceApplyFailure(job.ID, "invalid_domain_name", "invalid alias: "+alias), nil
			}
		}

		if webRoot != "" && targetPath != "" {
			resolved, err := resolvePathWithinRoot(webRoot, targetPath)
			if err != nil {
				errCode := "invalid_path"
				if strings.Contains(strings.ToLower(err.Error()), "outside") {
					errCode = "path_outside_webspace_root"
				}
				return webspaceApplyFailure(job.ID, errCode, err.Error()), nil
			}
			docroot = resolved
		}

		if docroot == "" {
			return webspaceApplyFailure(job.ID, "invalid_path", "docroot is required"), nil
		}
		if phpFpmListen == "" {
			return webspaceApplyFailure(job.ID, "invalid_payload", "php_fpm_listen is required"), nil
		}

		directives, err := parseWhitelistedDirectives(directivesRaw)
		if err != nil {
			return webspaceApplyFailure(job.ID, "forbidden_directive", err.Error()), nil
		}

		vhost := strings.TrimSpace(payloadValue(job.Payload, "nginx_vhost_path", "vhost_path"))
		if vhost == "" {
			vhost = filepath.Join("/etc/easywi/web/nginx/vhosts", domainName+".conf")
		}
		rollback = captureVhostRollback(vhost)
		content := buildManagedNginxVhost(domainName, aliasValues, docroot, phpFpmListen, redirectHTTPS, directives)
		changed, err := writeVhostAtomically(vhost, []byte(content))
		if err != nil {
			return webspaceApplyFailure(job.ID, "write_failed", err.Error()), nil
		}
		if !changed {
			return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"runtime": runtimeType, "apply_status": "succeeded", "changed": "0"}, Completed: time.Now().UTC()}, nil
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
		_ = rollback()
		return webspaceApplyFailure(job.ID, "configtest_failed", err.Error()), nil
	}
	if err := reloadWebserver(runtimeType); err != nil {
		if rollbackErr := rollback(); rollbackErr == nil {
			if validateErr := validateWebserverConfig(runtimeType); validateErr == nil {
				_ = reloadWebserver(runtimeType)
			}
		}
		return webspaceApplyFailure(job.ID, "reload_failed", err.Error()), nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"runtime": runtimeType, "apply_status": "succeeded", "changed": "1"}, Completed: time.Now().UTC()}, nil
}

func captureVhostRollback(path string) func() error {
	content, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return func() error {
				if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
					return fmt.Errorf("rollback remove vhost: %w", err)
				}
				return nil
			}
		}
		return func() error { return nil }
	}

	return func() error {
		if err := ensureDir(filepath.Dir(path)); err != nil {
			return err
		}
		if err := os.WriteFile(path, content, 0o644); err != nil {
			return fmt.Errorf("rollback restore vhost: %w", err)
		}
		return nil
	}
}

func writeVhostAtomically(path string, content []byte) (bool, error) {
	if err := ensureDir(filepath.Dir(path)); err != nil {
		return false, err
	}

	if current, err := os.ReadFile(path); err == nil {
		if string(current) == string(content) {
			return false, nil
		}
	}

	staging := path + ".staging-" + strconv.FormatInt(time.Now().UnixNano(), 10)
	perm := os.FileMode(0o644)
	uid := -1
	gid := -1
	if stat, err := os.Stat(path); err == nil {
		perm = stat.Mode().Perm()
		uid, gid = fileOwnerIDs(stat)
	}

	if err := os.WriteFile(staging, content, perm); err != nil {
		return false, fmt.Errorf("write staging vhost: %w", err)
	}
	if uid >= 0 && gid >= 0 {
		if err := os.Chown(staging, uid, gid); err != nil {
			_ = os.Remove(staging)
			return false, fmt.Errorf("set staging ownership: %w", err)
		}
	}

	if err := os.Rename(staging, path); err != nil {
		_ = os.Remove(staging)
		return false, fmt.Errorf("atomic swap vhost: %w", err)
	}

	return true, nil
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
