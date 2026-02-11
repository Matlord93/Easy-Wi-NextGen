package main

import (
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const defaultInstanceBackupBaseDir = "/var/lib/easywi/backups/instances"

func handleInstanceBackupCreate(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:  job.ID,
			Status: "failed",
			Output: map[string]string{
				"error":      "instance backups are not supported on windows agents",
				"error_code": "backup_unsupported_windows",
			},
			Completed: time.Now().UTC(),
		}, nil
	}

	instanceID := payloadValue(job.Payload, "instance_id")
	if strings.TrimSpace(instanceID) == "" {
		return failureResult(job.ID, fmt.Errorf("instance_id is required"))
	}

	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if _, err := os.Stat(instanceDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("instance directory missing: %w", err))
	}

	backupTargetType := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "backup_target_type")))
	backupRoot := backupRootDir()
	if backupTargetType == "local" {
		if config := payloadNestedValue(job.Payload, "backup_target_config", "base_path"); config != "" {
			backupRoot = config
		}
	}
	targetDir := filepath.Join(backupRoot, sanitizeIdentifier(instanceID))
	if err := os.MkdirAll(targetDir, 0o750); err != nil {
		return failureResult(job.ID, fmt.Errorf("create backup target dir: %w", err))
	}

	backupPath := filepath.Join(targetDir, fmt.Sprintf("instance-%s-%d.tar.gz", sanitizeIdentifier(instanceID), time.Now().UTC().Unix()))
	cmd := exec.Command("tar", "-czf", backupPath, "-C", instanceDir, ".")
	if _, err := StreamCommand(cmd, job.ID, nil); err != nil {
		return failureResult(job.ID, fmt.Errorf("create backup archive: %w", err))
	}

	checksum, sizeBytes, err := computeFileChecksumAndSize(backupPath)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("compute backup metadata: %w", err))
	}

	if backupTargetType == "webdav" || backupTargetType == "nextcloud" {
		remotePath, err := uploadBackupToWebdav(job.Payload, backupPath)
		if err != nil {
			return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"error": err.Error(), "error_code": "backup_target_connection_failed"}, Completed: time.Now().UTC()}, nil
		}
		backupPath = remotePath
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"backup_id":   payloadValue(job.Payload, "backup_id"),
			"backup_path": backupPath,
			"size_bytes":  strconv.FormatInt(sizeBytes, 10),
			"sha256":      checksum,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceBackupRestore(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return jobs.Result{
			JobID:  job.ID,
			Status: "failed",
			Output: map[string]string{
				"error":      "instance backup restore is not supported on windows agents",
				"error_code": "backup_restore_unsupported_windows",
			},
			Completed: time.Now().UTC(),
		}, nil
	}

	backupPath := payloadValue(job.Payload, "backup_path")
	if strings.TrimSpace(backupPath) == "" {
		return failureResult(job.ID, fmt.Errorf("backup_path is required"))
	}
	backupTargetType := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "backup_target_type")))
	if backupTargetType == "webdav" || backupTargetType == "nextcloud" {
		tmpPath, err := downloadBackupFromWebdav(job.Payload, backupPath)
		if err != nil {
			return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"error": err.Error(), "error_code": "backup_target_connection_failed"}, Completed: time.Now().UTC()}, nil
		}
		backupPath = tmpPath
	}
	if _, err := os.Stat(backupPath); err != nil {
		return failureResult(job.ID, fmt.Errorf("backup archive missing: %w", err))
	}

	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.MkdirAll(instanceDir, 0o750); err != nil {
		return failureResult(job.ID, fmt.Errorf("create instance dir: %w", err))
	}

	if parsePayloadBool(payloadValue(job.Payload, "pre_backup"), false) {
		preBackupPath := filepath.Join(filepath.Dir(backupPath), fmt.Sprintf("pre-restore-%d.tar.gz", time.Now().UTC().Unix()))
		cmd := exec.Command("tar", "-czf", preBackupPath, "-C", instanceDir, ".")
		if _, err := StreamCommand(cmd, job.ID, nil); err != nil {
			return failureResult(job.ID, fmt.Errorf("create pre-restore backup: %w", err))
		}
	}

	cmd := exec.Command("tar", "-xzf", backupPath, "-C", instanceDir)
	if _, err := StreamCommand(cmd, job.ID, nil); err != nil {
		return failureResult(job.ID, fmt.Errorf("restore backup archive: %w", err))
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"backup_id":     payloadValue(job.Payload, "backup_id"),
			"restored_from": backupPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func computeFileChecksumAndSize(path string) (string, int64, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", 0, err
	}
	defer file.Close()

	h := sha256.New()
	size, err := io.Copy(h, file)
	if err != nil {
		return "", 0, err
	}

	return hex.EncodeToString(h.Sum(nil)), size, nil
}

func backupRootDir() string {
	if custom := strings.TrimSpace(os.Getenv("EASYWI_INSTANCE_BACKUP_DIR")); custom != "" {
		return custom
	}
	return defaultInstanceBackupBaseDir
}

func payloadNestedValue(payload map[string]any, objectKey string, key string) string {
	obj, ok := payload[objectKey]
	if !ok {
		return ""
	}
	m, ok := obj.(map[string]any)
	if !ok {
		return ""
	}
	v, ok := m[key]
	if !ok {
		return ""
	}
	return strings.TrimSpace(fmt.Sprintf("%v", v))
}

func webdavClient(verifyTLS bool) *http.Client {
	tr := &http.Transport{}
	if !verifyTLS {
		tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: true} //nolint:gosec
	}
	return &http.Client{Timeout: 120 * time.Second, Transport: tr}
}

func uploadBackupToWebdav(payload map[string]any, localPath string) (string, error) {
	baseURL := strings.TrimRight(payloadNestedValue(payload, "backup_target_config", "url"), "/")
	remoteFolder := "/" + strings.TrimLeft(payloadNestedValue(payload, "backup_target_config", "remote_path"), "/")
	username := payloadNestedValue(payload, "backup_target_config", "username")
	password := payloadNestedValue(payload, "backup_target_secret", "password")
	verifyTLS := strings.ToLower(payloadNestedValue(payload, "backup_target_config", "verify_tls")) != "false"
	if baseURL == "" || username == "" || password == "" {
		return "", fmt.Errorf("webdav target credentials/config missing")
	}

	filename := filepath.Base(localPath)
	u, err := url.Parse(baseURL + remoteFolder + "/" + filename)
	if err != nil {
		return "", err
	}

	var lastErr error
	for attempt := 1; attempt <= 2; attempt++ {
		f, err := os.Open(localPath)
		if err != nil {
			return "", err
		}

		req, err := http.NewRequest(http.MethodPut, u.String(), f)
		if err != nil {
			_ = f.Close()
			return "", err
		}
		req.SetBasicAuth(username, password)

		resp, err := webdavClient(verifyTLS).Do(req)
		_ = f.Close()
		if err != nil {
			lastErr = err
			time.Sleep(500 * time.Millisecond)
			continue
		}

		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			body, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
			_ = resp.Body.Close()
			lastErr = fmt.Errorf("webdav upload failed: %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
			time.Sleep(500 * time.Millisecond)
			continue
		}
		_ = resp.Body.Close()

		return u.String(), nil
	}

	return "", lastErr
}

func downloadBackupFromWebdav(payload map[string]any, remote string) (string, error) {
	username := payloadNestedValue(payload, "backup_target_config", "username")
	password := payloadNestedValue(payload, "backup_target_secret", "password")
	verifyTLS := strings.ToLower(payloadNestedValue(payload, "backup_target_config", "verify_tls")) != "false"
	if remote == "" || username == "" || password == "" {
		return "", fmt.Errorf("webdav restore credentials/config missing")
	}

	var lastErr error
	for attempt := 1; attempt <= 2; attempt++ {
		req, err := http.NewRequest(http.MethodGet, remote, nil)
		if err != nil {
			return "", err
		}
		req.SetBasicAuth(username, password)

		resp, err := webdavClient(verifyTLS).Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(500 * time.Millisecond)
			continue
		}

		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			body, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
			_ = resp.Body.Close()
			lastErr = fmt.Errorf("webdav download failed: %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
			time.Sleep(500 * time.Millisecond)
			continue
		}

		tmp, err := os.CreateTemp("", "easywi-restore-*.tar.gz")
		if err != nil {
			_ = resp.Body.Close()
			return "", err
		}
		if _, err := io.Copy(tmp, resp.Body); err != nil {
			_ = resp.Body.Close()
			_ = tmp.Close()
			return "", err
		}
		_ = resp.Body.Close()
		if err := tmp.Close(); err != nil {
			return "", err
		}

		return tmp.Name(), nil
	}

	return "", lastErr
}
