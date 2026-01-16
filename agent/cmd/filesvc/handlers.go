package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

type fileEntry struct {
	Name       string `json:"name"`
	Size       int64  `json:"size"`
	Mode       string `json:"mode"`
	ModifiedAt string `json:"modified_at"`
	IsDir      bool   `json:"is_dir"`
}

type filesvcServer struct {
	config filesvcConfig
	cache  *listingCache
}

func (s *filesvcServer) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/v1/servers/", s.handleServers)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	return mux
}

func (s *filesvcServer) handleServers(w http.ResponseWriter, r *http.Request) {
	customerID, err := verifyRequestSignature(r, s.config)
	if err != nil {
		respondError(w, http.StatusUnauthorized, err.Error())
		return
	}

	instanceID, action, err := parseServerPath(r.URL.Path)
	if err != nil {
		respondError(w, http.StatusNotFound, "invalid path")
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
		respondError(w, http.StatusNotFound, "unknown endpoint")
	}
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
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
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
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	entries, err := os.ReadDir(target)
	if err != nil {
		respondError(w, http.StatusBadRequest, fmt.Sprintf("read dir: %v", err))
		return
	}

	fileEntries := make([]fileEntry, 0, len(entries))
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil {
			respondError(w, http.StatusBadRequest, fmt.Sprintf("stat %s: %v", entry.Name(), err))
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
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	path := sanitizeRelativePath(r.URL.Query().Get("path"))
	if path == "" {
		respondError(w, http.StatusBadRequest, "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, path)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	info, err := os.Stat(target)
	if err != nil {
		respondError(w, http.StatusBadRequest, fmt.Sprintf("stat file: %v", err))
		return
	}
	if info.IsDir() {
		respondError(w, http.StatusBadRequest, "path is a directory")
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
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var payload struct {
		Path    string `json:"path"`
		Content string `json:"content"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" {
		respondError(w, http.StatusBadRequest, "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := ensureParentExists(target); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	reader := strings.NewReader(payload.Content)
	if err := writeFileAtomic(target, reader, 0o640); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleUpload(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	if err := r.ParseMultipartForm(32 << 20); err != nil {
		respondError(w, http.StatusBadRequest, "invalid multipart payload")
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	directory := sanitizeRelativePath(r.FormValue("path"))
	file, header, err := r.FormFile("upload")
	if err != nil {
		respondError(w, http.StatusBadRequest, "missing upload")
		return
	}
	defer file.Close()

	if header.Filename == "" {
		respondError(w, http.StatusBadRequest, "missing filename")
		return
	}

	if err := validateRelativePathInput(header.Filename); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanName := sanitizeRelativePath(header.Filename)
	targetRelative := cleanName
	if directory != "" {
		targetRelative = filepath.Join(directory, cleanName)
	}

	target, err := sanitizeInstancePath(rootPath, targetRelative)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := ensureParentExists(target); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := writeFileAtomic(target, file, 0o640); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleMkdir(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var payload struct {
		Path string `json:"path"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" {
		respondError(w, http.StatusBadRequest, "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := os.MkdirAll(target, 0o750); err != nil {
		respondError(w, http.StatusBadRequest, fmt.Sprintf("mkdir failed: %v", err))
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleDelete(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var payload struct {
		Path string `json:"path"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" || cleanPath == "." {
		respondError(w, http.StatusBadRequest, "invalid path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	info, err := os.Stat(target)
	if err != nil {
		respondError(w, http.StatusBadRequest, fmt.Sprintf("stat failed: %v", err))
		return
	}

	if info.IsDir() {
		if err := os.RemoveAll(target); err != nil {
			respondError(w, http.StatusBadRequest, fmt.Sprintf("delete failed: %v", err))
			return
		}
	} else {
		if err := os.Remove(target); err != nil {
			respondError(w, http.StatusBadRequest, fmt.Sprintf("delete failed: %v", err))
			return
		}
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleRename(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var payload struct {
		Path    string `json:"path"`
		NewPath string `json:"new_path"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	cleanNew := sanitizeRelativePath(payload.NewPath)
	if cleanPath == "" || cleanNew == "" {
		respondError(w, http.StatusBadRequest, "missing path")
		return
	}

	src, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}
	dst, err := sanitizeInstancePath(rootPath, cleanNew)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := os.Rename(src, dst); err != nil {
		respondError(w, http.StatusBadRequest, fmt.Sprintf("rename failed: %v", err))
		return
	}

	s.invalidateCache(rootPath)
	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleChmod(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var payload struct {
		Path string      `json:"path"`
		Mode interface{} `json:"mode"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	mode, err := parseMode(payload.Mode)
	if err != nil {
		respondError(w, http.StatusBadRequest, "invalid permissions")
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	if cleanPath == "" {
		respondError(w, http.StatusBadRequest, "missing path")
		return
	}

	target, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := os.Chmod(target, os.FileMode(mode)); err != nil {
		respondError(w, http.StatusBadRequest, fmt.Sprintf("chmod failed: %v", err))
		return
	}

	respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *filesvcServer) handleExtract(w http.ResponseWriter, r *http.Request, customerID, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}

	var payload struct {
		Path        string `json:"path"`
		Destination string `json:"destination"`
	}
	if err := decodeJSON(r, &payload); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	rootPath, err := resolveInstanceRoot(s.config.BaseDir, customerID, instanceID)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	cleanPath := sanitizeRelativePath(payload.Path)
	cleanDestination := sanitizeRelativePath(payload.Destination)
	if cleanPath == "" {
		respondError(w, http.StatusBadRequest, "missing path")
		return
	}

	archivePath, err := sanitizeInstancePath(rootPath, cleanPath)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	destinationPath, err := sanitizeInstancePath(rootPath, cleanDestination)
	if err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := extractArchive(archivePath, destinationPath); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
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

func respondError(w http.ResponseWriter, status int, message string) {
	respondJSON(w, status, map[string]string{"error": message})
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
