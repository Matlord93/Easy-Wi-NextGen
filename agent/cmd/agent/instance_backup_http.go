package main

import (
	"encoding/json"
	"fmt"
	"io"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

type instanceBackupDownloadRequest struct {
	BackupPath         string         `json:"backup_path"`
	BackupTargetType   string         `json:"backup_target_type"`
	BackupTargetConfig map[string]any `json:"backup_target_config"`
	BackupTargetSecret map[string]any `json:"backup_target_secret"`
}

func handleInstanceBackupHTTP(w http.ResponseWriter, r *http.Request, instanceID string) bool {
	if !strings.Contains(r.URL.Path, "/backups/") {
		return false
	}

	if !strings.HasSuffix(strings.TrimRight(r.URL.Path, "/"), "/backups/download") {
		writeJSONError(w, http.StatusNotFound, "NOT_FOUND", "not found")
		return true
	}

	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
		return true
	}

	var req instanceBackupDownloadRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSONError(w, http.StatusBadRequest, "INVALID_PAYLOAD", "invalid backup download payload")
		return true
	}

	payload := map[string]any{
		"backup_path":          req.BackupPath,
		"backup_target_type":   req.BackupTargetType,
		"backup_target_config": req.BackupTargetConfig,
		"backup_target_secret": req.BackupTargetSecret,
	}

	backupPath := strings.TrimSpace(req.BackupPath)
	if backupPath == "" {
		writeJSONError(w, http.StatusBadRequest, "INVALID_PAYLOAD", "backup path is required")
		return true
	}

	removeAfterDownload := ""
	backupTargetType := strings.ToLower(strings.TrimSpace(req.BackupTargetType))
	if backupTargetType == "webdav" || backupTargetType == "nextcloud" {
		if err := validateRemoteBackupURL(payload, backupPath); err != nil {
			writeJSONError(w, http.StatusForbidden, "INVALID_BACKUP_PATH", err.Error())
			return true
		}
		tmpPath, err := downloadBackupFromWebdav(payload, backupPath)
		if err != nil {
			writeJSONError(w, http.StatusBadGateway, "BACKUP_DOWNLOAD_FAILED", err.Error())
			return true
		}
		backupPath = tmpPath
		removeAfterDownload = tmpPath
	} else if err := validateLocalBackupDownloadPath(instanceID, backupPath, payload); err != nil {
		writeJSONError(w, http.StatusForbidden, "INVALID_BACKUP_PATH", err.Error())
		return true
	}
	if removeAfterDownload != "" {
		defer func() { _ = os.Remove(removeAfterDownload) }()
	}

	file, err := os.Open(backupPath)
	if err != nil {
		status := http.StatusNotFound
		if !os.IsNotExist(err) {
			status = http.StatusForbidden
		}
		writeJSONError(w, status, "NOT_FOUND", "backup archive is unavailable")
		return true
	}
	defer func() { _ = file.Close() }()

	info, err := file.Stat()
	if err != nil || info.IsDir() {
		writeJSONError(w, http.StatusNotFound, "NOT_FOUND", "backup archive is unavailable")
		return true
	}

	filename := filepath.Base(backupPath)
	contentType := mime.TypeByExtension(filepath.Ext(filename))
	if strings.HasSuffix(strings.ToLower(filename), ".tar.gz") {
		contentType = "application/gzip"
	}
	if contentType == "" {
		contentType = "application/octet-stream"
	}

	w.Header().Set("Content-Type", contentType)
	w.Header().Set("Content-Length", strconv.FormatInt(info.Size(), 10))
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=\"%s\"", strings.ReplaceAll(filename, "\"", "")))
	if _, err := io.Copy(w, file); err != nil {
		return true
	}

	return true
}

func validateLocalBackupDownloadPath(instanceID string, backupPath string, payload map[string]any) error {
	cleanPath, err := filepath.Abs(filepath.Clean(backupPath))
	if err != nil {
		return fmt.Errorf("invalid backup path")
	}

	backupRoot := backupRootDir()
	if strings.EqualFold(strings.TrimSpace(payloadValue(payload, "backup_target_type")), "local") {
		if resolvedRoot, resolveErr := resolveLocalBackupRoot(payload, backupRoot); resolveErr == nil {
			backupRoot = resolvedRoot
		} else {
			return resolveErr
		}
	}

	instanceDirName := sanitizeIdentifier(instanceID)
	allowedRoot, err := filepath.Abs(filepath.Join(backupRoot, instanceDirName))
	if err != nil {
		return fmt.Errorf("invalid backup root")
	}

	if backupPathWithinRoot(cleanPath, allowedRoot) {
		return nil
	}

	// Some existing installations configured the local backup target to the
	// instance directory itself (for example
	// /var/lib/easywi/backups/instances/1) instead of the parent directory that
	// EasyWI appends the instance id to.  Keep downloads compatible with those
	// archives while still requiring the configured directory basename to match
	// the requested instance id.
	configuredRoot, err := filepath.Abs(filepath.Clean(backupRoot))
	if err != nil {
		return fmt.Errorf("invalid backup root")
	}
	if filepath.Base(configuredRoot) == instanceDirName && backupPathWithinRoot(cleanPath, configuredRoot) {
		return nil
	}

	return fmt.Errorf("backup path is outside the instance backup directory")
}

func backupPathWithinRoot(cleanPath string, allowedRoot string) bool {
	rel, err := filepath.Rel(allowedRoot, cleanPath)
	return err == nil && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator)) && !filepath.IsAbs(rel)
}
