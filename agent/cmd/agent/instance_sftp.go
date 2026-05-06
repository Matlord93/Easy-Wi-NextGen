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
	requestID := payloadValue(job.Payload, "request_id")
	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := strings.TrimSpace(payloadValue(job.Payload, "one_time_password", "password", "sftp_password"))
	preferredBackend := strings.ToUpper(strings.TrimSpace(payloadValue(job.Payload, "preferred_backend", "backend")))
	rootPath := strings.TrimSpace(payloadValue(job.Payload, "install_path", "root_path", "instance_root"))

	log.Printf("sftp credentials reset start job_id=%s request_id=%s instance_id=%s customer_id=%s username=%s base_dir=%s", job.ID, requestID, payloadValue(job.Payload, "instance_id"), payloadValue(job.Payload, "customer_id"), username, payloadValue(job.Payload, "base_dir"))

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "one_time_password", value: password},
		{key: "install_path", value: rootPath},
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

	if runtime.GOOS == "windows" {
		return handleInstanceSftpCredentialsResetWindows(job)
	}

	if preferredBackend != "" && preferredBackend != "PROFTPD_SFTP" {
		return failureResultWithCode(job.ID, "BACKEND_UNSUPPORTED", fmt.Errorf("unsupported backend %s on linux", preferredBackend), requestID, "")
	}

	homePath, err := resolveInstanceDir(map[string]any{"install_path": rootPath, "base_dir": payloadValue(job.Payload, "base_dir")})
	if err != nil {
		return failureResultWithCode(job.ID, "ROOT_INVALID", err, requestID, "")
	}
	if !filepath.IsAbs(homePath) || strings.Contains(homePath, "..") {
		return failureResultWithCode(job.ID, "ROOT_INVALID", fmt.Errorf("invalid root path: %s", homePath), requestID, "")
	}
	if st, err := os.Stat(homePath); err != nil || !st.IsDir() {
		if err == nil {
			err = fmt.Errorf("not a directory")
		}
		return failureResultWithCode(job.ID, "sftp_instance_dir_missing", fmt.Errorf("instance directory unavailable: %w", err), requestID, "")
	}

	if err := provisionLinuxProFTPD(username, password, homePath); err != nil {
		return failureResultWithCode(job.ID, mapAccessErr(err), err, requestID, "")
	}
	if err := checkLinuxProFTPDHealth(); err != nil {
		return failureResultWithCode(job.ID, mapAccessErr(err), err, requestID, "")
	}

	log.Printf("sftp credentials reset complete job_id=%s request_id=%s username=%s home_path=%s backend=PROFTPD_SFTP", job.ID, requestID, username, homePath)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":   username,
			"backend":    "PROFTPD_SFTP",
			"host":       payloadValue(job.Payload, "host", "node_ip", "bind_ip"),
			"port":       "2222",
			"root_path":  homePath,
			"home_path":  homePath,
			"request_id": requestID,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceSftpCredentialsResetWindows(job jobs.Job) (jobs.Result, func() error) {
	requestID := payloadValue(job.Payload, "request_id")

	return failureResultWithCode(job.ID, "WINDOWS_SFTP_UNSUPPORTED", fmt.Errorf("SFTP provisioning for Windows gameserver instances is not enabled; configure the embedded EasyWI SFTP service or use a Linux node"), requestID, "")
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
