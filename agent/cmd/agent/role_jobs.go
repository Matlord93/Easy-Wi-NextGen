package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleRoleEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	role := strings.ToLower(payloadValue(job.Payload, "role"))
	if role == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing role"},
			Completed: time.Now().UTC(),
		}, nil
	}

	message, err := ensureBaseForRole(role)
	if err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": message, "role": role},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"message": "role base ensured", "details": message, "role": role},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureBaseForRole(role string) (string, error) {
	if runtime.GOOS != "linux" {
		return "", fmt.Errorf("role ensure is only supported on linux agents")
	}

	family, err := detectOSFamily()
	if err != nil {
		return "", err
	}

	var output strings.Builder
	appendOutput(&output, fmt.Sprintf("detected_os_family=%s", family))

	packages := rolePackages(role, family)
	if len(packages) == 0 {
		return output.String(), fmt.Errorf("unsupported role: %s", role)
	}

	if err := installPackages(family, packages, &output); err != nil {
		return output.String(), err
	}

	if err := ensureRoleFiles(role, &output); err != nil {
		return output.String(), err
	}

	if role == "game" {
		if err := installSteamCmd(&output); err != nil {
			return output.String(), err
		}
	}

	if err := enableRoleServices(role, &output); err != nil {
		return output.String(), err
	}

	return output.String(), nil
}

func detectOSFamily() (string, error) {
	content, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return "", fmt.Errorf("read os-release: %w", err)
	}

	var id, idLike string
	for _, line := range strings.Split(string(content), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.Trim(value, `"'`)
		switch key {
		case "ID":
			id = value
		case "ID_LIKE":
			idLike = value
		}
	}

	switch id {
	case "debian", "ubuntu":
		return "debian", nil
	case "rhel", "centos", "fedora", "rocky", "almalinux":
		return "rhel", nil
	}

	if strings.Contains(idLike, "debian") {
		return "debian", nil
	}
	if strings.Contains(idLike, "rhel") || strings.Contains(idLike, "fedora") {
		return "rhel", nil
	}

	return "", fmt.Errorf("unsupported os family: %s", id)
}

func rolePackages(role, family string) []string {
	switch role {
	case "game":
		if family == "debian" {
			return []string{"ca-certificates", "curl", "tar", "xz-utils", "unzip", "tmux", "screen", "lib32gcc-s1", "lib32stdc++6", "libc6-i386"}
		}
		if family == "rhel" {
			return []string{"ca-certificates", "curl", "tar", "xz", "unzip", "tmux", "screen", "glibc.i686", "libstdc++.i686"}
		}
	case "web":
		return []string{"nginx", "php-fpm"}
	case "core":
		return []string{"nginx", "php-fpm"}
	case "dns":
		if family == "debian" {
			return []string{"pdns-server", "pdns-backend-bind"}
		}
		if family == "rhel" {
			return []string{"pdns", "pdns-backend-bind"}
		}
	case "mail":
		return append([]string{"postfix"}, mailDovecotPackages(family)...)
	case "db":
		if family == "debian" {
			return []string{"mariadb-server", "postgresql"}
		}
		if family == "rhel" {
			return []string{"mariadb-server", "postgresql-server"}
		}
	}
	return nil
}

func mailDovecotPackages(family string) []string {
	if family != "debian" {
		return []string{"dovecot"}
	}

	if commandExists("apt-cache") {
		if err := exec.Command("apt-cache", "show", "dovecot-core").Run(); err == nil {
			return []string{"dovecot-core", "dovecot-imapd"}
		}
		if err := exec.Command("apt-cache", "show", "dovecot").Run(); err == nil {
			return []string{"dovecot"}
		}
	}

	return []string{"dovecot-core", "dovecot-imapd"}
}

func installPackages(family string, packages []string, output *strings.Builder) error {
	if len(packages) == 0 {
		return nil
	}

	var updateCmd []string
	var installCmd []string

	switch family {
	case "debian":
		updateCmd = []string{"apt-get", "update", "-y"}
		installCmd = append([]string{"apt-get", "install", "-y"}, packages...)
	case "rhel":
		if commandExists("dnf") {
			updateCmd = []string{"dnf", "makecache"}
			installCmd = append([]string{"dnf", "install", "-y"}, packages...)
		} else {
			updateCmd = []string{"yum", "makecache"}
			installCmd = append([]string{"yum", "install", "-y"}, packages...)
		}
	default:
		return fmt.Errorf("unsupported package manager for family: %s", family)
	}

	if err := runCommandWithOutput(updateCmd[0], updateCmd[1:], output); err != nil {
		return err
	}
	if err := runCommandWithOutput(installCmd[0], installCmd[1:], output); err != nil {
		return err
	}

	return nil
}

func installSteamCmd(output *strings.Builder) error {
	if commandExists("steamcmd") {
		appendOutput(output, "steamcmd=already_installed")
		return nil
	}

	const steamCmdURL = "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz"
	steamCmdDir := "/var/lib/easywi/game/steamcmd"
	archivePath := filepath.Join(steamCmdDir, "steamcmd_linux.tar.gz")

	if err := os.MkdirAll(steamCmdDir, 0o750); err != nil {
		return fmt.Errorf("create steamcmd dir: %w", err)
	}

	switch {
	case commandExists("curl"):
		if err := runCommandWithOutput("curl", []string{"-fsSL", steamCmdURL, "-o", archivePath}, output); err != nil {
			return err
		}
	case commandExists("wget"):
		if err := runCommandWithOutput("wget", []string{"-qO", archivePath, steamCmdURL}, output); err != nil {
			return err
		}
	default:
		return fmt.Errorf("steamcmd download failed: missing curl or wget")
	}

	if err := runCommandWithOutput("tar", []string{"-xzf", archivePath, "-C", steamCmdDir}, output); err != nil {
		return err
	}
	if err := os.Remove(archivePath); err != nil {
		appendOutput(output, "steamcmd_archive_cleanup_failed="+err.Error())
	}

	steamCmdPath := filepath.Join(steamCmdDir, "steamcmd.sh")
	if _, err := os.Stat(steamCmdPath); err == nil {
		if err := runCommandWithOutput("ln", []string{"-sf", steamCmdPath, "/usr/local/bin/steamcmd"}, output); err != nil {
			return err
		}
	}

	return nil
}

func ensureRoleFiles(role string, output *strings.Builder) error {
	if err := os.MkdirAll("/etc/easywi/roles.d", 0o755); err != nil {
		return fmt.Errorf("create roles dir: %w", err)
	}

	roleConfig := filepath.Join("/etc/easywi/roles.d", role+".conf")
	if err := os.WriteFile(roleConfig, []byte("role="+role+"\n"), 0o600); err != nil {
		return fmt.Errorf("write role config: %w", err)
	}
	appendOutput(output, "role_config_written="+roleConfig)

	if role == "game" {
		dirs := []string{
			"/etc/easywi/game",
			"/var/lib/easywi/game/steamcmd",
			"/var/lib/easywi/game/runner",
			"/var/lib/easywi/game/sniper",
			"/var/lib/easywi/game/servers",
			"/var/log/easywi/game",
		}
		for _, dir := range dirs {
			if err := os.MkdirAll(dir, 0o750); err != nil {
				return fmt.Errorf("create game dir %s: %w", dir, err)
			}
		}
	}

	return nil
}

func enableRoleServices(role string, output *strings.Builder) error {
	var services []string

	switch role {
	case "web":
		services = []string{"nginx", "php-fpm", "php8.4-fpm", "php8.3-fpm", "php8.2-fpm", "php8.1-fpm"}
	case "core":
		services = []string{"nginx", "php-fpm", "php8.4-fpm", "php8.3-fpm", "php8.2-fpm", "php8.1-fpm"}
	case "dns":
		services = []string{"pdns", "powerdns"}
	case "mail":
		services = []string{"postfix", "dovecot"}
	case "db":
		services = []string{"mariadb", "mysql", "postgresql"}
	case "game":
		return nil
	default:
		return nil
	}

	for _, service := range services {
		if !commandExists("systemctl") {
			continue
		}
		if err := runCommandWithOutput("systemctl", []string{"enable", "--now", service}, output); err != nil {
			appendOutput(output, "service_failed="+service)
		} else {
			appendOutput(output, "service_enabled="+service)
		}
	}

	return nil
}

func commandExists(name string) bool {
	_, err := exec.LookPath(name)
	return err == nil
}

func runCommandWithOutput(name string, args []string, output *strings.Builder) error {
	cmd := exec.Command(name, args...)
	if name == "apt-get" {
		cmd.Env = append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
	}
	bytes, err := cmd.CombinedOutput()
	appendOutput(output, fmt.Sprintf("cmd=%s %s", name, strings.Join(args, " ")))
	if len(bytes) > 0 {
		appendOutput(output, string(bytes))
	}
	if err != nil {
		return fmt.Errorf("command %s failed: %w", name, err)
	}
	return nil
}

func appendOutput(output *strings.Builder, message string) {
	const maxOutput = 4000
	if output.Len() >= maxOutput {
		return
	}
	if output.Len()+len(message)+1 > maxOutput {
		message = message[:maxOutput-output.Len()-1]
	}
	output.WriteString(message)
	output.WriteString("\n")
}
