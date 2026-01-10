package main

import (
	"encoding/base64"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const webspaceFilesTimeFormat = time.RFC3339

type webspaceFileEntry struct {
	name       string
	size       int64
	mode       os.FileMode
	modifiedAt time.Time
	isDir      bool
}

const webspaceFileMode = 0o640

func handleWebspaceFilesList(job jobs.Job) (jobs.Result, func() error) {
	rootPath := payloadValue(job.Payload, "root_path", "web_root")
	relativePath := payloadValue(job.Payload, "path", "dir")

	missing := missingValues([]requiredValue{
		{key: "root_path", value: rootPath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	root, err := filepath.Abs(filepath.Clean(rootPath))
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("resolve root path: %w", err))
	}

	target, err := sanitizeWebspacePath(root, relativePath)
	if err != nil {
		return failureResult(job.ID, err)
	}

	entries, err := os.ReadDir(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("read dir %s: %w", target, err))
	}

	fileEntries := make([]webspaceFileEntry, 0, len(entries))
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("stat %s: %w", entry.Name(), err))
		}
		fileEntries = append(fileEntries, webspaceFileEntry{
			name:       entry.Name(),
			size:       info.Size(),
			mode:       info.Mode(),
			modifiedAt: info.ModTime(),
			isDir:      entry.IsDir(),
		})
	}

	sort.Slice(fileEntries, func(i, j int) bool {
		if fileEntries[i].isDir != fileEntries[j].isDir {
			return fileEntries[i].isDir
		}
		return strings.ToLower(fileEntries[i].name) < strings.ToLower(fileEntries[j].name)
	})

	output := map[string]string{
		"root_path": root,
		"path":      relativePath,
		"entries":   encodeWebspaceEntries(fileEntries),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceFileRead(job jobs.Job) (jobs.Result, func() error) {
	rootPath := payloadValue(job.Payload, "root_path", "web_root")
	relativePath := payloadValue(job.Payload, "path", "dir")
	filename := payloadValue(job.Payload, "name", "file")

	missing := missingValues([]requiredValue{
		{key: "root_path", value: rootPath},
		{key: "name", value: filename},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	root, err := filepath.Abs(filepath.Clean(rootPath))
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("resolve root path: %w", err))
	}

	targetRelative := filepath.Join(relativePath, filename)
	target, err := sanitizeWebspacePath(root, targetRelative)
	if err != nil {
		return failureResult(job.ID, err)
	}

	info, err := os.Stat(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("stat %s: %w", target, err))
	}
	if info.IsDir() {
		return failureResult(job.ID, fmt.Errorf("path is a directory"))
	}

	content, err := os.ReadFile(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("read file %s: %w", target, err))
	}

	output := map[string]string{
		"root_path":      root,
		"path":           relativePath,
		"name":           filename,
		"content_base64": base64.StdEncoding.EncodeToString(content),
		"size":           strconv.FormatInt(info.Size(), 10),
		"modified_at":    info.ModTime().UTC().Format(webspaceFilesTimeFormat),
		"mode":           info.Mode().String(),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceFileWrite(job jobs.Job) (jobs.Result, func() error) {
	rootPath := payloadValue(job.Payload, "root_path", "web_root")
	relativePath := payloadValue(job.Payload, "path", "dir")
	filename := payloadValue(job.Payload, "name", "file")
	contentEncoded := payloadValue(job.Payload, "content_base64", "content")

	missing := missingValues([]requiredValue{
		{key: "root_path", value: rootPath},
		{key: "name", value: filename},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	root, err := filepath.Abs(filepath.Clean(rootPath))
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("resolve root path: %w", err))
	}

	targetRelative := filepath.Join(relativePath, filename)
	target, err := sanitizeWebspacePath(root, targetRelative)
	if err != nil {
		return failureResult(job.ID, err)
	}

	parentDir := filepath.Dir(target)
	if _, err := os.Stat(parentDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("parent directory missing: %w", err))
	}

	content, err := base64.StdEncoding.DecodeString(contentEncoded)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("decode content: %w", err))
	}

	if err := os.WriteFile(target, content, webspaceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write file %s: %w", target, err))
	}

	info, err := os.Stat(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("stat %s: %w", target, err))
	}

	output := map[string]string{
		"root_path":   root,
		"path":        relativePath,
		"name":        filename,
		"size":        strconv.FormatInt(info.Size(), 10),
		"modified_at": info.ModTime().UTC().Format(webspaceFilesTimeFormat),
		"mode":        info.Mode().String(),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceFileDelete(job jobs.Job) (jobs.Result, func() error) {
	rootPath := payloadValue(job.Payload, "root_path", "web_root")
	relativePath := payloadValue(job.Payload, "path", "dir")
	filename := payloadValue(job.Payload, "name", "file")

	missing := missingValues([]requiredValue{
		{key: "root_path", value: rootPath},
		{key: "name", value: filename},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	root, err := filepath.Abs(filepath.Clean(rootPath))
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("resolve root path: %w", err))
	}

	targetRelative := filepath.Join(relativePath, filename)
	if strings.TrimSpace(targetRelative) == "" || targetRelative == "." {
		return failureResult(job.ID, fmt.Errorf("refusing to delete root"))
	}

	target, err := sanitizeWebspacePath(root, targetRelative)
	if err != nil {
		return failureResult(job.ID, err)
	}

	info, err := os.Stat(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("stat %s: %w", target, err))
	}

	if info.IsDir() {
		if err := os.RemoveAll(target); err != nil {
			return failureResult(job.ID, fmt.Errorf("remove dir %s: %w", target, err))
		}
	} else {
		if err := os.Remove(target); err != nil {
			return failureResult(job.ID, fmt.Errorf("remove file %s: %w", target, err))
		}
	}

	output := map[string]string{
		"root_path": root,
		"path":      relativePath,
		"name":      filename,
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleWebspaceFileMkdir(job jobs.Job) (jobs.Result, func() error) {
	rootPath := payloadValue(job.Payload, "root_path", "web_root")
	relativePath := payloadValue(job.Payload, "path", "dir")
	dirName := payloadValue(job.Payload, "name", "directory")

	missing := missingValues([]requiredValue{
		{key: "root_path", value: rootPath},
		{key: "name", value: dirName},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	root, err := filepath.Abs(filepath.Clean(rootPath))
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("resolve root path: %w", err))
	}

	targetRelative := filepath.Join(relativePath, dirName)
	target, err := sanitizeWebspacePath(root, targetRelative)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := os.MkdirAll(target, 0o750); err != nil {
		return failureResult(job.ID, fmt.Errorf("create directory %s: %w", target, err))
	}

	info, err := os.Stat(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("stat %s: %w", target, err))
	}

	output := map[string]string{
		"root_path":   root,
		"path":        relativePath,
		"name":        dirName,
		"modified_at": info.ModTime().UTC().Format(webspaceFilesTimeFormat),
		"mode":        info.Mode().String(),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func sanitizeWebspacePath(root, relativePath string) (string, error) {
	cleanRelative := filepath.Clean("/" + relativePath)
	cleanRelative = strings.TrimPrefix(cleanRelative, "/")
	joined := filepath.Join(root, cleanRelative)
	normalized := filepath.Clean(joined)
	relativeToRoot, err := filepath.Rel(root, normalized)
	if err != nil {
		return "", fmt.Errorf("resolve relative path: %w", err)
	}
	if strings.HasPrefix(relativeToRoot, ".."+string(filepath.Separator)) || relativeToRoot == ".." {
		return "", fmt.Errorf("path escapes root")
	}
	return normalized, nil
}

func encodeWebspaceEntries(entries []webspaceFileEntry) string {
	encoded := make([]string, 0, len(entries))
	for _, entry := range entries {
		encoded = append(encoded, fmt.Sprintf("%s|%d|%s|%s|%t",
			entry.name,
			entry.size,
			entry.mode.String(),
			entry.modifiedAt.UTC().Format(webspaceFilesTimeFormat),
			entry.isDir,
		))
	}
	return strings.Join(encoded, "\n")
}
