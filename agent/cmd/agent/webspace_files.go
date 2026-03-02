package main

import (
	"encoding/base64"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/jobs"
)

const webspaceFilesTimeFormat = time.RFC3339

const (
	webspaceFilesFileMode          = 0o640
	webspaceFilesDefaultTimeout    = 2 * time.Second
	webspaceFilesMaxTimeout        = 10 * time.Second
	webspaceFilesDefaultMaxBytes   = 1 << 20
	webspaceFilesAbsoluteMaxBytes  = 5 << 20
	webspaceFilesDefaultEntryLimit = 1000
)

var webspaceFileLocks sync.Map

type webspaceFileEntry struct {
	name       string
	size       int64
	mode       os.FileMode
	modifiedAt time.Time
	isDir      bool
}

type webspaceFilePolicy struct {
	acl        string
	timeout    time.Duration
	maxBytes   int
	maxEntries int
}

func handleWebspaceFilesList(job jobs.Job) (jobs.Result, func() error) {
	startedAt := time.Now()
	root, relativePath, policy, release, errResult, ok := prepareWebspaceFileOperation(job, true)
	if !ok {
		return errResult, nil
	}
	defer release()

	target, err := sanitizeWebspacePath(root, relativePath)
	if err != nil {
		return webspaceFileFailure(job.ID, "path_invalid", err), nil
	}

	entries, err := os.ReadDir(target)
	if err != nil {
		return webspaceFileFailure(job.ID, "fs_read_failed", fmt.Errorf("read dir %s: %w", target, err)), nil
	}
	if len(entries) > policy.maxEntries {
		return webspaceFileFailure(job.ID, "size_limit_exceeded", fmt.Errorf("directory entry limit exceeded")), nil
	}

	fileEntries := make([]webspaceFileEntry, 0, len(entries))
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil {
			return webspaceFileFailure(job.ID, "fs_stat_failed", fmt.Errorf("stat %s: %w", entry.Name(), err)), nil
		}
		fileEntries = append(fileEntries, webspaceFileEntry{name: entry.Name(), size: info.Size(), mode: info.Mode(), modifiedAt: info.ModTime(), isDir: entry.IsDir()})
	}

	if err := ensureWebspaceFileTimeout(startedAt, policy.timeout); err != nil {
		return webspaceFileFailure(job.ID, "operation_timeout", err), nil
	}

	sort.Slice(fileEntries, func(i, j int) bool {
		if fileEntries[i].isDir != fileEntries[j].isDir {
			return fileEntries[i].isDir
		}
		return strings.ToLower(fileEntries[i].name) < strings.ToLower(fileEntries[j].name)
	})

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"root_path": root, "path": relativePath, "entries": encodeWebspaceEntries(fileEntries)}, Completed: time.Now().UTC()}, nil
}

func handleWebspaceFileRead(job jobs.Job) (jobs.Result, func() error) {
	startedAt := time.Now()
	root, relativePath, policy, release, errResult, ok := prepareWebspaceFileOperation(job, true)
	if !ok {
		return errResult, nil
	}
	defer release()
	filename := payloadValue(job.Payload, "name", "file")
	if filename == "" {
		return webspaceFileFailure(job.ID, "invalid_payload", fmt.Errorf("missing required values: name")), nil
	}

	target, err := sanitizeWebspacePath(root, filepath.Join(relativePath, filename))
	if err != nil {
		return webspaceFileFailure(job.ID, "path_invalid", err), nil
	}
	info, err := os.Stat(target)
	if err != nil {
		return webspaceFileFailure(job.ID, "fs_stat_failed", fmt.Errorf("stat %s: %w", target, err)), nil
	}
	if info.IsDir() {
		return webspaceFileFailure(job.ID, "path_invalid", fmt.Errorf("path is a directory")), nil
	}
	if info.Size() > int64(policy.maxBytes) {
		return webspaceFileFailure(job.ID, "size_limit_exceeded", fmt.Errorf("file exceeds max_bytes")), nil
	}

	content, err := os.ReadFile(target)
	if err != nil {
		return webspaceFileFailure(job.ID, "fs_read_failed", fmt.Errorf("read file %s: %w", target, err)), nil
	}
	if err := ensureWebspaceFileTimeout(startedAt, policy.timeout); err != nil {
		return webspaceFileFailure(job.ID, "operation_timeout", err), nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"root_path": root, "path": relativePath, "name": filename, "content_base64": base64.StdEncoding.EncodeToString(content), "size": strconv.FormatInt(info.Size(), 10), "modified_at": info.ModTime().UTC().Format(webspaceFilesTimeFormat), "mode": info.Mode().String()}, Completed: time.Now().UTC()}, nil
}

func handleWebspaceFileWrite(job jobs.Job) (jobs.Result, func() error) {
	startedAt := time.Now()
	root, relativePath, policy, release, errResult, ok := prepareWebspaceFileOperation(job, false)
	if !ok {
		return errResult, nil
	}
	defer release()
	filename := payloadValue(job.Payload, "name", "file")
	contentEncoded := payloadValue(job.Payload, "content_base64", "content")
	if filename == "" {
		return webspaceFileFailure(job.ID, "invalid_payload", fmt.Errorf("missing required values: name")), nil
	}

	content, err := base64.StdEncoding.DecodeString(contentEncoded)
	if err != nil {
		return webspaceFileFailure(job.ID, "invalid_payload", fmt.Errorf("decode content: %w", err)), nil
	}
	if len(content) > policy.maxBytes {
		return webspaceFileFailure(job.ID, "size_limit_exceeded", fmt.Errorf("content exceeds max_bytes")), nil
	}

	target, err := sanitizeWebspacePath(root, filepath.Join(relativePath, filename))
	if err != nil {
		return webspaceFileFailure(job.ID, "path_invalid", err), nil
	}
	if _, err := os.Stat(filepath.Dir(target)); err != nil {
		return webspaceFileFailure(job.ID, "path_invalid", fmt.Errorf("parent directory missing: %w", err)), nil
	}
	if err := os.WriteFile(target, content, webspaceFilesFileMode); err != nil {
		return webspaceFileFailure(job.ID, "fs_write_failed", fmt.Errorf("write file %s: %w", target, err)), nil
	}
	if err := ensureWebspaceFileTimeout(startedAt, policy.timeout); err != nil {
		return webspaceFileFailure(job.ID, "operation_timeout", err), nil
	}
	info, err := os.Stat(target)
	if err != nil {
		return webspaceFileFailure(job.ID, "fs_stat_failed", fmt.Errorf("stat %s: %w", target, err)), nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"root_path": root, "path": relativePath, "name": filename, "size": strconv.FormatInt(info.Size(), 10), "modified_at": info.ModTime().UTC().Format(webspaceFilesTimeFormat), "mode": info.Mode().String()}, Completed: time.Now().UTC()}, nil
}

func handleWebspaceFileDelete(job jobs.Job) (jobs.Result, func() error) {
	root, relativePath, _, release, errResult, ok := prepareWebspaceFileOperation(job, false)
	if !ok {
		return errResult, nil
	}
	defer release()
	filename := payloadValue(job.Payload, "name", "file")
	if filename == "" {
		return webspaceFileFailure(job.ID, "invalid_payload", fmt.Errorf("missing required values: name")), nil
	}
	targetRelative := filepath.Join(relativePath, filename)
	if strings.TrimSpace(targetRelative) == "" || targetRelative == "." {
		return webspaceFileFailure(job.ID, "path_invalid", fmt.Errorf("refusing to delete root")), nil
	}

	target, err := sanitizeWebspacePath(root, targetRelative)
	if err != nil {
		return webspaceFileFailure(job.ID, "path_invalid", err), nil
	}
	info, err := os.Stat(target)
	if err != nil {
		return webspaceFileFailure(job.ID, "fs_stat_failed", fmt.Errorf("stat %s: %w", target, err)), nil
	}
	if info.IsDir() {
		if err := os.RemoveAll(target); err != nil {
			return webspaceFileFailure(job.ID, "fs_write_failed", fmt.Errorf("remove dir %s: %w", target, err)), nil
		}
	} else if err := os.Remove(target); err != nil {
		return webspaceFileFailure(job.ID, "fs_write_failed", fmt.Errorf("remove file %s: %w", target, err)), nil
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"root_path": root, "path": relativePath, "name": filename}, Completed: time.Now().UTC()}, nil
}

func handleWebspaceFileMkdir(job jobs.Job) (jobs.Result, func() error) {
	root, relativePath, _, release, errResult, ok := prepareWebspaceFileOperation(job, false)
	if !ok {
		return errResult, nil
	}
	defer release()
	dirName := payloadValue(job.Payload, "name", "directory")
	if dirName == "" {
		return webspaceFileFailure(job.ID, "invalid_payload", fmt.Errorf("missing required values: name")), nil
	}

	target, err := sanitizeWebspacePath(root, filepath.Join(relativePath, dirName))
	if err != nil {
		return webspaceFileFailure(job.ID, "path_invalid", err), nil
	}
	if err := os.MkdirAll(target, 0o750); err != nil {
		return webspaceFileFailure(job.ID, "fs_write_failed", fmt.Errorf("create directory %s: %w", target, err)), nil
	}
	info, err := os.Stat(target)
	if err != nil {
		return webspaceFileFailure(job.ID, "fs_stat_failed", fmt.Errorf("stat %s: %w", target, err)), nil
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"root_path": root, "path": relativePath, "name": dirName, "modified_at": info.ModTime().UTC().Format(webspaceFilesTimeFormat), "mode": info.Mode().String()}, Completed: time.Now().UTC()}, nil
}

func prepareWebspaceFileOperation(job jobs.Job, readOnly bool) (string, string, webspaceFilePolicy, func(), jobs.Result, bool) {
	rootPath := payloadValue(job.Payload, "root_path", "web_root")
	relativePath := payloadValue(job.Payload, "path", "dir")
	if rootPath == "" {
		return "", "", webspaceFilePolicy{}, func() {}, webspaceFileFailureNow(job.ID, "invalid_payload", "missing required values: root_path"), false
	}
	root, err := filepath.Abs(filepath.Clean(rootPath))
	if err != nil {
		return "", "", webspaceFilePolicy{}, func() {}, webspaceFileFailureNow(job.ID, "path_invalid", "resolve root path"), false
	}
	policy := parseWebspaceFilePolicy(job.Payload)
	if err := ensureWebspaceFileACL(policy.acl, readOnly); err != nil {
		return "", "", webspaceFilePolicy{}, func() {}, webspaceFileFailureNow(job.ID, "acl_denied", err.Error()), false
	}
	release, err := lockWebspaceFiles(job.Payload, root)
	if err != nil {
		return "", "", webspaceFilePolicy{}, func() {}, webspaceFileFailureNow(job.ID, "webspace_action_in_progress", err.Error()), false
	}
	return root, relativePath, policy, release, jobs.Result{}, true
}

func parseWebspaceFilePolicy(payload map[string]any) webspaceFilePolicy {
	timeout := parseIntDefault(payloadValue(payload, "timeout_ms"), int(webspaceFilesDefaultTimeout.Milliseconds()))
	if timeout <= 0 {
		timeout = int(webspaceFilesDefaultTimeout.Milliseconds())
	}
	if timeout > int(webspaceFilesMaxTimeout.Milliseconds()) {
		timeout = int(webspaceFilesMaxTimeout.Milliseconds())
	}
	maxBytes := parseIntDefault(payloadValue(payload, "max_bytes"), webspaceFilesDefaultMaxBytes)
	if maxBytes <= 0 {
		maxBytes = webspaceFilesDefaultMaxBytes
	}
	if maxBytes > webspaceFilesAbsoluteMaxBytes {
		maxBytes = webspaceFilesAbsoluteMaxBytes
	}
	maxEntries := parseIntDefault(payloadValue(payload, "max_entries"), webspaceFilesDefaultEntryLimit)
	if maxEntries <= 0 {
		maxEntries = webspaceFilesDefaultEntryLimit
	}
	return webspaceFilePolicy{acl: strings.ToLower(strings.TrimSpace(payloadValue(payload, "acl", "file_acl"))), timeout: time.Duration(timeout) * time.Millisecond, maxBytes: maxBytes, maxEntries: maxEntries}
}

func ensureWebspaceFileACL(acl string, readOnly bool) error {
	if acl == "" || acl == "rw" {
		return nil
	}
	if acl == "ro" && readOnly {
		return nil
	}
	return fmt.Errorf("requested file operation is not allowed")
}

func ensureWebspaceFileTimeout(start time.Time, timeout time.Duration) error {
	if time.Since(start) > timeout {
		return fmt.Errorf("operation exceeded timeout")
	}
	return nil
}

func lockWebspaceFiles(payload map[string]any, root string) (func(), error) {
	key := strings.TrimSpace(payloadValue(payload, "webspace_id"))
	if key == "" {
		key = root
	}
	lockRaw, _ := webspaceFileLocks.LoadOrStore(key, &sync.Mutex{})
	lock := lockRaw.(*sync.Mutex)
	if !lock.TryLock() {
		return nil, fmt.Errorf("webspace file operation already running")
	}
	return func() { lock.Unlock() }, nil
}

func sanitizeWebspacePath(root, relativePath string) (string, error) {
	cleanRelative := filepath.Clean("/" + relativePath)
	cleanRelative = strings.TrimPrefix(cleanRelative, "/")
	evaluatedRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", fmt.Errorf("resolve root: %w", err)
	}
	joined := filepath.Join(evaluatedRoot, cleanRelative)
	normalized := filepath.Clean(joined)
	parent := filepath.Dir(normalized)
	if parentEval, err := filepath.EvalSymlinks(parent); err == nil {
		normalized = filepath.Join(parentEval, filepath.Base(normalized))
	}
	relativeToRoot, err := filepath.Rel(evaluatedRoot, normalized)
	if err != nil {
		return "", fmt.Errorf("resolve relative path: %w", err)
	}
	if strings.HasPrefix(relativeToRoot, ".."+string(filepath.Separator)) || relativeToRoot == ".." {
		return "", fmt.Errorf("path escapes root")
	}
	return normalized, nil
}

func webspaceFileFailure(jobID, code string, err error) jobs.Result {
	return webspaceFileFailureNow(jobID, code, sanitizeOutput(err.Error()))
}

func webspaceFileFailureNow(jobID, code, message string) jobs.Result {
	return jobs.Result{JobID: jobID, Status: "failed", Output: map[string]string{"error_code": code, "error_message": sanitizeOutput(message)}, Completed: time.Now().UTC()}
}

func encodeWebspaceEntries(entries []webspaceFileEntry) string {
	encoded := make([]string, 0, len(entries))
	for _, entry := range entries {
		encoded = append(encoded, fmt.Sprintf("%s|%d|%s|%s|%t", entry.name, entry.size, entry.mode.String(), entry.modifiedAt.UTC().Format(webspaceFilesTimeFormat), entry.isDir))
	}
	return strings.Join(encoded, "\n")
}
