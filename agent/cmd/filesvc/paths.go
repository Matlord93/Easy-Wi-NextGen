package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

func sanitizeRelativePath(path string) string {
	clean := filepath.Clean("/" + path)
	clean = strings.TrimPrefix(clean, "/")
	clean = strings.TrimPrefix(clean, string(filepath.Separator))
	return clean
}

func sanitizeInstancePath(root, relativePath string) (string, error) {
	if err := validateRelativePathInput(relativePath); err != nil {
		return "", err
	}
	cleanRelative := sanitizeRelativePath(relativePath)
	joined := filepath.Join(root, cleanRelative)
	normalized := filepath.Clean(joined)
	relativeToRoot, err := filepath.Rel(root, normalized)
	if err != nil {
		return "", fmt.Errorf("resolve relative path: %w", err)
	}
	if relativeToRoot == ".." || strings.HasPrefix(relativeToRoot, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("path escapes root")
	}
	return normalized, nil
}

func validateRelativePathInput(path string) error {
	normalized := strings.ReplaceAll(strings.TrimSpace(path), "\\", "/")
	if normalized == "" {
		return nil
	}
	if strings.HasPrefix(normalized, "/") {
		return fmt.Errorf("absolute paths are not allowed")
	}
	for _, segment := range strings.Split(normalized, "/") {
		if segment == ".." {
			return fmt.Errorf("path traversal is not allowed")
		}
		if strings.Contains(segment, "\x00") {
			return fmt.Errorf("invalid path segment")
		}
	}
	return nil
}

// DO NOT BUILD SERVER PATHS HERE.
// Filesvc must use the canonical install root supplied via X-Server-Root.
func validateServerRootAgainstBase(serverRoot, baseDir string) (string, error) {
	cleanRoot := filepath.Clean(strings.TrimSpace(serverRoot))
	cleanBase := filepath.Clean(strings.TrimSpace(baseDir))
	if cleanRoot == "" || !filepath.IsAbs(cleanRoot) {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	if cleanBase == "" || !filepath.IsAbs(cleanBase) {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	rel, err := filepath.Rel(cleanBase, cleanRoot)
	if err != nil {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	if rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("INVALID_SERVER_ROOT")
	}
	info, err := os.Stat(cleanRoot)
	if err != nil {
		if os.IsNotExist(err) {
			return "", fmt.Errorf("SERVER_ROOT_NOT_FOUND")
		}
		return "", fmt.Errorf("SERVER_ROOT_NOT_ACCESSIBLE")
	}
	if !info.IsDir() {
		return "", fmt.Errorf("SERVER_ROOT_NOT_ACCESSIBLE")
	}
	if _, err := os.ReadDir(cleanRoot); err != nil {
		return "", fmt.Errorf("SERVER_ROOT_NOT_ACCESSIBLE")
	}
	return cleanRoot, nil
}
