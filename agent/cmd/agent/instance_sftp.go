package main

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const defaultSftpGroup = "sftp"

func handleInstanceSftpCredentialsReset(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "sftp_username", "user")
	password := payloadValue(job.Payload, "password", "sftp_password")
	group := payloadValue(job.Payload, "sftp_group", "group")
	shell := payloadValue(job.Payload, "shell", "sftp_shell")
	if group == "" {
		group = os.Getenv("EASYWI_SFTP_GROUP")
	}
	if group == "" {
		group = defaultSftpGroup
	}
	if shell == "" {
		shell = "/usr/sbin/nologin"
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

	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := ensureGroup(group); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureInstanceDir(instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

	if userExists(username) {
		if err := runCommand("usermod", "--home", instanceDir, "--shell", shell, "--gid", group, username); err != nil {
			return failureResult(job.ID, err)
		}
	} else {
		if err := runCommand("useradd", "--system", "--home-dir", instanceDir, "--shell", shell, "--gid", group, "--no-create-home", username); err != nil {
			return failureResult(job.ID, err)
		}
	}

	if err := setUserPassword(username, password); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":    username,
			"chroot_path": instanceDir,
			"group":       group,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func userExists(username string) bool {
	return exec.Command("id", "-u", username).Run() == nil
}

func setUserPassword(username, password string) error {
	cmd := exec.Command("chpasswd")
	cmd.Stdin = strings.NewReader(fmt.Sprintf("%s:%s", username, password))
	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("set password for %s failed: %w (%s)", username, err, strings.TrimSpace(string(output)))
	}
	return nil
}
