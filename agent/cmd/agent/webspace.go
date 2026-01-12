package main

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	webspaceDirMode  = 0o750
	webspaceFileMode = 0o644
)

func handleWebspaceCreate(job jobs.Job) (jobs.Result, func() error) {
	webRoot := payloadValue(job.Payload, "web_root", "path")
	docroot := payloadValue(job.Payload, "docroot", "document_root")
	ownerUser := payloadValue(job.Payload, "owner_user", "user")
	ownerGroup := payloadValue(job.Payload, "owner_group", "group")
	phpFpmPoolPath := payloadValue(job.Payload, "php_fpm_pool_path", "fpm_pool_path")
	phpFpmListen := payloadValue(job.Payload, "php_fpm_listen", "fpm_listen")
	nginxIncludePath := payloadValue(job.Payload, "nginx_include_path", "nginx_include")
	phpVersion := payloadValue(job.Payload, "php_version")
	poolName := payloadValue(job.Payload, "pool_name", "php_fpm_pool_name")

	var cleanupPaths []string
	rollback := func() error {
		for i := len(cleanupPaths) - 1; i >= 0; i-- {
			path := cleanupPaths[i]
			if err := os.RemoveAll(path); err != nil {
				return fmt.Errorf("rollback remove %s: %w", path, err)
			}
		}
		return nil
	}
	failWithRollback := func(err error) (jobs.Result, func() error) {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error()},
			Completed: time.Now().UTC(),
		}, rollback
	}

	if ownerGroup == "" {
		ownerGroup = ownerUser
	}
	if poolName == "" {
		poolName = ownerUser
	}
	if docroot == "" {
		docroot = filepath.Join(webRoot, "public")
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

	if err := ensureGroup(ownerGroup); err != nil {
		return failWithRollback(err)
	}
	if err := ensureUser(ownerUser, ownerGroup, webRoot); err != nil {
		return failWithRollback(err)
	}

	if !pathExists(webRoot) {
		cleanupPaths = append(cleanupPaths, webRoot)
	}
	if err := ensureDir(webRoot); err != nil {
		return failWithRollback(err)
	}

	logsDir := filepath.Join(webRoot, "logs")
	tmpDir := filepath.Join(webRoot, "tmp")

	for _, dir := range []string{docroot, logsDir, tmpDir} {
		if !pathExists(dir) {
			cleanupPaths = append(cleanupPaths, dir)
		}
		if err := ensureDir(dir); err != nil {
			return failWithRollback(err)
		}
	}

	uid, gid, err := lookupIDs(ownerUser, ownerGroup)
	if err != nil {
		return failWithRollback(err)
	}
	for _, dir := range []string{webRoot, docroot, logsDir, tmpDir} {
		if err := os.Chown(dir, uid, gid); err != nil {
			return failWithRollback(fmt.Errorf("chown %s: %w", dir, err))
		}
		if err := os.Chmod(dir, webspaceDirMode); err != nil {
			return failWithRollback(fmt.Errorf("chmod %s: %w", dir, err))
		}
	}

	if phpFpmPoolPath != "" && !pathExists(phpFpmPoolPath) {
		cleanupPaths = append(cleanupPaths, phpFpmPoolPath)
	}
	if err := writePhpFpmPool(phpFpmPoolPath, poolName, ownerUser, ownerGroup, phpFpmListen, webRoot, logsDir, tmpDir, phpVersion); err != nil {
		return failWithRollback(err)
	}
	if nginxIncludePath != "" && !pathExists(nginxIncludePath) {
		cleanupPaths = append(cleanupPaths, nginxIncludePath)
	}
	if err := writeNginxInclude(nginxIncludePath, docroot, logsDir, phpFpmListen); err != nil {
		return failWithRollback(err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"web_root":          webRoot,
			"docroot":           docroot,
			"logs_dir":          logsDir,
			"tmp_dir":           tmpDir,
			"php_fpm_pool_path": phpFpmPoolPath,
			"nginx_include":     nginxIncludePath,
			"php_fpm_listen":    phpFpmListen,
			"php_version":       phpVersion,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureDir(path string) error {
	if err := os.MkdirAll(path, webspaceDirMode); err != nil {
		return fmt.Errorf("create dir %s: %w", path, err)
	}
	return nil
}

func ensureGroup(name string) error {
	if name == "" {
		return fmt.Errorf("group name cannot be empty")
	}
	if exec.Command("getent", "group", name).Run() == nil {
		return nil
	}
	if err := runCommand("groupadd", "--system", name); err != nil {
		return fmt.Errorf("create group %s: %w", name, err)
	}
	return nil
}

func ensureUser(name, group, home string) error {
	if name == "" {
		return fmt.Errorf("user name cannot be empty")
	}
	if exec.Command("id", "-u", name).Run() == nil {
		return nil
	}
	if err := runCommand("useradd", "--system", "--home-dir", home, "--shell", "/usr/sbin/nologin", "--gid", group, "--no-create-home", name); err != nil {
		return fmt.Errorf("create user %s: %w", name, err)
	}
	return nil
}

func lookupIDs(userName, groupName string) (int, int, error) {
	uid, err := lookupUID(userName)
	if err != nil {
		return 0, 0, err
	}
	gid, err := lookupGID(groupName)
	if err != nil {
		return 0, 0, err
	}
	return uid, gid, nil
}

func lookupUID(userName string) (int, error) {
	output, err := exec.Command("id", "-u", userName).Output()
	if err != nil {
		return 0, fmt.Errorf("lookup uid for %s: %w", userName, err)
	}
	value := strings.TrimSpace(string(output))
	uid, err := strconv.Atoi(value)
	if err != nil {
		return 0, fmt.Errorf("parse uid for %s: %w", userName, err)
	}
	return uid, nil
}

func lookupGID(groupName string) (int, error) {
	output, err := exec.Command("getent", "group", groupName).Output()
	if err != nil {
		return 0, fmt.Errorf("lookup gid for %s: %w", groupName, err)
	}
	parts := strings.SplitN(strings.TrimSpace(string(output)), ":", 4)
	if len(parts) < 3 {
		return 0, fmt.Errorf("unexpected group entry for %s", groupName)
	}
	gid, err := strconv.Atoi(parts[2])
	if err != nil {
		return 0, fmt.Errorf("parse gid for %s: %w", groupName, err)
	}
	return gid, nil
}

func writePhpFpmPool(path, pool, user, group, listen, webRoot, logsDir, tmpDir, phpVersion string) error {
	if err := ensureDir(filepath.Dir(path)); err != nil {
		return err
	}
	content := phpFpmPoolTemplate(pool, user, group, listen, webRoot, logsDir, tmpDir, phpVersion)
	if err := os.WriteFile(path, []byte(content), webspaceFileMode); err != nil {
		return fmt.Errorf("write php-fpm pool %s: %w", path, err)
	}
	return nil
}

func writeNginxInclude(path, docroot, logsDir, phpFpmListen string) error {
	if err := ensureDir(filepath.Dir(path)); err != nil {
		return err
	}
	content := nginxIncludeTemplate(docroot, logsDir, phpFpmListen)
	if err := os.WriteFile(path, []byte(content), webspaceFileMode); err != nil {
		return fmt.Errorf("write nginx include %s: %w", path, err)
	}
	return nil
}

func phpFpmPoolTemplate(pool, user, group, listen, webRoot, logsDir, tmpDir, phpVersion string) string {
	var buffer bytes.Buffer
	buffer.WriteString("; Managed by Easy-Wi agent\n")
	if phpVersion != "" {
		buffer.WriteString(fmt.Sprintf("; PHP version: %s\n", phpVersion))
	}
	buffer.WriteString(fmt.Sprintf("[%s]\n", pool))
	buffer.WriteString(fmt.Sprintf("user = %s\n", user))
	buffer.WriteString(fmt.Sprintf("group = %s\n", group))
	buffer.WriteString(fmt.Sprintf("listen = %s\n", listen))
	buffer.WriteString(fmt.Sprintf("listen.owner = %s\n", user))
	buffer.WriteString(fmt.Sprintf("listen.group = %s\n", group))
	buffer.WriteString("listen.mode = 0660\n")
	buffer.WriteString("pm = ondemand\n")
	buffer.WriteString("pm.max_children = 10\n")
	buffer.WriteString("pm.process_idle_timeout = 10s\n")
	buffer.WriteString("pm.max_requests = 500\n")
	buffer.WriteString("catch_workers_output = yes\n")
	buffer.WriteString(fmt.Sprintf("access.log = %s/php-fpm-access.log\n", logsDir))
	buffer.WriteString(fmt.Sprintf("php_admin_value[open_basedir] = %s:/tmp\n", webRoot))
	buffer.WriteString(fmt.Sprintf("php_admin_value[upload_tmp_dir] = %s\n", tmpDir))
	buffer.WriteString(fmt.Sprintf("php_admin_value[session.save_path] = %s\n", tmpDir))
	buffer.WriteString("security.limit_extensions = .php\n")
	return buffer.String()
}

func nginxIncludeTemplate(docroot, logsDir, phpFpmListen string) string {
	return fmt.Sprintf(`## Managed by Easy-Wi agent
root %s;
index index.php index.html;

access_log %s/access.log;
error_log %s/error.log;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass %s;
}
`, docroot, logsDir, logsDir, phpFpmListen)
}

func runCommand(name string, args ...string) error {
	cmd := exec.Command(name, args...)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s %s failed: %w (%s)", name, strings.Join(args, " "), err, strings.TrimSpace(string(output)))
	}
	return nil
}

func pathExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}

func failureResult(jobID string, err error) (jobs.Result, func() error) {
	return jobs.Result{
		JobID:     jobID,
		Status:    "failed",
		Output:    map[string]string{"message": err.Error()},
		Completed: time.Now().UTC(),
	}, nil
}

type requiredValue struct {
	key   string
	value string
}

func missingValues(values []requiredValue) []string {
	var missing []string
	for _, entry := range values {
		if entry.value == "" {
			missing = append(missing, entry.key)
		}
	}
	return missing
}
