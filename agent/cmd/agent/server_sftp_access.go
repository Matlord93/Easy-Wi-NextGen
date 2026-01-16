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

const sftpAccessConfigDir = "/etc/ssh/sshd_config.d"

func handleInstanceSftpAccessEnable(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "sftp access is not supported on windows"},
			Completed: time.Now().UTC(),
		}, nil
	}

	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := payloadValue(job.Payload, "password", "sftp_password")
	instanceRoot := payloadValue(job.Payload, "instance_root", "instance_dir", "chroot_dir")
	authorizedKeys := payloadValue(job.Payload, "authorized_keys", "keys", "sftp_keys")

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "password", value: password},
		{key: "instance_root", value: instanceRoot},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := validateSftpUsername(username); err != nil {
		return failureResult(job.ID, err)
	}

	instanceRootPath, err := normalizeAbsolutePath(instanceRoot)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := ensureGroup(username); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureSftpAccessUser(username); err != nil {
		return failureResult(job.ID, err)
	}

	instanceUser := resolveInstanceOwner(job.Payload)
	dataDir, err := ensureSftpAccessDataDir(instanceRootPath, username, instanceUser)
	if err != nil {
		return failureResult(job.ID, err)
	}

	authorizedKeysPath, err := ensureSftpAuthorizedKeys(username, dataDir, authorizedKeys)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := setUserPassword(username, password); err != nil {
		return failureResult(job.ID, err)
	}

	configPath, err := writeSftpMatchConfig(username, instanceRootPath, authorizedKeysPath)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadSshd(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":        username,
			"instance_root":   instanceRootPath,
			"data_dir":        dataDir,
			"config_path":     configPath,
			"authorized_keys": fmt.Sprintf("%t", authorizedKeys != ""),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceSftpAccessResetPassword(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "sftp access is not supported on windows"},
			Completed: time.Now().UTC(),
		}, nil
	}

	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := payloadValue(job.Payload, "password", "sftp_password")
	instanceRoot := payloadValue(job.Payload, "instance_root", "instance_dir", "chroot_dir")

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "password", value: password},
		{key: "instance_root", value: instanceRoot},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := validateSftpUsername(username); err != nil {
		return failureResult(job.ID, err)
	}

	instanceRootPath, err := normalizeAbsolutePath(instanceRoot)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := ensureGroup(username); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureSftpAccessUser(username); err != nil {
		return failureResult(job.ID, err)
	}

	if err := ensureSftpChroot(instanceRootPath); err != nil {
		return failureResult(job.ID, err)
	}

	if err := setUserPassword(username, password); err != nil {
		return failureResult(job.ID, err)
	}

	if err := reloadSshd(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":      username,
			"instance_root": instanceRootPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceSftpAccessKeys(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "sftp access is not supported on windows"},
			Completed: time.Now().UTC(),
		}, nil
	}

	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	instanceRoot := payloadValue(job.Payload, "instance_root", "instance_dir", "chroot_dir")
	authorizedKeys := payloadValue(job.Payload, "authorized_keys", "keys", "sftp_keys")

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "instance_root", value: instanceRoot},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := validateSftpUsername(username); err != nil {
		return failureResult(job.ID, err)
	}

	instanceRootPath, err := normalizeAbsolutePath(instanceRoot)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := ensureGroup(username); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureSftpAccessUser(username); err != nil {
		return failureResult(job.ID, err)
	}

	instanceUser := resolveInstanceOwner(job.Payload)
	dataDir, err := ensureSftpAccessDataDir(instanceRootPath, username, instanceUser)
	if err != nil {
		return failureResult(job.ID, err)
	}

	authorizedKeysPath, err := ensureSftpAuthorizedKeys(username, dataDir, authorizedKeys)
	if err != nil {
		return failureResult(job.ID, err)
	}

	configPath, err := writeSftpMatchConfig(username, instanceRootPath, authorizedKeysPath)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadSshd(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":        username,
			"instance_root":   instanceRootPath,
			"data_dir":        dataDir,
			"config_path":     configPath,
			"authorized_keys": fmt.Sprintf("%t", authorizedKeys != ""),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceSftpAccessDisable(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "sftp access is not supported on windows"},
			Completed: time.Now().UTC(),
		}, nil
	}

	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	instanceRoot := payloadValue(job.Payload, "instance_root", "instance_dir", "chroot_dir")

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "instance_root", value: instanceRoot},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := validateSftpUsername(username); err != nil {
		return failureResult(job.ID, err)
	}

	instanceRootPath, err := normalizeAbsolutePath(instanceRoot)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if userExists(username) {
		if err := runCommand("usermod", "--lock", "--shell", defaultSftpShell, username); err != nil {
			return failureResult(job.ID, err)
		}
	}

	configPath := sftpAccessConfigPath(username)
	if err := os.Remove(configPath); err != nil && !os.IsNotExist(err) {
		return failureResult(job.ID, fmt.Errorf("remove sshd config %s: %w", configPath, err))
	}

	if err := reloadSshd(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":      username,
			"instance_root": instanceRootPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureSftpAccessUser(username string) error {
	if userExists(username) {
		return runCommand("usermod", "--home", "/data", "--shell", defaultSftpShell, "--gid", username, username)
	}

	return runCommand("useradd", "--system", "--home-dir", "/data", "--shell", defaultSftpShell, "--gid", username, "--no-create-home", username)
}

func resolveInstanceOwner(payload map[string]any) string {
	instanceID := payloadValue(payload, "instance_id")
	customerID := payloadValue(payload, "customer_id")
	if instanceID == "" || customerID == "" {
		return ""
	}
	return buildInstanceUsername(customerID, instanceID)
}

func ensureSftpAccessDataDir(instanceRoot, sftpUser, instanceUser string) (string, error) {
	if err := ensureSftpChroot(instanceRoot); err != nil {
		return "", err
	}

	dataDir := filepath.Join(instanceRoot, "data")
	if err := ensureInstanceDir(dataDir); err != nil {
		return "", err
	}

	if instanceUser != "" {
		uid, gid, err := lookupIDs(instanceUser, instanceUser)
		if err != nil {
			return "", err
		}
		if err := os.Chown(dataDir, uid, gid); err != nil {
			return "", fmt.Errorf("chown %s: %w", dataDir, err)
		}
		if err := os.Chmod(dataDir, 0o770); err != nil {
			return "", fmt.Errorf("chmod %s: %w", dataDir, err)
		}
		if err := runCommand("usermod", "-aG", instanceUser, sftpUser); err != nil {
			return "", err
		}
		return dataDir, nil
	}

	uid, gid, err := lookupIDs(sftpUser, sftpUser)
	if err != nil {
		return "", err
	}
	if err := os.Chown(dataDir, uid, gid); err != nil {
		return "", fmt.Errorf("chown %s: %w", dataDir, err)
	}
	if err := os.Chmod(dataDir, instanceDirMode); err != nil {
		return "", fmt.Errorf("chmod %s: %w", dataDir, err)
	}

	return dataDir, nil
}

func ensureSftpAuthorizedKeys(username, dataDir, keys string) (string, error) {
	sshDir := filepath.Join(dataDir, ".ssh")
	if err := os.MkdirAll(sshDir, 0o700); err != nil {
		return "", fmt.Errorf("create %s: %w", sshDir, err)
	}

	uid, gid, err := lookupIDs(username, username)
	if err != nil {
		return "", err
	}
	if err := os.Chown(sshDir, uid, gid); err != nil {
		return "", fmt.Errorf("chown %s: %w", sshDir, err)
	}
	if err := os.Chmod(sshDir, 0o700); err != nil {
		return "", fmt.Errorf("chmod %s: %w", sshDir, err)
	}

	authorizedKeysPath := filepath.Join(sshDir, "authorized_keys")
	if err := os.WriteFile(authorizedKeysPath, []byte(keys), 0o600); err != nil {
		return "", fmt.Errorf("write %s: %w", authorizedKeysPath, err)
	}
	if err := os.Chown(authorizedKeysPath, uid, gid); err != nil {
		return "", fmt.Errorf("chown %s: %w", authorizedKeysPath, err)
	}
	if err := os.Chmod(authorizedKeysPath, 0o600); err != nil {
		return "", fmt.Errorf("chmod %s: %w", authorizedKeysPath, err)
	}

	return authorizedKeysPath, nil
}

func writeSftpMatchConfig(username, instanceRoot, authorizedKeysPath string) (string, error) {
	if err := os.MkdirAll(sftpAccessConfigDir, 0o755); err != nil {
		return "", fmt.Errorf("create %s: %w", sftpAccessConfigDir, err)
	}

	configPath := sftpAccessConfigPath(username)
	content := fmt.Sprintf("Match User %s\n  ChrootDirectory %s\n  ForceCommand internal-sftp\n  AllowTcpForwarding no\n  X11Forwarding no\n  PasswordAuthentication yes\n  PubkeyAuthentication yes\n  AuthorizedKeysFile %s\n", username, instanceRoot, authorizedKeysPath)

	if err := os.WriteFile(configPath, []byte(content), 0o640); err != nil {
		return "", fmt.Errorf("write sshd config %s: %w", configPath, err)
	}

	return configPath, nil
}

func sftpAccessConfigPath(username string) string {
	return filepath.Join(sftpAccessConfigDir, fmt.Sprintf("80-easywi-sftp-%s.conf", username))
}

func reloadSshd() error {
	if commandExists("systemctl") {
		if err := runCommand("systemctl", "reload", "sshd"); err == nil {
			return nil
		}
		if err := runCommand("systemctl", "reload", "ssh"); err == nil {
			return nil
		}
	}

	return fmt.Errorf("unable to reload sshd")
}

func normalizeAbsolutePath(path string) (string, error) {
	cleaned := filepath.Clean(path)
	if !filepath.IsAbs(cleaned) {
		return "", fmt.Errorf("path must be absolute")
	}
	return cleaned, nil
}

func validateSftpUsername(username string) error {
	if strings.TrimSpace(username) == "" {
		return fmt.Errorf("username cannot be empty")
	}
	if strings.ContainsAny(username, " \t\n") {
		return fmt.Errorf("username contains invalid whitespace")
	}
	return nil
}
