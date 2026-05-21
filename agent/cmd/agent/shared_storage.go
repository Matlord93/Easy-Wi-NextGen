package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"
)

type sharedPathSpec struct {
	Source   string
	Target   string
	Mode     string
	ReadOnly bool
}

var sensitiveSharedPathTokens = []string{
	"config", "cfg", "server.cfg", "secrets", "secret", "token", "key", "api_key", "apikey", "password", "passwd", "credential", "credentials",
	"database", "db", "sqlite", "logs", "log", "saves", "save", "worlds", "world", "users", "permissions", "whitelist", "banlist", "cache", "tmp", "temp", "rcon",
}

var templateIDPattern = regexp.MustCompile(`^[a-zA-Z0-9._-]+$`)

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
	sharedRoot := filepath.Join(defaultInstanceBaseDir(), "shared", templateSafe)
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
	if _, err := os.Stat(sharedSource); os.IsNotExist(err) {
		if err := os.MkdirAll(sharedSource, instanceDirMode); err != nil {
			return fmt.Errorf("create shared source %s: %w", sharedSource, err)
		}
	}
	targetPath := filepath.Join(instanceDir, targetRel)
	if err := ensurePathInsideRoot(instanceDir, targetPath); err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(targetPath), instanceDirMode); err != nil {
		return err
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
		empty, emptyErr := dirEmpty(targetPath)
		if emptyErr != nil {
			return emptyErr
		}
		if !empty {
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
	_, _ = lockFile.WriteString(fmt.Sprintf("%d\n", os.Getpid()))
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
