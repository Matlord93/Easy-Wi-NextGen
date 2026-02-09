package fileapi

import (
	"errors"
	"fmt"
	"io"
	"mime"
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

type healthResponse struct {
	Status           string          `json:"status"`
	Version          string          `json:"version"`
	Root             string          `json:"root"`
	RootReachable    bool            `json:"root_reachable"`
	FileAPI          bool            `json:"file_api"`
	SupportedActions []string        `json:"supported_actions"`
	Capabilities     map[string]bool `json:"capabilities"`
	TimeUTC          string          `json:"time_utc"`
}

const headerServerRoot = "X-Server-Root"

const (
	errorInvalidServerRoot    = "INVALID_SERVER_ROOT"
	errorServerRootNotFound   = "SERVER_ROOT_NOT_FOUND"
	errorServerRootNoAccess   = "SERVER_ROOT_NOT_ACCESSIBLE"
	errorPermissionDenied     = "PERMISSION_DENIED"
	errorNotFound             = "NOT_FOUND"
	errorInvalidPath          = "INVALID_PATH"
	errorTooLarge             = "TOO_LARGE"
	errorTimeout              = "TIMEOUT"
	errorInternal             = "INTERNAL"
	errorMethodNotAllowed     = "METHOD_NOT_ALLOWED"
	errorUnsupportedMediaType = "UNSUPPORTED_MEDIA_TYPE"
)

type Server struct {
	config Config
	cache  *listingCache
	locks  *lockManager
}

func NewServer(cfg Config) (*Server, error) {
	if err := cfg.Validate(); err != nil {
		return nil, err
	}
	cache := newListingCache(cfg.CacheSize)
	return &Server{
		config: cfg,
		cache:  cache,
		locks:  newLockManager(),
	}, nil
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/v1/servers/", s.handleServers)
	mux.HandleFunc("/health", s.handleHealth)
	mux.HandleFunc("/healthz", s.handleHealth)
	return withRequestContext(mux)
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	_, rootErr := validateServerRootAgainstBase(s.config.BaseDir, s.config.BaseDir)
	payload := healthResponse{
		Status:        "ok",
		Version:       s.config.Version,
		Root:          s.config.BaseDir,
		RootReachable: rootErr == nil,
		FileAPI:       true,
		SupportedActions: []string{
			"files",
			"read",
			"download",
			"write",
			"upload",
			"mkdir",
			"delete",
			"rename",
			"chmod",
			"extract",
		},
		Capabilities: map[string]bool{
			"edit":     true,
			"upload":   true,
			"download": true,
			"file_api": true,
		},
		TimeUTC: time.Now().UTC().Format(time.RFC3339),
	}
	respondJSON(w, http.StatusOK, payload)
}

func (s *Server) handleServers(w http.ResponseWriter, r *http.Request) {
	_, err := verifyRequestSignature(r, s.config)
	if err != nil {
		respondError(r, w, http.StatusUnauthorized, errorPermissionDenied, "signature verification failed")
		return
	}

	instanceID, action, err := parseServerPath(r.URL.Path)
	if err != nil {
		respondError(r, w, http.StatusNotFound, errorNotFound, "invalid path")
		return
	}

	switch action {
	case "files":
		s.handleList(w, r, instanceID)
	case "read":
		s.handleRead(w, r, instanceID, false)
	case "download":
		s.handleRead(w, r, instanceID, true)
	case "write":
		s.handleWrite(w, r, instanceID)
	case "upload":
		s.handleUpload(w, r, instanceID)
	case "mkdir":
		s.handleMkdir(w, r, instanceID)
	case "delete":
		s.handleDelete(w, r, instanceID)
	case "rename":
		s.handleRename(w, r, instanceID)
	case "chmod":
		s.handleChmod(w, r, instanceID)
	case "extract":
		s.handleExtract(w, r, instanceID)
	default:
		respondError(r, w, http.StatusNotFound, errorNotFound, "unknown endpoint")
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

func (s *Server) resolveServerRoot(r *http.Request) (string, error) {
	serverRoot := strings.TrimSpace(r.Header.Get(headerServerRoot))
	if serverRoot == "" {
		return "", errors.New(errorInvalidServerRoot)
	}

	rootPath, err := validateServerRootAgainstBase(serverRoot, s.config.BaseDir)
	if err != nil {
		return "", err
	}
	return rootPath, nil
}

func (s *Server) handleList(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodGet {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithReadLock(instanceID, func() error {
		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		relativePath := sanitizeRelativePath(r.URL.Query().Get("path"))
		cacheKey := fmt.Sprintf("%s|%s", rootPath, relativePath)
		if cached, ok := s.cache.Get(cacheKey); ok {
			respondJSON(w, http.StatusOK, cached)
			return nil
		}

		target, err := sanitizeInstancePath(rootPath, relativePath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		entries, err := os.ReadDir(target)
		if err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "read dir failed")
			return nil
		}

		fileEntries := make([]fileEntry, 0, len(entries))
		for _, entry := range entries {
			info, err := entry.Info()
			if err != nil {
				respondError(r, w, statusFromError(err), codeFromError(err), "stat failed")
				return nil
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
		s.cache.Set(cacheKey, response)
		respondJSON(w, http.StatusOK, response)
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "list failed")
	}
}

func (s *Server) handleRead(w http.ResponseWriter, r *http.Request, instanceID string, download bool) {
	if r.Method != http.MethodGet {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithReadLock(instanceID, func() error {
		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		path := sanitizeRelativePath(r.URL.Query().Get("path"))
		if path == "" {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing path")
			return nil
		}

		target, err := sanitizeInstancePath(rootPath, path)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		info, err := os.Stat(target)
		if err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "stat failed")
			return nil
		}
		if info.IsDir() {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "path is a directory")
			return nil
		}

		file, err := os.Open(target)
		if err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "open failed")
			return nil
		}

		if download {
			filename := filepath.Base(target)
			w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=\"%s\"", filename))
		}

		contentType := mime.TypeByExtension(filepath.Ext(target))
		if contentType == "" {
			contentType = "application/octet-stream"
		}
		w.Header().Set("Content-Type", contentType)
		w.Header().Set("Content-Length", strconv.FormatInt(info.Size(), 10))
		if _, err := io.Copy(w, file); err != nil {
			respondError(r, w, http.StatusInternalServerError, errorInternal, "stream failed")
			_ = file.Close()
			return nil
		}
		if err := file.Close(); err != nil {
			respondError(r, w, http.StatusInternalServerError, errorInternal, "close failed")
		}
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "read failed")
	}
}

func (s *Server) handleWrite(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		var payload struct {
			Path    string `json:"path"`
			Content string `json:"content"`
		}
		if err := decodeJSON(r, &payload); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid json payload")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		cleanPath := sanitizeRelativePath(payload.Path)
		if cleanPath == "" {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing path")
			return nil
		}

		target, err := sanitizeInstancePath(rootPath, cleanPath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := ensureParentExists(target); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		reader := strings.NewReader(payload.Content)
		if err := writeFileAtomic(target, reader, 0o640); err != nil {
			respondError(r, w, http.StatusInternalServerError, errorInternal, "write failed")
			return nil
		}

		s.invalidateCache(rootPath)
		respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "write failed")
	}
}

func (s *Server) handleUpload(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		if s.config.MaxUploadBytes > 0 {
			r.Body = http.MaxBytesReader(w, r.Body, s.config.MaxUploadBytes)
		}

		mediaType, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
		if err != nil {
			respondError(r, w, http.StatusUnsupportedMediaType, errorUnsupportedMediaType, "invalid content type")
			return nil
		}
		if !strings.HasPrefix(mediaType, "multipart/") {
			respondError(r, w, http.StatusUnsupportedMediaType, errorUnsupportedMediaType, "multipart form required")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		reader, err := r.MultipartReader()
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid multipart payload")
			return nil
		}

		var directory string
		var fileName string
		var fileReader io.Reader
		for {
			part, err := reader.NextPart()
			if errors.Is(err, io.EOF) {
				break
			}
			if err != nil {
				if isTooLarge(err) {
					respondError(r, w, http.StatusRequestEntityTooLarge, errorTooLarge, "upload too large")
					return nil
				}
				respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid multipart payload")
				return nil
			}
			if part.FormName() == "path" {
				buf, _ := io.ReadAll(io.LimitReader(part, 4096))
				directory = sanitizeRelativePath(string(buf))
				continue
			}
			if part.FormName() == "upload" {
				fileName = part.FileName()
				fileReader = part
				break
			}
		}

		if fileName == "" || fileReader == nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing upload")
			return nil
		}

		if err := validateRelativePathInput(fileName); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid filename")
			return nil
		}
		cleanName := sanitizeRelativePath(fileName)
		targetRelative := cleanName
		if directory != "" {
			targetRelative = filepath.Join(directory, cleanName)
		}

		target, err := sanitizeInstancePath(rootPath, targetRelative)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := ensureParentExists(target); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := writeFileAtomic(target, fileReader, 0o640); err != nil {
			respondError(r, w, http.StatusInternalServerError, errorInternal, "upload failed")
			return nil
		}

		s.invalidateCache(rootPath)
		respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "upload failed")
	}
}

func (s *Server) handleMkdir(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		var payload struct {
			Path string `json:"path"`
		}
		if err := decodeJSON(r, &payload); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid json payload")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		cleanPath := sanitizeRelativePath(payload.Path)
		if cleanPath == "" {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing path")
			return nil
		}

		target, err := sanitizeInstancePath(rootPath, cleanPath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := os.MkdirAll(target, 0o750); err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "mkdir failed")
			return nil
		}

		s.invalidateCache(rootPath)
		respondJSON(w, http.StatusCreated, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "mkdir failed")
	}
}

func (s *Server) handleDelete(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		var payload struct {
			Path string `json:"path"`
		}
		if err := decodeJSON(r, &payload); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid json payload")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		cleanPath := sanitizeRelativePath(payload.Path)
		if cleanPath == "" || cleanPath == "." {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid path")
			return nil
		}

		target, err := sanitizeInstancePath(rootPath, cleanPath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		info, err := os.Stat(target)
		if err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "stat failed")
			return nil
		}

		if info.IsDir() {
			if err := os.RemoveAll(target); err != nil {
				respondError(r, w, statusFromError(err), codeFromError(err), "delete failed")
				return nil
			}
		} else {
			if err := os.Remove(target); err != nil {
				respondError(r, w, statusFromError(err), codeFromError(err), "delete failed")
				return nil
			}
		}

		s.invalidateCache(rootPath)
		respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "delete failed")
	}
}

func (s *Server) handleRename(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		var payload struct {
			Path    string `json:"path"`
			NewPath string `json:"new_path"`
		}
		if err := decodeJSON(r, &payload); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid json payload")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		cleanPath := sanitizeRelativePath(payload.Path)
		cleanNew := sanitizeRelativePath(payload.NewPath)
		if cleanPath == "" || cleanNew == "" {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing path")
			return nil
		}

		src, err := sanitizeInstancePath(rootPath, cleanPath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}
		dst, err := sanitizeInstancePath(rootPath, cleanNew)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := os.Rename(src, dst); err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "rename failed")
			return nil
		}

		s.invalidateCache(rootPath)
		respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "rename failed")
	}
}

func (s *Server) handleChmod(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		var payload struct {
			Path string      `json:"path"`
			Mode interface{} `json:"mode"`
		}
		if err := decodeJSON(r, &payload); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid json payload")
			return nil
		}

		mode, err := parseMode(payload.Mode)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid permissions")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		cleanPath := sanitizeRelativePath(payload.Path)
		if cleanPath == "" {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing path")
			return nil
		}

		target, err := sanitizeInstancePath(rootPath, cleanPath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := os.Chmod(target, os.FileMode(mode)); err != nil {
			respondError(r, w, statusFromError(err), codeFromError(err), "chmod failed")
			return nil
		}

		respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "chmod failed")
	}
}

func (s *Server) handleExtract(w http.ResponseWriter, r *http.Request, instanceID string) {
	if r.Method != http.MethodPost {
		respondError(r, w, http.StatusMethodNotAllowed, errorMethodNotAllowed, "method not allowed")
		return
	}

	err := s.locks.WithWriteLock(instanceID, func() error {
		var payload struct {
			Path        string `json:"path"`
			Destination string `json:"destination"`
		}
		if err := decodeJSON(r, &payload); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "invalid json payload")
			return nil
		}

		rootPath, err := s.resolveServerRoot(r)
		if err != nil {
			respondServerRootError(r, w, err)
			return nil
		}

		cleanPath := sanitizeRelativePath(payload.Path)
		cleanDestination := sanitizeRelativePath(payload.Destination)
		if cleanPath == "" {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, "missing path")
			return nil
		}

		archivePath, err := sanitizeInstancePath(rootPath, cleanPath)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		destinationPath, err := sanitizeInstancePath(rootPath, cleanDestination)
		if err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		if err := extractArchive(archivePath, destinationPath); err != nil {
			respondError(r, w, http.StatusBadRequest, errorInvalidPath, err.Error())
			return nil
		}

		s.invalidateCache(rootPath)
		respondJSON(w, http.StatusOK, map[string]string{"status": "ok"})
		return nil
	})
	if err != nil {
		respondError(r, w, http.StatusInternalServerError, errorInternal, "extract failed")
	}
}

func (s *Server) invalidateCache(rootPath string) {
	s.cache.Invalidate(rootPath + "|")
}

func respondServerRootError(r *http.Request, w http.ResponseWriter, err error) {
	message := strings.TrimSpace(err.Error())
	switch message {
	case errorInvalidServerRoot:
		respondError(r, w, http.StatusBadRequest, errorInvalidServerRoot, "invalid or missing canonical server root")
	case errorServerRootNotFound:
		respondError(r, w, http.StatusNotFound, errorServerRootNotFound, "server root not found")
	case errorServerRootNoAccess:
		respondError(r, w, http.StatusForbidden, errorServerRootNoAccess, "server root not accessible")
	default:
		respondError(r, w, http.StatusBadRequest, errorInvalidServerRoot, message)
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

func statusFromError(err error) int {
	if err == nil {
		return http.StatusInternalServerError
	}
	if errors.Is(err, os.ErrNotExist) {
		return http.StatusNotFound
	}
	if errors.Is(err, os.ErrPermission) {
		return http.StatusForbidden
	}
	return http.StatusBadRequest
}

func codeFromError(err error) string {
	if err == nil {
		return errorInternal
	}
	if errors.Is(err, os.ErrNotExist) {
		return errorNotFound
	}
	if errors.Is(err, os.ErrPermission) {
		return errorPermissionDenied
	}
	return errorInvalidPath
}

func isTooLarge(err error) bool {
	var maxErr *http.MaxBytesError
	return errors.As(err, &maxErr)
}
