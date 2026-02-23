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

func writeConfigAtomically(path string, content []byte, backup bool) (configWriteStats, error) {
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
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return configWriteStats{}, err
	}
	sum := sha256.Sum256(content)
	return configWriteStats{Bytes: len(content), BackupPath: backupPath, Checksum: hex.EncodeToString(sum[:])}, nil
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
