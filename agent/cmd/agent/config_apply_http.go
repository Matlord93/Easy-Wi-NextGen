package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

const configApplyMaxBytes = 256 * 1024

func handleInstanceConfigApplyHTTP(w http.ResponseWriter, r *http.Request, instanceID string) bool {
	base := "/v1/instances/" + strings.TrimSpace(instanceID) + "/configs/"
	if !strings.HasPrefix(r.URL.Path, base) {
		return false
	}
	if strings.Trim(strings.TrimPrefix(r.URL.Path, base), "/ ") != "apply" {
		return false
	}
	requestID := strings.TrimSpace(r.Header.Get("X-Request-ID"))
	if r.Method != http.MethodPost {
		writeAccessEnvelope(w, http.StatusMethodNotAllowed, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
		return true
	}

	var payload map[string]any
	if err := json.NewDecoder(io.LimitReader(r.Body, configApplyMaxBytes+4096)).Decode(&payload); err != nil {
		writeAccessEnvelope(w, http.StatusBadRequest, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "invalid json", RequestID: requestID})
		return true
	}

	root := strings.TrimSpace(asString(payload["instance_root"]))
	target := strings.TrimSpace(asString(payload["path"]))
	content := asString(payload["content"])
	backup := asBool(payload["backup"])

	if root == "" || target == "" {
		writeAccessEnvelope(w, http.StatusBadRequest, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "instance_root and path are required", RequestID: requestID})
		return true
	}
	if len(content) > configApplyMaxBytes {
		writeAccessEnvelope(w, http.StatusBadRequest, accessEnvelope{OK: false, ErrorCode: "FILE_TOO_LARGE", Message: "content exceeds 256KB", RequestID: requestID})
		return true
	}
	if strings.ContainsRune(content, '\x00') {
		writeAccessEnvelope(w, http.StatusBadRequest, accessEnvelope{OK: false, ErrorCode: "BINARY_NOT_ALLOWED", Message: "binary content not allowed", RequestID: requestID})
		return true
	}

	absRoot, absTarget, err := resolveConfigApplyPath(root, target)
	if err != nil {
		writeAccessEnvelope(w, http.StatusBadRequest, accessEnvelope{OK: false, ErrorCode: mapConfigErr(err), Message: err.Error(), RequestID: requestID})
		return true
	}
	stats, err := writeConfigAtomically(absTarget, []byte(content), backup)
	if err != nil {
		writeAccessEnvelope(w, http.StatusOK, accessEnvelope{OK: false, ErrorCode: mapConfigErr(err), Message: err.Error(), RequestID: requestID})
		return true
	}
	_ = absRoot
	writeAccessEnvelope(w, http.StatusOK, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
		"written":     true,
		"path":        absTarget,
		"bytes":       stats.Bytes,
		"backup_path": stats.BackupPath,
		"checksum":    stats.Checksum,
		"written_at":  time.Now().UTC().Format(time.RFC3339),
	}})
	return true
}

type configWriteStats struct {
	Bytes      int
	BackupPath string
	Checksum   string
}

func resolveConfigApplyPath(root, target string) (string, string, error) {
	absRoot, err := filepath.Abs(filepath.Clean(root))
	if err != nil {
		return "", "", fmt.Errorf("ROOT_INVALID")
	}
	absTarget, err := filepath.Abs(filepath.Clean(target))
	if err != nil {
		return "", "", fmt.Errorf("PATH_TRAVERSAL")
	}
	rel, err := filepath.Rel(absRoot, absTarget)
	if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return "", "", fmt.Errorf("PATH_TRAVERSAL")
	}

	return absRoot, absTarget, nil
}

func resolveConfigApplyRelativePath(root, relativePath, osType string) (string, error) {
	absRoot, err := normalizeConfigPathForOS(root, osType)
	if err != nil {
		return "", fmt.Errorf("ROOT_INVALID")
	}
	rel := strings.TrimSpace(strings.ReplaceAll(relativePath, `\`, `/`))
	if rel == "" || configPathIsAbs(rel, osType) {
		return "", fmt.Errorf("PATH_TRAVERSAL")
	}
	parts := make([]string, 0)
	for _, part := range strings.Split(strings.Trim(rel, "/"), "/") {
		if part == "" || part == "." {
			continue
		}
		if part == ".." {
			return "", fmt.Errorf("PATH_TRAVERSAL")
		}
		parts = append(parts, part)
	}
	if len(parts) == 0 {
		return "", fmt.Errorf("PATH_TRAVERSAL")
	}
	target := strings.TrimRight(absRoot, `/\`) + "/" + strings.Join(parts, "/")
	target, err = normalizeConfigPathForOS(target, osType)
	if err != nil {
		return "", fmt.Errorf("PATH_TRAVERSAL")
	}
	if !configPathWithinRoot(target, absRoot, osType) {
		return "", fmt.Errorf("PATH_TRAVERSAL")
	}

	return target, nil
}

func normalizeConfigPathForOS(pathValue, osType string) (string, error) {
	cleaned := strings.TrimSpace(strings.ReplaceAll(pathValue, `\`, `/`))
	if cleaned == "" {
		return "", fmt.Errorf("path empty")
	}
	if strings.EqualFold(osType, "windows") {
		if !configPathIsAbs(cleaned, "windows") {
			return "", fmt.Errorf("windows path must be absolute")
		}
		prefix := ""
		if len(cleaned) >= 2 && cleaned[1] == ':' {
			prefix = strings.ToUpper(cleaned[:2])
			cleaned = cleaned[2:]
		}
		parts := cleanConfigPathParts(cleaned)
		return prefix + "/" + strings.Join(parts, "/"), nil
	}

	if !strings.HasPrefix(cleaned, "/") {
		return "", fmt.Errorf("linux path must be absolute")
	}
	return "/" + strings.Join(cleanConfigPathParts(cleaned), "/"), nil
}

func cleanConfigPathParts(pathValue string) []string {
	parts := make([]string, 0)
	for _, part := range strings.Split(pathValue, "/") {
		if part == "" || part == "." {
			continue
		}
		if part == ".." {
			if len(parts) > 0 {
				parts = parts[:len(parts)-1]
			}
			continue
		}
		parts = append(parts, part)
	}
	return parts
}

func configPathIsAbs(pathValue, osType string) bool {
	pathValue = strings.ReplaceAll(strings.TrimSpace(pathValue), `\`, `/`)
	if strings.EqualFold(osType, "windows") {
		return len(pathValue) >= 3 && pathValue[1] == ':' && pathValue[2] == '/'
	}

	return strings.HasPrefix(pathValue, "/")
}

func configPathWithinRoot(target, root, osType string) bool {
	t := strings.TrimRight(target, `/\`)
	r := strings.TrimRight(root, `/\`)
	if strings.EqualFold(osType, "windows") {
		t = strings.ToLower(t)
		r = strings.ToLower(r)
	}

	return t == r || strings.HasPrefix(t, r+"/")
}

var renameConfigFile = os.Rename
var removeConfigFile = os.Remove

func writeConfigAtomically(path string, content []byte, backup bool) (configWriteStats, error) {
	return writeConfigAtomicallyForOS(path, content, backup, runtime.GOOS)
}

func writeConfigAtomicallyForOS(path string, content []byte, backup bool, osType string) (configWriteStats, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		if errors.Is(err, os.ErrPermission) {
			return configWriteStats{}, fmt.Errorf("PERMISSION_DENIED")
		}
		return configWriteStats{}, err
	}
	backupPath := ""
	if backup {
		if _, err := os.Stat(path); err == nil {
			backupPath = path + ".bak." + time.Now().UTC().Format("20060102150405")
			if err := copyConfigFile(path, backupPath); err != nil {
				return configWriteStats{}, err
			}
		}
	}
	tmp := path + ".tmp-" + strconv.FormatInt(time.Now().UnixNano(), 10)
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		if errors.Is(err, os.ErrPermission) {
			return configWriteStats{}, fmt.Errorf("PERMISSION_DENIED")
		}
		return configWriteStats{}, err
	}
	if _, err := f.Write(content); err != nil {
		_ = f.Close()
		_ = os.Remove(tmp)
		return configWriteStats{}, err
	}
	if err := f.Sync(); err != nil {
		_ = f.Close()
		_ = os.Remove(tmp)
		return configWriteStats{}, err
	}
	if err := f.Close(); err != nil {
		_ = os.Remove(tmp)
		return configWriteStats{}, err
	}
	var replaceErr error
	backupPath, replaceErr = replaceConfigFile(tmp, path, backupPath, strings.EqualFold(osType, "windows"))
	if replaceErr != nil {
		_ = os.Remove(tmp)
		return configWriteStats{}, replaceErr
	}
	sum := sha256.Sum256(content)
	return configWriteStats{Bytes: len(content), BackupPath: backupPath, Checksum: hex.EncodeToString(sum[:])}, nil
}

func replaceConfigFile(tmp, target, backupPath string, windows bool) (string, error) {
	if err := renameConfigFile(tmp, target); err == nil {
		return backupPath, nil
	} else if !windows {
		if configErrIsFileLocked(err) {
			return backupPath, fmt.Errorf("FILE_LOCKED: target file is locked: %w", err)
		}
		return backupPath, err
	} else {
		if backupPath == "" {
			if _, statErr := os.Stat(target); statErr == nil {
				backupPath = target + ".bak." + time.Now().UTC().Format("20060102150405")
				if copyErr := copyConfigFile(target, backupPath); copyErr != nil {
					if configErrIsFileLocked(copyErr) {
						return backupPath, fmt.Errorf("FILE_LOCKED: backup existing target failed: %w", copyErr)
					}
					return backupPath, fmt.Errorf("WINDOWS_RENAME_FAILED: backup existing target failed: %w", copyErr)
				}
			}
		}
		if removeErr := removeConfigFile(target); removeErr != nil && !errors.Is(removeErr, os.ErrNotExist) {
			if configErrIsFileLocked(removeErr) {
				return backupPath, fmt.Errorf("FILE_LOCKED: target file is locked: %w", removeErr)
			}
			return backupPath, fmt.Errorf("WINDOWS_RENAME_FAILED: remove existing target failed: %w", removeErr)
		}
		if retryErr := renameConfigFile(tmp, target); retryErr != nil {
			if backupPath != "" {
				_ = copyConfigFile(backupPath, target)
			}
			if configErrIsFileLocked(retryErr) {
				return backupPath, fmt.Errorf("FILE_LOCKED: target file is locked after retry: %w", retryErr)
			}
			return backupPath, fmt.Errorf("WINDOWS_RENAME_FAILED: rename retry failed: %w", retryErr)
		}
		return backupPath, nil
	}
}

func configErrIsFileLocked(err error) bool {
	if err == nil {
		return false
	}
	message := strings.ToLower(err.Error())
	return strings.Contains(message, "being used by another process") ||
		strings.Contains(message, "file is locked") ||
		strings.Contains(message, "text file busy") ||
		strings.Contains(message, "access is denied") ||
		strings.Contains(message, "permission denied")
}

func copyConfigFile(src, dst string) (err error) {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := in.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := out.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()
	if _, err := io.Copy(out, in); err != nil {
		return err
	}
	return out.Sync()
}

func asString(v any) string {
	s, _ := v.(string)
	return s
}

func asBool(v any) bool {
	b, _ := v.(bool)
	return b
}

func mapConfigErr(err error) string {
	e := strings.ToUpper(strings.TrimSpace(err.Error()))
	switch {
	case strings.Contains(e, "PATH_TRAVERSAL"):
		return "PATH_TRAVERSAL"
	case strings.Contains(e, "ROOT_INVALID"):
		return "ROOT_INVALID"
	case strings.Contains(e, "FILE_LOCKED"):
		return "FILE_LOCKED"
	case strings.Contains(e, "WINDOWS_RENAME_FAILED"):
		return "WINDOWS_RENAME_FAILED"
	case strings.Contains(e, "PERMISSION_DENIED"):
		return "PERMISSION_DENIED"
	case strings.Contains(e, "FILE_TOO_LARGE"):
		return "FILE_TOO_LARGE"
	case strings.Contains(e, "BINARY_NOT_ALLOWED"):
		return "BINARY_NOT_ALLOWED"
	default:
		return "INTERNAL_ERROR"
	}
}
