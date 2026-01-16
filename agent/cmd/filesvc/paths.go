package main

import (
	"fmt"
	"path/filepath"
	"strings"
)

func resolveInstanceRoot(baseDir, customerID, instanceID string) (string, error) {
	if customerID == "" || instanceID == "" {
		return "", fmt.Errorf("missing instance or customer id")
	}
	if !filepath.IsAbs(baseDir) {
		return "", fmt.Errorf("base dir must be absolute")
	}
	username := buildInstanceUsername(customerID, instanceID)
	root := filepath.Join(baseDir, username)
	return ensureSafePath(root, baseDir)
}

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

func buildInstanceUsername(customerID, instanceID string) string {
	sanitizedInstance := sanitizeIdentifier(instanceID)
	if len(sanitizedInstance) > 8 {
		sanitizedInstance = sanitizedInstance[:8]
	}
	return fmt.Sprintf("gs%s%s", customerID, sanitizedInstance)
}

func sanitizeIdentifier(value string) string {
	value = strings.ToLower(value)
	var builder strings.Builder
	for _, r := range value {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			builder.WriteRune(r)
		}
	}
	if builder.Len() == 0 {
		return "instance"
	}
	return builder.String()
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
