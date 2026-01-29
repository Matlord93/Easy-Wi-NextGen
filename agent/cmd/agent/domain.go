package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	domainDirMode  = 0o750
	domainFileMode = 0o644
)

func handleDomainAdd(job jobs.Job) (jobs.Result, func() error) {
	domainName := payloadValue(job.Payload, "domain", "hostname", "name")
	webRoot := payloadValue(job.Payload, "web_root", "path")
	sourceDir := payloadValue(job.Payload, "source_dir", "public_dir", "docroot_source")
	docroot := payloadValue(job.Payload, "docroot", "document_root", "docroot_target")
	nginxVhostPath := payloadValue(job.Payload, "nginx_vhost_path", "vhost_path", "nginx_vhost")
	nginxIncludePath := payloadValue(job.Payload, "nginx_include_path", "nginx_include")
	phpFpmListen := payloadValue(job.Payload, "php_fpm_listen", "fpm_listen")
	logsDir := payloadValue(job.Payload, "logs_dir")
	serverAliases := payloadValue(job.Payload, "server_aliases", "aliases")

	if sourceDir == "" && webRoot != "" {
		sourceDir = filepath.Join(webRoot, "public")
	}
	if logsDir == "" && webRoot != "" {
		logsDir = filepath.Join(webRoot, "logs")
	}

	missing := missingValues([]requiredValue{
		{key: "domain", value: domainName},
		{key: "docroot", value: docroot},
		{key: "source_dir", value: sourceDir},
		{key: "nginx_vhost_path", value: nginxVhostPath},
		{key: "php_fpm_listen", value: phpFpmListen},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := ensureDirWithMode(docroot, domainDirMode); err != nil {
		return failureResult(job.ID, err)
	}
	if logsDir != "" {
		if err := ensureDirWithMode(logsDir, domainDirMode); err != nil {
			return failureResult(job.ID, err)
		}
	}
	if err := ensureDirWithMode(filepath.Dir(nginxVhostPath), domainDirMode); err != nil {
		return failureResult(job.ID, err)
	}

	if _, err := os.Stat(sourceDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("source dir %s: %w", sourceDir, err))
	}

	if filepath.Clean(sourceDir) != filepath.Clean(docroot) {
		mounted, err := isBindMounted(sourceDir, docroot)
		if err != nil {
			return failureResult(job.ID, err)
		}
		if !mounted {
			if err := runCommand("mount", "--bind", sourceDir, docroot); err != nil {
				return failureResult(job.ID, err)
			}
		}
	}

	if err := writeNginxVhost(nginxVhostPath, domainName, serverAliases, docroot, logsDir, phpFpmListen, nginxIncludePath); err != nil {
		return failureResult(job.ID, err)
	}

	if err := reloadNginx(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"domain":             domainName,
			"docroot":            docroot,
			"source_dir":         sourceDir,
			"nginx_vhost_path":   nginxVhostPath,
			"php_fpm_listen":     phpFpmListen,
			"nginx_logs_dir":     logsDir,
			"nginx_server_names": buildServerNames(domainName, serverAliases),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureDirWithMode(path string, mode os.FileMode) error {
	if err := os.MkdirAll(path, mode); err != nil {
		return fmt.Errorf("create dir %s: %w", path, err)
	}
	if err := os.Chmod(path, mode); err != nil {
		return fmt.Errorf("chmod %s: %w", path, err)
	}
	return nil
}

func isBindMounted(sourceDir, targetDir string) (bool, error) {
	mounts, err := os.ReadFile("/proc/mounts")
	if err != nil {
		return false, fmt.Errorf("read /proc/mounts: %w", err)
	}
	cleanSource := filepath.Clean(sourceDir)
	cleanTarget := filepath.Clean(targetDir)

	for _, line := range strings.Split(string(mounts), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 4 {
			continue
		}
		source := filepath.Clean(fields[0])
		target := filepath.Clean(fields[1])
		options := fields[3]

		if target != cleanTarget {
			continue
		}
		if source == cleanSource {
			return true, nil
		}
		if strings.Contains(options, "bind") {
			return false, fmt.Errorf("docroot %s already mounted from %s", cleanTarget, source)
		}
	}

	return false, nil
}

func writeNginxVhost(path, domainName, serverAliases, docroot, logsDir, phpFpmListen, includePath string) error {
	content := nginxVhostTemplate(domainName, serverAliases, docroot, logsDir, phpFpmListen, includePath)
	if err := os.WriteFile(path, []byte(content), domainFileMode); err != nil {
		return fmt.Errorf("write nginx vhost %s: %w", path, err)
	}
	return nil
}

func nginxVhostTemplate(domainName, serverAliases, docroot, logsDir, phpFpmListen, includePath string) string {
	serverNames := buildServerNames(domainName, serverAliases)
	logBlock := ""
	if logsDir != "" {
		logBlock = fmt.Sprintf("\n    access_log %s/%s-access.log;\n    error_log %s/%s-error.log;\n", logsDir, domainName, logsDir, domainName)
	}
	includeBlock := ""
	if includePath != "" {
		includeBlock = fmt.Sprintf("\n    include %s;\n", includePath)
		logBlock = ""
	}

	return fmt.Sprintf(`## Managed by Easy-Wi agent
server {
    listen 80;
    server_name %s;

    root %s;
    index index.php index.html;%s%s

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass %s;
    }
}
`, serverNames, docroot, logBlock, includeBlock, phpFpmListen)
}

func buildServerNames(domainName, serverAliases string) string {
	values := []string{}
	if domainName != "" {
		values = append(values, domainName)
	}
	if serverAliases != "" {
		for _, alias := range strings.FieldsFunc(serverAliases, func(r rune) bool {
			return r == ',' || r == ' ' || r == ';'
		}) {
			trimmed := strings.TrimSpace(alias)
			if trimmed != "" {
				values = append(values, trimmed)
			}
		}
	}
	if len(values) == 0 {
		return "_"
	}
	return strings.Join(values, " ")
}

func reloadNginx() error {
	if err := runCommand("systemctl", "reload", "nginx"); err != nil {
		if fallbackErr := runCommand("nginx", "-s", "reload"); fallbackErr != nil {
			return fmt.Errorf("reload nginx: systemctl error: %w; nginx error: %v", err, fallbackErr)
		}
	}
	return nil
}
