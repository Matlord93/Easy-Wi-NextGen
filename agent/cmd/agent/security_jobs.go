package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleSecurityEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return handleSecurityEnsureBaseWindows(job)
	}
	if runtime.GOOS != "linux" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "security ensure is only supported on linux and windows agents"},
			Completed: time.Now().UTC(),
		}, nil
	}

	family, err := detectOSFamily()
	if err != nil {
		return failureResult(job.ID, err)
	}

	var output strings.Builder
	appendOutput(&output, fmt.Sprintf("detected_os_family=%s", family))

	packages := securityPackages(family)
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

	if err := ensureSecurityConfigLinux(&output); err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": output.String()},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":            "security base ensured",
			"details":            output.String(),
			"security_supported": "true",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleSecurityEnsureBaseWindows(job jobs.Job) (jobs.Result, func() error) {
	var output strings.Builder
	appendOutput(&output, "detected_os_family=windows")

	if err := ensureSecurityConfigWindows(&output); err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": output.String()},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":            "security base ensured",
			"details":            output.String(),
			"security_supported": "true",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func securityPackages(family string) []string {
	switch family {
	case "debian":
		return []string{"openssh-server", "ufw", "fail2ban"}
	case "rhel":
		return []string{"openssh-server", "firewalld", "fail2ban"}
	default:
		return nil
	}
}

func ensureSecurityConfigLinux(output *strings.Builder) error {
	sshdConfig := "/etc/ssh/sshd_config"
	if _, err := os.Stat(sshdConfig); err == nil {
		if err := os.MkdirAll("/etc/ssh/sshd_config.d", 0o755); err != nil {
			return fmt.Errorf("create sshd_config.d: %w", err)
		}
		configPath := "/etc/ssh/sshd_config.d/99-easywi-security.conf"
		configContent := `Port 22
Port 2222
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
Subsystem sftp internal-sftp

Match LocalPort 22
  PasswordAuthentication no
  KbdInteractiveAuthentication no
  PubkeyAuthentication yes

Match LocalPort 2222
  ChrootDirectory /var/lib/easywi/sftp/%u
  ForceCommand internal-sftp
  AllowTcpForwarding no
  X11Forwarding no
  PasswordAuthentication yes
  PubkeyAuthentication yes
`
		if err := os.WriteFile(configPath, []byte(configContent), 0o640); err != nil {
			return fmt.Errorf("write sshd config: %w", err)
		}
		appendOutput(output, "sshd_config_written="+configPath)
	}

	if err := ensureGroup("easywi-sftp"); err != nil {
		return err
	}
	sftpBase := "/var/lib/easywi/sftp"
	if err := os.MkdirAll(sftpBase, 0o755); err != nil {
		return fmt.Errorf("create sftp base: %w", err)
	}
	if err := os.Chown(sftpBase, 0, 0); err != nil {
		return fmt.Errorf("chown sftp base: %w", err)
	}
	appendOutput(output, "sftp_base="+sftpBase)

	if commandExists("systemctl") {
		if err := runCommandWithOutput("systemctl", []string{"restart", "sshd"}, output); err != nil {
			if err := runCommandWithOutput("systemctl", []string{"restart", "ssh"}, output); err != nil {
				return err
			}
		}
	}

	if commandExists("ufw") {
		if err := runCommandWithOutput("ufw", []string{"default", "deny", "incoming"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("ufw", []string{"allow", "22/tcp"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("ufw", []string{"allow", "2222/tcp"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("ufw", []string{"--force", "enable"}, output); err != nil {
			return err
		}
	}

	if commandExists("firewall-cmd") {
		if err := runCommandWithOutput("systemctl", []string{"enable", "--now", "firewalld"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("firewall-cmd", []string{"--permanent", "--zone=public", "--set-target=DROP"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("firewall-cmd", []string{"--permanent", "--add-service=ssh"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("firewall-cmd", []string{"--permanent", "--add-port=2222/tcp"}, output); err != nil {
			return err
		}
		if err := runCommandWithOutput("firewall-cmd", []string{"--reload"}, output); err != nil {
			return err
		}
	}

	return nil
}

func ensureSecurityConfigWindows(output *strings.Builder) error {
	shell := windowsPowerShellBinary()
	if shell == "" {
		return fmt.Errorf("powershell is required to configure windows security baseline")
	}

	script := strings.Join([]string{
		`Set-NetFirewallProfile -Profile Domain,Public,Private -Enabled True -DefaultInboundAction Block -DefaultOutboundAction Allow`,
		`$existing = Get-NetFirewallRule -DisplayName 'Easy-Wi SSH' -ErrorAction SilentlyContinue`,
		`if (-not $existing) { New-NetFirewallRule -DisplayName 'Easy-Wi SSH' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 22,2222 }`,
	}, "; ")
	if err := runCommandWithOutput(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, output); err != nil {
		return err
	}

	baseDir := windowsEasyWiBaseDir()
	sftpBase := filepath.Join(baseDir, "sftp")
	if err := os.MkdirAll(sftpBase, 0o750); err != nil {
		return fmt.Errorf("create windows sftp base: %w", err)
	}
	appendOutput(output, "sftp_base="+sftpBase)

	return nil
}
