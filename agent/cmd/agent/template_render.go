package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
)

var templateVariableRegex = regexp.MustCompile(`\{\{\s*([A-Za-z0-9_:-]+)\s*}}`)

func renderTemplateStrict(template string, values map[string]string) (string, error) {
	if template == "" {
		return "", nil
	}

	matches := templateVariableRegex.FindAllStringSubmatch(template, -1)
	for _, match := range matches {
		if len(match) < 2 {
			continue
		}
		key := strings.TrimSpace(match[1])
		if key == "" {
			continue
		}
		if _, ok := values[key]; !ok {
			return "", fmt.Errorf("missing template variable %s", key)
		}
	}

	rendered := template
	for _, match := range matches {
		if len(match) < 2 {
			continue
		}
		key := strings.TrimSpace(match[1])
		if key == "" {
			continue
		}
		rendered = strings.ReplaceAll(rendered, match[0], values[key])
	}

	return rendered, nil
}

func writeStartScript(instanceDir, startCommand string) (string, error) {
	if instanceDir == "" {
		return "", fmt.Errorf("missing instance directory")
	}
	if startCommand == "" {
		return "", fmt.Errorf("missing start command")
	}

	scriptDir := filepath.Join(instanceDir, "_easywi")
	if err := ensureInstanceDir(scriptDir); err != nil {
		return "", err
	}

	if runtime.GOOS != "windows" {
		if err := chownToInstanceOwner(instanceDir, scriptDir); err != nil {
			return "", err
		}
	}

	if runtime.GOOS == "windows" {
		scriptPath := filepath.Join(scriptDir, "start.bat")
		content := fmt.Sprintf("@echo off\r\ncd /d \"%s\"\r\n%s\r\n", instanceDir, startCommand)
		if err := os.WriteFile(scriptPath, []byte(content), instanceFileMode); err != nil {
			return "", fmt.Errorf("write start script: %w", err)
		}
		return scriptPath, nil
	}

	scriptPath := filepath.Join(scriptDir, "start.sh")
	content := fmt.Sprintf("#!/bin/bash\ncd \"%s\"\nexec %s\n", instanceDir, startCommand)
	if err := os.WriteFile(scriptPath, []byte(content), instanceFileMode); err != nil {
		return "", fmt.Errorf("write start script: %w", err)
	}
	if err := chownToInstanceOwner(instanceDir, scriptPath); err != nil {
		return "", err
	}
	if err := os.Chmod(scriptPath, 0o750); err != nil {
		return "", fmt.Errorf("chmod start script: %w", err)
	}

	return fmt.Sprintf("/bin/bash %q", scriptPath), nil
}

func maskSensitiveValues(input string, values map[string]string) string {
	if input == "" || len(values) == 0 {
		return input
	}

	masked := input
	for key, value := range values {
		if value == "" {
			continue
		}
		if !isSensitiveKey(key) {
			continue
		}
		masked = strings.ReplaceAll(masked, value, "****")
	}
	return masked
}

func isSensitiveKey(key string) bool {
	normalized := strings.ToUpper(key)
	indicators := []string{"PASSWORD", "TOKEN", "SECRET", "KEY", "RCON"}
	for _, indicator := range indicators {
		if strings.Contains(normalized, indicator) {
			return true
		}
	}
	return false
}

func validateBinaryExists(instanceDir, startCommand string) error {
	binaryToken := extractFirstCommandToken(startCommand)
	if binaryToken == "" {
		return nil
	}
	if strings.HasPrefix(binaryToken, "-") || strings.HasPrefix(binaryToken, "+") {
		return nil
	}
	if !strings.Contains(binaryToken, "/") && !filepath.IsAbs(binaryToken) {
		if _, err := exec.LookPath(binaryToken); err == nil {
			return nil
		}
	}
	binaryPath := resolveBinaryPath(instanceDir, binaryToken)
	if binaryPath == "" {
		return nil
	}
	if !pathExists(binaryPath) {
		return fmt.Errorf("missing binary")
	}
	return nil
}

func extractFirstCommandToken(command string) string {
	if command == "" {
		return ""
	}
	fields := strings.Fields(command)
	if len(fields) == 0 {
		return ""
	}
	return strings.Trim(fields[0], "\"'")
}

func resolveBinaryPath(instanceDir, token string) string {
	trimmed := strings.Trim(token, "\"'")
	if trimmed == "" {
		return ""
	}
	if strings.HasPrefix(trimmed, "./") {
		trimmed = strings.TrimPrefix(trimmed, "./")
	}
	if filepath.IsAbs(trimmed) {
		return trimmed
	}
	return filepath.Join(instanceDir, trimmed)
}
