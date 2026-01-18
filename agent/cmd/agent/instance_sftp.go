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

const (
	defaultSftpGroup   = "easywi-sftp"
	defaultSftpBaseDir = "/var/lib/easywi/sftp"
	defaultSftpShell   = "/usr/sbin/nologin"
)

func handleInstanceSftpCredentialsReset(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return handleInstanceSftpCredentialsResetWindows(job)
	}

	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := payloadValue(job.Payload, "password", "sftp_password")
	group := payloadValue(job.Payload, "sftp_group", "group")
	shell := payloadValue(job.Payload, "shell", "sftp_shell")
	sftpBaseDir := payloadValue(job.Payload, "sftp_base_dir", "sftp_root")
	if group == "" {
		group = os.Getenv("EASYWI_SFTP_GROUP")
	}
	if group == "" {
		group = defaultSftpGroup
	}
	if shell == "" {
		shell = defaultSftpShell
	}
	if sftpBaseDir == "" {
		sftpBaseDir = os.Getenv("EASYWI_SFTP_BASE_DIR")
	}
	if sftpBaseDir == "" {
		sftpBaseDir = defaultSftpBaseDir
	}

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "password", value: password},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := ensureGroup(group); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureBaseDir(sftpBaseDir); err != nil {
		return failureResult(job.ID, err)
	}

	chrootPath := filepath.Join(sftpBaseDir, username)
	if err := ensureSftpChroot(chrootPath); err != nil {
		return failureResult(job.ID, err)
	}
	homePath := filepath.Join(chrootPath, "data")
	if err := ensureInstanceDir(homePath); err != nil {
		return failureResult(job.ID, err)
	}

	if userExists(username) {
		if err := runCommand("usermod", "--home", "/data", "--shell", shell, "--gid", group, username); err != nil {
			return failureResult(job.ID, err)
		}
	} else {
		if err := runCommand("useradd", "--system", "--home-dir", "/data", "--shell", shell, "--gid", group, "--no-create-home", username); err != nil {
			return failureResult(job.ID, err)
		}
	}

	uid, gid, err := lookupIDs(username, group)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.Chown(homePath, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", homePath, err))
	}
	if err := os.Chmod(homePath, instanceDirMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("chmod %s: %w", homePath, err))
	}

	if err := setUserPassword(username, password); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":    username,
			"chroot_path": chrootPath,
			"home_path":   homePath,
			"group":       group,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceSftpCredentialsResetWindows(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := payloadValue(job.Payload, "password", "sftp_password")
	group := payloadValue(job.Payload, "sftp_group", "group")
	sftpBaseDir := payloadValue(job.Payload, "sftp_base_dir", "sftp_root")
	if group == "" {
		group = os.Getenv("EASYWI_SFTP_GROUP")
	}
	if group == "" {
		group = defaultSftpGroup
	}
	if sftpBaseDir == "" {
		sftpBaseDir = os.Getenv("EASYWI_SFTP_BASE_DIR")
	}
	if sftpBaseDir == "" {
		sftpBaseDir = filepath.Join(windowsEasyWiBaseDir(), "sftp")
	}

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "password", value: password},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	shell := windowsPowerShellBinary()
	if shell == "" {
		return failureResult(job.ID, fmt.Errorf("powershell is required to create sftp users on windows"))
	}

	escapedUser := escapePowerShellSingleQuotes(username)
	escapedPass := escapePowerShellSingleQuotes(password)
	escapedGroup := escapePowerShellSingleQuotes(group)
	escapedBase := escapePowerShellSingleQuotes(sftpBaseDir)
	script := strings.Join([]string{
		`$ErrorActionPreference = 'Stop'`,
		fmt.Sprintf(`$username = '%s'`, escapedUser),
		fmt.Sprintf(`$password = '%s'`, escapedPass),
		fmt.Sprintf(`$group = '%s'`, escapedGroup),
		fmt.Sprintf(`$base = '%s'`, escapedBase),
		`if (-not (Get-Command New-LocalUser -ErrorAction SilentlyContinue)) { throw 'New-LocalUser cmdlet not available' }`,
		`if (-not (Get-LocalGroup -Name $group -ErrorAction SilentlyContinue)) { New-LocalGroup -Name $group | Out-Null }`,
		`$secure = ConvertTo-SecureString $password -AsPlainText -Force`,
		`if (-not (Get-LocalUser -Name $username -ErrorAction SilentlyContinue)) {`,
		`  New-LocalUser -Name $username -Password $secure -PasswordNeverExpires:$true -UserMayNotChangePassword:$true | Out-Null`,
		`} else {`,
		`  Set-LocalUser -Name $username -Password $secure | Out-Null`,
		`}`,
		`Add-LocalGroupMember -Group $group -Member $username -ErrorAction SilentlyContinue`,
		`$chroot = Join-Path $base $username`,
		`$home = Join-Path $chroot 'data'`,
		`New-Item -ItemType Directory -Force -Path $home | Out-Null`,
		`icacls $chroot /inheritance:r | Out-Null`,
		`icacls $chroot /grant:r "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F" | Out-Null`,
		`icacls $home /grant:r "$username:(OI)(CI)M" | Out-Null`,
	}, "; ")

	var output strings.Builder
	if err := runCommandWithOutput(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, &output); err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": output.String()},
			Completed: time.Now().UTC(),
		}, nil
	}

	chrootPath := filepath.Join(sftpBaseDir, username)
	homePath := filepath.Join(chrootPath, "data")

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":    username,
			"chroot_path": chrootPath,
			"home_path":   homePath,
			"group":       group,
			"details":     output.String(),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureSftpChroot(path string) error {
	if err := os.MkdirAll(path, baseDirMode); err != nil {
		return fmt.Errorf("create sftp chroot %s: %w", path, err)
	}
	if err := os.Chown(path, 0, 0); err != nil {
		return fmt.Errorf("chown sftp chroot %s: %w", path, err)
	}
	if err := os.Chmod(path, baseDirMode); err != nil {
		return fmt.Errorf("chmod sftp chroot %s: %w", path, err)
	}
	return nil
}

func userExists(username string) bool {
	return exec.Command("id", "-u", username).Run() == nil
}

func setUserPassword(username, password string) error {
	cmd := exec.Command("chpasswd")
	cmd.Stdin = strings.NewReader(fmt.Sprintf("%s:%s", username, password))
	output, err := StreamCommand(cmd, "", nil)
	if err != nil {
		return fmt.Errorf("set password for %s failed: %w (%s)", username, err, strings.TrimSpace(output))
	}
	return nil
}

func escapePowerShellSingleQuotes(value string) string {
	return strings.ReplaceAll(value, "'", "''")
}
