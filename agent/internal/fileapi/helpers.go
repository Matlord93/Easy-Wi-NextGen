package fileapi

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

type requestIDContextKey struct{}

func withRequestContext(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestID := strings.TrimSpace(r.Header.Get("X-Request-ID"))
		if requestID == "" {
			requestID = generateRequestID()
		}
		w.Header().Set("X-Request-ID", requestID)
		r.Header.Set("X-Request-ID", requestID)
		ctx := context.WithValue(r.Context(), requestIDContextKey{}, requestID)
		startedAt := time.Now()
		next.ServeHTTP(w, r.WithContext(ctx))
		logRequest(r, requestID, time.Since(startedAt))
	})
}

func logRequest(r *http.Request, requestID string, duration time.Duration) {
	if r == nil {
		return
	}
	method := r.Method
	path := r.URL.Path
	logLine := fmt.Sprintf("fileapi.request request_id=%s method=%s path=%s duration_ms=%d", requestID, method, path, duration.Milliseconds())
	_, _ = fmt.Fprintln(os.Stdout, logLine)
}

func requestIDFromContext(ctx context.Context) string {
	if ctx == nil {
		return ""
	}
	if value, ok := ctx.Value(requestIDContextKey{}).(string); ok {
		return value
	}
	return ""
}

func generateRequestID() string {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return strconv.FormatInt(time.Now().UnixNano(), 10)
	}
	return hex.EncodeToString(b)
}

func decodeJSON(r *http.Request, out interface{}) error {
	if r.Body == nil {
		return fmt.Errorf("missing request body")
	}
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(out); err != nil {
		_ = r.Body.Close()
		return fmt.Errorf("invalid json payload")
	}
	if err := r.Body.Close(); err != nil {
		return fmt.Errorf("close request body: %w", err)
	}
	return nil
}

func respondJSON(w http.ResponseWriter, status int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	if payload == nil {
		return
	}
	if err := json.NewEncoder(w).Encode(payload); err != nil {
		log.Printf("fileapi: write json response: %v", err)
	}
}

func respondError(r *http.Request, w http.ResponseWriter, status int, code string, message string) {
	requestID := ""
	if r != nil {
		requestID = requestIDFromContext(r.Context())
	}
	respondJSON(w, status, map[string]any{
		"error": map[string]any{
			"code":       code,
			"message":    message,
			"request_id": requestID,
		},
	})
}

func ensureParentExists(path string) error {
	parent := filepath.Dir(path)
	info, err := os.Stat(parent)
	if err != nil {
		return fmt.Errorf("parent directory missing")
	}
	if !info.IsDir() {
		return fmt.Errorf("parent is not a directory")
	}
	return nil
}

func writeFileAtomic(target string, reader io.Reader, perm os.FileMode) error {
	dir := filepath.Dir(target)
	uid, gid, hasOwner := desiredOwnerForFile(target)
	tmp, err := os.CreateTemp(dir, ".fileapi-*")
	if err != nil {
		return fmt.Errorf("create temp file: %w", err)
	}
	defer func() {
		_ = os.Remove(tmp.Name())
	}()

	if _, err := io.Copy(tmp, reader); err != nil {
		closeErr := tmp.Close()
		if closeErr != nil {
			return fmt.Errorf("write temp file: %w", errors.Join(err, closeErr))
		}
		return fmt.Errorf("write temp file: %w", err)
	}
	if err := tmp.Sync(); err != nil {
		closeErr := tmp.Close()
		if closeErr != nil {
			return fmt.Errorf("sync temp file: %w", errors.Join(err, closeErr))
		}
		return fmt.Errorf("sync temp file: %w", err)
	}
	releaseFileFromPageCache(tmp)
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("close temp file: %w", err)
	}
	if perm != 0 {
		if err := os.Chmod(tmp.Name(), perm); err != nil {
			return fmt.Errorf("chmod temp file: %w", err)
		}
	}
	if hasOwner {
		if err := chownPath(tmp.Name(), uid, gid); err != nil && !os.IsPermission(err) {
			return fmt.Errorf("chown temp file: %w", err)
		}
	}
	if err := os.Rename(tmp.Name(), target); err != nil {
		return fmt.Errorf("rename temp file: %w", err)
	}
	return nil
}

func desiredOwnerForFile(target string) (int, int, bool) {
	if uid, gid, ok := fileOwnership(target); ok {
		return uid, gid, true
	}
	return fileOwnership(filepath.Dir(target))
}

func mkdirAllPreserveOwner(target string, perm os.FileMode) error {
	if _, err := os.Stat(target); err == nil {
		return nil
	} else if !os.IsNotExist(err) {
		return err
	}

	ownerRoot, uid, gid, hasOwner := nearestExistingOwner(filepath.Dir(target))
	firstCreated := target
	if hasOwner {
		firstCreated = firstMissingChild(ownerRoot, target)
	}
	if err := os.MkdirAll(target, perm); err != nil {
		return err
	}
	if !hasOwner {
		return nil
	}

	return filepath.WalkDir(firstCreated, func(path string, _ os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		if err := chownPath(path, uid, gid); err != nil && !os.IsPermission(err) {
			return fmt.Errorf("chown %s: %w", path, err)
		}
		return nil
	})
}

func nearestExistingOwner(path string) (string, int, int, bool) {
	current := filepath.Clean(path)
	for {
		if uid, gid, ok := fileOwnership(current); ok {
			return current, uid, gid, true
		}
		parent := filepath.Dir(current)
		if parent == current {
			return "", -1, -1, false
		}
		current = parent
	}
}

func firstMissingChild(existingRoot, target string) string {
	rel, err := filepath.Rel(existingRoot, filepath.Clean(target))
	if err != nil || rel == "." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) || rel == ".." {
		return target
	}
	parts := strings.Split(rel, string(filepath.Separator))
	if len(parts) == 0 || parts[0] == "" {
		return target
	}
	return filepath.Join(existingRoot, parts[0])
}
