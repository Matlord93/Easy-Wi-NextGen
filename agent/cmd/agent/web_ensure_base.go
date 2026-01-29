package main

import (
	"fmt"
	"os"
	"os/exec"
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

	if err := ensureComposer(&output); err != nil {
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
		packages := []string{"nginx", "php-fpm", "certbot", "git"}
		return append(packages, webPhpPackagesDebian()...)
	case "rhel":
		return []string{"nginx", "php-fpm", "certbot", "git"}
	default:
		return nil
	}
}

func webPhpPackagesDebian() []string {
	if !commandExists("apt-cache") {
		return nil
	}

	versions := []string{"8.0", "8.1", "8.2", "8.3", "8.4", "8.5"}
	packages := []string{}
	for _, version := range versions {
		fpmPkg := fmt.Sprintf("php%s-fpm", version)
		cliPkg := fmt.Sprintf("php%s-cli", version)
		commonPkg := fmt.Sprintf("php%s-common", version)

		if packageExistsDebian(fpmPkg) {
			packages = append(packages, fpmPkg)
		}
		if packageExistsDebian(cliPkg) {
			packages = append(packages, cliPkg)
		}
		if packageExistsDebian(commonPkg) {
			packages = append(packages, commonPkg)
		}
	}

	return packages
}

func packageExistsDebian(pkg string) bool {
	return exec.Command("apt-cache", "show", pkg).Run() == nil
}

func ensureComposer(output *strings.Builder) error {
	if commandExists("composer") {
		appendOutput(output, "composer=already_installed")
		return nil
	}
	if !commandExists("php") {
		return fmt.Errorf("composer requires php to be installed")
	}

	installerPath := "/tmp/composer-setup.php"
	switch {
	case commandExists("curl"):
		if err := runCommandWithOutput("curl", []string{"-fsSL", "https://getcomposer.org/installer", "-o", installerPath}, output); err != nil {
			return err
		}
	case commandExists("wget"):
		if err := runCommandWithOutput("wget", []string{"-qO", installerPath, "https://getcomposer.org/installer"}, output); err != nil {
			return err
		}
	default:
		return fmt.Errorf("composer installer download failed: missing curl or wget")
	}

	if err := runCommandWithOutput("php", []string{installerPath, "--install-dir=/usr/local/bin", "--filename=composer"}, output); err != nil {
		return err
	}
	appendOutput(output, "composer=installed")
	return nil
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

	roundcubeInclude := filepath.Join(baseDir, "nginx", "includes", "roundcube.conf")
	if err := os.WriteFile(roundcubeInclude, []byte(""), 0o640); err != nil {
		return fmt.Errorf("write roundcube include: %w", err)
	}
	appendOutput(output, "roundcube_include="+roundcubeInclude)

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
