package fileapi

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

var (
	errInvalidPath             = errors.New("INVALID_PATH")
	errPathOutsideInstanceRoot = errors.New("PATH_OUTSIDE_INSTANCE_ROOT")
)

func sanitizeRelativePath(path string) string {
	clean := filepath.Clean("/" + path)
	clean = strings.TrimPrefix(clean, "/")
	clean = strings.TrimPrefix(clean, string(filepath.Separator))
	return clean
}

func sanitizeInstancePath(root, relativePath string) (string, error) {
	if err := validateRelativePathInput(relativePath); err != nil {
		if errors.Is(err, errPathOutsideInstanceRoot) {
			return "", fmt.Errorf("%w: %v", errPathOutsideInstanceRoot, err)
		}
		return "", fmt.Errorf("%w: %v", errInvalidPath, err)
	}
	cleanRelative := sanitizeRelativePath(relativePath)
	joined := filepath.Join(root, cleanRelative)
	normalized := filepath.Clean(joined)
	relativeToRoot, err := filepath.Rel(root, normalized)
	if err != nil {
		return "", fmt.Errorf("%w: resolve relative path: %v", errInvalidPath, err)
	}
	if relativeToRoot == ".." || strings.HasPrefix(relativeToRoot, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("%w: path escapes root", errPathOutsideInstanceRoot)
	}
	if err := ensurePathInsideRoot(root, normalized); err != nil {
		return "", err
	}
	return normalized, nil
}

func ensurePathInsideRoot(root, target string) error {
	rootEval, err := filepath.EvalSymlinks(root)
	if err != nil {
		return fmt.Errorf("%w: resolve root symlink: %v", errInvalidPath, err)
	}

	checkPath := target
	if _, err := os.Lstat(checkPath); err != nil {
		if !os.IsNotExist(err) {
			return fmt.Errorf("%w: lstat path: %v", errInvalidPath, err)
		}
		checkPath = filepath.Dir(checkPath)
	}

	checkEval, err := filepath.EvalSymlinks(checkPath)
	if err != nil {
		return fmt.Errorf("%w: resolve path symlink: %v", errInvalidPath, err)
	}

	rel, err := filepath.Rel(rootEval, checkEval)
	if err != nil {
		return fmt.Errorf("%w: resolve relative path: %v", errInvalidPath, err)
	}
	if rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return fmt.Errorf("%w: symlink escapes root", errPathOutsideInstanceRoot)
	}

	return nil
}

func validateRelativePathInput(path string) error {
	normalized := strings.ReplaceAll(strings.TrimSpace(path), "\\", "/")
	if normalized == "" {
		return nil
	}
	if strings.HasPrefix(normalized, "/") {
		return fmt.Errorf("%w: absolute paths are not allowed", errPathOutsideInstanceRoot)
	}
	for _, segment := range strings.Split(normalized, "/") {
		if segment == ".." {
			return fmt.Errorf("%w: path traversal is not allowed", errPathOutsideInstanceRoot)
		}
		if strings.Contains(segment, "\x00") {
			return fmt.Errorf("invalid path segment")
		}
	}
	return nil
}

// DO NOT BUILD SERVER PATHS HERE.
// File API must use the canonical install root supplied via X-Server-Root.
func validateServerRootAgainstBase(serverRoot, baseDir string) (string, error) {
	cleanRoot := filepath.Clean(strings.TrimSpace(serverRoot))
	cleanBase := filepath.Clean(strings.TrimSpace(baseDir))
	if cleanRoot == "" || !filepath.IsAbs(cleanRoot) {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	if cleanBase == "" || !filepath.IsAbs(cleanBase) {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}

	baseAbs, err := filepath.Abs(cleanBase)
	if err != nil {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	rootAbs, err := filepath.Abs(cleanRoot)
	if err != nil {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}

	baseCanonical := baseAbs
	if resolvedBase, err := filepath.EvalSymlinks(baseAbs); err == nil {
		baseCanonical = resolvedBase
	}

	rootCanonical := rootAbs
	if resolvedRoot, err := filepath.EvalSymlinks(rootAbs); err == nil {
		rootCanonical = resolvedRoot
	}

	rel, err := filepath.Rel(baseCanonical, rootCanonical)
	if err != nil {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	if rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}

	info, err := os.Stat(rootCanonical)
	if err != nil {
		if os.IsNotExist(err) {
			return "", fmt.Errorf("SERVER_ROOT_NOT_FOUND")
		}
		return "", fmt.Errorf("SERVER_ROOT_NOT_ACCESSIBLE")
	}
	if !info.IsDir() {
		return "", fmt.Errorf("SERVER_ROOT_NOT_ACCESSIBLE")
	}
	if _, err := os.ReadDir(rootCanonical); err != nil {
		return "", fmt.Errorf("SERVER_ROOT_NOT_ACCESSIBLE")
	}
	return rootCanonical, nil
}

func validateServerRootAgainstBases(serverRoot string, baseDirs []string) (string, error) {
	if len(baseDirs) == 0 {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	var lastErr error
	for _, baseDir := range baseDirs {
		baseDir = strings.TrimSpace(baseDir)
		if baseDir == "" {
			continue
		}
		resolved, err := validateServerRootAgainstBase(serverRoot, baseDir)
		if err == nil {
			return resolved, nil
		}
		lastErr = err
	}
	if lastErr != nil {
		return "", lastErr
	}
	return "", fmt.Errorf("INVALID_SERVER_ROOT")
}
