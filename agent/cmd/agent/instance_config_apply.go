package main

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleInstanceConfigApply(job jobs.Job) (jobs.Result, func() error) {
	requestID := payloadValue(job.Payload, "request_id")
	installPath := strings.TrimSpace(payloadValue(job.Payload, "install_path", "instance_root", "root_path"))
	baseDir := strings.TrimSpace(payloadValue(job.Payload, "base_dir"))
	osType := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "os_type")))
	if osType == "" {
		osType = runtimeOSType()
	}

	files, err := configApplyFilesFromPayload(job.Payload)
	if err != nil {
		return failureResultWithCode(job.ID, "INVALID_INPUT", err, requestID, "")
	}
	if installPath == "" || len(files) == 0 {
		return failureResultWithCode(job.ID, "INVALID_INPUT", fmt.Errorf("install_path and files are required"), requestID, "")
	}

	root, err := normalizeConfigPathForOS(installPath, osType)
	if err != nil {
		return failureResultWithCode(job.ID, "ROOT_INVALID", err, requestID, "")
	}
	if baseDir != "" {
		normalizedBase, err := normalizeConfigPathForOS(baseDir, osType)
		if err != nil || !configPathWithinRoot(root, normalizedBase, osType) {
			return failureResultWithCode(job.ID, "ROOT_INVALID", fmt.Errorf("install_path must be inside base_dir"), requestID, "")
		}
	}

	written := make([]map[string]string, 0, len(files))
	for _, file := range files {
		target, err := resolveConfigApplyRelativePath(root, file.Path, osType)
		if err != nil {
			return failureResultWithCode(job.ID, mapConfigErr(err), err, requestID, "")
		}
		if len(file.Content) > configApplyMaxBytes {
			return failureResultWithCode(job.ID, "FILE_TOO_LARGE", fmt.Errorf("content exceeds 256KB"), requestID, "")
		}
		if strings.ContainsRune(string(file.Content), '\x00') {
			return failureResultWithCode(job.ID, "BINARY_NOT_ALLOWED", fmt.Errorf("binary content not allowed"), requestID, "")
		}

		stats, err := writeConfigAtomicallyForOS(target, file.Content, file.Backup, osType)
		if err != nil {
			return failureResultWithCode(job.ID, mapConfigErr(err), err, requestID, "")
		}
		written = append(written, map[string]string{
			"path":        file.Path,
			"target":      target,
			"bytes":       fmt.Sprintf("%d", stats.Bytes),
			"backup_path": stats.BackupPath,
			"checksum":    stats.Checksum,
		})
	}

	writtenJSON, _ := json.Marshal(written)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":       "Config apply completed.",
			"apply_mode":    payloadValue(job.Payload, "apply_mode"),
			"files_written": string(writtenJSON),
			"written_at":    time.Now().UTC().Format(time.RFC3339),
			"request_id":    requestID,
		},
		Completed: time.Now().UTC(),
	}, nil
}

type configApplyFilePayload struct {
	Path    string
	Content []byte
	Backup  bool
}

func configApplyFilesFromPayload(payload map[string]any) ([]configApplyFilePayload, error) {
	rawFiles, ok := payload["files"].([]any)
	if !ok || len(rawFiles) == 0 {
		legacyPath := strings.TrimSpace(payloadValue(payload, "file_path", "path"))
		legacyContent := payloadValue(payload, "content")
		if legacyPath == "" || legacyContent == "" {
			return nil, fmt.Errorf("files must contain at least one config file")
		}
		return []configApplyFilePayload{{Path: legacyPath, Content: []byte(legacyContent), Backup: true}}, nil
	}

	files := make([]configApplyFilePayload, 0, len(rawFiles))
	for _, raw := range rawFiles {
		entry, ok := raw.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("invalid files entry")
		}
		pathValue := strings.TrimSpace(asString(entry["path"]))
		content := []byte(asString(entry["content"]))
		if encoded := strings.TrimSpace(asString(entry["content_base64"])); encoded != "" {
			decoded, err := base64.StdEncoding.DecodeString(encoded)
			if err != nil {
				return nil, fmt.Errorf("invalid content_base64 for %s", pathValue)
			}
			content = decoded
		}
		if pathValue == "" {
			return nil, fmt.Errorf("file path is required")
		}
		files = append(files, configApplyFilePayload{Path: pathValue, Content: content, Backup: asBoolDefault(entry["backup"], true)})
	}

	return files, nil
}

func runtimeOSType() string {
	if runtime.GOOS == "windows" {
		return "windows"
	}

	return "linux"
}

func asBoolDefault(v any, fallback bool) bool {
	b, ok := v.(bool)
	if !ok {
		return fallback
	}

	return b
}
