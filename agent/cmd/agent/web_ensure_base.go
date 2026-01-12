package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleWebEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return handleWebEnsureBaseWindows(job)
	}
	if runtime.GOOS != "linux" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "web ensure is only supported on linux agents"},
			Completed: time.Now().UTC(),
		}, nil
	}

	family, err := detectOSFamily()
	if err != nil {
		return failureResult(job.ID, err)
	}

	var output strings.Builder
	appendOutput(&output, fmt.Sprintf("detected_os_family=%s", family))

	packages := webBasePackages(family)
	if len(packages) == 0 {
		return failureResult(job.ID, fmt.Errorf("unsupported os family: %s", family))
	}

	if err := installPackages(family, packages, &output); err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": output.String()},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := ensureWebBaseFiles(&output); err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": output.String()},
			Completed: time.Now().UTC(),
		}, nil
	}

	phpVersions := detectPhpVersions()
	if len(phpVersions) == 0 {
		phpVersions = []string{"php8.4"}
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":       "web base ensured",
			"details":       output.String(),
			"web_supported": "true",
			"php_versions":  strings.Join(phpVersions, ","),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebEnsureBaseWindows(job jobs.Job) (jobs.Result, func() error) {
	var output strings.Builder
	appendOutput(&output, "detected_os_family=windows")

	if err := ensureWebBaseFilesWindows(&output); err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": output.String()},
			Completed: time.Now().UTC(),
		}, nil
	}

	phpVersions := detectWindowsPhpVersions()
	if len(phpVersions) == 0 {
		phpVersions = []string{"php8.4"}
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":       "web base ensured",
			"details":       output.String(),
			"web_supported": "true",
			"php_versions":  strings.Join(phpVersions, ","),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func webBasePackages(family string) []string {
	switch family {
	case "debian":
		return []string{"nginx", "php-fpm", "certbot"}
	case "rhel":
		return []string{"nginx", "php-fpm", "certbot"}
	default:
		return nil
	}
}

func ensureWebBaseFiles(output *strings.Builder) error {
	baseDir := "/etc/easywi/web"
	paths := []string{
		filepath.Join(baseDir, "nginx", "includes"),
		filepath.Join(baseDir, "nginx", "templates"),
		filepath.Join(baseDir, "hooks"),
		"/var/lib/easywi/web",
		"/var/log/easywi/web",
	}

	for _, dir := range paths {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return fmt.Errorf("create web dir %s: %w", dir, err)
		}
	}

	templatePath := filepath.Join(baseDir, "nginx", "templates", "base_vhost.conf")
	templateContent := "## Managed by Easy-Wi agent\nserver {\n    listen 80;\n    server_name {{SERVER_NAMES}};\n    include {{INCLUDE_PATH}};\n}\n"
	if err := os.WriteFile(templatePath, []byte(templateContent), 0o640); err != nil {
		return fmt.Errorf("write nginx template: %w", err)
	}
	appendOutput(output, "template_written="+templatePath)

	hookPath := filepath.Join(baseDir, "hooks", "reload.sh")
	hookContent := "#!/bin/sh\nsystemctl reload nginx || true\n"
	if err := os.WriteFile(hookPath, []byte(hookContent), 0o750); err != nil {
		return fmt.Errorf("write reload hook: %w", err)
	}
	appendOutput(output, "hook_written="+hookPath)

	return nil
}

func ensureWebBaseFilesWindows(output *strings.Builder) error {
	baseDir := filepath.Join(windowsEasyWiBaseDir(), "web")
	paths := []string{
		filepath.Join(baseDir, "templates"),
		filepath.Join(baseDir, "hooks"),
		filepath.Join(baseDir, "data"),
		filepath.Join(baseDir, "logs"),
	}

	for _, dir := range paths {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return fmt.Errorf("create web dir %s: %w", dir, err)
		}
	}

	templatePath := filepath.Join(baseDir, "templates", "iis_site.config")
	templateContent := "<configuration>\n  <!-- Managed by Easy-Wi agent -->\n</configuration>\n"
	if err := os.WriteFile(templatePath, []byte(templateContent), 0o640); err != nil {
		return fmt.Errorf("write iis template: %w", err)
	}
	appendOutput(output, "template_written="+templatePath)

	hookPath := filepath.Join(baseDir, "hooks", "reload.ps1")
	hookContent := "Restart-Service W3SVC -ErrorAction SilentlyContinue\n"
	if err := os.WriteFile(hookPath, []byte(hookContent), 0o750); err != nil {
		return fmt.Errorf("write reload hook: %w", err)
	}
	appendOutput(output, "hook_written="+hookPath)

	return nil
}

func detectWindowsPhpVersions() []string {
	phpBinary := "php"
	if !commandExists(phpBinary) && commandExists("php-cgi") {
		phpBinary = "php-cgi"
	}
	if !commandExists(phpBinary) {
		return nil
	}
	output, err := runCommandOutput(phpBinary, "-v")
	if err != nil {
		return nil
	}
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "PHP ") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		versionParts := strings.Split(fields[1], ".")
		if len(versionParts) < 2 {
			continue
		}
		return []string{fmt.Sprintf("php%s.%s", versionParts[0], versionParts[1])}
	}
	return nil
}

func detectPhpVersions() []string {
	var versions []string
	if entries, err := os.ReadDir("/etc/php"); err == nil {
		for _, entry := range entries {
			if entry.IsDir() {
				versions = append(versions, "php"+entry.Name())
			}
		}
	}

	if len(versions) == 0 {
		matches, _ := filepath.Glob("/usr/sbin/php-fpm*")
		for _, match := range matches {
			base := filepath.Base(match)
			version := strings.TrimPrefix(base, "php-fpm")
			if version == "" {
				continue
			}
			version = strings.TrimLeft(version, "-")
			versions = append(versions, "php"+version)
		}
	}

	versions = uniqueStrings(versions)
	sort.Strings(versions)
	return versions
}
