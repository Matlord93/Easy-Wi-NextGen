package main

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"log"
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
	password := strings.TrimSpace(payloadValue(job.Payload, "password", "sftp_password"))
	preferredBackend := strings.ToUpper(strings.TrimSpace(payloadValue(job.Payload, "preferred_backend", "backend")))
	if password == "" {
		password = generateSftpPassword()
	}
	requestID := payloadValue(job.Payload, "request_id")
	group := payloadValue(job.Payload, "sftp_group", "group")
	shell := payloadValue(job.Payload, "shell", "sftp_shell")
	sftpBaseDir := payloadValue(job.Payload, "sftp_base_dir", "sftp_root")
	instanceDir, instanceErr := resolveInstanceDir(job.Payload)
	rootPath := strings.TrimSpace(payloadValue(job.Payload, "root_path", "instance_root", "install_path"))
	instanceUser := ""
	if instanceErr == nil {
		instanceUser = buildInstanceUsername(payloadValue(job.Payload, "customer_id"), payloadValue(job.Payload, "instance_id"))
	}
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

	log.Printf("sftp credentials reset start job_id=%s request_id=%s instance_id=%s customer_id=%s username=%s base_dir=%s", job.ID, requestID, payloadValue(job.Payload, "instance_id"), payloadValue(job.Payload, "customer_id"), username, sftpBaseDir)

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:  job.ID,
			Status: "failed",
			Output: map[string]string{
				"message":    "missing required values: " + strings.Join(missing, ", "),
				"error_code": "INVALID_INPUT",
				"request_id": requestID,
			},
			Completed: time.Now().UTC(),
		}, nil
	}

	if preferredBackend != "" && preferredBackend != "PROFTPD_SFTP" && preferredBackend != "FTP_ONLY" {
		return failureResultWithCode(job.ID, "BACKEND_UNSUPPORTED", fmt.Errorf("unsupported backend %s on linux", preferredBackend), requestID, "")
	}

	if err := ensureGroup(group); err != nil {
		return failureResultWithCode(job.ID, "PERMISSION_DENIED", err, requestID, "")
	}

	useInstanceDir := instanceErr == nil
	var chrootPath string
	var homePath string
	if useInstanceDir {
		homePath = instanceDir
		if _, err := os.Stat(homePath); err != nil {
			return failureResultWithCode(job.ID, "sftp_instance_dir_missing", fmt.Errorf("instance directory unavailable: %w", err), requestID, "")
		}
	} else {
		if err := ensureBaseDir(sftpBaseDir); err != nil {
			return failureResultWithCode(job.ID, "sftp_chroot_failed", err, requestID, "")
		}
		chrootPath = filepath.Join(sftpBaseDir, username)
		if err := ensureSftpChroot(chrootPath); err != nil {
			return failureResultWithCode(job.ID, "sftp_chroot_failed", err, requestID, "")
		}
		homePath = filepath.Join(chrootPath, "data")
		if err := ensureInstanceDir(homePath); err != nil {
			return failureResultWithCode(job.ID, "sftp_chroot_failed", err, requestID, "")
		}
	}

	if rootPath != "" {
		homePath = rootPath
	}
	if !filepath.IsAbs(homePath) || strings.Contains(homePath, "..") {
		return failureResultWithCode(job.ID, "ROOT_INVALID", fmt.Errorf("invalid root path: %s", homePath), requestID, "")
	}

	if userExists(username) {
		if output, err := runCommandLogged("usermod", "--home", homePath, "--shell", shell, "--gid", group, username); err != nil {
			return failureResultWithCode(job.ID, "sftp_user_create_failed", err, requestID, output)
		}
	} else {
		if output, err := runCommandLogged("useradd", "--system", "--home-dir", homePath, "--shell", shell, "--gid", group, "--no-create-home", username); err != nil {
			return failureResultWithCode(job.ID, "sftp_user_create_failed", err, requestID, output)
		}
	}

	if useInstanceDir && instanceUser != "" {
		if err := ensureGroup(instanceUser); err != nil {
			return failureResultWithCode(job.ID, "sftp_group_failed", err, requestID, "")
		}
		if output, err := runCommandLogged("usermod", "-aG", instanceUser, username); err != nil {
			return failureResultWithCode(job.ID, "sftp_user_create_failed", err, requestID, output)
		}
	}

	uid, gid, err := lookupIDs(username, group)
	if err != nil {
		return failureResultWithCode(job.ID, "sftp_permissions_failed", err, requestID, "")
	}
	if !useInstanceDir {
		if err := os.Chown(homePath, uid, gid); err != nil {
			return failureResultWithCode(job.ID, "sftp_permissions_failed", fmt.Errorf("chown %s: %w", homePath, err), requestID, "")
		}
		if err := os.Chmod(homePath, instanceDirMode); err != nil {
			return failureResultWithCode(job.ID, "sftp_permissions_failed", fmt.Errorf("chmod %s: %w", homePath, err), requestID, "")
		}
	}

	if err := setUserPassword(username, password); err != nil {
		return failureResultWithCode(job.ID, "sftp_password_failed", err, requestID, "")
	}

	log.Printf("sftp credentials reset complete job_id=%s request_id=%s username=%s chroot_path=%s home_path=%s group=%s", job.ID, requestID, username, chrootPath, homePath, group)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":    username,
			"backend":     "PROFTPD_SFTP",
			"host":        payloadValue(job.Payload, "host", "node_ip", "bind_ip"),
			"port":        "2222",
			"root_path":   homePath,
			"chroot_path": chrootPath,
			"home_path":   homePath,
			"group":       group,
			"request_id":  requestID,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceSftpCredentialsResetWindows(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := strings.TrimSpace(payloadValue(job.Payload, "password", "sftp_password"))
	if password == "" {
		password = generateSftpPassword()
	}
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
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", "), "error_code": "INVALID_INPUT"},
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
			"backend":     "WINDOWS_OPENSSH_SFTP",
			"port":        "2222",
			"root_path":   homePath,
			"chroot_path": chrootPath,
			"home_path":   homePath,
			"group":       group,
			"details":     output.String(),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func generateSftpPassword() string {
	b := make([]byte, 18)
	if _, err := rand.Read(b); err != nil {
		return fmt.Sprintf("%d%s", time.Now().Unix(), "Ab!")
	}
	return hex.EncodeToString(b)
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
		log.Printf("sftp set password failed username=%s err=%v output=%s", username, err, strings.TrimSpace(output))
		return fmt.Errorf("set password for %s failed: %w (%s)", username, err, strings.TrimSpace(output))
	}
	if trimmed := strings.TrimSpace(output); trimmed != "" {
		log.Printf("sftp set password output username=%s output=%s", username, trimmed)
	}
	return nil
}

func handleWebspaceSftpCredentialsReset(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := strings.TrimSpace(payloadValue(job.Payload, "password", "sftp_password"))
	rootPath := strings.TrimSpace(payloadValue(job.Payload, "root_path", "webspace_path", "web_root"))
	group := payloadValue(job.Payload, "sftp_group", "group")
	shell := payloadValue(job.Payload, "shell")

	if password == "" {
		password = generateSftpPassword()
	}
	if group == "" {
		group = os.Getenv("EASYWI_SFTP_GROUP")
	}
	if group == "" {
		group = defaultSftpGroup
	}
	if shell == "" {
		shell = defaultSftpShell
	}

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "root_path", value: rootPath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", "), "error_code": "INVALID_INPUT"},
			Completed: time.Now().UTC(),
		}, nil
	}

	if !filepath.IsAbs(rootPath) || strings.Contains(rootPath, "..") {
		return failureResultWithCode(job.ID, "ROOT_INVALID", fmt.Errorf("invalid root path: %s", rootPath), "", "")
	}

	if err := ensureGroup(group); err != nil {
		return failureResultWithCode(job.ID, "PERMISSION_DENIED", err, "", "")
	}

	if userExists(username) {
		if output, err := runCommandLogged("usermod", "--home", rootPath, "--shell", shell, "--gid", group, username); err != nil {
			return failureResultWithCode(job.ID, "sftp_user_create_failed", err, "", output)
		}
	} else {
		if output, err := runCommandLogged("useradd", "--system", "--home-dir", rootPath, "--shell", shell, "--gid", group, "--no-create-home", username); err != nil {
			return failureResultWithCode(job.ID, "sftp_user_create_failed", err, "", output)
		}
	}

	if err := setUserPassword(username, password); err != nil {
		return failureResultWithCode(job.ID, "sftp_password_failed", err, "", "")
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":  username,
			"root_path": rootPath,
			"group":     group,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func escapePowerShellSingleQuotes(value string) string {
	return strings.ReplaceAll(value, "'", "''")
}

func runCommandLogged(name string, args ...string) (string, error) {
	output, err := runCommandOutput(name, args...)
	trimmed := strings.TrimSpace(output)
	if err != nil {
		exitCode := -1
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			exitCode = exitErr.ExitCode()
		}
		log.Printf("sftp command failed name=%s args=%v exit_code=%d output=%s err=%v", name, args, exitCode, trimmed, err)
		return trimmed, err
	}
	if trimmed != "" {
		log.Printf("sftp command output name=%s args=%v output=%s", name, args, trimmed)
	} else {
		log.Printf("sftp command ok name=%s args=%v", name, args)
	}
	return trimmed, nil
}

func failureResultWithCode(jobID, code string, err error, requestID string, output string) (jobs.Result, func() error) {
	result := jobs.Result{
		JobID:  jobID,
		Status: "failed",
		Output: map[string]string{
			"message":    err.Error(),
			"error_code": code,
			"request_id": requestID,
		},
		Completed: time.Now().UTC(),
	}

	if strings.TrimSpace(output) != "" {
		result.Output["output"] = strings.TrimSpace(output)
	}

	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		result.Output["exit_code"] = fmt.Sprintf("%d", exitErr.ExitCode())
	}

	return result, nil
}
