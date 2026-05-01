package main

import (
	"crypto/md5"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

type addonManifest struct {
	PluginID    string   `json:"plugin_id"`
	Name        string   `json:"name"`
	Version     string   `json:"version"`
	InstalledAt string   `json:"installed_at"`
	Entries     []string `json:"entries"`
}

func handleInstanceAddonInstall(job jobs.Job) (jobs.Result, func() error) {
	return handleInstanceAddonInstallUpdate(job, false)
}

func handleInstanceAddonUpdate(job jobs.Job) (jobs.Result, func() error) {
	return handleInstanceAddonInstallUpdate(job, true)
}

func handleInstanceAddonRemove(job jobs.Job) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	pluginID := payloadValue(job.Payload, "plugin_id")
	if pluginID == "" {
		return failureResult(job.ID, fmt.Errorf("missing required values: plugin_id"))
	}
	manifestPath := addonManifestPath(instanceDir, pluginID)
	manifest, err := readAddonManifest(manifestPath)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := removeAddonEntries(instanceDir, manifest.Entries); err != nil {
		return failureResult(job.ID, err)
	}
	_ = os.Remove(manifestPath)

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":        "addon removed",
			"plugin_id":      manifest.PluginID,
			"plugin_name":    manifest.Name,
			"plugin_version": manifest.Version,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceAddonInstallUpdate(job jobs.Job, isUpdate bool) (jobs.Result, func() error) {
	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	pluginID := payloadValue(job.Payload, "plugin_id")
	pluginName := payloadValue(job.Payload, "plugin_name")
	pluginVersion := payloadValue(job.Payload, "plugin_version")
	downloadURL := payloadValue(job.Payload, "plugin_download_url", "download_url")
	checksum := payloadValue(job.Payload, "plugin_checksum", "checksum")
	extractSubdir := payloadValue(job.Payload, "plugin_extract_subdir")
	installMode := payloadValue(job.Payload, "plugin_install_mode")
	if installMode == "" {
		installMode = "extract"
	}

	missing := missingValues([]requiredValue{
		{key: "plugin_id", value: pluginID},
		{key: "plugin_download_url", value: downloadURL},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	// Resolve the destination directory (instanceDir or instanceDir/subdir)
	destDir := instanceDir
	if extractSubdir != "" {
		cleanSubdir := filepath.Clean(extractSubdir)
		// Reject subdirs that try to escape the instance directory
		if strings.HasPrefix(cleanSubdir, "..") {
			return failureResult(job.ID, fmt.Errorf("invalid extract_subdir: %q", extractSubdir))
		}
		destDir = filepath.Join(instanceDir, cleanSubdir)
	}

	if isUpdate {
		manifestPath := addonManifestPath(instanceDir, pluginID)
		manifest, err := readAddonManifest(manifestPath)
		if err == nil {
			if err := removeAddonEntries(instanceDir, manifest.Entries); err != nil {
				return failureResult(job.ID, err)
			}
			_ = os.Remove(manifestPath)
		}
	}

	tempDir, err := os.MkdirTemp("", "easywi-addon-")
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("create temp dir: %w", err))
	}
	defer func() {
		_ = os.RemoveAll(tempDir)
	}()

	archivePath, err := downloadArchiveForInstall(tempDir, downloadURL, "", "addon.zip")
	if err != nil {
		return failureResult(job.ID, err)
	}

	if checksum != "" {
		if err := verifyChecksum(archivePath, checksum); err != nil {
			return failureResult(job.ID, err)
		}
	}

	var entries []string

	if installMode == "copy" {
		// Copy the downloaded file directly into destDir using the URL basename as filename
		filename := filepath.Base(downloadURL)
		if filename == "" || filename == "." || filename == "/" {
			filename = pluginID + ".jar"
		}
		if err := os.MkdirAll(destDir, 0o755); err != nil {
			return failureResult(job.ID, fmt.Errorf("create addon dest dir: %w", err))
		}
		destFile := filepath.Join(destDir, filename)
		if err := copyAddonFile(archivePath, destFile); err != nil {
			return failureResult(job.ID, err)
		}
		relPath := filename
		if extractSubdir != "" {
			relPath = filepath.Join(filepath.Clean(extractSubdir), filename)
		}
		entries = []string{relPath}
	} else {
		entries, err = listArchiveEntries(archivePath, downloadURL)
		if err != nil {
			return failureResult(job.ID, err)
		}

		if err := extractArchiveWithoutStrip(archivePath, downloadURL, destDir); err != nil {
			return failureResult(job.ID, err)
		}

		// Prefix entries with extractSubdir so manifests reflect actual paths from instanceDir
		if extractSubdir != "" {
			cleanSubdir := filepath.Clean(extractSubdir)
			prefixed := make([]string, len(entries))
			for i, e := range entries {
				prefixed[i] = filepath.Join(cleanSubdir, e)
			}
			entries = prefixed
		}
	}

	if err := chownAddonEntries(instanceDir, job.Payload, entries); err != nil {
		return failureResult(job.ID, err)
	}

	manifest := addonManifest{
		PluginID:    pluginID,
		Name:        pluginName,
		Version:     pluginVersion,
		InstalledAt: time.Now().UTC().Format(time.RFC3339),
		Entries:     entries,
	}
	if err := writeAddonManifest(instanceDir, pluginID, manifest); err != nil {
		return failureResult(job.ID, err)
	}

	action := "addon installed"
	if isUpdate {
		action = "addon updated"
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"message":        action,
			"plugin_id":      pluginID,
			"plugin_name":    pluginName,
			"plugin_version": pluginVersion,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func addonManifestPath(instanceDir, pluginID string) string {
	return filepath.Join(instanceDir, ".easywi", "addons", fmt.Sprintf("%s.json", pluginID))
}

func writeAddonManifest(instanceDir, pluginID string, manifest addonManifest) error {
	manifestDir := filepath.Join(instanceDir, ".easywi", "addons")
	if err := os.MkdirAll(manifestDir, 0o750); err != nil {
		return fmt.Errorf("create addon manifest dir: %w", err)
	}
	data, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		return fmt.Errorf("encode addon manifest: %w", err)
	}
	manifestPath := addonManifestPath(instanceDir, pluginID)
	if err := os.WriteFile(manifestPath, data, 0o640); err != nil {
		return fmt.Errorf("write addon manifest: %w", err)
	}
	return nil
}

func readAddonManifest(path string) (addonManifest, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return addonManifest{}, fmt.Errorf("read addon manifest: %w", err)
	}
	var manifest addonManifest
	if err := json.Unmarshal(data, &manifest); err != nil {
		return addonManifest{}, fmt.Errorf("decode addon manifest: %w", err)
	}
	return manifest, nil
}

func listArchiveEntries(archivePath, downloadURL string) ([]string, error) {
	archiveLower := strings.ToLower(archivePath)
	downloadLower := strings.ToLower(downloadURL)
	var output string
	var err error
	if strings.HasSuffix(archiveLower, ".zip") || strings.HasSuffix(downloadLower, ".zip") {
		output, err = runCommandOutput("unzip", "-Z1", archivePath)
	} else {
		output, err = runCommandOutput("tar", "-tf", archivePath)
	}
	if err != nil {
		return nil, err
	}
	entries := []string{}
	for _, line := range strings.Split(strings.TrimSpace(output), "\n") {
		entry := strings.TrimSpace(line)
		if entry == "" {
			continue
		}
		entry = strings.TrimPrefix(entry, "./")
		entry = strings.TrimPrefix(entry, "/")
		entries = append(entries, entry)
	}
	sort.Strings(entries)
	return entries, nil
}

func verifyChecksum(path, expected string) (err error) {
	normalized := strings.ToLower(strings.TrimSpace(expected))
	// Strip algorithm prefix (e.g. "sha256:", "sha1:", "md5:")
	if idx := strings.Index(normalized, ":"); idx != -1 {
		normalized = normalized[idx+1:]
	}
	var algo string
	switch len(normalized) {
	case 32:
		algo = "md5"
	case 40:
		algo = "sha1"
	case 64:
		algo = "sha256"
	default:
		return fmt.Errorf("unsupported checksum length")
	}
	file, err := os.Open(path)
	if err != nil {
		return fmt.Errorf("open archive for checksum: %w", err)
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close archive for checksum: %w", closeErr)
		}
	}()

	var sum string
	switch algo {
	case "md5":
		hash := md5.New()
		if _, err := io.Copy(hash, file); err != nil {
			return fmt.Errorf("checksum read: %w", err)
		}
		sum = hex.EncodeToString(hash.Sum(nil))
	case "sha1":
		hash := sha1.New()
		if _, err := io.Copy(hash, file); err != nil {
			return fmt.Errorf("checksum read: %w", err)
		}
		sum = hex.EncodeToString(hash.Sum(nil))
	case "sha256":
		hash := sha256.New()
		if _, err := io.Copy(hash, file); err != nil {
			return fmt.Errorf("checksum read: %w", err)
		}
		sum = hex.EncodeToString(hash.Sum(nil))
	}

	if sum != normalized {
		return fmt.Errorf("checksum mismatch")
	}
	return nil
}

func removeAddonEntries(instanceDir string, entries []string) error {
	if len(entries) == 0 {
		return nil
	}
	paths := make([]string, 0, len(entries))
	dirSet := make(map[string]struct{})
	for _, entry := range entries {
		normalized := strings.TrimSpace(entry)
		if normalized == "" {
			continue
		}
		target, err := sanitizeInstancePath(instanceDir, normalized)
		if err != nil {
			return err
		}
		paths = append(paths, target)
		dirSet[filepath.Dir(target)] = struct{}{}
	}
	sort.Strings(paths)
	for _, target := range paths {
		if err := os.RemoveAll(target); err != nil && !os.IsNotExist(err) {
			return fmt.Errorf("remove addon entry %s: %w", target, err)
		}
	}
	dirs := make([]string, 0, len(dirSet))
	for dir := range dirSet {
		dirs = append(dirs, dir)
	}
	sort.Slice(dirs, func(i, j int) bool {
		return len(dirs[i]) > len(dirs[j])
	})
	for _, dir := range dirs {
		if dir == instanceDir {
			continue
		}
		if err := os.Remove(dir); err != nil && !os.IsNotExist(err) {
			if !os.IsNotExist(err) && !strings.Contains(err.Error(), "directory not empty") {
				return fmt.Errorf("remove addon dir %s: %w", dir, err)
			}
		}
	}
	return nil
}

func copyAddonFile(src, dst string) (err error) {
	in, err := os.Open(src)
	if err != nil {
		return fmt.Errorf("open addon source: %w", err)
	}
	defer func() {
		if closeErr := in.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close addon source: %w", closeErr)
		}
	}()
	out, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
	if err != nil {
		return fmt.Errorf("create addon dest: %w", err)
	}
	defer func() {
		if closeErr := out.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close addon dest: %w", closeErr)
		}
	}()
	if _, err := io.Copy(out, in); err != nil {
		return fmt.Errorf("copy addon file: %w", err)
	}
	return nil
}

func chownAddonEntries(instanceDir string, payload map[string]any, entries []string) error {
	instanceID := payloadValue(payload, "instance_id")
	customerID := payloadValue(payload, "customer_id")
	if instanceID == "" || customerID == "" {
		return nil
	}
	osUsername := buildInstanceUsername(customerID, instanceID)
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return fmt.Errorf("lookup addon owner: %w", err)
	}
	roots := make(map[string]struct{})
	for _, entry := range entries {
		normalized := strings.TrimSpace(entry)
		if normalized == "" {
			continue
		}
		parts := strings.Split(normalized, string(filepath.Separator))
		root := parts[0]
		if root == "" || root == "." {
			continue
		}
		roots[root] = struct{}{}
	}
	for root := range roots {
		target, err := sanitizeInstancePath(instanceDir, root)
		if err != nil {
			return err
		}
		if err := os.Chown(target, uid, gid); err != nil {
			return fmt.Errorf("chown %s: %w", target, err)
		}
		if err := chownRecursive(target, uid, gid); err != nil {
			return err
		}
	}
	return nil
}
