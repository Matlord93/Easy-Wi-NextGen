package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

type fileEntry struct {
	Name       string `json:"name"`
	Size       int64  `json:"size"`
	Mode       string `json:"mode"`
	ModifiedAt string `json:"modified_at"`
	IsDir      bool   `json:"is_dir"`
}

type filesvcHealthResponse struct {
	Status        string          `json:"status"`
	Version       string          `json:"version"`
	Root          string          `json:"root"`
	RootReachable bool            `json:"root_reachable"`
	Capabilities  map[string]bool `json:"capabilities"`
	TimeUTC       string          `json:"time_utc"`
}

const headerServerRoot = "X-Server-Root"

type filesvcServer struct {
	config filesvcConfig
	cache  *listingCache
}

func (s *filesvcServer) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/v1/servers/", s.handleServers)
	mux.HandleFunc("/health", s.handleHealth)
	mux.HandleFunc("/healthz", s.handleHealth)
	return withRequestContext(mux)
}

func (s *filesvcServer) handleHealth(w http.ResponseWriter, r *http.Request) {
	_, rootErr := validateServerRootAgainstBase(s.config.BaseDir, s.config.BaseDir)
	payload := filesvcHealthResponse{
		Status:        "ok",
		Version:       filesvcVersion(),
		Root:          s.config.BaseDir,
		RootReachable: rootErr == nil,
		Capabilities: map[string]bool{
			"edit":     true,
			"upload":   true,
			"download": true,
		},
		TimeUTC: time.Now().UTC().Format(time.RFC3339),
	}
	log.Printf("filesvc.health request_id=%s status=ok", requestIDFromContext(r.Context()))
	respondJSON(w, http.StatusOK, payload)
}

func (s *filesvcServer) handleServers(w http.ResponseWriter, r *http.Request) {
	customerID, err := verifyRequestSignature(r, s.config)
	if err != nil {
		respondError(r, w, http.StatusUnauthorized, "unauthorized", err.Error())
		return
	}

	instanceID, action, err := parseServerPath(r.URL.Path)
	if err != nil {
		respondError(r, w, http.StatusNotFound, "not_found", "invalid path")
		return
	}

	switch action {
	case "files":
		s.handleList(w, r, customerID, instanceID)
	case "read":
		s.handleRead(w, r, customerID, instanceID, false)
	case "download":
		s.handleRead(w, r, customerID, instanceID, true)
	case "write":
		s.handleWrite(w, r, customerID, instanceID)
	case "upload":
		s.handleUpload(w, r, customerID, instanceID)
	case "mkdir":
		s.handleMkdir(w, r, customerID, instanceID)
	case "delete":
		s.handleDelete(w, r, customerID, instanceID)
	case "rename":
		s.handleRename(w, r, customerID, instanceID)
	case "chmod":
		s.handleChmod(w, r, customerID, instanceID)
	case "extract":
		s.handleExtract(w, r, customerID, instanceID)
	default:
		respondError(r, w, http.StatusNotFound, "not_found", "unknown endpoint")
	}
}

func (s *filesvcServer) resolveServerRoot(r *http.Request) (string, error) {
	serverRoot := strings.TrimSpace(r.Header.Get(headerServerRoot))
	if serverRoot == "" {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}

	rootPath, err := validateServerRootAgainstBase(serverRoot, s.config.BaseDir)
	if err != nil {
		return "", err
	}
	return rootPath, nil
}

func parseServerPath(path string) (string, string, error) {
	trimmed := strings.TrimPrefix(path, "/v1/servers/")
	parts := strings.Split(strings.Trim(trimmed, "/"), "/")
	if len(parts) < 2 {
		return "", "", fmt.Errorf("invalid path")
	}
	return parts[0], parts[1], nil
}

func (s *filesvcServer) handleList(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodGet {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	relativePath := sanitizeRelativePath(r.URL.Query().Get("path"))
	cacheKey := fmt.Sprintf("%s|%s", rootPath, relativePath)
	if cached, ok := s.cache.Get(cacheKey); ok {
		respondJSON(w, http.StatusOK, cached)
		return
	}

	target, err := sanitizeInstancePath(rootPath, relativePath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	entries, err := os.ReadDir(target)
	if err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("read dir: %v", err))
		return
	}

	fileEntries := make([]fileEntry, 0, len(entries))
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil {
			respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("stat %s: %v", entry.Name(), err))
			return
		}
		fileEntries = append(fileEntries, fileEntry{
			Name:       entry.Name(),
			Size:       info.Size(),
			Mode:       fmt.Sprintf("%04o", info.Mode().Perm()),
			ModifiedAt: info.ModTime().UTC().Format("2006-01-02 15:04:05"),
			IsDir:      entry.IsDir(),
		})
	}

	sort.Slice(fileEntries, func(i, j int) bool {
		if fileEntries[i].IsDir != fileEntries[j].IsDir {
			return fileEntries[i].IsDir
		}
		return strings.ToLower(fileEntries[i].Name) < strings.ToLower(fileEntries[j].Name)
	})

	response := listResponse{
		RootPath: rootPath,
		Path:     relativePath,
		Entries:  fileEntries,
	}
	if s.cache != nil {
		s.cache.Set(cacheKey, response)
	}
	respondJSON(w, http.StatusOK, response)
}

func (s *filesvcServer) handleRead(w http.ResponseWriter, r *http.Request, customerID, instanceID string, download bool) {
	if r.Method != http.MethodGet {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	path := sanitizeRelativePath(r.URL.Query().Get("path"))
	if path == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, path)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	info, err := os.Stat(target)
	if err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("stat file: %v", err))
		return
	}
	if info.IsDir() {
		respondError(r, w, http.StatusBadRequest, "bad_request", "path is a directory")
		return
	}

	if download {
		filename := filepath.Base(target)
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=\"%s\"", filename))
	}

	http.ServeFile(w, r, target)
}

func (s *filesvcServer) handleWrite(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var payload struct {
		Path    string `json:"path"`
		Content string `json:"content"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := ensureParentExists(target); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	reader := strings.NewReader(payload.Content)
	if err := writeFileAtomic(target, reader, 0o640); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleUpload(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	if err := r.ParseMultipartForm(32 << 20); err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", "invalid multipart payload")
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	directory := sanitizeRelativePath(r.FormValue("path"))
	file, header, err := r.FormFile("upload")
	if err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing upload")
		return
	}
	defer file.Close()

	if header.Filename == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing filename")
		return
	}

	if err := validateRelativePathInput(header.Filename); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanName := sanitizeRelativePath(header.Filename)
	targetRelative := cleanName
	if directory != "" {
		targetRelative = filepath.Join(directory, cleanName)
	}

	target, err := sanitizeInstancePath(rootPath, targetRelative)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := ensureParentExists(target); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := writeFileAtomic(target, file, 0o640); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleMkdir(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var payload struct {
		Path string `json:"path"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := os.MkdirAll(target, 0o750); err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("mkdir failed: %v", err))
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleDelete(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var payload struct {
		Path string `json:"path"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" || cleanPath == "." {
		respondError(r, w, http.StatusBadRequest, "bad_request", "invalid path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	info, err := os.Stat(target)
	if err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("stat failed: %v", err))
		return
	}

	if info.IsDir() {
		if err := os.RemoveAll(target); err != nil {
			respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("delete failed: %v", err))
			return
		}
	} else {
		if err := os.Remove(target); err != nil {
			respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("delete failed: %v", err))
			return
		}
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleRename(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var payload struct {
		Path    string `json:"path"`
		NewPath string `json:"new_path"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	cleanNew := sanitizeRelativePath(payload.NewPath)
	if cleanPath == "" || cleanNew == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing path")
		return
	}

	src, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}
	dst, err := sanitizeInstancePath(rootPath, cleanNew)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := os.Rename(src, dst); err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("rename failed: %v", err))
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleChmod(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var payload struct {
		Path string      `json:"path"`
		Mode interface{} `json:"mode"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	mode, err := parseMode(payload.Mode)
	if err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", "invalid permissions")
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := os.Chmod(target, os.FileMode(mode)); err != nil {
		respondError(r, w, http.StatusBadRequest, "bad_request", fmt.Sprintf("chmod failed: %v", err))
		return
	}

	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleExtract(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var payload struct {
		Path        string `json:"path"`
		Destination string `json:"destination"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	rootPath, err := s.resolveServerRoot(r)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	cleanDestination := sanitizeRelativePath(payload.Destination)
	if cleanPath == "" {
		respondError(r, w, http.StatusBadRequest, "bad_request", "missing path")
		return
	}

	archivePath, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	destinationPath, err := sanitizeInstancePath(rootPath, cleanDestination)
	if err != nil {
		respondServerRootError(r, w, err)
		return
	}

	if err := extractArchive(archivePath, destinationPath); err != nil {
		respondServerRootError(r, w, err)
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) invalidateCache(rootPath string) {
	if s.cache != nil {
		s.cache.Invalidate(rootPath + "|")
	}
}

func decodeJSON(r *http.Request, out interface{}) error {
	if r.Body == nil {
		return fmt.Errorf("missing request body")
	}
	defer r.Body.Close()
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(out); err != nil {
		return fmt.Errorf("invalid json payload")
	}
	return nil
}

func respondJSON(w http.ResponseWriter, status int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
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

func respondServerRootError(r *http.Request, w http.ResponseWriter, err error) {
	message := strings.TrimSpace(err.Error())
	switch message {
	case "INVALID_SERVER_ROOT":
		respondError(r, w, http.StatusBadRequest, "INVALID_SERVER_ROOT", "invalid or missing canonical server root")
	case "SERVER_ROOT_NOT_FOUND":
		respondError(r, w, http.StatusNotFound, "SERVER_ROOT_NOT_FOUND", "server root not found")
	case "SERVER_ROOT_NOT_ACCESSIBLE":
		respondError(r, w, http.StatusForbidden, "SERVER_ROOT_NOT_ACCESSIBLE", "server root not accessible")
	default:
		respondError(r, w, http.StatusBadRequest, "INVALID_SERVER_ROOT", message)
	}
}

func parseMode(value interface{}) (int64, error) {

	switch v := value.(type) {
	case float64:
		return int64(v), nil
	case string:
		v = strings.TrimSpace(v)
		if v == "" {
			return 0, fmt.Errorf("empty mode")
		}
		if strings.HasPrefix(v, "0") {
			parsed, err := strconv.ParseInt(v, 8, 64)
			if err == nil {
				return parsed, nil
			}
		}
		parsed, err := strconv.ParseInt(v, 10, 64)
		if err != nil {
			return 0, err
		}
		return parsed, nil
	default:
		return 0, fmt.Errorf("invalid mode")
	}
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
	tmp, err := os.CreateTemp(dir, ".filesvc-*")
	if err != nil {
		return fmt.Errorf("create temp file: %w", err)
	}
	defer func() {
		_ = os.Remove(tmp.Name())
	}()

	if _, err := io.Copy(tmp, reader); err != nil {
		tmp.Close()
		return fmt.Errorf("write temp file: %w", err)
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return fmt.Errorf("sync temp file: %w", err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("close temp file: %w", err)
	}
	if perm != 0 {
		if err := os.Chmod(tmp.Name(), perm); err != nil {
			return fmt.Errorf("chmod temp file: %w", err)
		}
	}
	if err := os.Rename(tmp.Name(), target); err != nil {
		return fmt.Errorf("rename temp file: %w", err)
	}
	return nil
}

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
		log.Printf("filesvc.request request_id=%s method=%s path=%s duration_ms=%d", requestID, r.Method, r.URL.Path, time.Since(startedAt).Milliseconds())
	})
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

func mapStatusToCode(status int) string {
	switch status {
	case http.StatusUnauthorized:
		return "unauthorized"
	case http.StatusNotFound:
		return "not_found"
	case http.StatusMethodNotAllowed:
		return "method_not_allowed"
	case http.StatusBadRequest:
		return "bad_request"
	default:
		return "request_failed"
	}
}
