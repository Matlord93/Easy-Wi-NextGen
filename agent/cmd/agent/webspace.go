package main

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
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
	if runtime.GOOS == "windows" {
		return webspaceApplyFailure(job.ID, "webspace_unsupported_os", "webspace create unsupported on windows"), nil
	}

	webRoot := payloadValue(job.Payload, "web_root", "path")
	docroot := payloadValue(job.Payload, "docroot", "document_root")
	ownerUser := payloadValue(job.Payload, "owner_user", "user")
	ownerGroup := payloadValue(job.Payload, "owner_group", "group")
	phpFpmPoolPath := payloadValue(job.Payload, "php_fpm_pool_path", "fpm_pool_path")
	phpFpmListen := payloadValue(job.Payload, "php_fpm_listen", "fpm_listen")
	nginxIncludePath := payloadValue(job.Payload, "nginx_include_path", "nginx_include")
	phpVersion := payloadValue(job.Payload, "php_version")
	poolName := payloadValue(job.Payload, "pool_name", "php_fpm_pool_name")
	phpSettings := payloadPhpSettings(job.Payload)
	nginxSocketUser, nginxSocketGroup := detectNginxSocketIdentity()

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
	if err := writePhpFpmPoolWithSettings(phpFpmPoolPath, poolName, ownerUser, ownerGroup, nginxSocketUser, nginxSocketGroup, phpFpmListen, webRoot, logsDir, tmpDir, phpVersion, phpSettings); err != nil {
		return failWithRollback(err)
	}
	if err := activatePhpFpmPool(phpVersion, phpFpmPoolPath); err != nil {
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
			"php_settings":      fmt.Sprintf("%d", len(phpSettings)),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceDelete(job jobs.Job) (jobs.Result, func() error) {
	webRoot := payloadValue(job.Payload, "web_root", "path")
	ownerUser := payloadValue(job.Payload, "owner_user", "user")
	ownerGroup := payloadValue(job.Payload, "owner_group", "group")
	phpFpmPoolPath := payloadValue(job.Payload, "php_fpm_pool_path", "fpm_pool_path")
	nginxIncludePath := payloadValue(job.Payload, "nginx_include_path", "nginx_include")

	missing := missingValues([]requiredValue{
		{key: "web_root", value: webRoot},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	baseDir := payloadValue(job.Payload, "base_dir")
	if baseDir == "" {
		baseDir = "/"
	}

	safeRoot, err := ensureSafePath(webRoot, baseDir)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if filepath.Clean(safeRoot) == "/" {
		return failureResult(job.ID, fmt.Errorf("refusing to remove root path"))
	}

	for _, path := range []string{phpFpmPoolPath, nginxIncludePath} {
		if path == "" {
			continue
		}
		if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
			return failureResult(job.ID, fmt.Errorf("remove %s: %w", path, err))
		}
	}

	if err := os.RemoveAll(safeRoot); err != nil {
		return failureResult(job.ID, fmt.Errorf("remove web root: %w", err))
	}

	if ownerUser != "" {
		_ = runCommand("userdel", "--remove", ownerUser)
	}
	if ownerGroup != "" {
		_ = runCommand("groupdel", ownerGroup)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"web_root": safeRoot,
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

func writePhpFpmPoolWithSettings(path, pool, user, group, listenUser, listenGroup, listen, webRoot, logsDir, tmpDir, phpVersion string, phpSettings map[string]string) error {
	if err := ensureDir(filepath.Dir(path)); err != nil {
		return err
	}
	content := phpFpmPoolTemplate(pool, user, group, listenUser, listenGroup, listen, webRoot, logsDir, tmpDir, phpVersion, phpSettings)
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

func phpFpmPoolTemplate(pool, user, group, listenUser, listenGroup, listen, webRoot, logsDir, tmpDir, phpVersion string, phpSettings map[string]string) string {
	var buffer bytes.Buffer
	buffer.WriteString("; Managed by Easy-Wi agent\n")
	if phpVersion != "" {
		_, _ = fmt.Fprintf(&buffer, "; PHP version: %s\n", phpVersion)
	}
	_, _ = fmt.Fprintf(&buffer, "[%s]\n", pool)
	_, _ = fmt.Fprintf(&buffer, "user = %s\n", user)
	_, _ = fmt.Fprintf(&buffer, "group = %s\n", group)
	_, _ = fmt.Fprintf(&buffer, "listen = %s\n", listen)
	_, _ = fmt.Fprintf(&buffer, "listen.owner = %s\n", listenUser)
	_, _ = fmt.Fprintf(&buffer, "listen.group = %s\n", listenGroup)
	buffer.WriteString("listen.mode = 0660\n")
	buffer.WriteString("pm = ondemand\n")
	buffer.WriteString("pm.max_children = 10\n")
	buffer.WriteString("pm.process_idle_timeout = 10s\n")
	buffer.WriteString("pm.max_requests = 500\n")
	buffer.WriteString("catch_workers_output = yes\n")
	_, _ = fmt.Fprintf(&buffer, "access.log = %s/php-fpm-access.log\n", logsDir)
	_, _ = fmt.Fprintf(&buffer, "php_admin_value[open_basedir] = %s:/tmp\n", webRoot)
	_, _ = fmt.Fprintf(&buffer, "php_admin_value[upload_tmp_dir] = %s\n", tmpDir)
	_, _ = fmt.Fprintf(&buffer, "php_admin_value[session.save_path] = %s\n", tmpDir)
	if len(phpSettings) > 0 {
		for _, key := range sortedPhpSettings(phpSettings) {
			value := phpSettings[key]
			if value == "" {
				continue
			}
			_, _ = fmt.Fprintf(&buffer, "php_admin_value[%s] = %s\n", key, value)
		}
	}
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
include /etc/easywi/web/nginx/includes/roundcube.conf;
`, docroot, logsDir, logsDir, nginxFastcgiPass(phpFpmListen))
}

func nginxFastcgiPass(listen string) string {
	listen = strings.TrimSpace(listen)
	if strings.HasPrefix(listen, "/") {
		return "unix:" + listen
	}
	return listen
}

func detectNginxSocketIdentity() (string, string) {
	candidates := [][2]string{{"www-data", "www-data"}, {"nginx", "nginx"}, {"http", "http"}}
	for _, candidate := range candidates {
		if lookupUIDErr(candidate[0]) == nil && lookupGIDErr(candidate[1]) == nil {
			return candidate[0], candidate[1]
		}
	}
	return "www-data", "www-data"
}

func lookupUIDErr(userName string) error {
	_, err := lookupUID(userName)
	return err
}

func lookupGIDErr(groupName string) error {
	_, err := lookupGID(groupName)
	return err
}

func activatePhpFpmPool(phpVersion, poolPath string) error {
	version := strings.TrimPrefix(strings.TrimSpace(phpVersion), "php")
	if version == "" {
		return nil
	}
	poolName := filepath.Base(poolPath)
	poolDir := filepath.Join("/etc/php", version, "fpm", "pool.d")
	if err := ensureDir(poolDir); err != nil {
		return err
	}
	target := filepath.Join(poolDir, poolName)
	_ = os.Remove(target)
	if err := os.Symlink(poolPath, target); err != nil {
		return fmt.Errorf("activate php-fpm pool symlink %s: %w", target, err)
	}
	service := "php" + version + "-fpm"
	if err := runCommand("systemctl", "reload", service); err != nil {
		if restartErr := runCommand("systemctl", "restart", service); restartErr != nil {
			return fmt.Errorf("reload/restart %s failed: %w", service, err)
		}
	}
	return nil
}

func runCommand(name string, args ...string) error {
	_, err := runCommandOutput(name, args...)
	return err
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
