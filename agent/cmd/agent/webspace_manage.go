package main

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const webspaceBackupDir = "/var/lib/easywi/web/backups"

func handleWebspaceUpdate(job jobs.Job) (jobs.Result, func() error) {
	webRoot := payloadValue(job.Payload, "web_root", "path")
	docroot := payloadValue(job.Payload, "docroot", "document_root")
	ownerUser := payloadValue(job.Payload, "owner_user", "user")
	ownerGroup := payloadValue(job.Payload, "owner_group", "group")
	phpFpmPoolPath := payloadValue(job.Payload, "php_fpm_pool_path", "fpm_pool_path")
	phpFpmListen := payloadValue(job.Payload, "php_fpm_listen", "fpm_listen")
	nginxIncludePath := payloadValue(job.Payload, "nginx_include_path", "nginx_include")
	phpVersion := payloadValue(job.Payload, "php_version")
	poolName := payloadValue(job.Payload, "pool_name", "php_fpm_pool_name")
	logsDir := payloadValue(job.Payload, "logs_dir")
	tmpDir := payloadValue(job.Payload, "tmp_dir")

	if ownerGroup == "" {
		ownerGroup = ownerUser
	}
	if poolName == "" {
		poolName = ownerUser
	}
	if logsDir == "" && webRoot != "" {
		logsDir = filepath.Join(webRoot, "logs")
	}
	if tmpDir == "" && webRoot != "" {
		tmpDir = filepath.Join(webRoot, "tmp")
	}

	missing := missingValues([]requiredValue{
		{key: "web_root", value: webRoot},
		{key: "docroot", value: docroot},
		{key: "owner_user", value: ownerUser},
		{key: "php_fpm_pool_path", value: phpFpmPoolPath},
		{key: "php_fpm_listen", value: phpFpmListen},
		{key: "nginx_include", value: nginxIncludePath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	phpSettings := payloadPhpSettings(job.Payload)

	if err := ensureDir(docroot); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureDir(logsDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureDir(tmpDir); err != nil {
		return failureResult(job.ID, err)
	}

	if err := writePhpFpmPoolWithSettings(phpFpmPoolPath, poolName, ownerUser, ownerGroup, phpFpmListen, webRoot, logsDir, tmpDir, phpVersion, phpSettings); err != nil {
		return failureResult(job.ID, err)
	}
	if err := writeNginxInclude(nginxIncludePath, docroot, logsDir, phpFpmListen); err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadNginx(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"web_root":      webRoot,
			"docroot":       docroot,
			"logs_dir":      logsDir,
			"php_version":   phpVersion,
			"php_settings":  fmt.Sprintf("%d", len(phpSettings)),
			"nginx_include": nginxIncludePath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceBackup(job jobs.Job) (jobs.Result, func() error) {
	webRoot := payloadValue(job.Payload, "web_root", "path")
	label := payloadValue(job.Payload, "label", "backup_label")
	webspaceID := payloadValue(job.Payload, "webspace_id")

	if webRoot == "" {
		return failureResult(job.ID, fmt.Errorf("missing web_root"))
	}

	if label == "" {
		label = time.Now().UTC().Format("20060102-150405")
	}
	backupRoot := filepath.Join(webspaceBackupDir, webspaceID)
	if webspaceID == "" {
		backupRoot = webspaceBackupDir
	}
	if err := os.MkdirAll(backupRoot, 0o750); err != nil {
		return failureResult(job.ID, fmt.Errorf("create backup dir: %w", err))
	}

	backupPath := filepath.Join(backupRoot, fmt.Sprintf("webspace-%s.tar.gz", label))
	if err := runCommand("tar", "-czf", backupPath, "-C", filepath.Dir(webRoot), filepath.Base(webRoot)); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"backup_path": backupPath,
			"label":       label,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceRestore(job jobs.Job) (jobs.Result, func() error) {
	webRoot := payloadValue(job.Payload, "web_root", "path")
	backupPath := payloadValue(job.Payload, "backup_path")

	if webRoot == "" || backupPath == "" {
		return failureResult(job.ID, fmt.Errorf("missing web_root or backup_path"))
	}

	if err := runCommand("tar", "-xzf", backupPath, "-C", filepath.Dir(webRoot)); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"restored_from": backupPath,
			"web_root":      webRoot,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceLogsTail(job jobs.Job) (jobs.Result, func() error) {
	logsDir := payloadValue(job.Payload, "logs_dir")
	logName := payloadValue(job.Payload, "log_name")
	linesValue := payloadValue(job.Payload, "lines")

	if logsDir == "" || logName == "" {
		return failureResult(job.ID, fmt.Errorf("missing logs_dir or log_name"))
	}

	lines := 200
	if linesValue != "" {
		if parsed, err := strconv.Atoi(linesValue); err == nil && parsed > 0 {
			lines = parsed
		}
	}

	logPath := filepath.Join(logsDir, logName)
	content, err := tailFile(logPath, lines)
	if err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"log_path": logPath,
			"lines":    strconv.Itoa(lines),
			"content":  content,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceCronUpdate(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "owner_user", "username", "user")
	cronTasks := payloadValue(job.Payload, "cron_tasks", "tasks")

	if username == "" {
		return failureResult(job.ID, fmt.Errorf("missing owner_user"))
	}

	cronPath := filepath.Join("/etc/cron.d", fmt.Sprintf("easywi-webspace-%s", username))
	content := "SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n\n"
	if strings.TrimSpace(cronTasks) != "" {
		content += cronTasks + "\n"
	}

	if err := os.WriteFile(cronPath, []byte(content), 0o644); err != nil {
		return failureResult(job.ID, fmt.Errorf("write cron file: %w", err))
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"cron_path": cronPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceGitDeploy(job jobs.Job) (jobs.Result, func() error) {
	repoURL := payloadValue(job.Payload, "repo_url", "git_repo_url")
	branch := payloadValue(job.Payload, "branch", "git_branch")
	docroot := payloadValue(job.Payload, "docroot", "document_root")

	if repoURL == "" || docroot == "" {
		return failureResult(job.ID, fmt.Errorf("missing repo_url or docroot"))
	}
	if branch == "" {
		branch = "main"
	}

	if _, err := exec.LookPath("git"); err != nil {
		return failureResult(job.ID, fmt.Errorf("git not installed"))
	}

	if _, err := os.Stat(filepath.Join(docroot, ".git")); err == nil {
		if err := runCommand("git", "-C", docroot, "fetch", "--all"); err != nil {
			return failureResult(job.ID, err)
		}
		if err := runCommand("git", "-C", docroot, "checkout", branch); err != nil {
			return failureResult(job.ID, err)
		}
		if err := runCommand("git", "-C", docroot, "pull", "--ff-only", "origin", branch); err != nil {
			return failureResult(job.ID, err)
		}
	} else {
		if err := runCommand("git", "clone", "--branch", branch, repoURL, docroot); err != nil {
			return failureResult(job.ID, err)
		}
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"repo_url": repoURL,
			"branch":   branch,
			"docroot":  docroot,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceComposerInstall(job jobs.Job) (jobs.Result, func() error) {
	docroot := payloadValue(job.Payload, "docroot", "document_root")
	if docroot == "" {
		return failureResult(job.ID, fmt.Errorf("missing docroot"))
	}

	if _, err := exec.LookPath("composer"); err != nil {
		return failureResult(job.ID, fmt.Errorf("composer not installed"))
	}

	args := []string{"install", "--no-dev", "--optimize-autoloader", "--no-interaction"}
	if err := runCommand("composer", append([]string{"--working-dir", docroot}, args...)...); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"docroot": docroot,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleRoundcubeInstall(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS != "linux" {
		return failureResult(job.ID, fmt.Errorf("roundcube install is only supported on linux agents"))
	}

	family, err := detectOSFamily()
	if err != nil {
		return failureResult(job.ID, err)
	}

	packages := roundcubePackages(family)
	if len(packages) == 0 {
		return failureResult(job.ID, fmt.Errorf("roundcube packages not available for %s", family))
	}

	var output strings.Builder
	if err := installPackages(family, packages, &output); err != nil {
		return failureResult(job.ID, err)
	}

	roundcubeRoot := detectRoundcubeRoot()
	includePath := "/etc/easywi/web/nginx/includes/roundcube.conf"
	if err := writeRoundcubeInclude(includePath, roundcubeRoot); err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadNginx(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"roundcube_root": roundcubeRoot,
			"nginx_include":  includePath,
			"details":        output.String(),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func roundcubePackages(family string) []string {
	switch family {
	case "debian":
		return []string{"roundcube", "roundcube-core", "roundcube-mysql", "roundcube-plugins"}
	case "rhel":
		return []string{"roundcubemail"}
	default:
		return nil
	}
}

func detectRoundcubeRoot() string {
	candidates := []string{"/var/lib/roundcube", "/usr/share/roundcube"}
	for _, candidate := range candidates {
		if info, err := os.Stat(candidate); err == nil && info.IsDir() {
			return candidate
		}
	}
	return "/var/lib/roundcube"
}

func writeRoundcubeInclude(path, root string) error {
	content := fmt.Sprintf(`location /roundcube {
    alias %s/;
    index index.php;
    location ~ \\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
`, root)
	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return err
	}
	return os.WriteFile(path, []byte(content), 0o640)
}

func payloadPhpSettings(payload map[string]any) map[string]string {
	raw, ok := payload["php_settings"]
	if !ok {
		return nil
	}
	settings := map[string]string{}
	switch value := raw.(type) {
	case map[string]any:
		for key, entry := range value {
			text := strings.TrimSpace(fmt.Sprintf("%v", entry))
			if key != "" && text != "" {
				settings[key] = text
			}
		}
	case map[string]string:
		for key, entry := range value {
			if key != "" && strings.TrimSpace(entry) != "" {
				settings[key] = strings.TrimSpace(entry)
			}
		}
	}
	if len(settings) == 0 {
		return nil
	}
	return settings
}

func tailFile(path string, lines int) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", fmt.Errorf("open log file: %w", err)
	}
	defer file.Close()

	var buffer []string
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		buffer = append(buffer, scanner.Text())
	}
	if err := scanner.Err(); err != nil {
		return "", fmt.Errorf("read log file: %w", err)
	}

	start := 0
	if len(buffer) > lines {
		start = len(buffer) - lines
	}
	return strings.Join(buffer[start:], "\n"), nil
}

func sortedPhpSettings(settings map[string]string) []string {
	keys := make([]string, 0, len(settings))
	for key := range settings {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	return keys
}
