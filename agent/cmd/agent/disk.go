package main

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	diskScanTimeout = 30 * time.Second
	diskStatTimeout = 10 * time.Second
	diskTopTimeout  = 45 * time.Second
)

type diskEntry struct {
	Size int64
	Path string
}

func handleInstanceDiskScan(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}

	output, err := runCommandOutputWithTimeout(diskScanTimeout, "du", "-sb", instanceDir)
	if err != nil {
		return failureResult(job.ID, err)
	}

	fields := strings.Fields(output)
	if len(fields) == 0 {
		return failureResult(job.ID, fmt.Errorf("unexpected du output"))
	}
	usedBytes, err := strconv.ParseInt(fields[0], 10, 64)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("parse du output: %w", err))
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"used_bytes": strconv.FormatInt(usedBytes, 10),
			"path":       instanceDir,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceDiskTop(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	limitValue := payloadValue(job.Payload, "limit")
	limit := 10
	if limitValue != "" {
		if parsed, err := strconv.Atoi(limitValue); err == nil && parsed > 0 {
			limit = parsed
		}
	}

	output, err := runCommandOutputWithTimeout(diskTopTimeout, "du", "-ab", "--max-depth=2", instanceDir)
	if err != nil {
		return failureResult(job.ID, err)
	}

	entries := parseDiskEntries(output)
	entries = filterInstanceEntries(entries, instanceDir)
	sort.Slice(entries, func(i, j int) bool {
		return entries[i].Size > entries[j].Size
	})
	if len(entries) > limit {
		entries = entries[:limit]
	}

	var builder strings.Builder
	for _, entry := range entries {
		builder.WriteString(strconv.FormatInt(entry.Size, 10))
		builder.WriteString("|")
		builder.WriteString(entry.Path)
		builder.WriteString("\n")
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"entries": builder.String(),
			"path":    instanceDir,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleNodeDiskStat(job jobs.Job) (jobs.Result, func() error) {
	baseDir := payloadValue(job.Payload, "base_dir")
	if baseDir == "" {
		baseDir = os.Getenv("EASYWI_INSTANCE_BASE_DIR")
	}
	if baseDir == "" {
		baseDir = "/home"
	}
	cleanBase, err := ensureSafePath(baseDir, baseDir)
	if err != nil {
		return failureResult(job.ID, err)
	}

	output, err := runCommandOutputWithTimeout(diskStatTimeout, "df", "-B1", "--output=avail,pcent", cleanBase)
	if err != nil {
		return failureResult(job.ID, err)
	}
	lines := strings.Split(strings.TrimSpace(output), "\n")
	if len(lines) < 2 {
		return failureResult(job.ID, fmt.Errorf("unexpected df output"))
	}
	fields := strings.Fields(lines[len(lines)-1])
	if len(fields) < 2 {
		return failureResult(job.ID, fmt.Errorf("unexpected df output"))
	}
	freeBytes, err := strconv.ParseInt(fields[0], 10, 64)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("parse df output: %w", err))
	}
	usedPercentRaw := strings.TrimSpace(strings.TrimSuffix(fields[1], "%"))
	usedPercent, err := strconv.ParseFloat(usedPercentRaw, 64)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("parse df percent: %w", err))
	}
	freePercent := 100 - usedPercent

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"free_bytes":   strconv.FormatInt(freeBytes, 10),
			"free_percent": fmt.Sprintf("%.2f", freePercent),
			"path":         cleanBase,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func resolveInstanceDir(payload map[string]any) (string, error) {
	instanceDir := payloadValue(payload, "instance_dir")
	baseDir := payloadValue(payload, "base_dir")
	if baseDir == "" {
		baseDir = os.Getenv("EASYWI_INSTANCE_BASE_DIR")
	}
	if baseDir == "" {
		baseDir = "/home"
	}
	if instanceDir == "" {
		instanceID := payloadValue(payload, "instance_id")
		customerID := payloadValue(payload, "customer_id")
		if instanceID == "" || customerID == "" {
			return "", fmt.Errorf("instance_dir or instance identifiers required")
		}
		osUsername := buildInstanceUsername(customerID, instanceID)
		instanceDir = filepath.Join(baseDir, osUsername)
	}

	return ensureSafePath(instanceDir, baseDir)
}

func ensureSafePath(path, baseDir string) (string, error) {
	cleanPath := filepath.Clean(path)
	cleanBase := filepath.Clean(baseDir)
	if !filepath.IsAbs(cleanPath) {
		return "", fmt.Errorf("path must be absolute")
	}
	if !filepath.IsAbs(cleanBase) {
		return "", fmt.Errorf("base dir must be absolute")
	}
	rel, err := filepath.Rel(cleanBase, cleanPath)
	if err != nil {
		return "", fmt.Errorf("resolve path: %w", err)
	}
	if rel == "." {
		return cleanPath, nil
	}
	if strings.HasPrefix(rel, "..") {
		return "", fmt.Errorf("path escapes base dir")
	}
	return cleanPath, nil
}

func runCommandOutputWithTimeout(timeout time.Duration, name string, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, name, args...)
	output, err := cmd.CombinedOutput()
	if ctx.Err() == context.DeadlineExceeded {
		return string(output), fmt.Errorf("%s %s timed out", name, strings.Join(args, " "))
	}
	if err != nil {
		return string(output), fmt.Errorf("%s %s failed: %w (%s)", name, strings.Join(args, " "), err, strings.TrimSpace(string(output)))
	}
	return string(output), nil
}

func parseDiskEntries(output string) []diskEntry {
	var entries []diskEntry
	for _, line := range strings.Split(strings.TrimSpace(output), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		size, err := strconv.ParseInt(fields[0], 10, 64)
		if err != nil {
			continue
		}
		path := strings.Join(fields[1:], " ")
		entries = append(entries, diskEntry{Size: size, Path: path})
	}
	return entries
}

func filterInstanceEntries(entries []diskEntry, basePath string) []diskEntry {
	filtered := make([]diskEntry, 0, len(entries))
	for _, entry := range entries {
		if entry.Path == basePath {
			continue
		}
		filtered = append(filtered, entry)
	}
	return filtered
}
