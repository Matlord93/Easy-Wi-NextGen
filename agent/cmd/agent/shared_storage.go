package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"
)

type sharedPathSpec struct {
	Source   string
	Target   string
	Mode     string
	ReadOnly bool
}

type sharedManifest struct {
	SharedKey               string           `json:"shared_key"`
	TemplateID              string           `json:"template_id"`
	TemplateName            string           `json:"template_name,omitempty"`
	CreatedAt               string           `json:"created_at"`
	UpdatedAt               string           `json:"updated_at"`
	InstallCommandHash      string           `json:"install_command_hash"`
	SharedPaths             []sharedPathSpec `json:"shared_paths"`
	LastSuccessfulInstallAt string           `json:"last_successful_install_at,omitempty"`
	LastSuccessfulUpdateAt  string           `json:"last_successful_update_at,omitempty"`
	Status                  string           `json:"status"`
	FailureReason           string           `json:"failure_reason,omitempty"`
}

var sensitiveSharedPathTokens = []string{
	"config", "cfg", "server.cfg", "secrets", "secret", "token", "key", "api_key", "apikey", "password", "passwd", "credential", "credentials",
	"database", "db", "sqlite", "logs", "log", "saves", "save", "worlds", "world", "users", "permissions", "whitelist", "banlist", "cache", "tmp", "temp", "rcon",
}

var templateIDPattern = regexp.MustCompile(`^[a-zA-Z0-9._-]+$`)

func buildSharedKey(payload map[string]any) (string, error) {
	for _, k := range []string{"template_slug", "template_key", "game_key", "template_tag", "template_name", "template_id"} {
		v := strings.TrimSpace(payloadString(payload[k]))
		if v == "" {
			continue
		}
		clean := strings.ToLower(v)
		clean = regexp.MustCompile(`[^a-z0-9._-]+`).ReplaceAllString(clean, "-")
		clean = strings.Trim(clean, "-._")
		if clean != "" {
			return clean, nil
		}
	}
	return "", errors.New("SHARED_PATH_INVALID: missing template identifier for shared key")
}

func sharedRootFor(baseDir, sharedKey string) string {
	return filepath.Join(baseDir, "Shared", sharedKey)
}
func sharedServerDir(baseDir, sharedKey string) string {
	return filepath.Join(sharedRootFor(baseDir, sharedKey), "server")
}
func sharedManifestPath(baseDir, sharedKey string) string {
	return filepath.Join(sharedRootFor(baseDir, sharedKey), ".shared-manifest.json")
}
func sharedLockPath(baseDir, sharedKey string) string {
	return filepath.Join(baseDir, "Shared", ".locks", sharedKey+".lock")
}

func acquireSharedStorageLockWithTimeout(lockPath string, timeout time.Duration) (func(), error) {
	deadline := time.Now().Add(timeout)
	if err := os.MkdirAll(filepath.Dir(lockPath), instanceDirMode); err != nil {
		return nil, err
	}
	for {
		lockFile, err := os.OpenFile(lockPath, os.O_CREATE|os.O_EXCL|os.O_WRONLY, instanceFileMode)
		if err == nil {
			_, _ = fmt.Fprintf(lockFile, "%d\n%d\n", os.Getpid(), time.Now().UTC().Unix())
			return func() { _ = lockFile.Close(); _ = os.Remove(lockPath) }, nil
		}
		if !os.IsExist(err) {
			return nil, fmt.Errorf("SHARED_LOCK_TIMEOUT: %w", err)
		}
		if time.Now().After(deadline) {
			return nil, fmt.Errorf("SHARED_LOCK_TIMEOUT: timeout waiting for lock %s", lockPath)
		}
		_ = cleanupStaleLock(lockPath, timeout)
		time.Sleep(250 * time.Millisecond)
	}
}

func cleanupStaleLock(lockPath string, timeout time.Duration) error {
	b, err := os.ReadFile(lockPath)
	if err != nil {
		return err
	}
	parts := strings.Split(strings.TrimSpace(string(b)), "\n")
	if len(parts) < 2 {
		return nil
	}
	ts, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil {
		return nil
	}
	if time.Since(time.Unix(ts, 0)) > timeout {
		_ = os.Remove(lockPath)
	}
	return nil
}

func writeSharedManifest(path string, mf sharedManifest) error {
	mf.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	data, _ := json.MarshalIndent(mf, "", "  ")
	return os.WriteFile(path, append(data, '\n'), instanceFileMode)
}

func readSharedManifest(path string) (*sharedManifest, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var mf sharedManifest
	if err := json.Unmarshal(b, &mf); err != nil {
		return nil, err
	}
	return &mf, nil
}

func copyNonSharedFromServer(sharedServer, instanceDir string, specs []sharedPathSpec) error {
	sharedTargets := map[string]struct{}{}
	for _, s := range specs {
		t, _ := validateSharedRelativePath(s.Target)
		sharedTargets[t] = struct{}{}
	}
	return filepath.WalkDir(sharedServer, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(sharedServer, path)
		if rel == "." {
			return nil
		}
		rel = filepath.Clean(rel)
		for t := range sharedTargets {
			if rel == t || strings.HasPrefix(rel, t+string(filepath.Separator)) {
				if d.IsDir() {
					return filepath.SkipDir
				}
				return nil
			}
		}
		dst := filepath.Join(instanceDir, rel)
		if d.IsDir() {
			return os.MkdirAll(dst, instanceDirMode)
		}
		if _, err := os.Stat(dst); err == nil {
			return nil
		}
		if err := os.MkdirAll(filepath.Dir(dst), instanceDirMode); err != nil {
			return err
		}
		srcf, err := os.Open(path)
		if err != nil {
			return err
		}
		dstf, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, instanceFileMode)
		if err != nil {
			_ = srcf.Close()
			return err
		}
		_, copyErr := io.Copy(dstf, srcf)
		closeSrcErr := srcf.Close()
		closeDstErr := dstf.Close()
		if copyErr != nil {
			return copyErr
		}
		if closeSrcErr != nil {
			return closeSrcErr
		}
		return closeDstErr
	})
}

func parseSharedPathSpecs(payload map[string]any) ([]sharedPathSpec, error) {
	raw, ok := payload["shared_paths"]
	if !ok || raw == nil {
		return nil, nil
	}
	items, ok := raw.([]any)
	if !ok {
		return nil, fmt.Errorf("shared_paths must be an array")
	}
	specs := make([]sharedPathSpec, 0, len(items))
	for i, item := range items {
		entry, ok := item.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("shared_paths[%d] must be an object", i)
		}
		source := strings.TrimSpace(payloadString(entry["source"]))
		target := strings.TrimSpace(payloadString(entry["target"]))
		mode := strings.ToLower(strings.TrimSpace(payloadString(entry["mode"])))
		if mode == "" {
			mode = "symlink"
		}
		readonly := parsePayloadBool(payloadString(entry["readonly"]), false)
		if source == "" || target == "" {
			return nil, fmt.Errorf("shared_paths[%d] requires source and target", i)
		}
		specs = append(specs, sharedPathSpec{Source: source, Target: target, Mode: mode, ReadOnly: readonly})
	}
	return specs, nil
}

func applySharedPaths(instanceDir, templateID string, specs []sharedPathSpec) error {
	if len(specs) == 0 {
		return nil
	}
	if templateID == "" {
		return errors.New("template_id is required when shared_paths are configured")
	}
	templateSafe, err := validateTemplateID(templateID)
	if err != nil {
		return err
	}
	instanceSafe, err := filepath.Abs(filepath.Clean(instanceDir))
	if err != nil {
		return fmt.Errorf("resolve instance dir: %w", err)
	}
	lockRelease, err := acquireSharedStorageLock(filepath.Join(instanceSafe, ".shared-storage.lock"))
	if err != nil {
		return err
	}
	defer lockRelease()
	sharedRoot := filepath.Join(sharedStorageBaseDir(instanceSafe), "shared", templateSafe)
	if err := os.MkdirAll(sharedRoot, instanceDirMode); err != nil {
		return fmt.Errorf("create shared root: %w", err)
	}
	for _, spec := range specs {
		if err := applyOneSharedPath(instanceDir, sharedRoot, spec); err != nil {
			return err
		}
	}
	return nil
}

func sharedStorageBaseDir(instanceDir string) string {
	baseDir := strings.TrimSpace(os.Getenv("EASYWI_INSTANCE_BASE_DIR"))
	if baseDir != "" {
		return baseDir
	}
	parent := filepath.Dir(filepath.Clean(instanceDir))
	if parent == "." || parent == string(filepath.Separator) || parent == "" {
		return defaultInstanceBaseDir()
	}
	return parent
}

func applyOneSharedPath(instanceDir, sharedRoot string, spec sharedPathSpec) error {
	if !spec.ReadOnly {
		return fmt.Errorf("shared path %q rejected: readonly=false is not allowed", spec.Target)
	}
	srcRel, err := validateSharedRelativePath(spec.Source)
	if err != nil {
		return err
	}
	targetRel, err := validateSharedRelativePath(spec.Target)
	if err != nil {
		return err
	}
	if isSensitiveSharedPath(targetRel) || isSensitiveSharedPath(srcRel) {
		return fmt.Errorf("shared path %q is blocked because it appears sensitive", targetRel)
	}
	sharedSource := filepath.Join(sharedRoot, srcRel)
	if err := os.MkdirAll(filepath.Dir(sharedSource), instanceDirMode); err != nil {
		return err
	}
	targetPath := filepath.Join(instanceDir, targetRel)
	if err := ensurePathInsideRoot(instanceDir, targetPath); err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(targetPath), instanceDirMode); err != nil {
		return err
	}

	sharedExists := false
	if _, err := os.Stat(sharedSource); err == nil {
		sharedExists = true
	} else if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("stat shared source %s: %w", sharedSource, err)
	}

	targetInfo, targetInfoErr := os.Lstat(targetPath)
	targetExists := targetInfoErr == nil
	if targetInfoErr != nil && !os.IsNotExist(targetInfoErr) {
		return fmt.Errorf("stat target path %s: %w", targetPath, targetInfoErr)
	}

	// Seed shared storage from existing instance data on first migration.
	// This avoids forcing a full reinstall download when the instance already has the assets locally.
	// Never seed from an existing symlink: a mismatched symlink must be rejected below.
	if !sharedExists && targetExists && targetInfo.Mode()&os.ModeSymlink == 0 {
		hasContent, contentErr := pathHasContent(targetPath)
		if contentErr != nil {
			return fmt.Errorf("stat target path %s: %w", targetPath, contentErr)
		}
		if hasContent {
			if err := os.Rename(targetPath, sharedSource); err != nil {
				return fmt.Errorf("seed shared source %s from %s: %w", sharedSource, targetPath, err)
			}
			sharedExists = true
		}
	}

	if !sharedExists {
		if err := os.MkdirAll(sharedSource, instanceDirMode); err != nil {
			return fmt.Errorf("create shared source %s: %w", sharedSource, err)
		}
	}

	if info, err := os.Lstat(targetPath); err == nil {
		if info.Mode()&os.ModeSymlink != 0 {
			if existing, readErr := os.Readlink(targetPath); readErr == nil {
				resolvedExisting := existing
				if !filepath.IsAbs(existing) {
					resolvedExisting = filepath.Join(filepath.Dir(targetPath), existing)
				}
				if filepath.Clean(resolvedExisting) == filepath.Clean(sharedSource) {
					return nil
				}
				return fmt.Errorf("target %s is a symlink to %s (expected %s); refusing to replace automatically", targetPath, resolvedExisting, sharedSource)
			}
		}
		hasContent, contentErr := pathHasContent(targetPath)
		if contentErr != nil {
			return contentErr
		}
		if hasContent {
			backup, backupErr := uniqueBackupPath(targetPath, ".instance-backup")
			if backupErr != nil {
				return backupErr
			}
			if err := os.Rename(targetPath, backup); err != nil {
				return fmt.Errorf("backup existing target %s: %w", targetPath, err)
			}
		} else {
			if err := os.RemoveAll(targetPath); err != nil {
				return fmt.Errorf("remove empty target %s: %w", targetPath, err)
			}
		}
	}
	return linkPath(sharedSource, targetPath, spec.Mode)
}

func linkPath(source, target, mode string) error {
	switch mode {
	case "symlink", "":
		if runtime.GOOS == "windows" {
			return os.Symlink(source, target)
		}
		return os.Symlink(source, target)
	default:
		return fmt.Errorf("unsupported shared path mode %q", mode)
	}
}

func validateSharedRelativePath(path string) (string, error) {
	if path == "" {
		return "", errors.New("path is empty")
	}
	clean := filepath.Clean(path)
	if strings.HasPrefix(clean, "..") || filepath.IsAbs(clean) || clean == "." {
		return "", fmt.Errorf("invalid shared path %q", path)
	}
	if strings.Contains(path, "../") || strings.Contains(path, `..\`) {
		return "", fmt.Errorf("invalid shared path %q", path)
	}
	return clean, nil
}

func validateTemplateID(templateID string) (string, error) {
	trimmed := strings.TrimSpace(templateID)
	if trimmed == "" {
		return "", errors.New("template_id is required when shared_paths are configured")
	}
	if strings.Contains(trimmed, "..") || strings.Contains(trimmed, `/`) || strings.Contains(trimmed, `\`) {
		return "", fmt.Errorf("invalid template_id %q", templateID)
	}
	if !templateIDPattern.MatchString(trimmed) {
		return "", fmt.Errorf("invalid template_id %q: allowed characters are a-z, A-Z, 0-9, ., _, -", templateID)
	}
	return trimmed, nil
}

func uniqueBackupPath(basePath, suffix string) (string, error) {
	parent := filepath.Dir(basePath)
	if err := ensurePathInsideRoot(parent, parent); err != nil {
		return "", err
	}
	ts := time.Now().UTC().UnixNano()
	for i := 0; i < 1000; i++ {
		candidate := fmt.Sprintf("%s%s.%d.%d", basePath, suffix, ts, i)
		if _, err := os.Lstat(candidate); os.IsNotExist(err) {
			if err := ensurePathInsideRoot(parent, candidate); err != nil {
				return "", err
			}
			return candidate, nil
		}
	}
	return "", fmt.Errorf("failed to generate unique backup path for %s", basePath)
}

func ensurePathInsideRoot(root, candidate string) error {
	rootAbs, err := filepath.Abs(filepath.Clean(root))
	if err != nil {
		return fmt.Errorf("resolve root %s: %w", root, err)
	}
	candidateAbs, err := filepath.Abs(filepath.Clean(candidate))
	if err != nil {
		return fmt.Errorf("resolve candidate %s: %w", candidate, err)
	}
	rel, err := filepath.Rel(rootAbs, candidateAbs)
	if err != nil {
		return fmt.Errorf("rel path check failed for %s: %w", candidate, err)
	}
	if rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return fmt.Errorf("path %s escapes allowed root %s", candidateAbs, rootAbs)
	}
	return nil
}

func acquireSharedStorageLock(lockPath string) (func(), error) {
	lockFile, err := os.OpenFile(lockPath, os.O_CREATE|os.O_EXCL|os.O_WRONLY, instanceFileMode)
	if err != nil {
		return nil, fmt.Errorf("shared storage lock busy (%s): %w", lockPath, err)
	}
	_, _ = fmt.Fprintf(lockFile, "%d\n", os.Getpid())
	return func() {
		_ = lockFile.Close()
		_ = os.Remove(lockPath)
	}, nil
}

func isSensitiveSharedPath(path string) bool {
	low := strings.ToLower(path)
	segments := strings.FieldsFunc(low, func(r rune) bool { return r == '/' || r == '\\' })
	for _, segment := range segments {
		for _, token := range sensitiveSharedPathTokens {
			if segment == token {
				return true
			}
		}
	}
	for _, token := range sensitiveSharedPathTokens {
		if low == token || strings.HasPrefix(low, token+"/") || strings.HasPrefix(low, token+`\`) {
			return true
		}
	}
	return false
}

func dirEmpty(path string) (bool, error) {
	info, err := os.Lstat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return true, nil
		}
		return false, err
	}
	if !info.IsDir() {
		return false, nil
	}
	entries, err := os.ReadDir(path)
	if err != nil {
		return false, err
	}
	return len(entries) == 0, nil
}

func pathHasContent(path string) (bool, error) {
	info, err := os.Lstat(path)
	if err != nil {
		return false, err
	}
	if !info.IsDir() {
		return true, nil
	}
	empty, err := dirEmpty(path)
	if err != nil {
		return false, err
	}
	return !empty, nil
}
