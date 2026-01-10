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

const instanceFilesFileMode = 0o640

type instanceFileEntry struct {
	name       string
	size       int64
	mode       os.FileMode
	modifiedAt time.Time
	isDir      bool
}

func handleInstanceFilesList(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	relativePath := payloadValue(job.Payload, "path", "dir")

	target, err := sanitizeInstancePath(instanceDir, relativePath)
	if err != nil {
		return failureResult(job.ID, err)
	}

	entries, err := os.ReadDir(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("read dir %s: %w", target, err))
	}

	fileEntries := make([]instanceFileEntry, 0, len(entries))
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("stat %s: %w", entry.Name(), err))
		}
		fileEntries = append(fileEntries, instanceFileEntry{
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
		"root_path": instanceDir,
		"path":      relativePath,
		"entries":   encodeInstanceEntries(fileEntries),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceFileRead(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	relativePath := payloadValue(job.Payload, "path", "dir")
	filename := payloadValue(job.Payload, "name", "file")

	missing := missingValues([]requiredValue{
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

	targetRelative := filepath.Join(relativePath, filename)
	target, err := sanitizeInstancePath(instanceDir, targetRelative)
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
		"root_path":      instanceDir,
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

func handleInstanceFileWrite(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	relativePath := payloadValue(job.Payload, "path", "dir")
	filename := payloadValue(job.Payload, "name", "file")
	contentEncoded := payloadValue(job.Payload, "content_base64", "content")

	missing := missingValues([]requiredValue{
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

	targetRelative := filepath.Join(relativePath, filename)
	target, err := sanitizeInstancePath(instanceDir, targetRelative)
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

	if err := os.WriteFile(target, content, instanceFilesFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write file %s: %w", target, err))
	}

	info, err := os.Stat(target)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("stat %s: %w", target, err))
	}

	output := map[string]string{
		"root_path":   instanceDir,
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

func handleInstanceFileDelete(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	relativePath := payloadValue(job.Payload, "path", "dir")
	filename := payloadValue(job.Payload, "name", "file")

	missing := missingValues([]requiredValue{
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

	targetRelative := filepath.Join(relativePath, filename)
	if strings.TrimSpace(targetRelative) == "" || targetRelative == "." {
		return failureResult(job.ID, fmt.Errorf("refusing to delete root"))
	}

	target, err := sanitizeInstancePath(instanceDir, targetRelative)
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
		"root_path": instanceDir,
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

func handleInstanceFileMkdir(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	relativePath := payloadValue(job.Payload, "path", "dir")
	dirName := payloadValue(job.Payload, "name", "directory")

	missing := missingValues([]requiredValue{
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

	targetRelative := filepath.Join(relativePath, dirName)
	target, err := sanitizeInstancePath(instanceDir, targetRelative)
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
		"root_path":   instanceDir,
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

func sanitizeInstancePath(root, relativePath string) (string, error) {
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

func encodeInstanceEntries(entries []instanceFileEntry) string {
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
